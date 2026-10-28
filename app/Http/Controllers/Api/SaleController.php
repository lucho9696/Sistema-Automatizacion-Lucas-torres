<?php

namespace App\Http\Controllers\Api;

use App\Domain\Sales\PaymentService;
use App\Domain\Sales\SaleService;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaleController extends Controller
{
    public function __construct(private SaleService $sales, private PaymentService $payments) {}

    public function index(Request $request)
    {
        Gate::authorize('sales.create');

        return Sale::with('customer:id,name,business_name')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('payment_status'), fn ($q, $s) => $q->where('payment_status', $s))
            ->when($request->query('customer_id'), fn ($q, $c) => $q->where('customer_id', $c))
            ->when($request->query('from'), fn ($q, $d) => $q->where('sale_date', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->where('sale_date', '<=', $d))
            ->orderByDesc('sale_date')->orderByDesc('id')
            ->paginate(50);
    }

    public function show(Sale $sale)
    {
        Gate::authorize('sales.create');
        $sale->load('customer', 'seller:id,name', 'items.itemUnits.unit', 'payments');
        if (Gate::denies('units.view-imei')) {
            $sale->items->each(fn ($i) => $i->itemUnits->each(fn ($iu) => $iu->unit?->makeHidden(['imei', 'imei2', 'serial_number'])));
        }

        return $sale;
    }

    /** Crea la venta (borrador/cotización). Con confirm=true la confirma en la misma transacción. */
    public function store(Request $request)
    {
        Gate::authorize('sales.create');
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'currency' => ['required', Rule::in(config('business.currencies'))],
            'exchange_rate' => ['nullable', 'decimal:0,6', 'gt:0'],
            'exchange_rate_date' => ['nullable', 'date'],
            'exchange_rate_source' => ['nullable', 'string', 'max:120'],
            'sale_date' => ['nullable', 'date'],
            'payment_terms' => ['nullable', Rule::in(['contado', 'credito'])],
            'due_date' => ['nullable', 'date'],
            'quote_valid_until' => ['nullable', 'date'],
            'discount' => ['nullable', 'decimal:0,2', 'min:0'],
            'notes' => ['nullable', 'string'],
            'confirm' => ['boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'exists:product_variants,id'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'decimal:0,2', 'min:0'],
            'items.*.discount' => ['nullable', 'decimal:0,2', 'min:0'],
            'items.*.unit_ids' => ['array'],
            'items.*.unit_ids.*' => ['integer', 'exists:product_units,id'],
        ]);

        $sale = DB::transaction(function () use ($data) {
            $sale = $this->sales->createDraft($data);

            return ($data['confirm'] ?? false) ? $this->sales->confirm($sale) : $sale;
        });

        return response()->json($this->show($sale->fresh()), 201);
    }

    public function confirm(Sale $sale)
    {
        Gate::authorize('sales.create');

        return $this->show($this->sales->confirm($sale));
    }

    public function deliver(Sale $sale)
    {
        Gate::authorize('sales.create');

        return $this->show($this->sales->deliver($sale));
    }

    public function cancel(Request $request, Sale $sale)
    {
        // Un vendedor puede descartar su borrador; anular una venta confirmada requiere admin.
        Gate::authorize($sale->status === 'borrador' ? 'sales.create' : 'sales.cancel');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->show($this->sales->cancel($sale, $data['reason']));
    }

    public function returnSale(Request $request, Sale $sale)
    {
        Gate::authorize('sales.return');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->show($this->sales->returnSale($sale, $data['reason']));
    }

    public function storePayment(Request $request, Sale $sale)
    {
        Gate::authorize('payments.register');
        $data = $request->validate([
            'method' => ['required', Rule::in(config('business.payment_methods'))],
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'currency' => ['nullable', Rule::in(config('business.currencies'))],
            'exchange_rate' => ['nullable', 'decimal:0,6', 'gt:0'],
            'applied_amount' => ['nullable', 'decimal:0,2', 'gt:0'],
            'paid_at' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string'],
        ]);

        return response()->json($this->payments->register($sale, $data), 201);
    }

    public function voidPayment(Request $request, Payment $payment)
    {
        Gate::authorize('payments.void');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->payments->void($payment, $data['reason']);
    }
}
