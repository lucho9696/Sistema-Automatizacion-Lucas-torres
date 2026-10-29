<?php

namespace App\Domain\Messaging;

use App\Models\BotFaq;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bot de consultas por reglas (especificación §6.2/§6.3). Sin IA: precios y stock salen
 * siempre de una consulta al catálogo; ante duda, error o tema sensible deriva a una persona.
 * Cada mensaje entrante se atiende una sola vez (bloqueo de fila + processed_at).
 */
class SalesAssistant
{
    public function __construct(
        private ConversationService $conversations,
        private CatalogLookup $catalog,
        private BotQuoteService $quotes,
    ) {}

    public function handle(Message $inbound): void
    {
        DB::transaction(function () use ($inbound) {
            $inbound = Message::whereKey($inbound->id)->lockForUpdate()->first();
            if (! $inbound || $inbound->processed_at || $inbound->direction !== 'in') {
                return;
            }
            $inbound->update(['processed_at' => now()]);
            $conversation = Conversation::whereKey($inbound->conversation_id)->lockForUpdate()->first();

            if ($conversation->mode === 'humano') {
                return; // bot en pausa: el operador ve el mensaje en la bandeja
            }

            try {
                // Punto de guardado: si la respuesta falla, se deshace solo lo que hizo y se deriva.
                DB::transaction(fn () => $this->respond($conversation, $inbound));
            } catch (Throwable $e) {
                Log::error('whatsapp.bot_error', ['message_id' => $inbound->id, 'error' => $e->getMessage()]);
                try {
                    DB::transaction(fn () => $this->reply($conversation, 'Necesito verificar esa información en el sistema. Te paso con una persona del equipo para que te responda.', 'error'));
                } catch (Throwable) {
                    // p. ej. ventana de 24 h cerrada: el operador lo ve igual en la bandeja
                }
                $this->conversations->handoff($conversation, 'Error interno al atender el mensaje');
            }
        });
    }

    private function respond(Conversation $c, Message $in): void
    {
        $cfg = config('integrations.whatsapp');
        $raw = (string) $in->body;
        $text = CatalogLookup::normalize($raw);

        if ($in->type !== 'text' && $in->type !== 'interactive') {
            $this->reply($c, 'Por ahora solo puedo leer mensajes de texto. Escribime qué modelo y cantidad buscás, o escribí "persona" para hablar con alguien del equipo.', 'no_soportado');

            return;
        }
        if ($text === '') {
            $this->misunderstood($c);

            return;
        }

        if ($this->matches($text, $cfg['opt_out_keywords'])) {
            $this->conversations->optOut($c, 'Pedido de baja por WhatsApp: "'.mb_substr($raw, 0, 80).'"');
            $this->reply($c, 'Listo, registramos tu baja: no te vamos a enviar mensajes promocionales. Si nos escribís por una consulta, te respondemos igual.', 'baja');

            return;
        }

        $pending = $this->quotes->pending($c);
        if ($pending) {
            if ($this->matches($text, $cfg['reject_keywords'])) {
                $this->quotes->close($pending, 'rechazada', 'El cliente no aceptó por WhatsApp');
                $this->reply($c, "Perfecto, dejé sin efecto la cotización #{$pending->id}. Si querés otra, decime modelo y cantidad.", 'rechazo');

                return;
            }
            if ($this->matches($text, $cfg['accept_keywords'])) {
                if ($pending->valid_until->isPast()) {
                    $this->quotes->close($pending, 'vencida', 'Aceptación posterior al vencimiento');
                    $this->reply($c, "La cotización #{$pending->id} venció. Pedímela de nuevo con modelo y cantidad y te paso precio y stock actualizados.", 'vencida');

                    return;
                }
                $this->quotes->accept($pending, $in);
                $this->reply($c, "¡Gracias! Registré tu pedido como *pendiente de confirmación* (cotización #{$pending->id}). "
                    .'Una persona del equipo te escribe para validar stock, forma de pago y entrega. '
                    .'El stock se reserva y el pago se considera recibido solo cuando lo confirmamos nosotros.', 'aceptacion');

                return;
            }
        }

        if ($this->matches($text, $cfg['handoff_keywords'])) {
            $this->reply($c, 'Te paso con una persona del equipo. Te van a responder por acá en cuanto estén disponibles.', 'derivacion');
            $this->conversations->handoff($c, 'El cliente pidió hablar con una persona o tocó un tema que requiere a alguien del equipo');

            return;
        }

        if ($faq = $this->faq($text)) {
            $this->reply($c, $faq->answer, 'faq', [['type' => 'faq', 'id' => $faq->id, 'topic' => $faq->topic]]);
            $c->update(['misunderstood_count' => 0]);

            return;
        }
        if ($topic = $this->firstMatch($text, $cfg['sensitive_topics'])) {
            $this->reply($c, 'Sobre eso prefiero que te responda una persona del equipo para darte la información correcta. Ya le aviso.', 'derivacion');
            $this->conversations->handoff($c, "Consulta sobre \"{$topic}\" sin respuesta configurada en las preguntas frecuentes");

            return;
        }

        if (CatalogLookup::quantity($text) === null && preg_match('/\b(estado|seguimiento|mi pedido|mis pedidos|mi compra|donde esta)\b/', $text)) {
            $this->orderStatus($c);

            return;
        }

        $variants = $this->catalog->search($raw);
        if ($variants->isNotEmpty()) {
            $c->update(['misunderstood_count' => 0]);
            $qty = CatalogLookup::quantity($text);
            if ($qty !== null && $variants->count() === 1) {
                $this->quote($c, $in, $variants->first(), $qty);

                return;
            }
            $this->priceList($c, $variants, $qty);

            return;
        }

        if (preg_match('/^(hola|buenas|buen dia|buenos dias|buenas tardes|buenas noches|hey|que tal)\b/', $text)) {
            $this->reply($c, $this->menu('¡Hola! Soy el asistente automático.'), 'saludo');

            return;
        }

        $this->misunderstood($c);
    }

    private function priceList(Conversation $c, Collection $variants, ?int $qty): void
    {
        $at = now()->timezone(config('business.display_timezone'))->format('d/m H:i');
        $lines = [];
        $sources = [];
        foreach ($variants as $v) {
            /** @var ProductVariant $v */
            $name = trim($v->product->name.' '.$v->name);
            $price = $v->list_price !== null ? $this->money($v->price_currency, (string) $v->list_price) : 'precio a confirmar por un vendedor';
            $stock = $v->stock_available > 0 ? "{$v->stock_available} disponibles" : 'sin stock';
            $min = $v->wholesale_min_qty ? " · mínimo {$v->wholesale_min_qty} u." : '';
            $lines[] = "• {$name}: {$price} · {$stock}{$min}";
            $sources[] = $this->source($v);
        }
        $intro = "Esto es lo que figura ahora en el sistema ({$at}):";
        $outro = $qty !== null
            ? 'Hay más de una opción: decime cuál querés (por ejemplo con la capacidad o el color) y la cantidad, y te armo la cotización.'
            : 'Para cotizar, decime modelo y cantidad, por ejemplo: "10 unidades iPhone 15 128".';
        $this->reply($c, $intro."\n".implode("\n", $lines)."\n\n".$outro, 'precio_stock', $sources);
    }

    private function quote(Conversation $c, Message $in, ProductVariant $v, int $qty): void
    {
        $name = trim($v->product->name.' '.$v->name);
        $source = [$this->source($v)];
        if ($v->list_price === null) {
            $this->reply($c, "{$name} no tiene precio cargado en este momento. Te paso con un vendedor para que te lo confirme.", 'sin_precio', $source);
            $this->conversations->handoff($c, "Pidió cotización de {$qty} × {$v->sku} sin precio cargado");

            return;
        }
        if ($v->wholesale_min_qty && $qty < $v->wholesale_min_qty) {
            $this->reply($c, "Para {$name} el mínimo mayorista es de {$v->wholesale_min_qty} unidades. ¿Querés que te cotice esa cantidad?", 'minimo', $source);

            return;
        }
        if ($v->stock_available < $qty) {
            $available = $v->stock_available > 0 ? "Hoy hay {$v->stock_available} disponibles." : 'Hoy no hay stock disponible.';
            $this->reply($c, "No tengo {$qty} unidades de {$name}. {$available} Te paso con un vendedor para ver alternativas o ingresos.", 'sin_stock', $source);
            $this->conversations->handoff($c, "Pidió {$qty} × {$v->sku}; stock disponible {$v->stock_available}");

            return;
        }

        $quote = $this->quotes->create($c, $in, $v, $qty);
        $item = $quote->items[0];
        $until = $quote->valid_until->timezone(config('business.display_timezone'))->format('d/m H:i');
        $body = "Cotización #{$quote->id} (válida hasta el {$until}):\n"
            ."{$qty} × {$name} a {$this->money($quote->currency, $item['unit_price'])} = {$this->money($quote->currency, $item['line_total'])}\n"
            ."*Total: {$this->money($quote->currency, (string) $quote->total)}*\n\n"
            .'Si querés avanzar respondé *CONFIRMO*. El stock no queda reservado hasta que una persona valide el pedido, '
            .'y la forma de pago y la entrega se coordinan con un vendedor.';
        $source[] = ['type' => 'quote', 'id' => $quote->id];
        $this->reply($c, $body, 'cotizacion', $source);
    }

    private function orderStatus(Conversation $c): void
    {
        // La identidad se valida por el número: solo se informa sobre pedidos del cliente con ese teléfono.
        $customer = $c->customer ?? ($c->phone_e164 ? Customer::where('phone_e164', $c->phone_e164)->first() : null);
        if (! $customer) {
            $this->reply($c, 'No encuentro pedidos asociados a este número. Si compraste con otro número o a nombre de otra empresa, escribí "persona" y te ayuda alguien del equipo.', 'estado_pedido');

            return;
        }
        $sales = Sale::where('customer_id', $customer->id)->where('status', '!=', 'borrador')->latest('id')->limit(3)->get();
        if ($sales->isEmpty()) {
            $this->reply($c, 'No veo pedidos confirmados a tu nombre. Si estás esperando uno, escribí "persona" y lo revisamos.', 'estado_pedido');

            return;
        }
        $status = ['confirmada' => 'confirmado', 'entregada' => 'entregado', 'cancelada' => 'cancelado', 'devuelta' => 'devuelto'];
        $payment = ['pendiente' => 'pago pendiente', 'parcial' => 'pago parcial', 'pagado' => 'pagado'];
        $lines = $sales->map(fn (Sale $s) => "• {$s->number} del ".$s->sale_date->format('d/m').': '.($status[$s->status] ?? $s->status)
            .', '.($payment[$s->payment_status] ?? $s->payment_status)
            .(Money::cmp((string) $s->balance_due, '0') > 0 ? ', saldo '.$this->money($s->currency, (string) $s->balance_due) : ''));
        $this->reply($c, "Tus últimos pedidos:\n".$lines->implode("\n"), 'estado_pedido', [['type' => 'sales', 'ids' => $sales->pluck('id')->all()]]);
    }

    private function misunderstood(Conversation $c): void
    {
        $count = $c->misunderstood_count + 1;
        $c->update(['misunderstood_count' => $count]);
        if ($count >= config('integrations.whatsapp.max_misunderstood')) {
            $this->reply($c, 'No logro entenderte bien. Te paso con una persona del equipo.', 'derivacion');
            $this->conversations->handoff($c, "El bot no entendió {$count} mensajes seguidos");

            return;
        }
        $this->reply($c, $this->menu('No estoy seguro de haberte entendido.'), 'no_entendido');
    }

    private function menu(string $intro): string
    {
        return $intro." Puedo ayudarte con:\n"
            ."• Precio y stock: escribí el modelo, por ejemplo \"iPhone 15 128\".\n"
            ."• Cotización mayorista: modelo y cantidad, por ejemplo \"10 unidades iPhone 15 128\".\n"
            ."• Estado de tu pedido: escribí \"estado de mi pedido\".\n"
            .'• Hablar con una persona: escribí "persona".';
    }

    private function faq(string $text): ?BotFaq
    {
        foreach (BotFaq::where('is_active', true)->orderBy('id')->get() as $faq) {
            $keywords = array_filter(array_map(fn ($k) => CatalogLookup::normalize($k), explode(',', $faq->keywords)));
            if ($this->matches($text, $keywords)) {
                return $faq;
            }
        }

        return null;
    }

    /** Coincidencia por palabra o frase completa (o raíz si termina sin espacio, p. ej. "autentic"). */
    private function matches(string $text, array $needles): bool
    {
        return $this->firstMatch($text, $needles) !== null;
    }

    private function firstMatch(string $text, array $needles): ?string
    {
        $hay = " {$text} ";
        foreach ($needles as $n) {
            $n = CatalogLookup::normalize((string) $n);
            if ($n !== '' && (str_contains($hay, " {$n} ") || (strlen($n) >= 6 && str_contains($hay, " {$n}")))) {
                return $n;
            }
        }

        return null;
    }

    private function source(ProductVariant $v): array
    {
        return [
            'type' => 'variant', 'id' => $v->id, 'sku' => $v->sku,
            'list_price' => $v->list_price !== null ? (string) $v->list_price : null,
            'currency' => $v->price_currency, 'stock_available' => $v->stock_available,
            'at' => now()->toIso8601String(),
        ];
    }

    private function reply(Conversation $c, string $body, string $intent, ?array $sources = null): void
    {
        $this->conversations->sendText($c, $body, 'bot', null, $intent, $sources);
    }

    /** Formato argentino sin pasar por float: USD 9.500,00 */
    private function money(?string $currency, string $amount): string
    {
        $amount = Money::round($amount);
        $negative = str_starts_with($amount, '-');
        [$int, $dec] = explode('.', ltrim($amount, '-'));
        $int = strrev(implode('.', str_split(strrev($int), 3)));

        return trim(($currency ?: config('business.base_currency')).' '.($negative ? '-' : '').$int.','.$dec);
    }
}
