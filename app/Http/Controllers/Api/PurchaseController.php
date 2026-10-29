<?php

namespace App\Http\Controllers\Api;

use App\Domain\Purchasing\CostAllocator;
use App\Domain\Purchasing\PurchaseService;
use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PurchaseController extends Controller
{
    public function __construct(private PurchaseService $purchases) {}

    public function suppliers()
    {
        Gate::authorize('purchases.manage');

        return Supplier::orderBy('name')->get();
    }

    public function storeSupplier(Request $request)
    {
        Gate::authorize('purchases.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'country' => ['nullable', 'string', 'size:2'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email'],
            'notes' => ['nullable', 'string'],
        ]);

        return response()->json(Supplier::create($data), 201);
    }

    public function index(Request $request)
    {
        Gate::authorize('purchases.manage');

        return PurchaseOrder::with('supplier:id,name')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('purchase_date')->orderByDesc('id')
            ->paginate(50);
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        Gate::authorize('purchases.manage');

        return $purchaseOrder->load('supplier', 'items.variant', 'items.units', 'costs');
    }

    public function store(Request $request)
    {
        Gate::authorize('purchases.manage');
        $currencies = config('business.currencies');
        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'reference' => ['nullable', 'string', 'max:120'],
            'purchase_date' => ['required', 'date'],
            'currency' => ['required', Rule::in($currencies)],
            'exchange_rate' => ['nullable', 'decimal:0,6', 'gt:0'],
            'exchange_rate_date' => ['nullable', 'date'],
            'exchange_rate_source' => ['nullable', 'string', 'max:120'],
            'allocation_method' => ['nullable', Rule::in(CostAllocator::METHODS)],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'decimal:0,4', 'min:0'],
            'items.*.discount' => ['nullable', 'decimal:0,2', 'min:0'],
            'costs' => ['array'],
            'costs.*.type' => ['required', Rule::in(config('business.purchase_cost_types'))],
            'costs.*.description' => ['nullable', 'string', 'max:160'],
            'costs.*.amount' => ['required', 'decimal:0,2', 'gt:0'],
            'costs.*.currency' => ['nullable', Rule::in($currencies)],
            'costs.*.exchange_rate' => ['nullable', 'decimal:0,6', 'gt:0'],
        ]);

        return response()->json($this->purchases->create($data), 201);
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder)
    {
        Gate::authorize('purchases.manage');
        $data = $request->validate([
            'hold_for_inspection' => ['boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.quantity_received' => ['required', 'integer', 'min:0'],
            'lines.*.quantity_damaged' => ['nullable', 'integer', 'min:0'],
            'lines.*.units' => ['array'],
            'lines.*.units.*.imei' => ['nullable', 'string', 'regex:/^[0-9 \-]{14,20}$/'],
            'lines.*.units.*.imei2' => ['nullable', 'string', 'regex:/^[0-9 \-]{14,20}$/'],
            'lines.*.units.*.serial_number' => ['nullable', 'string', 'max:60'],
            'lines.*.units.*.condition' => ['nullable', 'string', 'max:30'],
            'lines.*.units.*.battery_health' => ['nullable', 'integer', 'between:0,100'],
            'lines.*.units.*.location' => ['nullable', 'string', 'max:120'],
            'lines.*.units.*.inspection_notes' => ['nullable', 'string'],
        ]);

        return $this->purchases->receive($purchaseOrder, $data['lines'], (bool) ($data['hold_for_inspection'] ?? false));
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder)
    {
        Gate::authorize('purchases.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->purchases->cancel($purchaseOrder, $data['reason']);
    }
}
