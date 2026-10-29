<?php

namespace App\Jobs;

use App\Domain\Messaging\WhatsAppWebhook;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** El webhook responde 200 enseguida y el trabajo pesado se hace acá (especificación §6.1). */
class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(public int $eventId) {}

    public function handle(WhatsAppWebhook $webhook): void
    {
        $event = WebhookEvent::find($this->eventId);
        if (! $event || $event->processed_at) {
            return;
        }
        $event->increment('attempts');
        $webhook->process($event); // idempotente: los mensajes se deduplican por ID externo
        $event->update(['processed_at' => now(), 'error' => null]);
    }

    public function failed(Throwable $e): void
    {
        // Sin el mensaje completo: puede incluir datos del cliente (p. ej. valores de una consulta SQL).
        WebhookEvent::whereKey($this->eventId)->update(['error' => class_basename($e).' (código '.$e->getCode().'). Ver logs con el id del evento.']);
        logger()->error('whatsapp.webhook_fallido', ['event_id' => $this->eventId, 'exception' => class_basename($e)]);
    }
}
