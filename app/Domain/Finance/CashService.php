<?php

namespace App\Domain\Finance;

use App\Domain\Shared\Auditor;
use App\Domain\Shared\BusinessRuleException;
use App\Domain\Shared\Currency;
use App\Models\CashMovement;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Caja = saldo inicial + entradas - salidas (por moneda). Nunca se calcula como
 * ventas - gastos devengados. Los movimientos no se borran: se revierten.
 */
class CashService
{
    public const MANUAL_TYPES = ['apertura', 'aporte', 'retiro', 'pago_proveedor', 'ajuste_ingreso', 'ajuste_egreso'];

    public function __construct(private Auditor $auditor) {}

    public function record(string $direction, string $type, string $amount, string $currency, string $rate, string $date, ?Model $reference = null, ?string $method = null, ?string $description = null, ?CashMovement $reverses = null): CashMovement
    {
        if (! in_array($direction, ['in', 'out'], true)) {
            throw new \InvalidArgumentException('direction inválida');
        }
        if (Money::cmp($amount, '0') <= 0) {
            throw new BusinessRuleException('El importe del movimiento de caja debe ser positivo.', 'amount');
        }

        return CashMovement::create([
            'occurred_at' => $date,
            'direction' => $direction,
            'type' => $type,
            'amount' => Money::round($amount),
            'currency' => $currency,
            'exchange_rate' => $rate,
            'amount_base' => Money::toBase($amount, $rate),
            'method' => $method,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'reverses_id' => $reverses?->id,
            'user_id' => Auth::id(),
            'description' => $description,
        ]);
    }

    /** Movimiento manual (apertura, aporte, retiro, pago a proveedor, ajustes). */
    public function manual(array $data): CashMovement
    {
        return DB::transaction(function () use ($data) {
            $type = $data['type'];
            if (! in_array($type, self::MANUAL_TYPES, true)) {
                throw new BusinessRuleException("Tipo de movimiento manual inválido: {$type}.", 'type');
            }
            $direction = in_array($type, ['apertura', 'aporte', 'ajuste_ingreso'], true) ? 'in' : 'out';
            $currency = Currency::assertAllowed($data['currency']);
            $rate = Currency::rateFor($currency, $data['exchange_rate'] ?? null);
            $movement = $this->record($direction, $type, $data['amount'], $currency, $rate, $data['occurred_at'], null, $data['method'] ?? null, $data['description'] ?? null);
            $this->auditor->record('caja.movimiento_manual', $movement, null, $movement->toArray(), $data['description'] ?? null);

            return $movement;
        });
    }

    /** Revierte todos los movimientos vigentes asociados a una referencia. */
    public function reverseFor(Model $reference, string $type, string $date, string $reason): void
    {
        $movements = CashMovement::query()
            ->where('reference_type', $reference->getMorphClass())
            ->where('reference_id', $reference->getKey())
            ->whereNull('reverses_id')
            ->whereNotIn('id', CashMovement::query()->whereNotNull('reverses_id')->select('reverses_id'))
            ->get();
        foreach ($movements as $m) {
            $this->record(
                $m->direction === 'in' ? 'out' : 'in', $type, (string) $m->amount, $m->currency, (string) $m->exchange_rate,
                $date, $reference, $m->method, "Reversa: {$reason}", $m
            );
        }
    }

    /**
     * Saldos por moneda en un rango de fechas (inclusive).
     *
     * @return array<string, array{opening:string, in:string, out:string, closing:string}>
     */
    public function balances(string $from, string $to): array
    {
        $rows = CashMovement::query()
            ->selectRaw('currency,
                SUM(CASE WHEN occurred_at < ? AND direction = \'in\' THEN amount ELSE 0 END) -
                SUM(CASE WHEN occurred_at < ? AND direction = \'out\' THEN amount ELSE 0 END) AS opening,
                SUM(CASE WHEN occurred_at BETWEEN ? AND ? AND direction = \'in\' THEN amount ELSE 0 END) AS total_in,
                SUM(CASE WHEN occurred_at BETWEEN ? AND ? AND direction = \'out\' THEN amount ELSE 0 END) AS total_out',
                [$from, $from, $from, $to, $from, $to])
            ->where('occurred_at', '<=', $to)
            ->groupBy('currency')
            ->get();

        $result = [];
        foreach ($rows as $r) {
            $result[$r->currency] = [
                'opening' => Money::round($r->opening),
                'in' => Money::round($r->total_in),
                'out' => Money::round($r->total_out),
                'closing' => Money::sub(Money::add($r->opening, $r->total_in), $r->total_out),
            ];
        }
        ksort($result);

        return $result;
    }
}
