<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Matriz de permisos por rol (especificación §4).
     * admin: todo. vendedor: clientes, ventas y cobros (no anula ni ve finanzas globales).
     * analista: reportes y catálogo en lectura; no ve IMEI ni modifica caja/inventario.
     */
    public const ABILITIES = [
        'catalog.manage' => [Role::Admin],
        'purchases.manage' => [Role::Admin],
        'inventory.adjust' => [Role::Admin],
        'inventory.view' => [Role::Admin, Role::Vendedor, Role::Analista],
        'units.view-imei' => [Role::Admin, Role::Vendedor],
        'customers.manage' => [Role::Admin, Role::Vendedor],
        'sales.create' => [Role::Admin, Role::Vendedor],
        'sales.cancel' => [Role::Admin],
        'sales.return' => [Role::Admin],
        'payments.register' => [Role::Admin, Role::Vendedor],
        'payments.void' => [Role::Admin],
        'finance.manage' => [Role::Admin],
        'reports.view' => [Role::Admin, Role::Analista],
        'audit.view' => [Role::Admin],
    ];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        foreach (self::ABILITIES as $ability => $roles) {
            Gate::define($ability, fn (User $user) => $user->is_active && $user->hasRole(...$roles));
        }

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
    }
}
