<?php

namespace Tests\Feature;

use App\Models\CashMovement;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsBusinessData;
use Tests\TestCase;

/**
 * Conciliación del escenario documentado en docs/hito-1-escenario-conciliacion.md.
 */
class FinancialDashboardTest extends TestCase
{
    use BuildsBusinessData, RefreshDatabase;

    private Sale $usdSale;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-25 15:00:00');
        $this->setUpBusiness();
        $this->stockedScenario();

        // Apertura de caja ARS
        $this->postJson('/api/cash', ['type' => 'apertura', 'amount' => '100000', 'currency' => 'ARS', 'occurred_at' => '2026-10-01', 'description' => 'Saldo inicial'])->assertCreated();

        // Venta 1: 2 iPhone a 900 USD (cotización 1100), crédito, cobro parcial 1000 USD
        $this->usdSale = Sale::findOrFail($this->postJson('/api/sales', [
            'customer_id' => $this->customer->id, 'currency' => 'USD', 'exchange_rate' => '1100', 'sale_date' => '2026-10-10',
            'payment_terms' => 'credito', 'due_date' => '2026-10-20', 'confirm' => true,
            'items' => [['product_variant_id' => $this->ip128->id, 'quantity' => 2, 'unit_price' => '900']],
        ])->assertCreated()->json('id'));
        $this->postJson("/api/sales/{$this->usdSale->id}/deliver")->assertOk();
        $this->postJson("/api/sales/{$this->usdSale->id}/payments", [
            'method' => 'transferencia', 'amount' => '1000', 'paid_at' => '2026-10-12', 'reference' => 'TRX-1',
        ])->assertCreated();

        // Venta 2: 4 fundas a 12.000 ARS - 3.000 de descuento, contado, cobrada completa
        $sale2 = $this->postJson('/api/sales', [
            'customer_id' => $this->customer->id, 'currency' => 'ARS', 'sale_date' => '2026-10-11', 'discount' => '3000', 'confirm' => true,
            'items' => [['product_variant_id' => $this->funda->id, 'quantity' => 4, 'unit_price' => '12000']],
        ])->assertCreated()->json('id');
        $this->postJson("/api/sales/{$sale2}/payments", ['method' => 'efectivo', 'amount' => '45000', 'paid_at' => '2026-10-11'])->assertCreated();

        // Gastos: publicidad pagada (ARS) y software devengado no pagado (USD a 1100)
        $this->postJson('/api/expenses', [
            'expense_category_id' => $this->expenseCategoryId('publicidad'), 'description' => 'Campaña Instagram',
            'amount' => '50000', 'currency' => 'ARS', 'issued_at' => '2026-10-05', 'paid_at' => '2026-10-05', 'payment_method' => 'tarjeta',
        ])->assertCreated();
        $this->postJson('/api/expenses', [
            'expense_category_id' => $this->expenseCategoryId('software'), 'description' => 'Hosting',
            'amount' => '20', 'currency' => 'USD', 'exchange_rate' => '1100', 'issued_at' => '2026-10-06',
        ])->assertCreated();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dashboard_reconciles_with_documented_scenario(): void
    {
        $d = $this->getJson('/api/dashboard?from=2026-10-01&to=2026-10-31')->assertOk()->json();

        $this->assertSame('ARS', $d['base_currency']);
        $this->assertSame('2025000.00', $d['sales']['confirmed_gross']);
        $this->assertSame('2025000.00', $d['sales']['net']);
        $this->assertSame('1576200.00', $d['cogs']);
        $this->assertSame('448800.00', $d['gross_profit']);
        $this->assertSame('72000.00', $d['operating_expenses']['total']);
        $this->assertSame('376800.00', $d['estimated_operating_result']);
        // Ventas ≠ cobros
        $this->assertSame('1145000.00', $d['collections']['net']);
        // Cuenta por cobrar: 800 USD (vencida desde el 20/10), equivalente a 1100
        $this->assertSame('800.00', $d['receivables']['by_currency']['USD']['pending']);
        $this->assertSame('800.00', $d['receivables']['by_currency']['USD']['overdue']);
        $this->assertSame('880000.00', $d['receivables']['pending_base']);
        // Caja por moneda = apertura + cobros - pagos (no ventas - gastos devengados)
        $this->assertSame(['opening' => '0.00', 'in' => '145000.00', 'out' => '50000.00', 'closing' => '95000.00'], $d['cash']['ARS']);
        $this->assertSame('1000.00', $d['cash']['USD']['closing']);
        $this->assertSame('22000.00', $d['unpaid_expenses_base']);
        // Inventario a costo (las 4 fundas reservadas siguen en poder del negocio): 1×777.000 + 2×832.500 + 10×5.550
        $this->assertSame('2497500.00', $d['inventory']['value_at_cost_base']);
        $this->assertSame(13, $d['inventory']['on_hand']);
        $this->assertSame(4, $d['inventory']['reserved']);
        $this->assertSame(9, $d['inventory']['available']);
        $this->assertStringContainsString('Calculado con movimientos registrados hasta', $d['coverage_note']);
        $this->assertSame('no_conectado', $d['integrations']['meta_ads']['status']);
    }

    public function test_partial_payment_is_reflected_separately(): void
    {
        $sale = $this->usdSale->fresh();
        $this->assertSame('entregada', $sale->status);
        $this->assertSame('parcial', $sale->payment_status);
        $this->assertSame('1000.00', $sale->paid_amount);
        $this->assertSame('800.00', $sale->balance_due);
    }

    public function test_later_reversals_do_not_rewrite_closed_periods(): void
    {
        Carbon::setTestNow('2026-11-05 12:00:00');
        $paymentId = $this->usdSale->payments()->first()->id;
        $this->postJson("/api/payments/{$paymentId}/void", ['reason' => 'reintegro por devolución'])->assertOk();
        $this->postJson("/api/sales/{$this->usdSale->id}/return", ['reason' => 'devolución'])->assertOk();

        $oct = $this->getJson('/api/dashboard?from=2026-10-01&to=2026-10-31')->json();
        $this->assertSame('2025000.00', $oct['sales']['net']);
        $this->assertSame('1145000.00', $oct['collections']['net']);

        $nov = $this->getJson('/api/dashboard?from=2026-11-01&to=2026-11-30')->json();
        $this->assertSame('1980000.00', $nov['sales']['returned']);
        $this->assertSame('-1980000.00', $nov['sales']['net']);
        $this->assertSame('-1554000.00', $nov['cogs']);
        $this->assertSame('1100000.00', $nov['collections']['voided']);
        $this->assertSame(['opening' => '1000.00', 'in' => '0.00', 'out' => '1000.00', 'closing' => '0.00'], $nov['cash']['USD']);
        // El movimiento original sigue existiendo y la reversa lo referencia
        $this->assertSame(1, CashMovement::where('type', 'anulacion_cobro')->whereNotNull('reverses_id')->count());
    }

    public function test_payment_rules(): void
    {
        $id = $this->usdSale->id;
        // No se puede cobrar más que el saldo
        $this->postJson("/api/sales/{$id}/payments", ['method' => 'efectivo', 'amount' => '800.01'])->assertStatus(422);
        // Cobro en otra moneda exige importe imputado explícito
        $this->postJson("/api/sales/{$id}/payments", ['method' => 'efectivo', 'amount' => '550000', 'currency' => 'ARS'])
            ->assertStatus(422)->assertJsonValidationErrors('applied_amount');
        $this->postJson("/api/sales/{$id}/payments", ['method' => 'efectivo', 'amount' => '550000', 'currency' => 'ARS', 'applied_amount' => '500'])
            ->assertCreated()->assertJsonPath('amount_base', '550000.00');
        $this->assertSame('300.00', $this->usdSale->fresh()->balance_due);
        $this->postJson("/api/sales/{$id}/payments", ['method' => 'transferencia', 'amount' => '300'])->assertCreated();
        $this->assertSame('pagado', $this->usdSale->fresh()->payment_status);
    }

    public function test_expense_void_reverses_cash_and_duplicate_external_key_is_blocked(): void
    {
        $data = [
            'expense_category_id' => $this->expenseCategoryId('publicidad'), 'description' => 'Meta Ads campaña X 2026-10-07',
            'amount' => '10000', 'currency' => 'ARS', 'issued_at' => '2026-10-07', 'paid_at' => '2026-10-07',
            'external_key' => 'meta:act_1:camp_9:2026-10-07',
        ];
        $id = $this->postJson('/api/expenses', $data)->assertCreated()->json('id');
        $this->postJson('/api/expenses', $data)->assertStatus(422)->assertJsonValidationErrors('external_key');

        $this->postJson("/api/expenses/{$id}/void", ['reason' => 'cargado por error'])->assertOk()->assertJsonPath('status', 'anulado');
        $d = $this->getJson('/api/dashboard?from=2026-10-01&to=2026-10-31')->json();
        $this->assertSame('72000.00', $d['operating_expenses']['total']);
        $this->assertSame('95000.00', $d['cash']['ARS']['closing']);
    }

    public function test_csv_exports(): void
    {
        $res = $this->get('/api/exports/ventas?from=2026-10-01&to=2026-10-31')->assertOk();
        $csv = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFnumero,fecha,cliente", $csv);
        $this->assertMatchesRegularExpression('/V-\d{6},2026-10-10,"Celulares Centro SRL",entregada,parcial,USD,1100.000000,1800.00,1980000.00,1554000.00,426000.00,1000.00,800.00,2026-10-20/', $csv);
        foreach (['cobros', 'gastos', 'caja', 'stock'] as $ds) {
            $this->get("/api/exports/{$ds}")->assertOk();
        }
    }
}
