<?php

namespace App\Domain\Messaging;

use App\Domain\Sales\SaleService;
use App\Domain\Shared\Auditor;
use App\Domain\Shared\BusinessRuleException;
use App\Domain\Shared\Currency;
use App\Models\BotQuote;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\BusinessDate;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Cotizaciones del bot (precios congelados del catálogo) y su paso a pedido.
 *
 *   enviada --(cliente responde CONFIRMO)--> aceptada = pedido pendiente de confirmación
 *   aceptada --(una persona valida)--> convertida (venta borrador, o confirmada con stock reservado)
 *   enviada|aceptada --> rechazada | vencida
 *
 * Nunca se registra un cobro desde acá: la venta queda con pago pendiente.
 */
class BotQuoteService
{
    public function __construct(private SaleService $sales, private Auditor $auditor) {}

    public function create(Conversation $conversation, Message $source, ProductVariant $variant, int $qty): BotQuote
    {
        $currency = $variant->price_currency ?: Currency::base();
        $unit = Money::round((string) $variant->list_price);
        $total = Money::mul($qty, $unit);

        return BotQuote::createOrFirst(['source_message_id' => $source->id], [
            'conversation_id' => $conversation->id,
            'currency' => $currency,
            'total' => $total,
            'items' => [[
                'product_variant_id' => $variant->id,
                'sku' => $variant->sku,
                'description' => trim($variant->product->name.' '.$variant->name),
                'quantity' => $qty,
                'unit_price' => $unit,
                'line_total' => $total,
                'stock_available' => $variant->stock_available,
            ]],
            'valid_until' => now()->addHours(config('integrations.whatsapp.quote_valid_hours')),
            'status' => 'enviada',
        ]);
    }

    /** Cotización vigente esperando respuesta del cliente (la más reciente). */
    public function pending(Conversation $conversation): ?BotQuote
    {
        return $conversation->quotes()->where('status', 'enviada')->latest('id')->first();
    }

    public function accept(BotQuote $quote, Message $message): BotQuote
    {
        $quote->update(['status' => 'aceptada', 'accepted_at' => now(), 'accepted_message_id' => $message->id]);
        $quote->conversation->update(['needs_attention' => true]);
        $this->auditor->record('whatsapp.cotizacion_aceptada', $quote, ['status' => 'enviada'], ['status' => 'aceptada']);

        return $quote;
    }

    public function close(BotQuote $quote, string $status, ?string $reason = null): BotQuote
    {
        if (! in_array($quote->status, ['enviada', 'aceptada'], true)) {
            throw new BusinessRuleException("La cotización ya está {$quote->status}.");
        }
        $before = $quote->status;
        $quote->update(['status' => $status]);
        $this->auditor->record("whatsapp.cotizacion_{$status}", $quote, ['status' => $before], ['status' => $status], $reason);

        return $quote;
    }

    /**
     * Una persona valida el pedido: crea la venta con los precios cotizados. Con $confirm la venta
     * queda confirmada (stock reservado, pago pendiente); si no hay stock, falla todo junto.
     */
    public function convertToSale(BotQuote $quote, User $user, ?string $exchangeRate, ?string $rateSource, bool $confirm, ?string $customerName): BotQuote
    {
        return DB::transaction(function () use ($quote, $user, $exchangeRate, $rateSource, $confirm, $customerName) {
            $quote = BotQuote::whereKey($quote->id)->lockForUpdate()->firstOrFail();
            if ($quote->status !== 'aceptada' || $quote->sale_id) {
                throw new BusinessRuleException('Solo se convierte una cotización aceptada por el cliente y todavía sin venta.');
            }
            $conversation = $quote->conversation;
            $customer = $conversation->customer
                ?? ($conversation->phone_e164 ? Customer::where('phone_e164', $conversation->phone_e164)->first() : null);
            if (! $customer) {
                $customer = Customer::create([
                    'name' => $customerName ?: ($conversation->profile_name ?: $conversation->phone_e164 ?: 'Cliente WhatsApp'),
                    'phone_e164' => $conversation->phone_e164,
                    'type' => 'mayorista',
                    'source' => 'whatsapp',
                ]);
            }
            $conversation->update(['customer_id' => $customer->id]);

            $sale = $this->sales->createDraft([
                'customer_id' => $customer->id,
                'currency' => $quote->currency,
                'exchange_rate' => $exchangeRate,
                'exchange_rate_source' => $rateSource,
                'payment_terms' => 'contado',
                'quote_valid_until' => $quote->valid_until->toDateString(),
                'sale_date' => BusinessDate::today(),
                'notes' => "Pedido por WhatsApp (cotización #{$quote->id}).",
                'items' => array_map(fn ($i) => [
                    'product_variant_id' => $i['product_variant_id'],
                    'quantity' => $i['quantity'],
                    'unit_price' => $i['unit_price'],
                ], $quote->items),
            ]);
            if ($confirm) {
                $sale = $this->sales->confirm($sale);
            }
            $quote->update(['status' => 'convertida', 'sale_id' => $sale->id, 'converted_by' => $user->id]);
            $conversation->update(['needs_attention' => false]);
            $this->auditor->record('whatsapp.cotizacion_convertida', $quote, ['status' => 'aceptada'], ['status' => 'convertida', 'sale' => $sale->number]);

            return $quote->load('sale');
        });
    }
}
