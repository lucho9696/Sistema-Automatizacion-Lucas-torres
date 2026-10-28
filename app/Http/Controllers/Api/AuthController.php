<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shared\Auditor;
use App\Http\Controllers\Controller;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request, Auditor $auditor)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('web')->attempt($credentials + ['is_active' => true])) {
            throw ValidationException::withMessages(['email' => 'Credenciales inválidas.']);
        }
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        $auditor->record('auth.login');

        return $this->me($request);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    public function me(Request $request)
    {
        $user = $request->user() ?? Auth::guard('web')->user();
        $abilities = collect(array_keys(AppServiceProvider::ABILITIES))
            ->filter(fn ($a) => Gate::forUser($user)->allows($a))->values();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'abilities' => $abilities,
            'settings' => [
                'base_currency' => config('business.base_currency'),
                'currencies' => config('business.currencies'),
                'payment_methods' => config('business.payment_methods'),
                'display_timezone' => config('business.display_timezone'),
                'purchase_cost_types' => config('business.purchase_cost_types'),
            ],
        ]);
    }
}
