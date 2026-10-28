<?php

namespace App\Support;

/**
 * Normalización simple a formato E.164 (por defecto Argentina, +54).
 * Suficiente para deduplicar altas manuales; la normalización completa
 * (prefijo 9 de móviles, "15" local, etc.) se aborda en el Hito 2 (CRM).
 */
final class Phone
{
    public static function normalize(?string $raw, string $defaultCountryCode = '54'): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $raw = trim($raw);
        $digits = preg_replace('/\D/', '', $raw);
        if ($digits === '' || strlen($digits) < 6) {
            return null;
        }
        if (str_starts_with($raw, '+')) {
            return '+'.$digits;
        }
        if (str_starts_with($digits, '00')) {
            return '+'.substr($digits, 2);
        }
        if (str_starts_with($digits, $defaultCountryCode) && strlen($digits) > 10) {
            return '+'.$digits;
        }
        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return '+'.$defaultCountryCode.$digits;
    }
}
