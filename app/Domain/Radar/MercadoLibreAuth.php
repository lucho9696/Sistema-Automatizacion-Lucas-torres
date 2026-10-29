<?php

namespace App\Domain\Radar;

use App\Domain\Integrations\IntegrationException;
use App\Models\IntegrationToken;
use Illuminate\Support\Facades\Http;

/**
 * OAuth 2.0 de Mercado Libre (authorization code + refresh token). Los tokens se guardan
 * cifrados en integration_tokens; nunca en Git, logs ni frontend.
 */
class MercadoLibreAuth
{
    public const PROVIDER = 'mercadolibre';

    /** Variables faltantes para operar en modo real. */
    public static function missingConfig(): array
    {
        return collect(['client_id' => 'ML_CLIENT_ID', 'client_secret' => 'ML_CLIENT_SECRET', 'redirect_uri' => 'ML_REDIRECT_URI'])
            ->filter(fn ($env, $key) => blank(config("integrations.mercadolibre.{$key}")))
            ->values()->all();
    }

    public function authorizationUrl(): string
    {
        $this->assertConfigured();

        return config('integrations.mercadolibre.auth_url').'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('integrations.mercadolibre.client_id'),
            'redirect_uri' => config('integrations.mercadolibre.redirect_uri'),
        ]);
    }

    public function exchangeCode(string $code): void
    {
        $this->assertConfigured();
        $this->storeTokens($this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('integrations.mercadolibre.redirect_uri'),
        ]));
    }

    public function isConnected(): bool
    {
        return IntegrationToken::where('provider', self::PROVIDER)->whereNotNull('refresh_token')->exists();
    }

    public function accessToken(): string
    {
        $this->assertConfigured();
        $token = IntegrationToken::where('provider', self::PROVIDER)->first();
        if (! $token || ! $token->refresh_token) {
            throw new IntegrationException('Mercado Libre no está autorizado: ejecute `php artisan app:ml-connect`.');
        }
        if ($token->access_token && $token->expires_at && $token->expires_at->isAfter(now()->addMinute())) {
            return $token->access_token;
        }
        $data = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $token->refresh_token]);
        $this->storeTokens($data);

        return $data['access_token'];
    }

    private function tokenRequest(array $params): array
    {
        $response = Http::asForm()->acceptJson()
            ->timeout(config('integrations.mercadolibre.timeout'))
            ->post(config('integrations.mercadolibre.base_url').'/oauth/token', $params + [
                'client_id' => config('integrations.mercadolibre.client_id'),
                'client_secret' => config('integrations.mercadolibre.client_secret'),
            ]);
        if (! $response->successful() || ! $response->json('access_token')) {
            // No se incluye el cuerpo de la respuesta: puede contener datos sensibles.
            throw new IntegrationException("Mercado Libre rechazó la autenticación (HTTP {$response->status()}). Reautorice la aplicación.");
        }

        return $response->json();
    }

    private function storeTokens(array $data): void
    {
        IntegrationToken::updateOrCreate(['provider' => self::PROVIDER], [
            'access_token' => $data['access_token'],
            // Mercado Libre rota el refresh token: siempre se guarda el último recibido.
            'refresh_token' => $data['refresh_token'] ?? IntegrationToken::where('provider', self::PROVIDER)->value('refresh_token'),
            'expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
        ]);
    }

    private function assertConfigured(): void
    {
        if ($missing = self::missingConfig()) {
            throw new IntegrationException('Faltan variables de entorno de Mercado Libre: '.implode(', ', $missing).'.');
        }
    }
}
