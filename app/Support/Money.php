<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Aritmética decimal exacta para importes. Nunca usar float para dinero.
 *
 * Convención de escalas (ver ADR 0001):
 *  - Importes monetarios: 2 decimales, redondeo HALF_UP.
 *  - Costos unitarios: 4 decimales.
 *  - Tipos de cambio: 6 decimales.
 * Los valores se intercambian como string para no perder precisión.
 */
final class Money
{
    public const SCALE = 2;

    public const UNIT_SCALE = 4;

    public const RATE_SCALE = 6;

    public static function of(string|int|float|BigDecimal|null $value): BigDecimal
    {
        if ($value === null || $value === '') {
            return BigDecimal::zero();
        }
        if (is_float($value)) {
            throw new InvalidArgumentException('No se aceptan floats para importes.');
        }

        return BigDecimal::of($value);
    }

    public static function add(string|int|BigDecimal|null ...$values): string
    {
        $sum = BigDecimal::zero();
        foreach ($values as $v) {
            $sum = $sum->plus(self::of($v));
        }

        return self::round($sum);
    }

    public static function sub(string|int|BigDecimal|null $a, string|int|BigDecimal|null $b): string
    {
        return self::round(self::of($a)->minus(self::of($b)));
    }

    public static function mul(string|int|BigDecimal|null $a, string|int|BigDecimal|null $b, int $scale = self::SCALE): string
    {
        return self::round(self::of($a)->multipliedBy(self::of($b)), $scale);
    }

    public static function div(string|int|BigDecimal|null $a, string|int|BigDecimal|null $b, int $scale = self::SCALE): string
    {
        return (string) self::of($a)->dividedBy(self::of($b), $scale, RoundingMode::HALF_UP);
    }

    public static function round(string|int|BigDecimal|null $value, int $scale = self::SCALE): string
    {
        return (string) self::of($value)->toScale($scale, RoundingMode::HALF_UP);
    }

    public static function cmp(string|int|BigDecimal|null $a, string|int|BigDecimal|null $b): int
    {
        return self::of($a)->compareTo(self::of($b));
    }

    public static function isZero(string|int|BigDecimal|null $a): bool
    {
        return self::of($a)->isZero();
    }

    public static function isNegative(string|int|BigDecimal|null $a): bool
    {
        return self::of($a)->isNegative();
    }

    /** Convierte un importe en moneda original a moneda base con la cotización guardada. */
    public static function toBase(string|int|BigDecimal|null $amount, string|int|BigDecimal|null $rate): string
    {
        return self::mul($amount, $rate);
    }

    /**
     * Reparte $total entre partes proporcionales a $weights, garantizando que la
     * suma de las partes sea exactamente $total (método del mayor resto).
     *
     * @param  array<int|string, string>  $weights
     * @return array<int|string, string>
     */
    public static function allocate(string $total, array $weights, int $scale = self::SCALE): array
    {
        if ($weights === []) {
            return [];
        }
        $totalDec = self::of($total)->toScale($scale, RoundingMode::HALF_UP);
        $sumWeights = BigDecimal::zero();
        foreach ($weights as $w) {
            if (self::of($w)->isNegative()) {
                throw new InvalidArgumentException('Los pesos de distribución no pueden ser negativos.');
            }
            $sumWeights = $sumWeights->plus(self::of($w));
        }
        if ($sumWeights->isZero()) {
            // Sin base proporcional: reparto equitativo.
            $weights = array_map(fn () => '1', $weights);
            $sumWeights = BigDecimal::of(count($weights));
        }

        $unit = BigDecimal::one()->withPointMovedLeft($scale);
        $parts = [];
        $remainders = [];
        $assigned = BigDecimal::zero();
        foreach ($weights as $key => $w) {
            $exact = $totalDec->multipliedBy(self::of($w))->dividedBy($sumWeights, $scale + 10, RoundingMode::DOWN);
            $floor = $exact->toScale($scale, RoundingMode::DOWN);
            $parts[$key] = $floor;
            $remainders[$key] = $exact->minus($floor);
            $assigned = $assigned->plus($floor);
        }
        $left = $totalDec->minus($assigned);
        // Ordenar por mayor resto, desempate estable por orden original.
        $keys = array_keys($remainders);
        $order = array_flip($keys);
        usort($keys, function ($a, $b) use ($remainders, $order) {
            $c = $remainders[$b]->compareTo($remainders[$a]);

            return $c !== 0 ? $c : $order[$a] <=> $order[$b];
        });
        $i = 0;
        while ($left->isPositive()) {
            $k = $keys[$i % count($keys)];
            $parts[$k] = $parts[$k]->plus($unit);
            $left = $left->minus($unit);
            $i++;
        }

        return array_map(fn (BigDecimal $p) => (string) $p, $parts);
    }
}
