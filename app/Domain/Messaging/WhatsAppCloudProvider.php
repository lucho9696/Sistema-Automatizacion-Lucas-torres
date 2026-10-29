<?php

namespace App\Domain\Messaging;

use App\Domain\Integrations\IntegrationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp Business Platform Cloud API (oficial): POST /{version}/{phone_number_id}/messages.
 * El token es de un usuario del sistema de la cuenta Meta Business del negocio; nunca se
 * pide la contraseña personal de nadie.
 */
class WhatsAppCloudProvider implements MessagingProvider
{
    public function mode(): string
    {
        return 'cloud';
    }

    /** @return list<string> variables faltantes */
    public static function missingConfig(): array
    {
        $map = [
            'WHATSAPP_PHONE_NUMBER_ID' => 'phone_number_id',
            'WHATSAPP_ACCESS_TOKEN' => 'access_token',
            'WHATSAPP_APP_SECRET' => 'app_secret',
            'WHATSAPP_VERIFY_TOKEN' => 'verify_token',
        ];

        return array_keys(array_filter($map, fn ($key) => blank(config("integrations.whatsapp.{$key}"))));
    }

    public function sendText(string $to, string $body): SendResult
    {
        return $this->post([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $body],
        ]);
    }

    public function sendTemplate(string $to, string $name, string $language, array $params = []): SendResult
    {
        $template = ['name' => $name, 'language' => ['code' => $language]];
        if ($params !== []) {
            $template['components'] = [[
                'type' => 'body',
                'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => (string) $p], array_values($params)),
            ]];
        }

        return $this->post(['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => $template]);
    }

    private function post(array $payload): SendResult
    {
        if ($missing = self::missingConfig()) {
            throw new IntegrationException('WhatsApp Cloud API sin configurar: faltan '.implode(', ', $missing).'.');
        }
        $cfg = config('integrations.whatsapp');
        $url = rtrim($cfg['graph_url'], '/')."/{$cfg['graph_version']}/{$cfg['phone_number_id']}/messages";

        try {
            $response = Http::withToken($cfg['access_token'])->acceptJson()->timeout($cfg['timeout'])
                ->retry(2, 500, fn ($e) => $e instanceof ConnectionException
                    || ($e->response ?? null)?->status() === 429 || ($e->response ?? null)?->serverError(), throw: false)
                ->post($url, $payload);
        } catch (ConnectionException) {
            throw new IntegrationException('No se pudo conectar con WhatsApp Cloud API (tiempo de espera agotado).');
        }

        if (! $response->successful()) {
            throw new IntegrationException($this->describe($response));
        }
        $id = $response->json('messages.0.id');
        if (! is_string($id) || $id === '') {
            throw new IntegrationException('WhatsApp respondió sin identificador de mensaje.');
        }

        return new SendResult($id, 'enviado');
    }

    /** Mensaje accionable y sin secretos a partir del error de la Graph API. */
    private function describe(Response $response): string
    {
        $code = $response->json('error.code');
        $msg = mb_substr((string) $response->json('error.message', ''), 0, 200);

        return match (true) {
            $response->status() === 401 || $code === 190 => 'El token de WhatsApp es inválido o venció: generá uno nuevo del usuario del sistema.',
            $code === 131047 => 'Pasaron más de 24 h desde el último mensaje del cliente: usá una plantilla aprobada.',
            $code === 131026 => 'El número no puede recibir mensajes de WhatsApp.',
            $code === 132001 => 'La plantilla no existe o no está aprobada en ese idioma.',
            $response->status() === 429 || $code === 130429 || $code === 131056 => 'Límite de envío de WhatsApp alcanzado; reintentar más tarde.',
            default => trim("WhatsApp respondió HTTP {$response->status()}".($code ? " (código {$code})" : '').($msg !== '' ? ": {$msg}" : '')),
        };
    }
}
