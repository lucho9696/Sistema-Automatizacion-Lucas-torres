<?php

namespace App\Domain\Messaging;

use App\Domain\Crm\Suppression;
use App\Domain\Integrations\IntegrationException;
use App\Domain\Shared\Auditor;
use App\Domain\Shared\BusinessRuleException;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Conversaciones y mensajes: registro de entrantes (idempotente por ID externo), envíos con
 * control de ventana de 24 h y opt-out, estados de entrega, derivación a una persona.
 */
class ConversationService
{
    private const STATUS_RANK = ['simulado' => 1, 'enviado' => 1, 'entregado' => 2, 'leido' => 3];

    public function __construct(
        private MessagingProvider $provider,
        private Suppression $suppression,
        private Auditor $auditor,
    ) {}

    /**
     * Registra un mensaje entrante. Devuelve null si el ID externo ya existía (reenvío del webhook).
     */
    public function recordInbound(string $waId, ?string $profileName, string $externalId, string $type, ?string $body, Carbon $at): ?Message
    {
        return DB::transaction(function () use ($waId, $profileName, $externalId, $type, $body, $at) {
            $phone = Phone::normalize('+'.ltrim($waId, '+'));
            $conversation = Conversation::createOrFirst(['channel' => 'whatsapp', 'wa_id' => $waId], ['phone_e164' => $phone]);
            $conversation = Conversation::whereKey($conversation->id)->lockForUpdate()->first();

            $message = Message::createOrFirst(['external_id' => $externalId], [
                'conversation_id' => $conversation->id,
                'direction' => 'in',
                'author' => 'cliente',
                'type' => $type,
                'body' => $body,
                'status' => 'recibido',
                'created_at' => $at,
            ]);
            if (! $message->wasRecentlyCreated) {
                return null;
            }

            $updates = ['last_message_at' => now()];
            if ($conversation->last_inbound_at === null || $at->gt($conversation->last_inbound_at)) {
                $updates['last_inbound_at'] = $at;
            }
            if ($profileName) {
                $updates['profile_name'] = mb_substr($profileName, 0, 120);
            }
            if (! $conversation->customer_id && $phone) {
                $updates['customer_id'] = Customer::where('phone_e164', $phone)->value('id');
            }
            if (! $conversation->lead_id && $phone) {
                $updates['lead_id'] = Lead::where('phone_e164', $phone)->whereNull('duplicate_of_id')->whereNull('erased_at')->value('id');
            }
            if ($conversation->mode === 'humano') {
                $updates['needs_attention'] = true;
            }
            $conversation->update(array_filter($updates, fn ($v) => $v !== null));

            return $message;
        });
    }

    /** Actualiza el estado de entrega de un mensaje saliente sin retroceder (leído no vuelve a entregado). */
    public function recordStatus(string $externalId, string $status, Carbon $at, ?string $error = null): void
    {
        $map = ['sent' => 'enviado', 'delivered' => 'entregado', 'read' => 'leido', 'failed' => 'fallido'];
        $new = $map[$status] ?? null;
        if (! $new) {
            return;
        }
        DB::transaction(function () use ($externalId, $new, $at, $error) {
            $message = Message::where('external_id', $externalId)->where('direction', 'out')->lockForUpdate()->first();
            if (! $message) {
                return;
            }
            if ($new === 'fallido') {
                $message->update(['status' => 'fallido', 'error' => $error ? mb_substr($error, 0, 500) : 'Entrega fallida']);

                return;
            }
            if ((self::STATUS_RANK[$new] ?? 0) <= (self::STATUS_RANK[$message->status] ?? 0) || $message->status === 'fallido') {
                return;
            }
            $message->update(array_filter([
                'status' => $new,
                'delivered_at' => in_array($new, ['entregado', 'leido'], true) ? ($message->delivered_at ?? $at) : null,
                'read_at' => $new === 'leido' ? $at : null,
            ]));
        });
    }

    /**
     * Envía texto libre. Solo dentro de la ventana de 24 h; afuera hay que usar una plantilla.
     * Un fallo del proveedor queda registrado en el mensaje (no se pierde ni se reintenta a ciegas).
     */
    public function sendText(Conversation $conversation, string $body, string $author, ?User $user = null, ?string $intent = null, ?array $sources = null): Message
    {
        if (! $conversation->window_open) {
            throw new BusinessRuleException('Pasaron más de 24 h desde el último mensaje del cliente: solo se pueden enviar plantillas aprobadas.', 'body');
        }
        $message = $conversation->messages()->create([
            'direction' => 'out', 'author' => $author, 'user_id' => $user?->id, 'type' => 'text',
            'body' => $body, 'intent' => $intent, 'sources' => $sources, 'status' => 'pendiente',
        ]);

        return $this->deliver($conversation, $message, fn () => $this->provider->sendText($conversation->wa_id, $body));
    }

    /** Plantilla aprobada (fuera de la ventana). No se envía a quien pidió la baja. */
    public function sendTemplate(Conversation $conversation, string $name, string $language, array $params, User $user): Message
    {
        if ($conversation->opted_out_at) {
            throw new BusinessRuleException('El contacto pidió la baja: no se le envían plantillas.');
        }
        if ($this->suppression->isSuppressed($conversation->phone_e164, null, 'whatsapp')) {
            throw new BusinessRuleException('El número está en la lista de no contactar.');
        }
        $message = $conversation->messages()->create([
            'direction' => 'out', 'author' => 'operador', 'user_id' => $user->id, 'type' => 'template',
            'template_name' => $name, 'body' => $params ? 'Variables: '.implode(' | ', $params) : null, 'status' => 'pendiente',
        ]);
        $this->auditor->record('whatsapp.plantilla_enviada', $conversation, null, ['template' => $name, 'language' => $language]);

        return $this->deliver($conversation, $message, fn () => $this->provider->sendTemplate($conversation->wa_id, $name, $language, $params));
    }

    private function deliver(Conversation $conversation, Message $message, callable $send): Message
    {
        try {
            /** @var SendResult $result */
            $result = $send();
            $message->update(['external_id' => $result->externalId, 'status' => $result->status, 'sent_at' => now()]);
        } catch (IntegrationException $e) {
            $message->update(['status' => 'fallido', 'error' => $e->getMessage()]);
            $conversation->update(['needs_attention' => true]);
            Log::warning('whatsapp.envio_fallido', ['message_id' => $message->id, 'error' => $e->getMessage()]);
        }
        $conversation->update(['last_message_at' => now()]);

        return $message;
    }

    /** Deriva a una persona y pausa el bot hasta que un operador lo reactive. */
    public function handoff(Conversation $conversation, string $reason, ?User $user = null): Conversation
    {
        $before = $conversation->only(['mode', 'assigned_to']);
        $conversation->update([
            'mode' => 'humano',
            'handoff_reason' => mb_substr($reason, 0, 250),
            'handoff_at' => now(),
            'needs_attention' => true,
            'assigned_to' => $user?->id ?? $conversation->assigned_to,
        ]);
        $this->auditor->record('whatsapp.derivada', $conversation, $before, $conversation->only(['mode', 'assigned_to']), $reason);

        return $conversation;
    }

    public function resumeBot(Conversation $conversation, User $user): Conversation
    {
        $before = $conversation->only(['mode', 'assigned_to']);
        $conversation->update(['mode' => 'bot', 'handoff_reason' => null, 'needs_attention' => false, 'misunderstood_count' => 0]);
        $this->auditor->record('whatsapp.bot_reactivado', $conversation, $before, ['mode' => 'bot', 'by' => $user->id]);

        return $conversation;
    }

    /** Baja pedida por el cliente: no recibe más plantillas ni entra en listas de envío por WhatsApp. */
    public function optOut(Conversation $conversation, string $reason): void
    {
        $conversation->update(['opted_out_at' => $conversation->opted_out_at ?? now()]);
        $this->suppression->add('phone', $conversation->phone_e164, 'whatsapp', $reason);
        $this->auditor->record('whatsapp.baja', $conversation, null, ['opted_out_at' => $conversation->opted_out_at], $reason);
    }
}
