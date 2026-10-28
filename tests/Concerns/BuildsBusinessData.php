<?php

namespace Tests\Concerns;

use App\Models\Category;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Escenario de prueba documentado (ver docs/hito-1-escenario-conciliacion.md).
 * Moneda base ARS. Compra en USD a 1000 ARS/USD.
 */
trait BuildsBusinessData
{
    protected User $admin;

    protected ProductVariant $ip128;   // serializado

    protected ProductVariant $ip256;   // serializado

    protected ProductVariant $funda;   // no serializado

    protected Customer $customer;

    protected Supplier $supplier;

    protected function setUpBusiness(): void
    {
        $this->seed();
        $this->admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($this->admin);

        $phones = Category::where('slug', 'celulares')->firstOrFail();
        $acc = Category::where('slug', 'accesorios')->firstOrFail();
        $iphone = Product::create(['category_id' => $phones->id, 'brand' => 'Apple', 'name' => 'iPhone 15', 'sku' => 'IP15', 'is_serialized' => true]);
        $this->ip128 = $iphone->variants()->create(['sku' => 'IP15-128', 'name' => '128 GB Negro', 'list_price' => '950.00', 'price_currency' => 'USD']);
        $this->ip256 = $iphone->variants()->create(['sku' => 'IP15-256', 'name' => '256 GB Azul']);
        $funda = Product::create(['category_id' => $acc->id, 'name' => 'Funda', 'sku' => 'FUNDA', 'is_serialized' => false]);
        $this->funda = $funda->variants()->create(['sku' => 'FUNDA-15', 'name' => 'iPhone 15']);
        $this->supplier = Supplier::create(['name' => 'Proveedor Miami', 'country' => 'US']);
        $this->customer = Customer::create(['name' => 'Celulares Centro SRL', 'type' => 'mayorista']);
    }

    protected function imeis(string $prefix, int $n): array
    {
        return array_map(fn ($i) => ['imei' => $prefix.str_pad((string) $i, 15 - strlen($prefix), '0', STR_PAD_LEFT)], range(1, $n));
    }

    /** Compra del escenario: 3.650 USD de mercadería + 401.500 ARS de costos atribuibles. */
    protected function createStandardPurchase(): PurchaseOrder
    {
        $res = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'reference' => 'INV-1001',
            'purchase_date' => '2026-10-01',
            'currency' => 'USD',
            'exchange_rate' => '1000',
            'exchange_rate_source' => 'manual',
            'items' => [
                ['product_variant_id' => $this->ip128->id, 'quantity' => 3, 'unit_cost' => '700'],
                ['product_variant_id' => $this->ip256->id, 'quantity' => 2, 'unit_cost' => '800', 'discount' => '100'],
                ['product_variant_id' => $this->funda->id, 'quantity' => 10, 'unit_cost' => '5'],
            ],
            'costs' => [
                ['type' => 'flete', 'amount' => '146.00'],                                   // USD a 1000
                ['type' => 'seguro', 'amount' => '36.50'],                                   // USD a 1000
                ['type' => 'aranceles', 'amount' => '219000.00', 'currency' => 'ARS'],       // ARS
            ],
        ])->assertCreated();

        return PurchaseOrder::findOrFail($res->json('id'));
    }

    protected function receiveStandardPurchase(PurchaseOrder $po, array $overrides = []): PurchaseOrder
    {
        $items = $po->items()->orderBy('id')->get();
        $lines = [
            ['item_id' => $items[0]->id, 'quantity_received' => 3, 'units' => $this->imeis('35000000000', 3)],
            ['item_id' => $items[1]->id, 'quantity_received' => 2, 'units' => $this->imeis('35111111111', 2)],
            ['item_id' => $items[2]->id, 'quantity_received' => 10],
        ];
        foreach ($overrides as $i => $o) {
            $lines[$i] = array_merge($lines[$i], $o);
        }
        $this->postJson("/api/purchase-orders/{$po->id}/receive", ['lines' => $lines])->assertOk();

        return $po->fresh();
    }

    protected function stockedScenario(): void
    {
        $this->receiveStandardPurchase($this->createStandardPurchase());
    }

    protected function expenseCategoryId(string $slug): int
    {
        return ExpenseCategory::where('slug', $slug)->value('id');
    }
}
