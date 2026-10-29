<?php

namespace App\Http\Controllers\Api;

use App\Domain\Prospecting\PlacesSearch;
use App\Domain\Radar\MercadoLibreAuth;
use App\Http\Controllers\Controller;
use App\Models\SyncRun;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** Estado de integraciones. Nunca devuelve secretos: solo si están configurados. */
class IntegrationController extends Controller
{
    public function status()
    {
        Gate::authorize('radar.view');
        $last = fn (string $provider) => SyncRun::where('provider', $provider)->latest('started_at')->first(['id', 'provider', 'mode', 'status', 'items_count', 'error', 'started_at', 'finished_at']);
        $lastOk = fn (string $provider) => SyncRun::where('provider', $provider)->where('status', 'success')->latest('started_at')->value('started_at');
        $provider = config('integrations.trends.provider');

        return [
            'mercadolibre_trends' => [
                'mode' => $provider === 'mercadolibre' ? 'live' : 'demo',
                'connected' => $provider === 'mercadolibre' && app(MercadoLibreAuth::class)->isConnected(),
                'missing_env' => $provider === 'mercadolibre' ? MercadoLibreAuth::missingConfig() : [],
                'last_run' => $last('mercadolibre_trends'),
                'last_success_at' => $lastOk('mercadolibre_trends'),
            ],
            'google_places' => PlacesSearch::status() + ['last_run' => $last('google_places')],
            'whatsapp' => ['mode' => 'no_conectado', 'note' => 'Hito 3'],
            'meta_ads' => ['mode' => 'no_conectado', 'note' => 'Hito 4'],
        ];
    }

    /** Paso 1 de OAuth: URL de autorización con `state` anti-CSRF guardado en sesión. */
    public function mercadolibreAuthorize(Request $request, MercadoLibreAuth $auth)
    {
        Gate::authorize('integrations.manage');
        $state = Str::random(40);
        $request->session()->put('ml_oauth_state', $state);

        return ['url' => $auth->authorizationUrl().'&state='.$state];
    }

    /** Paso 2: Mercado Libre redirige aquí (ML_REDIRECT_URI = APP_URL/integraciones/mercadolibre/callback). */
    public function mercadolibreCallback(Request $request, MercadoLibreAuth $auth)
    {
        Gate::authorize('integrations.manage');
        $expected = $request->session()->pull('ml_oauth_state');
        if (! $expected || ! hash_equals($expected, (string) $request->query('state')) || ! $request->query('code')) {
            return redirect('/integraciones?ml=error');
        }
        try {
            $auth->exchangeCode((string) $request->query('code'));
        } catch (\Throwable) {
            return redirect('/integraciones?ml=error');
        }

        return redirect('/integraciones?ml=ok');
    }
}
