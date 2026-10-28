<?php

namespace App\Domain\Sales;

use App\Domain\Finance\CashService;
use App\Domain\Shared\Auditor;
use App\Domain\Shared\BusinessRuleException;
use App\Domain\Shared\Currency;
use App\Models\Payment;
use App\Models\Sale;
use App\Support\BusinessDate;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Cobros. Un cobro solo se registra por una persona autorizada con un medio de pago
 * confirmado (nunca a partir de un mensaje del cliente). Genera su entrada de caja.
 */
class PaymentService
{
    public function __construct(private CashService $cash, private Auditor $auditor) {}

    public function register(Sale $sale, array $data): Payment
    {
        return DB::transaction(function () use ($sale, $data) {
            $sale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if (! in_array($sale->status, ['confirmada', 'entregada'], true)) {
                throw new BusinessRuleException("No se pueden registrar cobros en una venta en estado {$sale->status}.");
            }
            if (! in_array($data['method'], config('business.payment_methods'), true)) {
                throw new BusinessRuleException('Medio de cobro inválido.', 'method');
            }
            $amount = Money::round($data['amount']);
            if (Money::cmp($amount, '0') <= 0) {
                throw new BusinessRuleException('El importe del cobro debe ser positivo.', 'amount');
            }
            $currency = Currency::assertAllowed($data['currency'] ?? $sale->currency);
            $rate = Currency::rateFor($currency, $data['exchange_rate'] ?? ($currency === $sale->currency ? (string) $sale->exchange_rate : null));

            // Si se cobra en otra moneda, el importe imputado a la venta lo informa el usuario
            // (sin conversiones implícitas con cotizaciones actuales).
            if ($currency === $sale->currency) {
                $applied = $amount;
            } elseif (! empty($data['applied_amount'])) {
                $applied = Money::round($data['applied_amount']);
            } else {
                throw new BusinessRuleException("El cobro es en {$currency} y la venta en {$sale->currency}: informe el importe imputado (applied_amount).", 'applied_amount');
            }

            $balance = Money::sub($sale->total, $sale->paid_amount);
            if (Money::cmp($applied, $balance) > 0) {
                throw new BusinessRuleException("El cobro ({$applied}) supera el saldo pendiente ({$balance}).", 'amount');
            }

            $paidAt = $data['paid_at'] ?? BusinessDate::today();
            $payment = Payment::create([
                'sale_id' => $sale->id,
                'customer_id' => $sale->customer_id,
                'created_by' => Auth::id(),
                'method' => $data['method'],
                'amount' => $amount,
                'currency' => $currency,
                'exchange_rate' => $rate,
                'amount_base' => Money::toBase($amount, $rate),
                'applied_amount' => $applied,
                'paid_at' => $paidAt,
                'reference' => $data['reference'] ?? null,
                'status' => 'confirmado',
                'notes' => $data['notes'] ?? null,
            ]);
            $this->cash->record('in', 'cobro', $amount, $currency, $rate, substr((string) $paidAt, 0, 10), $payment, $data['method'], "Cobro venta {$sale->number}");
            $this->refreshSaleBalance($sale);
            $this->auditor->record('cobro.registrado', $payment, null, $payment->only(['sale_id', 'amount', 'currency', 'applied_amount', 'method', 'paid_at']));

            return $payment;
        });
    }

    public function void(Payment $payment, string $reason): Payment
    {
        return DB::transaction(function () use ($payment, $reason) {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $sale = Sale::query()->whereKey($payment->sale_id)->lockForUpdate()->firstOrFail();
            if ($payment->status !== 'confirmado') {
                throw new BusinessRuleException('El cobro ya está anulado.');
            }
            $payment->update(['status' => 'anulado', 'voided_at' => now(), 'void_reason' => $reason]);
            $this->cash->reverseFor($payment, 'anulacion_cobro', BusinessDate::today(), $reason);
            $this->refreshSaleBalance($sale);
            $this->auditor->record('cobro.anulado', $payment, ['status' => 'confirmado'], ['status' => 'anulado'], $reason);

            return $payment;
        });
    }

    private function refreshSaleBalance(Sale $sale): void
    {
        $paid = Money::round((string) Payment::where('sale_id', $sale->id)->where('status', 'confirmado')->sum('applied_amount'));
        $status = match (true) {
            Money::isZero($paid) => 'pendiente',
            Money::cmp($paid, $sale->total) >= 0 => 'pagado',
            default => 'parcial',
        };
        $sale->update(['paid_amount' => $paid, 'payment_status' => $status]);
    }
}
