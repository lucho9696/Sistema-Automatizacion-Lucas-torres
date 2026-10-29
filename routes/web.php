<?php

use App\Http\Controllers\Api\IntegrationController;
use Illuminate\Support\Facades\Route;

// Retorno de OAuth de Mercado Libre (ML_REDIRECT_URI). Requiere sesión de administrador.
Route::get('/integraciones/mercadolibre/callback', [IntegrationController::class, 'mercadolibreCallback'])
    ->middleware('auth:web');

// SPA privada (Vue 3). Toda la lógica y autorización vive en la API (/api/*).
Route::view('/{any?}', 'app')->where('any', '^(?!api|sanctum|up).*$');
