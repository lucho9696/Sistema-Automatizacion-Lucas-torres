<?php

use App\Domain\Radar\MercadoLibreAuth;
use App\Domain\Radar\TrendService;
use App\Models\Message;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;

Artisan::command('app:create-admin {--email=} {--name=Administrador}', function () {
    $email = $this->option('email') ?: env('ADMIN_EMAIL') ?: $this->ask('Email del administrador');
    $password = env('ADMIN_PASSWORD') ?: $this->secret('Contraseña (mín. 12 caracteres)');

    $v = Validator::make(compact('email', 'password'), [
        'email' => ['required', 'email'],
        'password' => ['required', 'string', 'min:12'],
    ]);
    if ($v->fails()) {
        foreach ($v->errors()->all() as $e) {
            $this->error($e);
        }

        return 1;
    }

    $user = User::updateOrCreate(['email' => $email], [
        'name' => $this->option('name'),
        'password' => Hash::make($password),
        'role' => 'admin',
        'is_active' => true,
    ]);
    $this->info("Administrador listo: {$user->email}");

    return 0;
})->purpose('Crea o actualiza el usuario administrador (sin credenciales por defecto).');

Artisan::command('app:trends-sync {--category=}', function (TrendService $trends) {
    $run = $trends->sync($this->option('category') ?: null);
    $this->line("Ejecución #{$run->id} ({$run->mode}): {$run->status}, {$run->items_count} términos.".($run->error ? " Error: {$run->error}" : ''));

    return $run->status === 'success' ? 0 : 1;
})->purpose('Actualiza el radar con la fuente de tendencias configurada (TRENDS_PROVIDER).');

Artisan::command('app:ml-connect {code?}', function (MercadoLibreAuth $auth) {
    if ($missing = MercadoLibreAuth::missingConfig()) {
        $this->error('Faltan variables: '.implode(', ', $missing));

        return 1;
    }
    if (! $code = $this->argument('code')) {
        $this->info('1) Abra esta URL con la cuenta de Mercado Libre del negocio y autorice la aplicación:');
        $this->line($auth->authorizationUrl());
        $this->info('2) Copie el parámetro "code" de la URL de retorno y ejecute: php artisan app:ml-connect <code>');
        $this->comment('Alternativa: desde la pantalla Integraciones del panel (administrador).');

        return 0;
    }
    $auth->exchangeCode($code);
    $this->info('Mercado Libre conectado. Tokens guardados cifrados.');

    return 0;
})->purpose('Autoriza la aplicación de Mercado Libre (OAuth) sin pedir contraseñas.');

// Tendencias: Mercado Libre actualiza semanalmente. Requiere cron con `php artisan schedule:run`.
Schedule::command('app:trends-sync')->weeklyOn(1, '08:00')->timezone(config('business.display_timezone'));

Artisan::command('app:whatsapp-prune {--days=}', function () {
    $days = (int) ($this->option('days') ?: config('integrations.whatsapp.retention_days'));
    if ($days < 1) {
        $this->error('La retención debe ser de al menos 1 día.');

        return 1;
    }
    // Se borra el contenido, no el registro: quedan fechas, estados y la relación con cotizaciones/ventas.
    $count = Message::whereNull('redacted_at')->where('created_at', '<', now()->subDays($days))
        ->update(['body' => null, 'sources' => null, 'redacted_at' => now()]);
    WebhookEvent::whereNotNull('processed_at')->where('received_at', '<', now()->subDays($days))->update(['payload' => '{}']);
    $this->info("{$count} mensajes con más de {$days} días quedaron sin contenido.");

    return 0;
})->purpose('Aplica la política de retención de mensajes de WhatsApp (WHATSAPP_RETENTION_DAYS).');

Schedule::command('app:whatsapp-prune')->dailyAt('03:00')->timezone(config('business.display_timezone'));
