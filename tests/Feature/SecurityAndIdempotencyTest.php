<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsBusinessData;
use Tests\TestCase;

class SecurityAndIdempotencyTest extends TestCase
{
    use BuildsBusinessData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBusiness();
        $this->stockedScenario();
    }

    private function salePayload(): array
    {
        return [
            'customer_id' => $this->customer->id, 'currency' => 'ARS', 'confirm' => true,
            'items' => [['product_variant_id' => $this->funda->id, 'quantity' => 1, 'unit_price' => '12000']],
        ];
    }

    public function test_repeated_request_with_same_idempotency_key_creates_one_sale(): void
    {
        $headers = ['Idempotency-Key' => 'form-abc-123'];
        $first = $this->postJson('/api/sales', $this->salePayload(), $headers)->assertCreated();
        $second = $this->postJson('/api/sales', $this->salePayload(), $headers)->assertCreated();
        $second->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, Sale::count());
        $this->assertSame(1, $this->funda->fresh()->stock_reserved);

        // Misma clave, otros datos => rechazo
        $other = $this->salePayload();
        $other['items'][0]['quantity'] = 2;
        $this->postJson('/api/sales', $other, $headers)->assertStatus(422);
    }

    public function test_repeated_payment_with_same_key_is_not_duplicated(): void
    {
        $sale = $this->postJson('/api/sales', $this->salePayload())->json('id');
        $headers = ['Idempotency-Key' => 'pay-1'];
        $this->postJson("/api/sales/{$sale}/payments", ['method' => 'efectivo', 'amount' => '5000'], $headers)->assertCreated();
        $this->postJson("/api/sales/{$sale}/payments", ['method' => 'efectivo', 'amount' => '5000'], $headers)->assertCreated();
        $this->assertSame(1, Payment::count());
        $this->assertSame('5000.00', Sale::find($sale)->paid_amount);
    }

    public function test_guests_are_rejected(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/dashboard')->assertUnauthorized();
        $this->postJson('/api/sales', [])->assertUnauthorized();
    }

    public function test_seller_cannot_void_payments_or_see_finance(): void
    {
        $sale = $this->postJson('/api/sales', $this->salePayload())->json('id');
        $payment = $this->postJson("/api/sales/{$sale}/payments", ['method' => 'efectivo', 'amount' => '1000'])->json('id');

        Sanctum::actingAs(User::factory()->role('vendedor')->create());
        $this->postJson('/api/sales', $this->salePayload())->assertCreated();
        $this->postJson("/api/payments/{$payment}/void", ['reason' => 'x'])->assertForbidden();
        $this->postJson("/api/sales/{$sale}/cancel", ['reason' => 'x'])->assertForbidden();
        $this->getJson('/api/dashboard')->assertForbidden();
        $this->postJson('/api/expenses', [])->assertForbidden();
        $this->postJson('/api/inventory/adjustments', [])->assertForbidden();
        $this->getJson('/api/units')->assertOk()->assertJsonStructure(['data' => [['imei']]]);
    }

    public function test_analyst_sees_reports_but_not_imei_and_cannot_sell(): void
    {
        Sanctum::actingAs(User::factory()->role('analista')->create());
        $this->getJson('/api/dashboard')->assertOk();
        $this->postJson('/api/sales', $this->salePayload())->assertForbidden();
        $unit = $this->getJson('/api/units')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('imei', $unit);
        $this->assertArrayNotHasKey('serial_number', $unit);
    }

    public function test_inactive_user_cannot_login_and_login_is_throttled(): void
    {
        $this->app['auth']->forgetGuards();
        $u = User::factory()->create(['email' => 'x@example.com', 'is_active' => false]);
        $this->postJson('/api/auth/login', ['email' => 'x@example.com', 'password' => 'password'])->assertStatus(422);
        $u->update(['is_active' => true]);
        $this->postJson('/api/auth/login', ['email' => 'x@example.com', 'password' => 'password'])->assertOk()->assertJsonPath('role', 'admin');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'y@example.com', 'password' => 'bad']);
        }
        $this->postJson('/api/auth/login', ['email' => 'y@example.com', 'password' => 'bad'])->assertStatus(429);
    }

    public function test_inventory_adjustment_requires_reason_and_blocks_negative(): void
    {
        $this->postJson('/api/inventory/adjustments', ['product_variant_id' => $this->funda->id, 'delta' => -11, 'reason' => 'conteo'])->assertStatus(422);
        $this->postJson('/api/inventory/adjustments', ['product_variant_id' => $this->funda->id, 'delta' => -1])->assertStatus(422);
        $this->postJson('/api/inventory/adjustments', ['product_variant_id' => $this->funda->id, 'delta' => -1, 'reason' => 'rota'])->assertOk()
            ->assertJsonPath('stock_on_hand', 9);
        $this->postJson('/api/inventory/adjustments', ['product_variant_id' => $this->ip128->id, 'delta' => -1, 'reason' => 'x'])->assertStatus(422);

        $unit = ProductUnit::where('product_variant_id', $this->ip128->id)->first();
        $this->postJson("/api/units/{$unit->id}/status", ['status' => 'perdido', 'reason' => 'extravío'])->assertOk();
        $this->assertSame(2, $this->ip128->fresh()->stock_on_hand);
        $this->postJson("/api/units/{$unit->id}/status", ['status' => 'disponible', 'reason' => 'apareció'])->assertStatus(422);
    }
}
