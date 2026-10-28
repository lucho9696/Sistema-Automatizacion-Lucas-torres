<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shared\Auditor;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    public function __construct(private Auditor $auditor) {}

    public function index(Request $request)
    {
        Gate::authorize('customers.manage');

        return Customer::query()
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', "%{$t}%")->orWhere('business_name', 'ilike', "%{$t}%")
                ->orWhere('phone_e164', 'like', '%'.preg_replace('/\D/', '', $t).'%')))
            ->orderBy('name')
            ->paginate(50);
    }

    public function show(Customer $customer)
    {
        Gate::authorize('customers.manage');

        return $customer->load(['sales' => fn ($q) => $q->latest('sale_date')->limit(50)]);
    }

    public function store(Request $request)
    {
        Gate::authorize('customers.manage');
        $data = $this->validated($request);
        $customer = Customer::create($data);
        $this->auditor->record('cliente.creado', $customer, null, $customer->toArray());

        return response()->json($customer, 201);
    }

    public function update(Request $request, Customer $customer)
    {
        Gate::authorize('customers.manage');
        $data = $this->validated($request, $customer);
        $old = $customer->only(array_keys($data));
        $customer->update($data);
        $this->auditor->record('cliente.modificado', $customer, $old, $data);

        return $customer;
    }

    private function validated(Request $request, ?Customer $customer = null): array
    {
        $data = $request->validate([
            'name' => [$customer ? 'sometimes' : 'required', 'string', 'max:160'],
            'business_name' => ['nullable', 'string', 'max:160'],
            'tax_id' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email'],
            'city' => ['nullable', 'string', 'max:120'],
            'province' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'size:2'],
            'type' => ['nullable', Rule::in(['mayorista', 'minorista'])],
            'source' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string'],
        ]);
        if (array_key_exists('phone', $data)) {
            $phone = Phone::normalize($data['phone']);
            unset($data['phone']);
            $data['phone_e164'] = $phone;
            if ($phone && Customer::where('phone_e164', $phone)->when($customer, fn ($q) => $q->whereKeyNot($customer->id))->exists()) {
                throw ValidationException::withMessages(['phone' => "Ya existe un cliente con el teléfono {$phone}."]);
            }
        }

        return $data;
    }
}
