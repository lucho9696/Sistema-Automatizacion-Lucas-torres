<?php

namespace App\Http\Controllers\Api;

use App\Domain\Radar\CandidateScorer;
use App\Domain\Radar\TrendService;
use App\Domain\Shared\Auditor;
use App\Http\Controllers\Controller;
use App\Models\TrendCandidate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class RadarController extends Controller
{
    public function __construct(private TrendService $trends, private CandidateScorer $scorer, private Auditor $auditor) {}

    public function index(Request $request)
    {
        Gate::authorize('radar.view');

        return TrendCandidate::query()
            ->withCount(['snapshots', 'suppliers'])
            ->when(! $request->boolean('include_demo'), fn ($q) => $q->where('is_demo', false))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('q'), fn ($q, $t) => $q->where('name', 'ilike', "%{$t}%"))
            ->orderByRaw('score DESC NULLS LAST')->orderByDesc('last_seen_at')
            ->paginate(min((int) $request->query('per_page', 50), 200));
    }

    public function show(TrendCandidate $candidate)
    {
        Gate::authorize('radar.view');
        $candidate->load(['snapshots' => fn ($q) => $q->limit(100), 'suppliers']);

        return [
            'candidate' => $candidate,
            'supplier_links' => TrendService::supplierLinks($candidate),
            'phone_supplier_checklist' => config('integrations.phone_supplier_checklist'),
        ];
    }

    public function update(Request $request, TrendCandidate $candidate)
    {
        Gate::authorize('radar.manage');
        $currencies = config('business.currencies');
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(TrendCandidate::STATUSES)],
            'est_buy_price' => ['nullable', 'decimal:0,2', 'gt:0'],
            'est_buy_currency' => ['nullable', Rule::in($currencies)],
            'est_landed_extra_pct' => ['nullable', 'decimal:0,2', 'min:0', 'max:500'],
            'est_sell_price' => ['nullable', 'decimal:0,2', 'gt:0'],
            'est_sell_currency' => ['nullable', Rule::in($currencies)],
            'est_exchange_rate' => ['nullable', 'decimal:0,6', 'gt:0'],
            'price_evidence' => ['nullable', 'string', 'max:255'],
            'competition_level' => ['nullable', Rule::in(['baja', 'media', 'alta'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if ((isset($data['est_buy_price']) || isset($data['est_sell_price'])) && empty($data['price_evidence']) && ! $candidate->price_evidence) {
            return response()->json(['message' => 'Indique de dónde salen los precios (price_evidence).', 'errors' => ['price_evidence' => ['Requerido al cargar precios.']]], 422);
        }
        $old = $candidate->only(array_keys($data));
        $candidate->update($data);
        $this->auditor->record('radar.candidato_modificado', $candidate, $old, $data);

        return $this->scorer->apply($candidate);
    }

    public function storeSupplier(Request $request, TrendCandidate $candidate)
    {
        Gate::authorize('radar.manage');
        $data = $request->validate([
            'supplier_name' => ['required', 'string', 'max:160'],
            'url' => ['nullable', 'url', 'max:500'],
            'price' => ['nullable', 'decimal:0,2', 'gt:0'],
            'currency' => ['nullable', 'required_with:price', Rule::in(config('business.currencies'))],
            'moq' => ['nullable', 'integer', 'min:1'],
            'shipping_terms' => ['nullable', 'string', 'max:255'],
            'consulted_at' => ['required', 'date'],
            'verified' => ['boolean'],
            'risk_note' => ['nullable', 'string', 'max:255'],
        ]);
        $row = $candidate->suppliers()->create($data + ['user_id' => Auth::id()]);
        $this->scorer->apply($candidate);

        return response()->json($row, 201);
    }

    public function storeSignal(Request $request)
    {
        Gate::authorize('radar.manage');
        $data = $request->validate([
            'keyword' => ['required', 'string', 'max:200', 'not_regex:/^\[DEMO\]/i'],
            'source' => ['required', 'string', 'max:40'],
            'url' => ['nullable', 'url', 'max:500'],
            'fetched_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);
        $snapshot = $this->trends->recordManual($data['keyword'], $data['source'], $data['url'] ?? null, $data['fetched_at'] ?? null);

        return response()->json($snapshot->load('candidate'), 201);
    }

    public function sync(Request $request)
    {
        Gate::authorize('radar.manage');
        $data = $request->validate(['category_id' => ['nullable', 'string', 'regex:/^[A-Z]{3}\d+$/']]);
        $run = $this->trends->sync($data['category_id'] ?? null);

        return response()->json($run, $run->status === 'success' ? 200 : 422);
    }
}
