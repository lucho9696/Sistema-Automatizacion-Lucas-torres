<?php

namespace App\Domain\Radar;

use App\Domain\Integrations\IntegrationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * API oficial de tendencias de Mercado Libre: GET /trends/{site}[/{category}].
 * Devuelve términos de búsqueda con su URL; no entrega volúmenes de venta ni de búsqueda,
 * así que el sistema no los inventa.
 */
class MercadoLibreTrendsProvider implements TrendProvider
{
    public function __construct(private MercadoLibreAuth $auth) {}

    public function name(): string
    {
        return 'mercadolibre_trends';
    }

    public function mode(): string
    {
        return 'live';
    }

    public function fetch(string $site, ?string $categoryId = null): array
    {
        if (! preg_match('/^[A-Z]{3}$/', $site) || ($categoryId !== null && ! preg_match('/^[A-Z]{3}\d+$/', $categoryId))) {
            throw new IntegrationException('Sitio o categoría de Mercado Libre inválidos.');
        }
        $url = config('integrations.mercadolibre.base_url')."/trends/{$site}".($categoryId ? "/{$categoryId}" : '');

        try {
            $response = Http::withToken($this->auth->accessToken())
                ->acceptJson()
                ->timeout(config('integrations.mercadolibre.timeout'))
                ->retry(3, fn (int $attempt) => 500 * 2 ** ($attempt - 1), function ($e) {
                    return $e instanceof ConnectionException
                        || ($e instanceof RequestException && ($e->response->status() === 429 || $e->response->serverError()));
                }, throw: false)
                ->get($url);
        } catch (ConnectionException) {
            throw new IntegrationException('No se pudo conectar con Mercado Libre (timeout).');
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new IntegrationException("Mercado Libre denegó el acceso a tendencias (HTTP {$response->status()}): verifique permisos de la aplicación.");
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw new IntegrationException("Respuesta inesperada de Mercado Libre (HTTP {$response->status()}).");
        }

        $groups = config('integrations.mercadolibre.groups');
        $items = [];
        foreach (array_values($response->json()) as $i => $row) {
            if (! is_array($row) || empty($row['keyword'])) {
                continue;
            }
            $position = $i + 1;
            $items[] = [
                'keyword' => mb_substr((string) $row['keyword'], 0, 250),
                'url' => isset($row['url']) && filter_var($row['url'], FILTER_VALIDATE_URL) ? $row['url'] : null,
                'position' => $position,
                'trend_type' => self::groupFor($position, $groups),
            ];
        }

        return $items;
    }

    public static function groupFor(int $position, array $groups): string
    {
        foreach ($groups as [$from, $to, $type]) {
            if ($position >= $from && $position <= $to) {
                return $type;
            }
        }

        return 'otros';
    }
}
