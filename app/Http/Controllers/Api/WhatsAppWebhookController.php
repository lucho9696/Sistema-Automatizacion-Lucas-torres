<?php

namespace App\Http\Controllers\Api;

use App\Domain\Messaging\WhatsAppWebhook;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;

/** Endpoint público que llama Meta. Sin sesión: se protege con verify token y firma HMAC. */
class WhatsAppWebhookController extends Controller
{
    /** Verificación de suscripción (GET con hub.mode, hub.verify_token, hub.challenge). */
    public function verify(Request $request)
    {
        $expected = config('integrations.whatsapp.verify_token');
        // PHP convierte los puntos de "hub.mode" en guiones bajos.
        $token = (string) $request->query('hub_verify_token', '');
        if (! $expected || $request->query('hub_mode') !== 'subscribe' || ! hash_equals($expected, $token)) {
            return response('Forbidden', 403);
        }

        return response((string) $request->query('hub_challenge', ''), 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request)
    {
        $secret = config('integrations.whatsapp.app_secret');
        if (! $secret) {
            return response()->json(['message' => 'Webhook de WhatsApp no configurado (WHATSAPP_APP_SECRET).'], 503);
        }
        $raw = $request->getContent();
        if (! WhatsAppWebhook::validSignature($raw, $request->header('X-Hub-Signature-256'), $secret)) {
            return response()->json(['message' => 'Firma inválida.'], 401);
        }
        $payload = json_decode($raw, true);
        if (! is_array($payload) || ($payload['object'] ?? null) !== 'whatsapp_business_account') {
            return response('EVENT_IGNORED', 200);
        }

        $event = WebhookEvent::createOrFirst(
            ['provider' => 'whatsapp', 'payload_hash' => hash('sha256', $raw)],
            ['payload' => $payload, 'received_at' => now()]
        );
        if ($event->wasRecentlyCreated) {
            ProcessWebhookEvent::dispatch($event->id);
        }

        return response('EVENT_RECEIVED', 200);
    }
}
