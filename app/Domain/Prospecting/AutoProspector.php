<?php

namespace App\Domain\Prospecting;

use App\Domain\Crm\LeadService;
use App\Domain\Integrations\IntegrationException;
use App\Domain\Shared\BusinessRuleException;
use App\Models\ProspectingSearch;

/**
 * Corre las búsquedas programadas de comercios y da de alta los resultados como prospectos
 * "por verificar", pasando por la deduplicación y la lista de supresión del CRM.
 *
 * En modo demo no se guarda ningún prospecto: solo se informa qué se habría creado.
 * Nunca agrega a nadie a una lista de envío (eso exige consentimiento registrado).
 */
class AutoProspector
{
    public function __construct(private PlacesSearch $places, private LeadService $leads) {}

    /** @return int búsquedas ejecutadas */
    public function runDue(): int
    {
        $due = ProspectingSearch::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', now()))
            ->orderBy('next_run_at')->get();
        foreach ($due as $search) {
            $this->run($search);
        }

        return $due->count();
    }

    public function run(ProspectingSearch $search): ProspectingSearch
    {
        $text = $search->textQuery();
        try {
            $result = $this->places->search($text);
        } catch (IntegrationException $e) {
            // Sin cuota, apagado o error de Google: se reintenta al día siguiente.
            $search->update(['last_run_at' => now(), 'last_error' => mb_substr($e->getMessage(), 0, 300), 'next_run_at' => now()->addDay()]);

            return $search;
        }

        $created = $skipped = 0;
        $preview = [];
        foreach ($result['results'] as $place) {
            if ($result['mode'] !== 'live') {
                $preview[] = ['name' => $place['business_name'], 'outcome' => 'demo: no se guarda'];

                continue;
            }
            try {
                $this->leads->create([
                    'business_name' => $place['business_name'],
                    'phone' => $place['phone'],
                    'website' => $place['website'],
                    'city' => $search->city,
                    'province' => $search->province,
                    'source' => 'google_places',
                    'external_id' => $place['external_id'],
                    'source_url' => $place['source_url'],
                    'notes' => trim(($place['address'] ?? '')."\nBúsqueda automática: {$text}"),
                    'status' => 'por_verificar',
                ]);
                $created++;
                $preview[] = ['name' => $place['business_name'], 'outcome' => 'creado'];
            } catch (BusinessRuleException $e) {
                $skipped++;
                $preview[] = ['name' => $place['business_name'], 'outcome' => $e->field === 'phone' ? 'en lista de no contactar' : 'ya existía'];
            }
        }

        $search->update([
            'last_run_at' => now(),
            'last_mode' => $result['mode'],
            'last_found' => count($result['results']),
            'last_created' => $created,
            'last_skipped' => $skipped,
            'last_error' => null,
            'last_preview' => array_slice($preview, 0, 50),
            'total_created' => $search->total_created + $created,
            'next_run_at' => now()->addDays($search->frequency_days),
        ]);

        return $search;
    }
}
