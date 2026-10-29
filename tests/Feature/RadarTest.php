<?php

namespace Tests\Feature;

use App\Domain\Radar\CandidateScorer;
use App\Models\IntegrationToken;
use App\Models\SyncRun;
use App\Models\TrendCandidate;
use App\Models\TrendSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RadarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        Http::preventStrayRequests();
    }

    private function useMercadoLibre(): void
    {
        config([
            'integrations.trends.provider' => 'mercadolibre',
            'integrations.mercadolibre.client_id' => 'test-client',
            'integrations.mercadolibre.client_secret' => 'test-secret',
            'integrations.mercadolibre.redirect_uri' => 'https://example.test/integraciones/mercadolibre/callback',
        ]);
    }

    public function test_demo_mode_is_flagged_and_hidden_by_default(): void
    {
        $this->postJson('/api/trends/sync')->assertOk()->assertJsonPath('mode', 'demo')->assertJsonPath('status', 'success');
        $this->assertTrue(TrendSnapshot::where('is_demo', false)->doesntExist());
        $this->assertSame(0, $this->getJson('/api/trends/candidates')->json('total'));
        $all = $this->getJson('/api/trends/candidates?include_demo=1')->json('data');
        $this->assertNotEmpty($all);
        foreach ($all as $c) {
            $this->assertTrue($c['is_demo']);
            $this->assertContains($c['confidence'], ['demo', 'insuficiente']);
        }
        Http::assertNothingSent();
    }

    public function test_mercadolibre_sync_refreshes_token_stores_snapshots_with_source_and_date(): void
    {
        $this->useMercadoLibre();
        IntegrationToken::create(['provider' => 'mercadolibre', 'access_token' => 'old', 'refresh_token' => 'r1', 'expires_at' => now()->subMinute()]);
        Http::fake([
            'api.mercadolibre.com/oauth/token' => Http::response(['access_token' => 'new-token', 'refresh_token' => 'r2', 'expires_in' => 21600]),
            'api.mercadolibre.com/trends/MLA' => Http::response([
                ['keyword' => 'funda iphone 16', 'url' => 'https://listado.mercadolibre.com.ar/funda-iphone-16'],
                ['keyword' => 'Funda iPhone 16!', 'url' => 'https://listado.mercadolibre.com.ar/funda-iphone-16#2'],
                ['keyword' => 'cargador usb c', 'url' => 'https://listado.mercadolibre.com.ar/cargador'],
            ]),
        ]);

        $this->postJson('/api/trends/sync')->assertOk()->assertJsonPath('mode', 'live')->assertJsonPath('items_count', 3);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/trends/MLA') && $r->hasHeader('Authorization', 'Bearer new-token'));
        $token = IntegrationToken::where('provider', 'mercadolibre')->first();
        $this->assertSame('r2', $token->refresh_token, 'el refresh token rotado se guarda');
        $this->assertNotSame('r2', IntegrationToken::query()->toBase()->value('refresh_token'), 'se guarda cifrado');

        // Variantes del mismo término se agrupan sin perder las instantáneas originales
        $c = TrendCandidate::where('normalized_key', 'funda iphone 16')->firstOrFail();
        $this->assertSame(2, $c->snapshots()->count());
        $snap = $c->snapshots()->orderBy('position')->first();
        $this->assertSame('mercadolibre_trends', $snap->source);
        $this->assertSame('MLA', $snap->site);
        $this->assertSame(1, $snap->position);
        $this->assertSame('crecimiento', $snap->trend_type);
        $this->assertNotNull($snap->fetched_at);
        $this->assertFalse($snap->is_demo);
    }

    public function test_mercadolibre_errors_are_recorded_without_secrets(): void
    {
        $this->useMercadoLibre();
        // Sin autorizar
        $this->postJson('/api/trends/sync')->assertStatus(422)->assertJsonPath('status', 'failed');
        $this->assertStringContainsString('app:ml-connect', SyncRun::latest('id')->first()->error);

        IntegrationToken::create(['provider' => 'mercadolibre', 'access_token' => 'valid', 'refresh_token' => 'r1', 'expires_at' => now()->addHour()]);
        Http::fake(['api.mercadolibre.com/trends/*' => Http::response(['message' => 'forbidden'], 403)]);
        $this->postJson('/api/trends/sync')->assertStatus(422);
        $run = SyncRun::latest('id')->first();
        $this->assertStringContainsString('HTTP 403', $run->error);
        $this->assertStringNotContainsString('valid', $run->error);

        // Variables faltantes se informan por nombre
        config(['integrations.mercadolibre.client_secret' => null]);
        $status = $this->getJson('/api/integrations/status')->assertOk()->json('mercadolibre_trends');
        $this->assertSame(['ML_CLIENT_SECRET'], $status['missing_env']);
        $this->assertStringNotContainsString('test-client', json_encode($status));
    }

    public function test_score_is_explainable_renormalized_and_withheld_when_insufficient(): void
    {
        Carbon::setTestNow('2026-10-29 12:00:00');
        $snap = $this->postJson('/api/trends/signals', ['keyword' => 'Cargador MagSafe', 'source' => 'tiktok_creative_center', 'url' => 'https://ads.tiktok.com/x'])->assertCreated();
        $id = $snap->json('candidate.id');
        $c = TrendCandidate::findOrFail($id);
        $this->assertNull($c->score, 'una sola señal no alcanza');
        $this->assertSame('insuficiente', $c->confidence);

        // Precios requieren evidencia
        $this->patchJson("/api/trends/candidates/{$id}", ['est_buy_price' => '10', 'est_buy_currency' => 'USD'])->assertStatus(422);
        $this->patchJson("/api/trends/candidates/{$id}", [
            'est_buy_price' => '10.00', 'est_buy_currency' => 'USD', 'est_landed_extra_pct' => '50',
            'est_sell_price' => '30000.00', 'est_sell_currency' => 'ARS', 'est_exchange_rate' => '1000',
            'price_evidence' => 'Cotización proveedor X / publicaciones ML 29/10', 'competition_level' => 'media',
        ])->assertOk();
        $c->refresh();
        // demanda manual 0.5 (30) + margen 50% → 1.0 (25) + competencia 0.5 (10) + evidencia 3/5=0.6 (5) = 15+25+5+3 = 48 / 70
        $this->assertSame(69, $c->score);
        $this->assertSame(3, $c->signals_count);
        $this->assertSame('media', $c->confidence);
        $this->assertSame(['Aceleración reciente', 'Proveedores investigados'], $c->score_breakdown['missing']);
        $margin = collect($c->score_breakdown['factors'])->firstWhere('key', 'margin');
        $this->assertStringContainsString('Margen estimado 50.0%', $margin['note']);

        $this->postJson("/api/trends/candidates/{$id}/suppliers", ['supplier_name' => 'Proveedor A', 'url' => 'https://example.com/a', 'price' => '9.50', 'currency' => 'USD', 'moq' => 50, 'consulted_at' => '2026-10-29'])->assertCreated();
        $this->assertSame(4, $c->fresh()->signals_count);

        $detail = $this->getJson("/api/trends/candidates/{$id}")->assertOk();
        $this->assertSame('https://www.alibaba.com/trade/search?SearchText=Cargador%20MagSafe', $detail->json('supplier_links.0.url'));
        $this->assertNotEmpty($detail->json('phone_supplier_checklist'));
        Carbon::setTestNow();
    }

    public function test_acceleration_compares_periods(): void
    {
        $c = TrendCandidate::create(['name' => 'x', 'normalized_key' => 'x']);
        TrendSnapshot::create(['trend_candidate_id' => $c->id, 'source' => 'mercadolibre_trends', 'keyword' => 'x', 'position' => 30, 'trend_type' => 'deseadas', 'fetched_at' => now()->subDays(7)]);
        TrendSnapshot::create(['trend_candidate_id' => $c->id, 'source' => 'mercadolibre_trends', 'keyword' => 'x', 'position' => 10, 'trend_type' => 'crecimiento', 'fetched_at' => now()]);
        $r = app(CandidateScorer::class)->score($c);
        $acc = collect($r['breakdown']['factors'])->firstWhere('key', 'acceleration');
        $this->assertSame(1.0, $acc['value']);
        $this->assertStringContainsString('30 → 10', $acc['note']);
    }

    public function test_demo_keywords_cannot_be_entered_as_real_signals(): void
    {
        $this->postJson('/api/trends/signals', ['keyword' => '[DEMO] algo', 'source' => 'manual'])->assertStatus(422);
    }

    public function test_google_places_is_off_by_default_and_respects_field_mask_and_limit(): void
    {
        $this->postJson('/api/prospecting/places/search', ['query' => 'celulares córdoba'])->assertStatus(422)->assertJsonFragment(['message' => 'Google Places está desactivado (GOOGLE_PLACES_MODE=off). Use alta manual o importación CSV.']);

        config(['integrations.google_places.mode' => 'demo']);
        $demo = $this->postJson('/api/prospecting/places/search', ['query' => 'celulares'])->assertOk();
        $this->assertSame('demo', $demo->json('mode'));
        $this->assertStringStartsWith('[DEMO]', $demo->json('results.0.business_name'));
        Http::assertNothingSent();

        config(['integrations.google_places.mode' => 'live', 'integrations.google_places.api_key' => 'k-123']);
        $this->postJson('/api/prospecting/places/search', ['query' => 'celulares'])->assertStatus(422); // falta tope mensual
        config(['integrations.google_places.monthly_request_limit' => 1]);
        Http::fake(['places.googleapis.com/*' => Http::response(['places' => [[
            'id' => 'ChIJ123', 'displayName' => ['text' => 'iCenter'], 'formattedAddress' => 'Av. Colón 100', 'nationalPhoneNumber' => '0351 422-0000', 'googleMapsUri' => 'https://maps.google.com/?cid=1',
        ]]])]);
        $res = $this->postJson('/api/prospecting/places/search', ['query' => 'celulares'])->assertOk();
        $this->assertSame('ChIJ123', $res->json('results.0.external_id'));
        Http::assertSent(fn ($r) => $r->hasHeader('X-Goog-FieldMask', config('integrations.google_places.field_mask')) && $r['textQuery'] === 'celulares');
        $this->postJson('/api/prospecting/places/search', ['query' => 'celulares'])->assertStatus(422)->assertJsonFragment(['message' => 'Se alcanzó el tope mensual de consultas a Google Places configurado.']);
        Http::assertSentCount(1);

        // Pasarlo a prospecto conserva place_id y no permite duplicarlo
        $lead = ['business_name' => 'iCenter', 'phone' => '0351 422-0000', 'source' => 'google_places', 'external_id' => 'ChIJ123', 'source_url' => 'https://maps.google.com/?cid=1'];
        $this->postJson('/api/leads', $lead)->assertCreated();
        $this->postJson('/api/leads', ['phone' => null] + $lead)->assertStatus(422);
    }
}
