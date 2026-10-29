<?php

namespace App\Domain\Messaging;

use App\Models\WebhookEvent;
use Illuminate\Support\Carbon;

/**
 * Interpreta el cuerpo de un webhook de WhatsApp Cloud API (objeto whatsapp_business_account):
 * mensajes entrantes y estados de entrega de mensajes enviados.
 */
class WhatsAppWebhook
{
    public function __construct(private ConversationService $conversations, private SalesAssistant $assistant) {}

    /** Firma X-Hub-Signature-256: HMAC-SHA256 del cuerpo crudo con el App Secret. */
    public static function validSignature(string $rawBody, ?string $header, ?string $appSecret): bool
    {
        if (! $appSecret || ! $header || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $rawBody, $appSecret), $header);
    }

    public function process(WebhookEvent $event): void
    {
        $expectedPhoneId = config('integrations.whatsapp.phone_number_id');
        foreach ($event->payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? null) !== 'messages') {
                    continue;
                }
                $value = $change['value'] ?? [];
                $phoneId = $value['metadata']['phone_number_id'] ?? null;
                if (! $event->simulated && $expectedPhoneId && $phoneId !== $expectedPhoneId) {
                    continue; // otro número de la misma cuenta: no es nuestro
                }
                $names = [];
                foreach ($value['contacts'] ?? [] as $contact) {
                    $names[$contact['wa_id'] ?? ''] = $contact['profile']['name'] ?? null;
                }
                foreach ($value['messages'] ?? [] as $msg) {
                    $this->inbound($msg, $names);
                }
                foreach ($value['statuses'] ?? [] as $status) {
                    $error = isset($status['errors'][0])
                        ? trim(($status['errors'][0]['code'] ?? '').' '.($status['errors'][0]['title'] ?? ''))
                        : null;
                    if (isset($status['id'], $status['status'])) {
                        $this->conversations->recordStatus($status['id'], $status['status'], $this->time($status['timestamp'] ?? null), $error);
                    }
                }
            }
        }
    }

    private function inbound(array $msg, array $names): void
    {
        if (! isset($msg['id'], $msg['from'])) {
            return;
        }
        $type = $msg['type'] ?? 'otro';
        $body = match ($type) {
            'text' => $msg['text']['body'] ?? '',
            'button' => $msg['button']['text'] ?? '',
            'interactive' => $msg['interactive']['button_reply']['title'] ?? $msg['interactive']['list_reply']['title'] ?? '',
            default => "[{$type}]",
        };
        $type = in_array($type, ['text', 'button'], true) ? 'text' : ($type === 'interactive' ? 'interactive' : 'otro');

        $message = $this->conversations->recordInbound(
            (string) $msg['from'], $names[$msg['from']] ?? null, (string) $msg['id'], $type, mb_substr($body, 0, 4096), $this->time($msg['timestamp'] ?? null)
        );
        if ($message) {
            $this->assistant->handle($message);
        }
    }

    private function time(mixed $timestamp): Carbon
    {
        return is_numeric($timestamp) ? Carbon::createFromTimestampUTC((int) $timestamp) : now();
    }
}
