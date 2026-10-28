<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Exportaciones CSV (UTF-8 con BOM para abrir en Excel; separador coma; decimales con punto). */
class ExportController extends Controller
{
    public function export(Request $request, string $dataset): StreamedResponse
    {
        Gate::authorize('reports.view');
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $from = $request->query('from');
        $to = $request->query('to');

        [$headers, $rows] = match ($dataset) {
            'ventas' => [
                ['numero', 'fecha', 'cliente', 'estado', 'estado_cobro', 'moneda', 'cotizacion', 'total', 'total_base', 'costo_base', 'margen_base', 'cobrado', 'saldo', 'vencimiento'],
                Sale::with('customer:id,name')
                    ->when($from, fn ($q) => $q->where('sale_date', '>=', $from))
                    ->when($to, fn ($q) => $q->where('sale_date', '<=', $to))
                    ->orderBy('sale_date')->orderBy('id')->lazy()
                    ->map(fn (Sale $s) => [
                        $s->number, $s->sale_date?->toDateString(), $s->customer->name, $s->status, $s->payment_status,
                        $s->currency, $s->exchange_rate, $s->total, $s->total_base, $s->cost_total_base,
                        Money::sub($s->total_base, $s->cost_total_base), $s->paid_amount, $s->balance_due, $s->due_date?->toDateString(),
                    ]),
            ],
            'cobros' => [
                ['id', 'fecha', 'venta', 'medio', 'moneda', 'importe', 'cotizacion', 'importe_base', 'imputado', 'estado', 'referencia'],
                Payment::with('sale:id,number')
                    ->when($from, fn ($q) => $q->where('paid_at', '>=', $from))
                    ->when($to, fn ($q) => $q->where('paid_at', '<=', $to.' 23:59:59'))
                    ->orderBy('paid_at')->lazy()
                    ->map(fn (Payment $p) => [$p->id, $p->paid_at?->toDateString(), $p->sale->number, $p->method, $p->currency, $p->amount, $p->exchange_rate, $p->amount_base, $p->applied_amount, $p->status, $p->reference]),
            ],
            'gastos' => [
                ['id', 'fecha_devengo', 'fecha_pago', 'categoria', 'proveedor', 'descripcion', 'moneda', 'importe', 'cotizacion', 'importe_base', 'estado', 'origen'],
                Expense::with('category:id,name')
                    ->when($from, fn ($q) => $q->where('issued_at', '>=', $from))
                    ->when($to, fn ($q) => $q->where('issued_at', '<=', $to))
                    ->orderBy('issued_at')->lazy()
                    ->map(fn (Expense $e) => [$e->id, $e->issued_at?->toDateString(), $e->paid_at?->toDateString(), $e->category->name, $e->vendor, $e->description, $e->currency, $e->amount, $e->exchange_rate, $e->amount_base, $e->status, $e->source]),
            ],
            'caja' => [
                ['id', 'fecha', 'sentido', 'tipo', 'moneda', 'importe', 'cotizacion', 'importe_base', 'medio', 'descripcion'],
                CashMovement::query()
                    ->when($from, fn ($q) => $q->where('occurred_at', '>=', $from))
                    ->when($to, fn ($q) => $q->where('occurred_at', '<=', $to))
                    ->orderBy('occurred_at')->orderBy('id')->lazy()
                    ->map(fn (CashMovement $c) => [$c->id, $c->occurred_at?->toDateString(), $c->direction, $c->type, $c->currency, $c->amount, $c->exchange_rate, $c->amount_base, $c->method, $c->description]),
            ],
            'stock' => [
                ['sku', 'producto', 'variante', 'en_poder', 'reservado', 'retenido', 'disponible', 'costo_promedio_base'],
                ProductVariant::with('product:id,name')->orderBy('sku')->lazy()
                    ->map(fn (ProductVariant $v) => [$v->sku, $v->product->name, $v->name, $v->stock_on_hand, $v->stock_reserved, $v->stock_held, $v->stock_available, $v->avg_cost_base]),
            ],
            default => abort(404, 'Exportación desconocida.'),
        };

        $filename = $dataset.'_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, escape: '');
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => $this->sanitize($v), $row), escape: '');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Evita inyección de fórmulas al abrir el CSV en planillas. */
    private function sanitize(mixed $value): string
    {
        $value = (string) ($value ?? '');
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) && ! is_numeric($value)) {
            return "'".$value;
        }

        return $value;
    }
}
