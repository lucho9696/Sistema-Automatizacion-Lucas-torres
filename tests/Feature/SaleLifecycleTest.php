<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\ProductUnit;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsBusinessData;
use Tests\TestCase;

class SaleLifecycleTest extends TestCase
{
    use BuildsBusinessData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBusiness();
        $this->stockedScenario();
    }

    private function sellIphones(int $qty = 2, bool $confirm = true): Sale
    {
        $res = $this->postJson('/api/sales', [
            'customer_id' => $this->customer->id,
            'currency' => 'USD', 'exchange_rate' => '1100', 'sale_date' => '2026-10-10',
            'payment_terms' => 'credito', 'due_date' => '2026-10-20',
            'confirm' => $confirm,
            'items' => [['product_variant_id' => $this->ip128->id, 'quantity' => $qty, 'unit_price' => '900']],
        ])->assertCreated();

        return Sale::findOrFail($res->json('id'));
    }

    public function test_confirming_freezes_price_and_cost_and_reserves_units(): void
    {
        $sale = $this->sellIphones();
        $this->assertSame('confirmada', $sale->status);
        $this->assertSame('1800.00', $sale->total);
        $this->assertSame('1980000.00', $sale->total_base);
        $this->assertSame('1554000.00', $sale->cost_total_base);
        $this->assertSame(2, ProductUnit::where('status', 'reservado')->count());
        $v = $this->ip128->fresh();
        $this->assertSame(3, $v->stock_on_hand);
        $this->assertSame(2, $v->stock_reserved);
        $this->assertSame(1, $v->stock_available);

        // Cambiar catálogo y comprar más caro no altera la venta confirmada
        $this->patchJson("/api/variants/{$this->ip128->id}", ['list_price' => '1200.00'])->assertOk();
        $po = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $this->supplier->id, 'purchase_date' => '2026-10-15', 'currency' => 'USD', 'exchange_rate' => '1500',
            'items' => [['product_variant_id' => $this->ip128->id, 'quantity' => 1, 'unit_cost' => '750']],
        ])->json();
        $item = $po['items'][0];
        $this->postJson("/api/purchase-orders/{$po['id']}/receive", ['lines' => [
            ['item_id' => $item['id'], 'quantity_received' => 1, 'units' => $this->imeis('35999999999', 1)],
        ]])->assertOk();

        $sale->refresh();
        $line = $sale->items()->first();
        $this->assertSame('900.00', $line->unit_price);
        $this->assertSame('777000.0000', $line->unit_cost_base);
        $this->assertSame('1554000.00', $sale->cost_total_base);
        $this->assertSame('1100.000000', $sale->exchange_rate);
    }

    public function test_stock_cannot_go_negative(): void
    {
        $this->postJson('/api/sales', [
            'customer_id' => $this->customer->id, 'currency' => 'ARS', 'confirm' => true,
            'items' => [['product_variant_id' => $this->funda->id, 'quantity' => 11, 'unit_price' => '10000']],
        ])->assertStatus(422);
        $this->assertSame(0, Sale::count(), 'La venta fallida no debe quedar a medias');
        $this->assertSame(10, $this->funda->fresh()->stock_available);

        $this->sellIphones(2);
        $this->postJson('/api/sales', [
            'customer_id' => $this->customer->id, 'currency' => 'USD', 'exchange_rate' => '1100', 'confirm' => true,
            'items' => [['product_variant_id' => $this->ip128->id, 'quantity' => 2, 'unit_price' => '900']],
        ])->assertStatus(422);
    }

    public function test_specific_units_can_be_sold_by_id(): void
    {
        $unit = ProductUnit::where('product_variant_id', $this->ip128->id)->orderByDesc('id')->first();
        $res = $this->postJson('/api/sales', [
            'customer_id' => $this->customer->id, 'currency' => 'USD', 'exchange_rate' => '1100', 'confirm' => true,
            'items' => [['product_variant_id' => $this->ip128->id, 'unit_ids' => [$unit->id], 'unit_price' => '950']],
        ])->assertCreated();
        $this->assertSame('reservado', $unit->fresh()->status);
        $this->assertSame($unit->id, $res->json('items.0.item_units.0.product_unit_id'));
    }

    public function test_deliver_consumes_stock_and_marks_units_sold(): void
    {
        $sale = $this->sellIphones();
        $this->postJson("/api/sales/{$sale->id}/deliver")->assertOk()->assertJsonPath('status', 'entregada');
        $v = $this->ip128->fresh();
        $this->assertSame(1, $v->stock_on_hand);
        $this->assertSame(0, $v->stock_reserved);
        $this->assertSame(2, ProductUnit::where('status', 'vendido')->count());
    }

    public function test_cancel_releases_reservation_and_requires_no_active_payments(): void
    {
        $sale = $this->sellIphones();
        $payment = $this->postJson("/api/sales/{$sale->id}/payments", ['method' => 'transferencia', 'amount' => '100'])->assertCreated();
        $this->postJson("/api/sales/{$sale->id}/cancel", ['reason' => 'cliente desiste'])->assertStatus(422);

        $this->postJson("/api/payments/{$payment->json('id')}/void", ['reason' => 'reintegro'])->assertOk();
        $this->postJson("/api/sales/{$sale->id}/cancel", ['reason' => 'cliente desiste'])->assertOk()->assertJsonPath('status', 'cancelada');
        $this->assertSame(3, $this->ip128->fresh()->stock_available);
        $this->assertSame(0, ProductUnit::where('status', 'reservado')->count());
        $this->assertSame(2, InventoryMovement::where('type', 'liberacion')->where('product_variant_id', $this->ip128->id)->count());
    }

    public function test_return_puts_units_on_hold_until_reviewed(): void
    {
        $sale = $this->sellIphones(1);
        $this->postJson("/api/sales/{$sale->id}/deliver")->assertOk();
        $this->postJson("/api/sales/{$sale->id}/return", ['reason' => 'falla de pantalla'])->assertOk()->assertJsonPath('status', 'devuelta');
        $v = $this->ip128->fresh();
        $this->assertSame(3, $v->stock_on_hand);
        $this->assertSame(1, $v->stock_held);
        $this->assertSame(2, $v->stock_available);

        $unit = ProductUnit::where('status', 'devuelto')->firstOrFail();
        $this->postJson("/api/units/{$unit->id}/status", ['status' => 'disponible', 'reason' => 'revisado OK'])->assertOk();
        $this->assertSame(3, $this->ip128->fresh()->stock_available);
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        $sale = $this->sellIphones(1, confirm: false);
        $this->postJson("/api/sales/{$sale->id}/deliver")->assertStatus(422);
        $this->postJson("/api/sales/{$sale->id}/payments", ['method' => 'efectivo', 'amount' => '10'])->assertStatus(422);
        $this->postJson("/api/sales/{$sale->id}/return", ['reason' => 'x'])->assertStatus(422);
        $this->postJson("/api/sales/{$sale->id}/confirm")->assertOk();
        $this->postJson("/api/sales/{$sale->id}/confirm")->assertStatus(422);
    }

    public function test_credit_sale_requires_due_date(): void
    {
        $this->postJson('/api/sales', [
            'customer_id' => $this->customer->id, 'currency' => 'ARS', 'payment_terms' => 'credito',
            'items' => [['product_variant_id' => $this->funda->id, 'quantity' => 1, 'unit_price' => '10000']],
        ])->assertStatus(422)->assertJsonValidationErrors('due_date');
    }

    public function test_every_financial_action_is_audited(): void
    {
        $sale = $this->sellIphones(1);
        $this->postJson("/api/sales/{$sale->id}/payments", ['method' => 'efectivo', 'amount' => '100'])->assertCreated();
        $events = $this->getJson('/api/audit-logs')->assertOk()->json('data.*.event');
        foreach (['compra.recibida', 'venta.borrador', 'venta.confirmada', 'cobro.registrado'] as $e) {
            $this->assertContains($e, $events);
        }
    }
}
