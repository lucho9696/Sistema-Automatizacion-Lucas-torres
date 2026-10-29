<?php

namespace App\Domain\Radar;

/**
 * MODO DEMOSTRACIÓN. Términos ficticios, marcados con el prefijo [DEMO] y is_demo=true.
 * No representan tendencias reales de Mercado Libre.
 */
class DemoTrendProvider implements TrendProvider
{
    public function name(): string
    {
        return 'mercadolibre_trends';
    }

    public function mode(): string
    {
        return 'demo';
    }

    public function fetch(string $site, ?string $categoryId = null): array
    {
        $terms = [
            'funda magsafe iphone 16', 'cargador 20w usb c', 'iphone 15 128gb', 'protector de pantalla iphone',
            'auriculares bluetooth', 'smartwatch', 'cable usb c lightning', 'power bank 10000',
        ];
        $groups = config('integrations.mercadolibre.groups');

        return array_map(function ($term, $i) use ($groups) {
            $position = [1, 3, 8, 12, 18, 25, 33, 41][$i];

            return [
                'keyword' => '[DEMO] '.$term,
                'url' => null,
                'position' => $position,
                'trend_type' => MercadoLibreTrendsProvider::groupFor($position, $groups),
            ];
        }, $terms, array_keys($terms));
    }
}
