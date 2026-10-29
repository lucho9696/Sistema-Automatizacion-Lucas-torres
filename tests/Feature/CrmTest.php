<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\SuppressionEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CrmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function lead(array $over = []): Lead
    {
        $res = $this->postJson('/api/leads', $over + [
            'business_name' => 'Celulares Centro SRL', 'city' => 'Córdoba', 'phone' => '0351 15 123-4567',
            'source' => 'visita', 'website' => 'https://www.celularescentro.com.ar',
        ])->assertCreated();

        return Lead::findOrFail($res->json('lead.id'));
    }

    public function test_lead_keeps_source_and_capture_date_and_normalizes_phone(): void
    {
        $lead = $this->lead();
        $this->assertSame('visita', $lead->source);
        $this->assertNotNull($lead->captured_at);
        $this->assertSame('+543511234567', $lead->phone_e164);
        $this->assertSame('celularescentro.com.ar', $lead->domain);
        $this->assertSame('celulares centro', $lead->name_key);
        $this->postJson('/api/leads', ['business_name' => 'Sin fuente'])->assertStatus(422)->assertJsonValidationErrors('source');
    }

    public function test_duplicates_are_detected_without_deleting_provenance(): void
    {
        $first = $this->lead();
        // Mismo teléfono en otro formato => duplicado fuerte, rechazado
        $this->postJson('/api/leads', ['business_name' => 'Otro nombre', 'phone' => '+54 9 351 123 4567', 'source' => 'csv'])
            ->assertStatus(422)->assertJsonValidationErrors('duplicate');
        // Mismo dominio por email corporativo
        $this->postJson('/api/leads', ['business_name' => 'X', 'email' => 'ventas@celularescentro.com.ar', 'source' => 'web'])->assertStatus(422);
        // Mismo nombre en la ciudad => se crea pero se advierte
        $res = $this->postJson('/api/leads', ['business_name' => 'Celulares Centro S.R.L.', 'city' => 'córdoba', 'source' => 'referido'])->assertCreated();
        $this->assertSame($first->id, $res->json('possible_duplicates.0.id'));

        // Fusión manual conserva el registro y la procedencia del duplicado
        $dupId = $res->json('lead.id');
        $this->postJson("/api/leads/{$dupId}/merge", ['into_id' => $first->id])->assertOk();
        $dup = Lead::findOrFail($dupId);
        $this->assertSame($first->id, $dup->duplicate_of_id);
        $this->assertSame('referido', $dup->source);
        $this->assertNotContains($dupId, $this->getJson('/api/leads')->json('data.*.id'));
        $this->assertStringContainsString('referido', $first->activities()->where('type', 'fusion')->first()->notes);
    }

    public function test_status_machine_and_contact_requires_basis_or_consent(): void
    {
        $lead = $this->lead();
        $this->postJson("/api/leads/{$lead->id}/status", ['status' => 'interesado'])->assertStatus(422);
        $this->postJson("/api/leads/{$lead->id}/status", ['status' => 'calificado'])->assertOk();
        $this->postJson("/api/leads/{$lead->id}/status", ['status' => 'contactado'])->assertStatus(422)->assertJsonValidationErrors('contact_basis');
        $this->postJson("/api/leads/{$lead->id}/status", ['status' => 'contactado', 'contact_basis' => 'Visita al local'])->assertOk();
        $this->assertSame(2, $lead->activities()->where('type', 'cambio_estado')->count());
    }

    public function test_no_contactar_is_suppressed_and_excluded_from_every_list(): void
    {
        $a = $this->lead();
        $b = $this->lead(['business_name' => 'Tienda B', 'phone' => '011 4444-5555', 'website' => null]);
        foreach ([$a, $b] as $l) {
            $this->postJson("/api/leads/{$l->id}/consents", ['channel' => 'whatsapp', 'granted' => true, 'method' => 'formulario_web', 'evidence' => 'form #1'])->assertCreated();
        }
        $this->assertCount(2, $this->getJson('/api/leads/outreach-list?channel=whatsapp')->json('data'));

        $this->postJson("/api/leads/{$a->id}/status", ['status' => 'no_contactar', 'note' => 'Pidió baja'])->assertOk();
        $list = $this->getJson('/api/leads/outreach-list?channel=whatsapp')->json('data');
        $this->assertSame([$b->id], array_column($list, 'id'));
        // La supresión guarda solo un hash, no el teléfono
        $this->assertFalse(SuppressionEntry::where('identifier_hash', 'like', '%3511234567%')->exists());
        // No se puede volver a cargar ni dar permiso
        $this->postJson('/api/leads', ['business_name' => 'Reintento', 'phone' => '351 1234567', 'source' => 'csv'])->assertStatus(422);
        $this->postJson("/api/leads/{$a->id}/status", ['status' => 'calificado'])->assertStatus(422);
    }

    public function test_revoked_consent_removes_from_channel_list(): void
    {
        $lead = $this->lead();
        $this->postJson("/api/leads/{$lead->id}/consents", ['channel' => 'whatsapp', 'granted' => true, 'method' => 'whatsapp_entrante'])->assertCreated();
        $this->postJson("/api/leads/{$lead->id}/consents", ['channel' => 'whatsapp', 'granted' => false, 'method' => 'whatsapp_entrante', 'evidence' => 'Escribió BAJA'])->assertCreated();
        $this->assertSame(0, $this->getJson('/api/leads/outreach-list?channel=whatsapp')->json('count'));
        $this->postJson("/api/leads/{$lead->id}/consents", ['channel' => 'whatsapp', 'granted' => true, 'method' => 'otro'])->assertStatus(422);
        // Sin permiso de WhatsApp no entra aunque esté calificado
        $other = $this->lead(['business_name' => 'Sin permiso', 'phone' => '0351 4000000', 'website' => null]);
        $this->postJson("/api/leads/{$other->id}/status", ['status' => 'calificado'])->assertOk();
        $this->assertSame(0, $this->getJson('/api/leads/outreach-list?channel=whatsapp')->json('count'));
    }

    public function test_convert_to_customer_and_erase_personal_data(): void
    {
        $lead = $this->lead(['contact_name' => 'Juan Pérez']);
        $customerId = $this->postJson("/api/leads/{$lead->id}/convert")->assertCreated()->json('id');
        $this->assertSame('cliente', $lead->fresh()->status);
        $this->assertStringContainsString("prospecto #{$lead->id} (visita)", Customer::find($customerId)->source);

        $other = $this->lead(['business_name' => 'Borrar', 'phone' => '0351 4111111', 'website' => null, 'contact_name' => 'Ana']);
        $this->postJson("/api/leads/{$other->id}/erase", ['reason' => 'Solicitud del titular'])->assertOk();
        $other->refresh();
        $this->assertNull($other->contact_name);
        $this->assertNull($other->phone_e164);
        $this->assertSame('no_contactar', $other->status);
        $this->postJson('/api/leads', ['business_name' => 'Borrar', 'phone' => '0351 4111111', 'source' => 'csv'])->assertStatus(422);
    }

    public function test_csv_import_with_dry_run_dedupe_and_suppression(): void
    {
        $this->lead();
        $this->postJson('/api/suppressions', ['phone' => '0351 4999999', 'scope' => 'global', 'reason' => 'Pidió no ser contactado'])->assertCreated();
        $csv = "nombre_comercial;ciudad;telefono;email;consentimiento_whatsapp;metodo_consentimiento\n"
            ."Nuevo Local;Córdoba;0351 4222222;;si;formulario_web\n"
            ."Repetido;Córdoba;351 1234567;;;\n"
            ."Suprimido;Córdoba;0351 4999999;;;\n"
            .";Córdoba;;;;\n"
            ."Consent sin método;Córdoba;0351 4333333;;si;\n";
        $file = fn () => UploadedFile::fake()->createWithContent('lista.csv', $csv);

        $dry = $this->post('/api/leads/import', ['file' => $file(), 'source' => 'csv', 'dry_run' => 1], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(['creado' => 1, 'duplicado' => 1, 'suprimido' => 1, 'error' => 2], $dry->json('counts'));
        $this->assertSame(1, Lead::count(), 'dry run no guarda');

        $real = $this->post('/api/leads/import', ['file' => $file(), 'source' => 'csv'], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(1, $real->json('counts.creado'));
        $created = Lead::where('business_name', 'Nuevo Local')->firstOrFail();
        $this->assertSame('csv', $created->source);
        $this->assertNotNull($created->lead_import_id);
        $this->assertSame(1, $this->getJson('/api/leads/outreach-list?channel=whatsapp')->json('count'));
    }

    public function test_permissions(): void
    {
        $lead = $this->lead();
        Sanctum::actingAs(User::factory()->role('vendedor')->create());
        $this->getJson('/api/leads')->assertOk();
        $this->postJson("/api/leads/{$lead->id}/erase", ['reason' => 'x'])->assertForbidden();
        $this->postJson('/api/suppressions', ['phone' => '1', 'scope' => 'global', 'reason' => 'x'])->assertForbidden();
        $this->postJson('/api/prospecting/places/search', ['query' => 'celulares'])->assertForbidden();
    }
}
