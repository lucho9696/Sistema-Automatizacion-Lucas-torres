<?php

namespace App\Domain\Crm;

use App\Domain\Shared\Auditor;
use App\Domain\Shared\BusinessRuleException;
use App\Models\ConsentRecord;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Support\Phone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class LeadService
{
    /** Transiciones permitidas (especificación §13.6). `no_contactar` siempre está permitido. */
    public const TRANSITIONS = [
        'nuevo' => ['por_verificar', 'calificado', 'descartado'],
        'por_verificar' => ['calificado', 'descartado'],
        'calificado' => ['contactado', 'descartado'],
        'contactado' => ['respondio', 'sin_respuesta'],
        'sin_respuesta' => ['contactado', 'sin_interes'],
        'respondio' => ['interesado', 'sin_interes'],
        'interesado' => ['cotizacion_enviada', 'sin_interes'],
        'cotizacion_enviada' => ['negociacion', 'sin_interes'],
        'negociacion' => ['cliente', 'sin_interes'],
        'cliente' => [],
        'sin_interes' => [],
        'descartado' => [],
        'no_contactar' => [],
    ];

    public const EXCLUDED_FROM_OUTREACH = ['no_contactar', 'sin_interes', 'descartado'];

    public function __construct(
        private LeadDeduplicator $dedupe,
        private Suppression $suppression,
        private Auditor $auditor,
    ) {}

    /**
     * Alta de prospecto con procedencia obligatoria.
     *
     * @return array{lead: Lead, possible_duplicates: Collection}
     */
    public function create(array $data, bool $allowPossibleDuplicates = true): array
    {
        $attrs = $this->normalize($data);
        if ($this->suppression->isSuppressed($attrs['phone_e164'] ?? null, $attrs['email'] ?? null)) {
            throw new BusinessRuleException('El contacto está en la lista de supresión (pidió no ser contactado).', 'phone');
        }
        if (! empty($attrs['external_id'])) {
            $same = Lead::where('source', $attrs['source'])->where('external_id', $attrs['external_id'])->first();
            if ($same) {
                throw new BusinessRuleException("Ya existe el prospecto #{$same->id} ({$same->business_name}) de la misma fuente.", 'duplicate');
            }
        }
        $duplicates = $this->dedupe->find($attrs['phone_e164'] ?? null, $attrs['domain'] ?? null, $attrs['name_key'] ?? null, $attrs['city'] ?? null);
        $strong = $duplicates->firstWhere('strength', 'fuerte');
        if ($strong) {
            throw new BusinessRuleException(
                "Ya existe el prospecto #{$strong['id']} ({$strong['business_name']}) con el mismo {$strong['match']}.", 'duplicate'
            );
        }
        if (! $allowPossibleDuplicates && $duplicates->isNotEmpty()) {
            throw new BusinessRuleException('Hay prospectos con el mismo nombre en la ciudad.', 'duplicate');
        }

        return DB::transaction(function () use ($attrs, $data, $duplicates) {
            $lead = Lead::create($attrs + [
                'captured_at' => $data['captured_at'] ?? now(),
                // Las altas automáticas (búsquedas programadas) entran "por verificar".
                'status' => ($data['status'] ?? null) === 'por_verificar' ? 'por_verificar' : 'nuevo',
                'owner_id' => $data['owner_id'] ?? Auth::id(),
            ]);
            $this->auditor->record('crm.prospecto_creado', $lead, null, ['source' => $lead->source, 'source_url' => $lead->source_url]);

            return ['lead' => $lead, 'possible_duplicates' => $duplicates];
        });
    }

    /** Corrección de datos (derecho de rectificación), auditada. */
    public function update(Lead $lead, array $data): Lead
    {
        $this->assertNotErased($lead);
        $attrs = $this->normalize($data);
        $merged = array_merge($lead->only(['business_name', 'website', 'email']), $attrs);
        $attrs['name_key'] = LeadDeduplicator::nameKey($merged['business_name']);
        $attrs['domain'] = LeadDeduplicator::domain($merged['website'], $merged['email']);
        if (! empty($attrs['phone_e164'])) {
            $dup = $this->dedupe->find($attrs['phone_e164'], null, null, null, $lead->id)->first();
            if ($dup) {
                throw new BusinessRuleException("El teléfono ya pertenece al prospecto #{$dup['id']}. Fusione los registros.", 'phone');
            }
        }
        $old = $lead->only(array_keys($attrs));
        $lead->update($attrs);
        $this->auditor->record('crm.prospecto_corregido', $lead, $old, $attrs);

        return $lead;
    }

    public function changeStatus(Lead $lead, string $to, ?string $note = null, ?string $contactBasis = null): Lead
    {
        return DB::transaction(function () use ($lead, $to, $note, $contactBasis) {
            $lead = Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();
            $from = $lead->status;
            if ($from === $to) {
                return $lead;
            }
            if ($to !== 'no_contactar' && ! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                throw new BusinessRuleException("Transición no permitida: {$from} → {$to}.", 'status');
            }
            if ($to === 'cliente') {
                throw new BusinessRuleException('Para pasar a cliente use "Convertir en cliente".', 'status');
            }
            if ($to === 'contactado') {
                if ($this->suppression->isSuppressed($lead->phone_e164, $lead->email)) {
                    throw new BusinessRuleException('El contacto está en la lista de supresión.', 'status');
                }
                $hasConsent = $this->activeConsentChannels($lead)->isNotEmpty();
                if (! $hasConsent && ! $contactBasis) {
                    throw new BusinessRuleException(
                        'Para marcar como contactado registre un permiso de contacto o indique la base del contacto (p. ej. visita al local, llamada al teléfono comercial publicado).',
                        'contact_basis'
                    );
                }
            }
            if ($to === 'no_contactar') {
                $this->suppression->add('phone', $lead->phone_e164, 'global', $note ?? 'Marcado no_contactar');
                $this->suppression->add('email', $lead->email, 'global', $note ?? 'Marcado no_contactar');
                $lead->exclusion_reason = $note;
            }
            $lead->status = $to;
            $lead->save();
            $this->activity($lead, 'cambio_estado', "{$from} → {$to}", trim(($note ?? '').($contactBasis ? " Base del contacto: {$contactBasis}" : '')) ?: null);
            $this->auditor->record('crm.prospecto_estado', $lead, ['status' => $from], ['status' => $to], $note ?? $contactBasis);

            return $lead;
        });
    }

    public function activity(Lead $lead, string $type, ?string $outcome = null, ?string $notes = null, ?string $nextStep = null, ?string $nextStepAt = null, ?string $occurredAt = null): LeadActivity
    {
        return $lead->activities()->create([
            'user_id' => Auth::id(),
            'type' => $type,
            'occurred_at' => $occurredAt ?? now(),
            'outcome' => $outcome,
            'notes' => $notes,
            'next_step' => $nextStep,
            'next_step_at' => $nextStepAt,
        ]);
    }

    /** Registro de permiso (o revocación). Inmutable: una revocación es un registro nuevo. */
    public function recordConsent(Lead $lead, string $channel, bool $granted, string $method, ?string $evidence): ConsentRecord
    {
        $this->assertNotErased($lead);
        if ($granted && $this->suppression->isSuppressed($lead->phone_e164, $lead->email, $channel)) {
            throw new BusinessRuleException('El contacto está en la lista de supresión; un nuevo permiso debe gestionarlo un administrador quitando la supresión.', 'channel');
        }
        $record = ConsentRecord::create([
            'lead_id' => $lead->id,
            'customer_id' => $lead->customer_id,
            'channel' => $channel,
            'granted' => $granted,
            'method' => $method,
            'evidence' => $evidence,
            'user_id' => Auth::id(),
            'recorded_at' => now(),
        ]);
        if (! $granted) {
            $this->suppression->add('phone', $lead->phone_e164, $channel, 'Baja de '.$channel);
            $this->suppression->add('email', $lead->email, $channel, 'Baja de '.$channel);
        }
        $this->auditor->record($granted ? 'crm.permiso_otorgado' : 'crm.permiso_revocado', $lead, null, ['channel' => $channel, 'method' => $method]);

        return $record;
    }

    /** Canales con permiso vigente (el último registro del canal es un otorgamiento). */
    public function activeConsentChannels(Lead $lead): Collection
    {
        return ConsentRecord::query()->where('lead_id', $lead->id)
            ->orderByDesc('recorded_at')->orderByDesc('id')->get()
            ->unique('channel')->where('granted', true)->pluck('channel')->values();
    }

    /** Fusión manual: $source queda marcado como duplicado de $target; nada se borra. */
    public function merge(Lead $source, Lead $target): Lead
    {
        if ($source->id === $target->id) {
            throw new BusinessRuleException('No se puede fusionar un prospecto consigo mismo.');
        }
        if ($target->duplicate_of_id) {
            throw new BusinessRuleException("El destino ya fue fusionado en #{$target->duplicate_of_id}.");
        }

        return DB::transaction(function () use ($source, $target) {
            $fill = [];
            foreach (['contact_name', 'phone_e164', 'email', 'website', 'domain', 'city', 'province', 'business_type', 'interest_category', 'estimated_volume'] as $f) {
                if (empty($target->{$f}) && ! empty($source->{$f})) {
                    $fill[$f] = $source->{$f};
                }
            }
            LeadActivity::where('lead_id', $source->id)->update(['lead_id' => $target->id]);
            ConsentRecord::where('lead_id', $source->id)->update(['lead_id' => $target->id]);
            $source->update(['duplicate_of_id' => $target->id, 'phone_e164' => null, 'email' => null, 'domain' => null]);
            $target->update($fill);
            $this->activity($target, 'fusion', "Fusionado #{$source->id}", "Origen del duplicado: {$source->source}".($source->source_url ? " ({$source->source_url})" : '').', capturado '.$source->captured_at->toDateString());
            $this->auditor->record('crm.prospectos_fusionados', $target, ['merged_id' => $source->id], $fill);

            return $target->fresh();
        });
    }

    public function convertToCustomer(Lead $lead): Customer
    {
        $this->assertNotErased($lead);
        if (in_array($lead->status, ['no_contactar', 'descartado'], true)) {
            throw new BusinessRuleException("Un prospecto en estado {$lead->status} no se convierte en cliente.");
        }

        return DB::transaction(function () use ($lead) {
            $customer = ($lead->phone_e164 ? Customer::where('phone_e164', $lead->phone_e164)->first() : null)
                ?? Customer::create([
                    'name' => $lead->contact_name ?: $lead->business_name,
                    'business_name' => $lead->business_name,
                    'phone_e164' => $lead->phone_e164,
                    'email' => $lead->email,
                    'city' => $lead->city,
                    'province' => $lead->province,
                    'country' => $lead->country,
                    'type' => 'mayorista',
                    'source' => "prospecto #{$lead->id} ({$lead->source})",
                ]);
            $old = $lead->status;
            $lead->update(['status' => 'cliente', 'customer_id' => $customer->id]);
            ConsentRecord::where('lead_id', $lead->id)->update(['customer_id' => $customer->id]);
            $this->activity($lead, 'cambio_estado', "{$old} → cliente", "Cliente #{$customer->id}");
            $this->auditor->record('crm.prospecto_convertido', $lead, ['status' => $old], ['status' => 'cliente', 'customer_id' => $customer->id]);

            return $customer;
        });
    }

    /**
     * Supresión de datos personales (Ley 25.326): borra datos de contacto y notas, conserva
     * solo el hash en la lista de supresión para no volver a contactar, y la traza anonimizada.
     */
    public function erase(Lead $lead, string $reason): Lead
    {
        return DB::transaction(function () use ($lead, $reason) {
            $this->suppression->add('phone', $lead->phone_e164, 'global', 'Supresión de datos');
            $this->suppression->add('email', $lead->email, 'global', 'Supresión de datos');
            LeadActivity::where('lead_id', $lead->id)->update(['notes' => null, 'next_step' => null]);
            $lead->update([
                'contact_name' => null, 'phone_e164' => null, 'email' => null, 'notes' => null,
                'status' => 'no_contactar', 'exclusion_reason' => 'Supresión de datos solicitada', 'erased_at' => now(),
            ]);
            $this->auditor->record('crm.datos_suprimidos', $lead, null, null, $reason);

            return $lead;
        });
    }

    /**
     * Lista para revisión humana (no envía nada): prospectos con permiso vigente del canal,
     * fuera de supresión y de estados excluidos.
     */
    public function outreachList(string $channel): Collection
    {
        return Lead::query()
            ->whereNull('duplicate_of_id')->whereNull('erased_at')
            ->whereNotIn('status', self::EXCLUDED_FROM_OUTREACH)
            ->whereExists(fn ($q) => $q->from('consent_records')->whereColumn('consent_records.lead_id', 'leads.id')->where('channel', $channel))
            ->orderBy('business_name')
            ->get()
            ->filter(fn (Lead $l) => $this->activeConsentChannels($l)->contains($channel))
            ->reject(fn (Lead $l) => $this->suppression->isSuppressed($l->phone_e164, $l->email, $channel))
            ->values();
    }

    private function normalize(array $data): array
    {
        $out = [];
        foreach (['business_name', 'business_type', 'contact_name', 'city', 'province', 'country', 'email', 'website', 'source', 'source_url', 'external_id', 'interest_category', 'estimated_volume', 'notes', 'last_verified_at', 'lead_import_id'] as $f) {
            if (array_key_exists($f, $data)) {
                $out[$f] = is_string($data[$f]) ? (trim($data[$f]) ?: null) : $data[$f];
            }
        }
        if (isset($out['email'])) {
            $out['email'] = strtolower($out['email']);
        }
        if (array_key_exists('phone', $data)) {
            $out['phone_e164'] = Phone::normalize($data['phone']);
        }
        $out['name_key'] = LeadDeduplicator::nameKey($out['business_name'] ?? null);
        $out['domain'] = LeadDeduplicator::domain($out['website'] ?? null, $out['email'] ?? null);

        return $out;
    }

    private function assertNotErased(Lead $lead): void
    {
        if ($lead->erased_at) {
            throw new BusinessRuleException('Los datos de este prospecto fueron suprimidos.');
        }
    }
}
