<?php

namespace App\Domain\Crm;

use App\Models\SuppressionEntry;
use App\Support\Phone;
use Illuminate\Support\Facades\Auth;

/**
 * Lista de supresión. Se guarda solo un hash (SHA-256 con la APP_KEY como sal) del
 * teléfono/email normalizado: alcanza para bloquear futuros contactos sin conservar el dato.
 */
class Suppression
{
    public const SCOPES = ['global', 'whatsapp', 'marketing'];

    public static function hash(string $type, string $value): string
    {
        $normalized = $type === 'phone' ? (Phone::normalize($value) ?? $value) : strtolower(trim($value));

        return hash_hmac('sha256', $type.':'.$normalized, (string) config('app.key'));
    }

    public function add(string $type, ?string $value, string $scope = 'global', ?string $reason = null): void
    {
        if (! $value) {
            return;
        }
        SuppressionEntry::firstOrCreate(
            ['identifier_type' => $type, 'identifier_hash' => self::hash($type, $value), 'scope' => $scope],
            ['reason' => $reason, 'user_id' => Auth::id()],
        );
    }

    /** ¿Está suprimido para el canal? (global aplica a todos). */
    public function isSuppressed(?string $phone, ?string $email, ?string $channel = null): bool
    {
        $scopes = array_values(array_unique(array_filter(['global', $channel])));
        $hashes = [];
        if ($phone) {
            $hashes[] = ['phone', self::hash('phone', $phone)];
        }
        if ($email) {
            $hashes[] = ['email', self::hash('email', $email)];
        }
        if ($hashes === []) {
            return false;
        }

        return SuppressionEntry::query()
            ->whereIn('scope', $scopes)
            ->where(function ($q) use ($hashes) {
                foreach ($hashes as [$type, $hash]) {
                    $q->orWhere(fn ($w) => $w->where('identifier_type', $type)->where('identifier_hash', $hash));
                }
            })
            ->exists();
    }
}
