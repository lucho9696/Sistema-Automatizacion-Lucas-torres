<?php

namespace App\Http\Controllers\Api;

use App\Domain\Prospecting\AutoProspector;
use App\Domain\Shared\Auditor;
use App\Http\Controllers\Controller;
use App\Models\ProspectingSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProspectingSearchController extends Controller
{
    public function __construct(private AutoProspector $prospector, private Auditor $auditor) {}

    public function index()
    {
        Gate::authorize('prospecting.places');

        return ProspectingSearch::orderBy('city')->orderBy('query')->get();
    }

    public function store(Request $request)
    {
        Gate::authorize('prospecting.places');
        $data = $request->validate([
            'query' => ['required', 'string', 'min:3', 'max:120'],
            'city' => ['required', 'string', 'min:2', 'max:80'],
            'province' => ['nullable', 'string', 'max:80'],
            'frequency_days' => ['required', Rule::in(ProspectingSearch::FREQUENCIES)],
        ]);
        $request->validate(['query' => [Rule::unique('prospecting_searches')->where('city', $data['city'])]],
            ['query.unique' => 'Ya hay una búsqueda con ese rubro en esa ciudad.']);
        $search = ProspectingSearch::create($data + ['created_by' => Auth::id(), 'next_run_at' => now()]);
        $this->auditor->record('prospeccion.busqueda_creada', $search, null, $data);

        return response()->json($search, 201);
    }

    public function update(Request $request, ProspectingSearch $search)
    {
        Gate::authorize('prospecting.places');
        $data = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'frequency_days' => ['sometimes', Rule::in(ProspectingSearch::FREQUENCIES)],
        ]);
        $old = $search->only(array_keys($data));
        $search->update($data);
        $this->auditor->record('prospeccion.busqueda_modificada', $search, $old, $data);

        return $search;
    }

    public function run(ProspectingSearch $search)
    {
        Gate::authorize('prospecting.places');

        return $this->prospector->run($search);
    }
}
