<?php

namespace App\Domain\Reports;

use App\Domain\Finance\CashService;
use App\Domain\Shared\Currency;
use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\ProductUnit;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Support\BusinessDate;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores financieros del período, en moneda base y a cotización histórica de
 * cada operación. Cada métrica se calcula por separado (ventas ≠ cobros ≠ ganancia ≠ caja).
 *
 * Fechas: ventas por sale_date; anulaciones/devoluciones por la fecha del evento
 * (para que los períodos cerrados no cambien); cobros y caja por fecha del movimiento;
 * gastos por fecha de devengo (issued_at).
 */
class DashboardService
{
    public function __construct(private CashService $cash) {}

    public function summary(string $from, string $to): array
    {
        $tz = config('business.display_timezone');
        $fromUtc = Carbon::parse($from, $tz)->startOfDay()->utc();
        $toUtc = Carbon::parse($to, $tz)->endOfDay()->utc();
        $today = BusinessDate::today();

        $confirmedStatuses = ['confirmada', 'entregada', 'devuelta', 'cancelada'];
        $gross = Sale::query()
            ->whereIn('status', $confirmedStatuses)->whereNotNull('confirmed_at')
            ->whereBetween('sale_date', [$from, $to])
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total_base),0) AS total, COALESCE(SUM(cost_total_base),0) AS cost')
            ->first();
        $cancelled = Sale::query()
            ->where('status', 'cancelada')->whereNotNull('confirmed_at')
            ->whereBetween('cancelled_at', [$fromUtc, $toUtc])
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total_base),0) AS total, COALESCE(SUM(cost_total_base),0) AS cost')
            ->first();
        $returned = Sale::query()
            ->where('status', 'devuelta')
            ->whereBetween('returned_at', [$fromUtc, $toUtc])
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total_base),0) AS total, COALESCE(SUM(cost_total_base),0) AS cost')
            ->first();

        $netSales = Money::sub(Money::sub($gross->total, $cancelled->total), $returned->total);
        $cogs = Money::sub(Money::sub($gross->cost, $cancelled->cost), $returned->cost);
        $grossProfit = Money::sub($netSales, $cogs);

        $collections = CashMovement::query()
            ->whereIn('type', ['cobro', 'anulacion_cobro'])
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'cobro' THEN amount_base ELSE 0 END),0) AS cobros,
                         COALESCE(SUM(CASE WHEN type = 'anulacion_cobro' THEN amount_base ELSE 0 END),0) AS anulaciones")
            ->first();

        $expensesByCategory = Expense::query()
            ->join('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->where('expenses.status', '!=', 'anulado')
            ->where('expense_categories.is_operating', true)
            ->whereBetween('issued_at', [$from, $to])
            ->groupBy('expense_categories.slug', 'expense_categories.name')
            ->orderBy('expense_categories.name')
            ->selectRaw('expense_categories.slug, expense_categories.name, SUM(amount_base) AS total, COUNT(*) AS n')
            ->get()
            ->map(fn ($r) => ['slug' => $r->slug, 'name' => $r->name, 'total' => Money::round($r->total), 'count' => (int) $r->n]);
        $operatingExpenses = Money::add('0', ...$expensesByCategory->pluck('total')->all());
        $unpaidExpenses = Expense::query()->where('status', 'devengado')
            ->selectRaw('COALESCE(SUM(amount_base),0) AS total, COUNT(*) AS n')->first();

        $receivables = $this->receivables($today);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'base_currency' => Currency::base(),
            'coverage_note' => 'Calculado con movimientos registrados hasta '.Carbon::now($tz)->format('d/m/Y H:i')." ({$tz}). No es ganancia neta: no incluye impuestos, comisiones ni gastos no cargados.",
            'sales' => [
                'confirmed_gross' => Money::round($gross->total),
                'confirmed_count' => (int) $gross->n,
                'cancelled' => Money::round($cancelled->total),
                'cancelled_count' => (int) $cancelled->n,
                'returned' => Money::round($returned->total),
                'returned_count' => (int) $returned->n,
                'net' => $netSales,
            ],
            'collections' => [
                'collected' => Money::round($collections->cobros),
                'voided' => Money::round($collections->anulaciones),
                'net' => Money::sub($collections->cobros, $collections->anulaciones),
            ],
            'cogs' => $cogs,
            'gross_profit' => $grossProfit,
            'operating_expenses' => [
                'total' => $operatingExpenses,
                'by_category' => $expensesByCategory->values(),
                'scope' => 'Gastos operativos no anulados con fecha de devengo en el período.',
            ],
            'estimated_operating_result' => Money::sub($grossProfit, $operatingExpenses),
            'receivables' => $receivables,
            'unpaid_expenses_base' => Money::round($unpaidExpenses->total),
            'cash' => $this->cash->balances($from, $to),
            'inventory' => $this->inventory(),
            'integrations' => [
                'meta_ads' => [
                    'status' => 'no_conectado',
                    'note' => 'Integración prevista en Hito 4. Puede cargar el gasto de publicidad como gasto manual (categoría Publicidad).',
                    'imported_spend_base' => Money::round((string) Expense::where('source', 'meta_ads')->where('status', '!=', 'anulado')->whereBetween('issued_at', [$from, $to])->sum('amount_base')),
                ],
            ],
        ];
    }

    /** Cuentas por cobrar a la fecha (saldo en moneda de la venta y equivalente a la cotización de la venta). */
    public function receivables(string $today): array
    {
        $rows = Sale::query()
            ->whereIn('status', ['confirmada', 'entregada'])
            ->whereColumn('paid_amount', '<', 'total')
            ->selectRaw('currency,
                SUM(total - paid_amount) AS pending,
                SUM((total - paid_amount) * exchange_rate) AS pending_base,
                SUM(CASE WHEN due_date < ? THEN total - paid_amount ELSE 0 END) AS overdue,
                SUM(CASE WHEN due_date < ? THEN (total - paid_amount) * exchange_rate ELSE 0 END) AS overdue_base,
                COUNT(*) AS n', [$today, $today])
            ->groupBy('currency')
            ->get();

        $byCurrency = [];
        $totalBase = '0';
        $overdueBase = '0';
        foreach ($rows as $r) {
            $byCurrency[$r->currency] = [
                'pending' => Money::round($r->pending),
                'overdue' => Money::round($r->overdue),
                'count' => (int) $r->n,
            ];
            $totalBase = Money::add($totalBase, $r->pending_base);
            $overdueBase = Money::add($overdueBase, $r->overdue_base);
        }

        return [
            'by_currency' => $byCurrency,
            'pending_base' => $totalBase,
            'overdue_base' => $overdueBase,
            'note' => 'Equivalente en moneda base a la cotización de cada venta.',
        ];
    }

    public function inventory(): array
    {
        $totals = ProductVariant::query()->selectRaw(
            'COALESCE(SUM(stock_on_hand),0) AS on_hand, COALESCE(SUM(stock_reserved),0) AS reserved, COALESCE(SUM(stock_held),0) AS held'
        )->first();

        // Serializados: costo real de cada unidad en poder del negocio.
        $serializedValue = ProductUnit::query()
            ->whereIn('status', ['disponible', 'reservado', 'en_revision', 'devuelto', 'en_garantia'])
            ->sum('unit_cost_base');
        // No serializados: saldo x costo promedio ponderado.
        $bulkValue = DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('products.is_serialized', false)
            ->where('product_variants.stock_on_hand', '>', 0)
            ->selectRaw('COALESCE(SUM(product_variants.stock_on_hand * product_variants.avg_cost_base),0) AS v')
            ->value('v');

        return [
            'on_hand' => (int) $totals->on_hand,
            'reserved' => (int) $totals->reserved,
            'held' => (int) $totals->held,
            'available' => (int) $totals->on_hand - (int) $totals->reserved - (int) $totals->held,
            'value_at_cost_base' => Money::add((string) $serializedValue, (string) $bulkValue),
        ];
    }
}
