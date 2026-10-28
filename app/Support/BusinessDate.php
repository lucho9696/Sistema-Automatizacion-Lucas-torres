<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/** Fechas de negocio en la zona horaria de presentación (America/Argentina/Cordoba por defecto). */
final class BusinessDate
{
    public static function today(): string
    {
        return Carbon::now(config('business.display_timezone'))->toDateString();
    }
}
