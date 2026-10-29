<?php

namespace App\Domain\Finance;

use App\Domain\Shared\Auditor;
use App\Domain\Shared\BusinessRuleException;
use App\Domain\Shared\Currency;
use App\Models\Expense;
use App\Support\BusinessDate;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ExpenseService
{
    public function __construct(private CashService $cash, private Auditor $auditor) {}

    public function create(array $data, int $userId): Expense
    {
        return DB::transaction(function () use ($data, $userId) {
            $currency = Currency::assertAllowed($data['currency']);
            $rate = Currency::rateFor($currency, $data['exchange_rate'] ?? null);
            if (Money::cmp($data['amount'], '0') <= 0) {
                throw new BusinessRuleException('El importe del gasto debe ser positivo.', 'amount');
            }
            $externalKey = $data['external_key'] ?? null;
            if ($externalKey && Expense::where('external_key', $externalKey)->exists()) {
                throw new BusinessRuleException("Ya existe un gasto importado con la clave {$externalKey}.", 'external_key');
            }
            try {
                $expense = Expense::create([
                    'expense_category_id' => $data['expense_category_id'],
                    'created_by' => $userId,
                    'vendor' => $data['vendor'] ?? null,
                    'description' => $data['description'],
                    'amount' => Money::round($data['amount']),
                    'currency' => $currency,
                    'exchange_rate' => $rate,
                    'amount_base' => Money::toBase($data['amount'], $rate),
                    'issued_at' => $data['issued_at'],
                    'status' => 'devengado',
                    'source' => $data['source'] ?? 'manual',
                    'external_key' => $externalKey,
                    'notes' => $data['notes'] ?? null,
                ]);
            } catch (QueryException $e) {
                // Carrera contra el índice único de external_key.
                throw new BusinessRuleException("Ya existe un gasto importado con la clave {$externalKey}.", 'external_key');
            }
            $this->auditor->record('gasto.creado', $expense, null, $expense->toArray());

            if (! empty($data['paid_at'])) {
                $this->markPaid($expense, $data['paid_at'], $data['payment_method'] ?? null);
            }

            return $expense->fresh('category');
        });
    }

    public function markPaid(Expense $expense, string $paidAt, ?string $method): Expense
    {
        return DB::transaction(function () use ($expense, $paidAt, $method) {
            $expense = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();
            if ($expense->status !== 'devengado') {
                throw new BusinessRuleException('Solo un gasto devengado puede marcarse como pagado.');
            }
            $expense->update(['status' => 'pagado', 'paid_at' => $paidAt, 'payment_method' => $method]);
            $this->cash->record('out', 'gasto', (string) $expense->amount, $expense->currency, (string) $expense->exchange_rate, $paidAt, $expense, $method, $expense->description);
            $this->auditor->record('gasto.pagado', $expense, null, ['paid_at' => $paidAt, 'payment_method' => $method]);

            return $expense;
        });
    }

    public function void(Expense $expense, string $reason): Expense
    {
        return DB::transaction(function () use ($expense, $reason) {
            $expense = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();
            if ($expense->status === 'anulado') {
                throw new BusinessRuleException('El gasto ya está anulado.');
            }
            $old = $expense->only(['status']);
            if ($expense->status === 'pagado') {
                $this->cash->reverseFor($expense, 'anulacion_gasto', BusinessDate::today(), $reason);
            }
            $expense->update(['status' => 'anulado', 'void_reason' => $reason]);
            $this->auditor->record('gasto.anulado', $expense, $old, ['status' => 'anulado'], $reason);

            return $expense;
        });
    }
}
