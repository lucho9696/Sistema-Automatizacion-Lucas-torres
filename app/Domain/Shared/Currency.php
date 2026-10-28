<?php

namespace App\Domain\Shared;

use App\Support\Money;

/** Reglas de moneda: moneda permitida y cotización coherente hacia la moneda base. */
class Currency
{
    public static function base(): string
    {
        return config('business.base_currency');
    }

    public static function assertAllowed(string $currency, string $field = 'currency'): string
    {
        $currency = strtoupper($currency);
        if (! in_array($currency, config('business.currencies'), true)) {
            throw new BusinessRuleException("Moneda {$currency} no habilitada (BUSINESS_CURRENCIES).", $field);
        }

        return $currency;
    }

    /**
     * Devuelve la cotización normalizada (6 decimales). Para la moneda base es siempre 1;
     * para otras monedas es obligatoria y positiva. Nunca se busca una cotización "actual"
     * implícitamente: la cotización aplicada la informa quien registra la operación.
     */
    public static function rateFor(string $currency, string|int|null $rate, string $field = 'exchange_rate'): string
    {
        if (strtoupper($currency) === self::base()) {
            if ($rate !== null && $rate !== '' && Money::cmp($rate, '1') !== 0) {
                throw new BusinessRuleException('La cotización de la moneda base debe ser 1.', $field);
            }

            return Money::round('1', Money::RATE_SCALE);
        }
        if ($rate === null || $rate === '' || Money::cmp($rate, '0') <= 0) {
            throw new BusinessRuleException("Falta la cotización de {$currency} a ".self::base().'.', $field);
        }

        return Money::round($rate, Money::RATE_SCALE);
    }
}
