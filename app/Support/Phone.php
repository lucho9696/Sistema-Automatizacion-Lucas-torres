<?php

namespace App\Support;

/**
 * Normalización de teléfonos a E.164 para deduplicar.
 *
 * Argentina (+54) se canoniza a +54 + 10 dígitos (código de área + abonado), sin el "9"
 * internacional de móviles ni el "15" local, para que "0351 15 123-4567",
 * "+54 9 351 1234567" y "351 1234567" sean el mismo contacto. El formato de envío por
 * WhatsApp (+549...) se resolverá en el adaptador de mensajería (Hito 3).
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
            $e164 = $digits;
        } elseif (str_starts_with($digits, '00')) {
            $e164 = substr($digits, 2);
        } elseif (str_starts_with($digits, $defaultCountryCode) && strlen($digits) > 10) {
            $e164 = $digits;
        } else {
            $e164 = $defaultCountryCode.ltrim($digits, '0');
        }

        if (str_starts_with($e164, '54')) {
            $e164 = '54'.self::argentineNational(substr($e164, 2));
        }

        return '+'.$e164;
    }

    /** Devuelve el número nacional argentino de 10 dígitos cuando es posible inferirlo. */
    private static function argentineNational(string $national): string
    {
        $national = ltrim($national, '0');
        // Prefijo móvil internacional: 54 9 AAAA...
        if (strlen($national) === 11 && str_starts_with($national, '9')) {
            $national = substr($national, 1);
        }
        // "15" local de móviles detrás de un código de área de 2, 3 o 4 dígitos.
        if (strlen($national) === 12) {
            foreach ([2, 3, 4] as $areaLength) {
                if (substr($national, $areaLength, 2) === '15') {
                    return substr($national, 0, $areaLength).substr($national, $areaLength + 2);
                }
            }
        }

        return $national;
    }
}
