<?php

namespace Tests\Feature;

use App\Domain\Crm\Suppression;
use App\Models\Lead;
use App\Models\ProspectingSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProspectingSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        Http::preventStrayRequests();
    }

    private function createSearch(): ProspectingSearch
    {
        $id = $this->postJson('/api/prospecting/searches', ['query' => 'venta de celulares', 'city' => 'Córdoba', 'frequency_days' => 7])
            ->assertCreated()->json('id');

        return ProspectingSearch::findOrFail($id);
    }

    private function live(int $limit = 10): void
    {
        config([
            'integrations.google_places.mode' => 'live',
            'integrations.google_places.api_key' => 'test-key',
            'integrations.google_places.monthly_request_limit' => $limit,
        ]);
    }

    public function test_demo_mode_reports_without_creating_leads(): void
    {
        config(['integrations.google_places.mode' => 'demo']);
        $search = $this->createSearch();
        $this->postJson("/api/prospecting/searches/{$search->id}/run")->assertOk()
            ->assertJsonPath('last_mode', 'demo')->assertJsonPath('last_found', 3)->assertJsonPath('last_created', 0);
        $this->assertSame(0, Lead::count());
        $this->assertStringStartsWith('[DEMO]', $search->fresh()->last_preview[0]['name']);
    }

    public function test_live_run_creates_leads_to_verify_with_dedupe_and_suppression(): void
    {
        $this->live();
        app(Suppression::class)->add('phone', '+543514000002', 'global', 'Pidió no ser contactado');
        Lead::create(['business_name' => 'Ya cargado', 'phone_e164' => '+543514000003', 'source' => 'manual', 'captured_at' => now(), 'status' => 'nuevo']);
        Http::fake(['places.googleapis.com/*' => Http::response(['places' => [
            ['id' => 'p1', 'displayName' => ['text' => 'Celulares Centro'], 'formattedAddress' => 'Av. Colón 100, Córdoba', 'nationalPhoneNumber' => '0351 400-0001', 'googleMapsUri' => 'https://maps.google.com/?cid=1'],
            ['id' => 'p2', 'displayName' => ['text' => 'Suprimido SA'], 'nationalPhoneNumber' => '0351 400-0002'],
            ['id' => 'p3', 'displayName' => ['text' => 'Repetido'], 'nationalPhoneNumber' => '0351 400-0003'],
        ]])]);

        $search = $this->createSearch();
        $this->artisan('app:prospecting-run')->assertExitCode(0);
        Http::assertSent(fn ($r) => $r->hasHeader('X-Goog-FieldMask') && $r['textQuery'] === 'venta de celulares en Córdoba');

        $search->refresh();
        $this->assertSame([3, 1, 2], [$search->last_found, $search->last_created, $search->last_skipped]);
        $this->assertTrue($search->next_run_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));
        $lead = Lead::where('external_id', 'p1')->firstOrFail();
        $this->assertSame(['por_verificar', 'google_places', '+543514000001', 'Córdoba'], [$lead->status, $lead->source, $lead->phone_e164, $lead->city]);
        $this->assertSame(['creado', 'en lista de no contactar', 'ya existía'], array_column($search->last_preview, 'outcome'));

        // No está vencida: el comando no la vuelve a correr. Forzada, no duplica.
        $this->artisan('app:prospecting-run')->expectsOutputToContain('0 búsqueda');
        $this->postJson("/api/prospecting/searches/{$search->id}/run")->assertOk()->assertJsonPath('last_created', 0);
        $this->assertSame(2, Lead::count());
    }

    public function test_errors_and_monthly_cap_retry_next_day_without_leads(): void
    {
        $search = $this->createSearch();
        $this->postJson("/api/prospecting/searches/{$search->id}/run")->assertOk(); // modo off
        $search->refresh();
        $this->assertStringContainsString('desactivado', $search->last_error);
        $this->assertTrue($search->next_run_at->gt(now()->addHours(23)));

        $this->live(1);
        Http::fake(['places.googleapis.com/*' => Http::response(['places' => []])]);
        $this->postJson("/api/prospecting/searches/{$search->id}/run")->assertOk()->assertJsonPath('last_error', null);
        $this->postJson("/api/prospecting/searches/{$search->id}/run")->assertOk()
            ->assertJsonPath('last_error', 'Se alcanzó el tope mensual de consultas a Google Places configurado.');
        Http::assertSentCount(1);
        $this->assertSame(0, Lead::count());
    }

    public function test_validation_and_permissions(): void
    {
        $this->createSearch();
        $this->postJson('/api/prospecting/searches', ['query' => 'venta de celulares', 'city' => 'Córdoba', 'frequency_days' => 7])->assertStatus(422);
        $this->postJson('/api/prospecting/searches', ['query' => 'accesorios', 'city' => 'Rosario', 'frequency_days' => 3])->assertStatus(422);

        Sanctum::actingAs(User::factory()->create(['role' => 'vendedor']));
        $this->getJson('/api/prospecting/searches')->assertForbidden();
    }
}
