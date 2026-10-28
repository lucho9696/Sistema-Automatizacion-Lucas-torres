<?php

use Illuminate\Support\Facades\Route;

// SPA privada (Vue 3). Toda la lógica y autorización vive en la API (/api/*).
Route::view('/{any?}', 'app')->where('any', '^(?!api|sanctum|up).*$');
