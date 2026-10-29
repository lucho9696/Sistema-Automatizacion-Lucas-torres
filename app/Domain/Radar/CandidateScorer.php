<?php

namespace App\Domain\Radar;

use App\Models\TrendCandidate;
use App\Support\Money;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;

/**
 * Puntaje 0-100 para ORDENAR candidatos (especificación §7.4). No es una predicción.
 *
 *  demanda 30% · aceleración 20% · margen 25% · competencia 10% · proveedores 10% · evidencia 5%
 *
 * Si falta una señal se excluye y se reparte su peso entre las presentes (renormalización),
 * se informa como faltante y baja la confianza. Con menos de 2 señales no se muestra puntaje.
 */
class CandidateScorer
{
    public const WEIGHTS = ['demand' => 30, 'acceleration' => 20, 'margin' => 25, 'competition' => 10, 'suppliers' => 10, 'evidence' => 5];

    public const LABELS = [
        'demand' => 'Demanda observable',
        'acceleration' => 'Aceleración reciente',
        'margin' => 'Margen potencial',
        'competition' => 'Competencia',
        'suppliers' => 'Proveedores investigados',
        'evidence' => 'Calidad de la evidencia',
    ];

    public function score(TrendCandidate $c, ?Carbon $now = null): array
    {
        $now ??= now();
        $snapshots = $c->snapshots()->where('fetched_at', '>=', $now->copy()->subDays(60))->get();
        $recent = $snapshots->where('fetched_at', '>=', $now->copy()->subDays(config('integrations.trends.demand_window_days')));
        $factors = [
            'demand' => $this->demand($recent),
            'acceleration' => $this->acceleration($snapshots),
            'margin' => $this->margin($c),
            'competition' => $this->competition($c),
            'suppliers' => $this->suppliers($c),
        ];
        $present = array_filter($factors, fn ($f) => $f['available']);
        $signals = count($present);
        $demoOnly = $snapshots->isNotEmpty() && $snapshots->every(fn ($s) => $s->is_demo);
        $factors['evidence'] = [
            'available' => true,
            'value' => $demoOnly ? 0.0 : round($signals / 5, 2),
            'note' => $demoOnly ? 'Solo hay datos de demostración.' : "{$signals} de 5 señales con datos.",
        ];

        $usedWeight = 0;
        $sum = 0.0;
        foreach ($factors as $key => $f) {
            if ($f['available']) {
                $usedWeight += self::WEIGHTS[$key];
                $sum += self::WEIGHTS[$key] * $f['value'];
            }
        }
        $coverage = (array_sum(array_intersect_key(self::WEIGHTS, $present))) / 95;
        $score = $signals >= 2 ? (int) round($sum / $usedWeight * 100) : null;
        $confidence = match (true) {
            $signals < 2 => 'insuficiente',
            $demoOnly => 'demo',
            $coverage >= 0.75 => 'alta',
            $coverage >= 0.45 => 'media',
            default => 'baja',
        };

        $breakdown = [];
        foreach (self::WEIGHTS as $key => $weight) {
            $breakdown[] = ['key' => $key, 'label' => self::LABELS[$key], 'weight' => $weight] + $factors[$key];
        }

        return [
            'score' => $score,
            'confidence' => $confidence,
            'signals_count' => $signals,
            'breakdown' => [
                'factors' => $breakdown,
                'missing' => array_values(array_map(fn ($k) => self::LABELS[$k], array_keys(array_diff_key($factors, $present, ['evidence' => 1])))),
                'coverage' => round($coverage, 2),
                'method' => 'Suma ponderada de las señales disponibles, renormalizada por el peso presente. Ayuda para ordenar, no predicción de ventas.',
            ],
        ];
    }

    public function apply(TrendCandidate $c): TrendCandidate
    {
        $r = $this->score($c);
        $c->update([
            'score' => $r['score'],
            'confidence' => $r['confidence'],
            'signals_count' => $r['signals_count'],
            'score_breakdown' => $r['breakdown'],
            'scored_at' => now(),
        ]);

        return $c;
    }

    private function demand($recent): array
    {
        if ($recent->isEmpty()) {
            return $this->missing('Sin apariciones en tendencias en los últimos '.config('integrations.trends.demand_window_days').' días.');
        }
        $best = $recent->map(fn ($s) => $s->position ? max(0, 1 - ($s->position - 1) / 100) : 0.5)->max();
        $top = $recent->whereNotNull('position')->sortBy('position')->first();

        return $this->value($best, $top
            ? "Mejor posición {$top->position} ({$top->trend_type}) en {$top->source}, ".$top->fetched_at->toDateString().'.'
            : 'Señal cargada manualmente sin posición.');
    }

    private function acceleration($snapshots): array
    {
        $byDay = $snapshots->whereNotNull('position')->groupBy(fn ($s) => $s->fetched_at->toDateString())->sortKeys();
        if ($byDay->count() >= 2) {
            $first = $byDay->first()->min('position');
            $last = $byDay->last()->min('position');
            $delta = $first - $last;

            return $this->value(max(0, min(1, 0.5 + $delta / 40)), "Posición {$first} → {$last} entre ".$byDay->keys()->first().' y '.$byDay->keys()->last().'.');
        }
        $latest = $snapshots->sortByDesc('fetched_at')->first();
        if ($latest && $latest->trend_type === 'crecimiento') {
            return $this->value(0.75, 'Una sola medición; la fuente lo agrupa entre las búsquedas de mayor crecimiento.');
        }

        return $this->missing('Se necesita más de una medición para comparar períodos.');
    }

    private function margin(TrendCandidate $c): array
    {
        if ($c->est_buy_price === null || $c->est_sell_price === null || ! $c->est_buy_currency || ! $c->est_sell_currency || $c->est_landed_extra_pct === null) {
            return $this->missing('Faltan precio de compra, precio de venta comparable o % de costos de importación.');
        }
        $rate = $c->est_buy_currency === $c->est_sell_currency ? '1' : $c->est_exchange_rate;
        if (! $rate || Money::cmp($rate, '0') <= 0) {
            return $this->missing('Monedas distintas sin cotización informada.');
        }
        if (Money::cmp($c->est_sell_price, '0') <= 0) {
            return $this->missing('Precio de venta inválido.');
        }
        $landed = Money::of($c->est_buy_price)
            ->multipliedBy(Money::of('100')->plus(Money::of($c->est_landed_extra_pct)))->dividedBy('100', 6, RoundingMode::HALF_UP)
            ->multipliedBy(Money::of($rate));
        $margin = Money::of($c->est_sell_price)->minus($landed)->dividedBy(Money::of($c->est_sell_price), 6, RoundingMode::HALF_UP)->toFloat();

        return $this->value(max(0, min(1, $margin / 0.4)), sprintf('Margen estimado %.1f%% (costo puesto %s %s vs venta %s %s). 40%% o más = puntaje máximo.',
            $margin * 100, $c->est_sell_currency, Money::round($landed), $c->est_sell_currency, $c->est_sell_price));
    }

    private function competition(TrendCandidate $c): array
    {
        return match ($c->competition_level) {
            'baja' => $this->value(1.0, 'Competencia observada baja (evaluación manual).'),
            'media' => $this->value(0.5, 'Competencia observada media (evaluación manual).'),
            'alta' => $this->value(0.15, 'Competencia observada alta (evaluación manual).'),
            default => $this->missing('Competencia no evaluada.'),
        };
    }

    private function suppliers(TrendCandidate $c): array
    {
        $rows = $c->suppliers()->get();
        if ($rows->isEmpty()) {
            return $this->missing('Sin proveedores investigados.');
        }
        $verified = $rows->where('verified', true)->count();
        $value = min(1, $rows->count() / 3) * ($verified > 0 ? 1 : 0.8);

        return $this->value($value, "{$rows->count()} proveedor(es) relevado(s), {$verified} verificado(s) por una persona.");
    }

    private function value(float $v, string $note): array
    {
        return ['available' => true, 'value' => round($v, 2), 'note' => $note];
    }

    private function missing(string $note): array
    {
        return ['available' => false, 'value' => null, 'note' => $note];
    }
}
