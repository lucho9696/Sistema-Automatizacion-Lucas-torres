<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
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
