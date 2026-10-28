<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\ProductUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsBusinessData;
use Tests\TestCase;

class PurchaseReceptionTest extends TestCase
{
    use BuildsBusinessData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBusiness();
    }

    public function test_usd_purchase_with_extra_costs_produces_expected_landed_unit_cost(): void
    {
        $po = $this->createStandardPurchase();
        $this->assertSame('3650.00', $po->goods_total);
        $this->assertSame('3650000.00', $po->goods_total_base);
        $this->assertSame('401500.00', $po->extra_costs_base);

        $po = $this->receiveStandardPurchase($po);
        $this->assertSame('received', $po->status);
        $this->assertSame('value', $po->allocation_snapshot['method']);

        $items = $po->items()->orderBy('id')->get();
        $this->assertSame('231000.00', $items[0]->allocated_costs_base);
        $this->assertSame('777000.0000', $items[0]->landed_unit_cost_base);
        $this->assertSame('832500.0000', $items[1]->landed_unit_cost_base);
        $this->assertSame('5550.0000', $items[2]->landed_unit_cost_base);

        // Unidades serializadas con su costo asignado
        $this->assertSame(3, ProductUnit::where('product_variant_id', $this->ip128->id)->where('status', 'disponible')->count());
        $this->assertSame('777000.0000', ProductUnit::where('product_variant_id', $this->ip128->id)->first()->unit_cost_base);

        // Saldos y costo promedio
        $this->assertSame(3, $this->ip128->fresh()->stock_on_hand);
        $this->assertSame(10, $this->funda->fresh()->stock_available);
        $this->assertSame('5550.0000', $this->funda->fresh()->avg_cost_base);
    }

    public function test_changing_rates_later_does_not_recalculate_history(): void
    {
        $po = $this->receiveStandardPurchase($this->createStandardPurchase());
        // Una compra posterior a otra cotización no altera la anterior
        $this->postJson('/api/purchase-orders', [
            'supplier_id' => $this->supplier->id, 'purchase_date' => '2026-10-20', 'currency' => 'USD', 'exchange_rate' => '1500',
            'items' => [['product_variant_id' => $this->ip128->id, 'quantity' => 1, 'unit_cost' => '700']],
        ])->assertCreated();
        $this->assertSame('777000.0000', $po->items()->orderBy('id')->first()->landed_unit_cost_base);
        $this->assertSame('1000.000000', $po->fresh()->exchange_rate);
    }

    public function test_damaged_and_missing_units_are_recorded_and_absorbed_by_valid_units(): void
    {
        $po = $this->createStandardPurchase();
        // ip256: llegan 2, 1 dañada => 1 válida con todo el costo de la línea
        $po = $this->receiveStandardPurchase($po, [1 => ['quantity_received' => 2, 'quantity_damaged' => 1, 'units' => $this->imeis('35111111111', 1)]]);
        $item = $po->items()->orderBy('id')->get()[1];
        $this->assertSame(1, $item->quantity_damaged);
        $this->assertSame('1665000.0000', $item->landed_unit_cost_base);
        $this->assertSame(1, $this->ip256->fresh()->stock_on_hand);

        $po2 = $this->createStandardPurchase();
        $po2 = $this->receiveStandardPurchase($po2, [
            0 => ['quantity_received' => 2, 'units' => $this->imeis('35222222222', 2)],
            1 => ['units' => $this->imeis('35333333333', 2)],
        ]);
        $this->assertSame(1, $po2->items()->orderBy('id')->first()->quantity_missing);
    }

    public function test_serialized_items_require_imei_per_unit_and_reject_duplicates(): void
    {
        $po = $this->createStandardPurchase();
        $items = $po->items()->orderBy('id')->get();
        $this->postJson("/api/purchase-orders/{$po->id}/receive", ['lines' => [
            ['item_id' => $items[0]->id, 'quantity_received' => 3, 'units' => $this->imeis('35000000000', 2)],
            ['item_id' => $items[1]->id, 'quantity_received' => 2, 'units' => $this->imeis('35111111111', 2)],
            ['item_id' => $items[2]->id, 'quantity_received' => 10],
        ]])->assertStatus(422);

        $this->receiveStandardPurchase($po);
        $po2 = $this->createStandardPurchase();
        $items2 = $po2->items()->orderBy('id')->get();
        $this->postJson("/api/purchase-orders/{$po2->id}/receive", ['lines' => [
            ['item_id' => $items2[0]->id, 'quantity_received' => 3, 'units' => $this->imeis('35000000000', 3)], // repetidos
            ['item_id' => $items2[1]->id, 'quantity_received' => 2, 'units' => $this->imeis('35444444444', 2)],
            ['item_id' => $items2[2]->id, 'quantity_received' => 10],
        ]])->assertStatus(422)->assertJsonFragment(['message' => 'Ya existe una unidad con imei 350000000000001.']);
        // Nada se aplicó del intento fallido (transacción)
        $this->assertSame('draft', $po2->fresh()->status);
        $this->assertSame(3, $this->ip128->fresh()->stock_on_hand);
    }

    public function test_imeis_are_rejected_for_non_serialized_items(): void
    {
        $po = $this->createStandardPurchase();
        $this->postJson("/api/purchase-orders/{$po->id}/receive", ['lines' => []])->assertStatus(422);
        $items = $po->items()->orderBy('id')->get();
        $this->postJson("/api/purchase-orders/{$po->id}/receive", ['lines' => [
            ['item_id' => $items[0]->id, 'quantity_received' => 3, 'units' => $this->imeis('35000000000', 3)],
            ['item_id' => $items[1]->id, 'quantity_received' => 2, 'units' => $this->imeis('35111111111', 2)],
            ['item_id' => $items[2]->id, 'quantity_received' => 10, 'units' => $this->imeis('35555555555', 10)],
        ]])->assertStatus(422);
        $this->assertSame('draft', $po->fresh()->status);
    }

    public function test_purchase_cannot_be_received_twice(): void
    {
        $po = $this->receiveStandardPurchase($this->createStandardPurchase());
        $this->postJson("/api/purchase-orders/{$po->id}/receive", ['lines' => [['item_id' => 1, 'quantity_received' => 1]]])
            ->assertStatus(422);
    }

    public function test_ledger_matches_balances(): void
    {
        $this->stockedScenario();
        foreach ([$this->ip128, $this->ip256, $this->funda] as $v) {
            $v->refresh();
            $this->assertSame($v->stock_on_hand, (int) InventoryMovement::where('product_variant_id', $v->id)->sum('on_hand_delta'));
            $this->assertSame($v->stock_reserved, (int) InventoryMovement::where('product_variant_id', $v->id)->sum('reserved_delta'));
        }
    }
}
