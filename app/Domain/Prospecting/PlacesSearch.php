<?php

namespace App\Domain\Prospecting;

use App\Domain\Integrations\IntegrationException;
use App\Domain\Integrations\SyncRunRecorder;
use App\Models\SyncRun;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Búsqueda de comercios con Google Places API (New) - Text Search.
 *
 * Modos (GOOGLE_PLACES_MODE): off (por defecto) | demo (resultados ficticios marcados) | live.
 * En live: FieldMask obligatorio, tope mensual de solicitudes y registro de cada consulta.
 * Los resultados no se guardan solos: una persona elige cuáles pasan a prospectos.
 */
class PlacesSearch
{
    public const PROVIDER = 'google_places';

    public function __construct(private SyncRunRecorder $runs) {}

    public static function status(): array
    {
        $mode = config('integrations.google_places.mode');
        $missing = [];
        if ($mode === 'live') {
            if (blank(config('integrations.google_places.api_key'))) {
                $missing[] = 'GOOGLE_PLACES_API_KEY';
            }
            if (config('integrations.google_places.monthly_request_limit') <= 0) {
                $missing[] = 'GOOGLE_PLACES_MONTHLY_REQUEST_LIMIT (> 0)';
            }
        }

        return [
            'mode' => $mode,
            'missing' => $missing,
            'monthly_limit' => config('integrations.google_places.monthly_request_limit'),
            'used_this_month' => self::usedThisMonth(),
            'field_mask' => config('integrations.google_places.field_mask'),
        ];
    }

    public static function usedThisMonth(): int
    {
        return (int) SyncRun::where('provider', self::PROVIDER)->where('mode', 'live')
            ->where('started_at', '>=', now()->startOfMonth())->sum('request_count');
    }

    /** @return array{mode: string, results: array<int, array<string, ?string>>} */
    public function search(string $query): array
    {
        $mode = config('integrations.google_places.mode');
        if ($mode === 'off') {
            throw new IntegrationException('Google Places está desactivado (GOOGLE_PLACES_MODE=off). Use alta manual o importación CSV.');
        }
        if ($mode === 'demo') {
            return ['mode' => 'demo', 'results' => $this->demo($query)];
        }
        if ($missing = self::status()['missing']) {
            throw new IntegrationException('Falta configurar: '.implode(', ', $missing).'.');
        }
        if (self::usedThisMonth() >= config('integrations.google_places.monthly_request_limit')) {
            throw new IntegrationException('Se alcanzó el tope mensual de consultas a Google Places configurado.');
        }

        $results = $this->runs->run(self::PROVIDER, 'live', ['query' => $query], function (SyncRun $run) use ($query) {
            $run->request_count = 1;
            try {
                $response = Http::withHeaders([
                    'X-Goog-Api-Key' => config('integrations.google_places.api_key'),
                    'X-Goog-FieldMask' => config('integrations.google_places.field_mask'),
                ])->timeout(config('integrations.google_places.timeout'))
                    ->post(config('integrations.google_places.endpoint'), [
                        'textQuery' => $query,
                        'languageCode' => 'es',
                        'regionCode' => 'AR',
                        'pageSize' => config('integrations.google_places.page_size'),
                    ]);
            } catch (ConnectionException) {
                throw new IntegrationException('No se pudo conectar con Google Places (timeout).');
            }
            if ($response->status() === 403 || $response->status() === 401) {
                throw new IntegrationException("Google rechazó la clave o la facturación no está habilitada (HTTP {$response->status()}).");
            }
            if ($response->status() === 429) {
                throw new IntegrationException('Google Places: cuota excedida. Reintente más tarde.');
            }
            if (! $response->successful()) {
                throw new IntegrationException("Respuesta inesperada de Google Places (HTTP {$response->status()}).");
            }
            $places = collect($response->json('places', []))->map(fn ($p) => [
                'external_id' => $p['id'] ?? null,
                'business_name' => $p['displayName']['text'] ?? null,
                'address' => $p['formattedAddress'] ?? null,
                'phone' => $p['nationalPhoneNumber'] ?? null,
                'website' => $p['websiteUri'] ?? null,
                'source_url' => $p['googleMapsUri'] ?? null,
            ])->filter(fn ($p) => $p['external_id'] && $p['business_name'])->values()->all();
            $run->items_count = count($places);

            return $places;
        });

        return ['mode' => 'live', 'results' => $results];
    }

    private function demo(string $query): array
    {
        return collect(range(1, 3))->map(fn ($i) => [
            'external_id' => "demo-place-{$i}",
            'business_name' => "[DEMO] Comercio ficticio {$i} ({$query})",
            'address' => 'Dirección ficticia, Córdoba',
            'phone' => null,
            'website' => null,
            'source_url' => null,
        ])->all();
    }
}
