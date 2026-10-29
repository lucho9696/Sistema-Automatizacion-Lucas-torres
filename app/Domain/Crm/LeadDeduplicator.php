<?php

namespace App\Domain\Crm;

use App\Models\Lead;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Detección de duplicados. Nunca borra: informa coincidencias y la fusión es manual.
 *  - fuerte: mismo teléfono normalizado, mismo dominio o mismo external_id de la fuente.
 *  - posible: mismo nombre comercial normalizado en la misma ciudad.
 */
class LeadDeduplicator
{
    private const FREE_EMAIL_DOMAINS = ['gmail.com', 'hotmail.com', 'outlook.com', 'yahoo.com', 'yahoo.com.ar', 'live.com', 'icloud.com', 'hotmail.com.ar'];

    private const LEGAL_SUFFIXES = ['srl', 'sa', 'sas', 'sh', 'sociedad anonima', 'ltda', 'inc', 'llc'];

    public static function nameKey(?string $name): ?string
    {
        if (! $name) {
            return null;
        }
        $key = Str::of(Str::ascii($name))->lower()->replace('.', '')->replaceMatches('/[^a-z0-9 ]/', ' ')->squish()->toString();
        foreach (self::LEGAL_SUFFIXES as $suffix) {
            $key = preg_replace('/\b'.preg_quote($suffix, '/').'$/', '', $key);
        }

        return trim($key) ?: null;
    }

    public static function domain(?string $website, ?string $email): ?string
    {
        if ($website) {
            $host = parse_url(str_contains($website, '://') ? $website : 'https://'.$website, PHP_URL_HOST);
            if ($host) {
                return preg_replace('/^www\./', '', strtolower($host));
            }
        }
        if ($email && str_contains($email, '@')) {
            $domain = strtolower(substr(strrchr($email, '@'), 1));
            if (! in_array($domain, self::FREE_EMAIL_DOMAINS, true)) {
                return $domain;
            }
        }

        return null;
    }

    /**
     * @return Collection<int, array{id:int, business_name:string, city:?string, match:string, strength:string}>
     */
    public function find(?string $phone, ?string $domain, ?string $nameKey, ?string $city, ?int $exceptId = null): Collection
    {
        $base = fn () => Lead::query()->whereNull('duplicate_of_id')->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId));
        $matches = collect();

        if ($phone) {
            $base()->where('phone_e164', $phone)->get()->each(fn ($l) => $matches->push($this->row($l, 'telefono', 'fuerte')));
        }
        if ($domain) {
            $base()->where('domain', $domain)->get()->each(fn ($l) => $matches->push($this->row($l, 'dominio', 'fuerte')));
        }
        if ($nameKey) {
            $base()->where('name_key', $nameKey)
                ->when($city, fn ($q) => $q->whereRaw('lower(city) = ?', [mb_strtolower($city)]))
                ->get()->each(fn ($l) => $matches->push($this->row($l, 'nombre', 'posible')));
        }

        return $matches->unique('id')->values();
    }

    private function row(Lead $lead, string $match, string $strength): array
    {
        return ['id' => $lead->id, 'business_name' => $lead->business_name, 'city' => $lead->city, 'source' => $lead->source, 'match' => $match, 'strength' => $strength];
    }
}
