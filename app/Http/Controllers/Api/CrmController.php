<?php

namespace App\Http\Controllers\Api;

use App\Domain\Crm\LeadCsvImporter;
use App\Domain\Crm\LeadDeduplicator;
use App\Domain\Crm\LeadService;
use App\Domain\Crm\Suppression;
use App\Domain\Prospecting\PlacesSearch;
use App\Domain\Shared\Auditor;
use App\Http\Controllers\Controller;
use App\Models\ConsentRecord;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CrmController extends Controller
{
    private const SOURCES = ['manual', 'csv', 'google_places', 'referido', 'visita', 'web', 'redes', 'evento', 'otro'];

    private const ACTIVITY_TYPES = ['llamada', 'whatsapp', 'email', 'visita', 'reunion', 'nota'];

    public function __construct(private LeadService $leads, private LeadDeduplicator $dedupe) {}

    public function index(Request $request)
    {
        Gate::authorize('crm.manage');

        return Lead::query()
            ->with('owner:id,name')
            ->when(! $request->boolean('include_merged'), fn ($q) => $q->whereNull('duplicate_of_id'))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('source'), fn ($q, $s) => $q->where('source', $s))
            ->when($request->query('city'), fn ($q, $c) => $q->where('city', 'ilike', "%{$c}%"))
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w
                ->where('business_name', 'ilike', "%{$t}%")->orWhere('contact_name', 'ilike', "%{$t}%")
                ->orWhere('phone_e164', 'like', '%'.preg_replace('/\D/', '', $t).'%')))
            ->orderByDesc('updated_at')
            ->paginate(min((int) $request->query('per_page', 50), 200));
    }

    public function pipeline()
    {
        Gate::authorize('crm.manage');
        $counts = Lead::whereNull('duplicate_of_id')->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');

        return collect(Lead::STATUSES)->map(fn ($s) => ['status' => $s, 'count' => (int) ($counts[$s] ?? 0)]);
    }

    public function store(Request $request)
    {
        Gate::authorize('crm.manage');
        $data = $this->validated($request);
        $result = $this->leads->create($data);

        return response()->json(['lead' => $result['lead'], 'possible_duplicates' => $result['possible_duplicates']], 201);
    }

    public function show(Lead $lead)
    {
        Gate::authorize('crm.manage');
        $lead->load(['activities.user:id,name', 'consents', 'owner:id,name', 'customer:id,name', 'duplicateOf:id,business_name']);

        return [
            'lead' => $lead,
            'active_consent_channels' => $this->leads->activeConsentChannels($lead),
            'allowed_transitions' => array_values(array_unique(array_merge(LeadService::TRANSITIONS[$lead->status] ?? [], $lead->status === 'no_contactar' ? [] : ['no_contactar']))),
            'possible_duplicates' => $lead->duplicate_of_id ? [] : $this->dedupe->find($lead->phone_e164, $lead->domain, $lead->name_key, $lead->city, $lead->id),
        ];
    }

    public function update(Request $request, Lead $lead)
    {
        Gate::authorize('crm.manage');

        return $this->leads->update($lead, $this->validated($request, partial: true));
    }

    public function changeStatus(Request $request, Lead $lead)
    {
        Gate::authorize('crm.manage');
        $data = $request->validate([
            'status' => ['required', Rule::in(Lead::STATUSES)],
            'note' => ['nullable', 'string', 'max:255'],
            'contact_basis' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->leads->changeStatus($lead, $data['status'], $data['note'] ?? null, $data['contact_basis'] ?? null);
    }

    public function storeActivity(Request $request, Lead $lead)
    {
        Gate::authorize('crm.manage');
        $data = $request->validate([
            'type' => ['required', Rule::in(self::ACTIVITY_TYPES)],
            'occurred_at' => ['nullable', 'date'],
            'outcome' => ['nullable', 'string', 'max:255'],
            'next_step' => ['nullable', 'string', 'max:255'],
            'next_step_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json($this->leads->activity($lead, $data['type'], $data['outcome'] ?? null, $data['notes'] ?? null, $data['next_step'] ?? null, $data['next_step_at'] ?? null, $data['occurred_at'] ?? null), 201);
    }

    public function storeConsent(Request $request, Lead $lead)
    {
        Gate::authorize('crm.manage');
        $data = $request->validate([
            'channel' => ['required', Rule::in(ConsentRecord::CHANNELS)],
            'granted' => ['required', 'boolean'],
            'method' => ['required', Rule::in(ConsentRecord::METHODS)],
            'evidence' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->leads->recordConsent($lead, $data['channel'], $data['granted'], $data['method'], $data['evidence'] ?? null), 201);
    }

    public function merge(Request $request, Lead $lead)
    {
        Gate::authorize('crm.manage');
        $data = $request->validate(['into_id' => ['required', 'integer', 'exists:leads,id']]);

        return $this->leads->merge($lead, Lead::findOrFail($data['into_id']));
    }

    public function convert(Lead $lead)
    {
        Gate::authorize('crm.manage');
        Gate::authorize('customers.manage');

        return response()->json($this->leads->convertToCustomer($lead), 201);
    }

    public function erase(Request $request, Lead $lead)
    {
        Gate::authorize('crm.erase');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->leads->erase($lead, $data['reason']);
    }

    public function import(Request $request, LeadCsvImporter $importer)
    {
        Gate::authorize('crm.manage');
        $data = $request->validate([
            'file' => ['required', 'file', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel', 'max:5120'],
            'source' => ['required', Rule::in(self::SOURCES)],
            'dry_run' => ['boolean'],
        ]);
        $file = $request->file('file');

        return $importer->import($file->getRealPath(), $file->getClientOriginalName(), $data['source'], $request->boolean('dry_run'));
    }

    /** Lista para revisión humana; no envía mensajes. */
    public function outreachList(Request $request): mixed
    {
        Gate::authorize('crm.manage');
        $data = $request->validate(['channel' => ['required', Rule::in(ConsentRecord::CHANNELS)], 'format' => ['nullable', Rule::in(['json', 'csv'])]]);
        $rows = $this->leads->outreachList($data['channel'])->map(fn (Lead $l) => $l->only(['id', 'business_name', 'contact_name', 'city', 'phone_e164', 'email', 'status']));
        app(Auditor::class)->record('crm.lista_contacto_generada', null, null, ['channel' => $data['channel'], 'count' => $rows->count()]);
        if (($data['format'] ?? 'json') === 'json') {
            return ['channel' => $data['channel'], 'count' => $rows->count(), 'note' => 'Lista para revisión humana. El sistema no envía mensajes.', 'data' => $rows];
        }

        return new StreamedResponse(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['id', 'nombre_comercial', 'contacto', 'ciudad', 'telefono', 'email', 'estado'], escape: '');
            foreach ($rows as $r) {
                fputcsv($out, array_map(fn ($v) => preg_match('/^[=+\-@]/', (string) $v) && ! is_numeric($v) ? "'".$v : $v, array_values($r)), escape: '');
            }
            fclose($out);
        }, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="lista_'.$data['channel'].'.csv"']);
    }

    /** Baja manual de alguien que no está en el CRM (p. ej. pidió no ser contactado). */
    public function suppress(Request $request, Suppression $suppression)
    {
        Gate::authorize('crm.suppress');
        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:40', 'required_without:email'],
            'email' => ['nullable', 'email'],
            'scope' => ['required', Rule::in(Suppression::SCOPES)],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $suppression->add('phone', $data['phone'] ?? null, $data['scope'], $data['reason']);
        $suppression->add('email', $data['email'] ?? null, $data['scope'], $data['reason']);
        app(Auditor::class)->record('crm.supresion_manual', null, null, ['scope' => $data['scope']], $data['reason']);

        return response()->json(['message' => 'Agregado a la lista de supresión.'], 201);
    }

    public function placesStatus()
    {
        Gate::authorize('prospecting.places');

        return PlacesSearch::status();
    }

    public function placesSearch(Request $request, PlacesSearch $places)
    {
        Gate::authorize('prospecting.places');
        $data = $request->validate(['query' => ['required', 'string', 'min:3', 'max:120']]);
        $result = $places->search($data['query']);
        // Marcar los que ya existen como prospecto (por place_id).
        $existing = Lead::where('source', 'google_places')->whereIn('external_id', array_column($result['results'], 'external_id'))->pluck('id', 'external_id');
        $result['results'] = array_map(fn ($r) => $r + ['lead_id' => $existing[$r['external_id']] ?? null], $result['results']);

        return $result;
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'business_name' => [$req, 'string', 'max:160'],
            'business_type' => ['nullable', 'string', 'max:60'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'province' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'size:2'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email'],
            'website' => ['nullable', 'string', 'max:255'],
            'source' => [$partial ? 'prohibited' : 'required', Rule::in(self::SOURCES)],
            'source_url' => ['nullable', 'url', 'max:500'],
            'external_id' => ['nullable', 'string', 'max:190'],
            'interest_category' => ['nullable', 'string', 'max:120'],
            'estimated_volume' => ['nullable', 'string', 'max:120'],
            'last_verified_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
