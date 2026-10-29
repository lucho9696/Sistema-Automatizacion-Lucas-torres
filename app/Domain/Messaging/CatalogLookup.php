<?php

namespace App\Domain\Messaging;

use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Búsqueda determinista en el catálogo real para el bot (sin IA, sin SQL libre).
 * Todas las palabras útiles del mensaje tienen que aparecer en el producto/variante; si no
 * hay coincidencia exacta se acepta una parcial que respete todos los números (modelo,
 * capacidad), para no confundir "iPhone 15" con "iPhone 13".
 */
class CatalogLookup
{
    private const STOPWORDS = [
        'hola', 'buenas', 'buen', 'dia', 'dias', 'tardes', 'noches', 'precio', 'precios', 'tenes', 'tienen', 'hay',
        'stock', 'quiero', 'queria', 'necesito', 'cuanto', 'cuantos', 'sale', 'salen', 'esta', 'estan', 'el', 'la', 'los',
        'las', 'de', 'del', 'un', 'una', 'unos', 'unas', 'por', 'favor', 'me', 'pasas', 'pasan', 'que', 'busco', 'gb', 'g',
        'consulta', 'consultar', 'cotizar', 'cotizacion', 'unidades', 'unidad', 'u', 'uds', 'ud', 'equipos', 'equipo', 'x',
        'con', 'para', 'en', 'y', 'o', 'a', 'al', 'si', 'disponible', 'disponibles', 'valor', 'costo', 'cuesta', 'mayorista',
        'mayor', 'lista', 'vendes', 'venden', 'info', 'informacion', 'quisiera', 'saber', 'cuantas', 'cantidad',
    ];

    public static function normalize(string $text): string
    {
        $t = Str::lower(Str::ascii($text));
        $t = preg_replace('/(\d+)\s*(gb|tb)\b/', '$1 $2', $t);

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $t));
    }

    /** Expresiones de cantidad ("10 unidades", "x 5", "quiero 10 ...", "10 iphone ..."); el grupo 1 es el número. */
    private const QUANTITY_PATTERNS = [
        '/\bx\s?(\d{1,4})\b/',
        '/\b(\d{1,4})\s?(?:u|un|ud|uds|unid|unidades|equipos|piezas|pcs)\b/',
        '/\bcantidad\s(\d{1,4})\b/',
        '/\b(?:quiero|necesito|serian|son|cotizame|cotiza|pasame|mandame)\s(\d{1,4})(?=\s[a-z])/',
        '/^(\d{1,4})(?=\s[a-z])/',
    ];

    /** Cantidad pedida. Null si no se indicó. */
    public static function quantity(string $normalized): ?int
    {
        foreach (self::QUANTITY_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized, $m)) {
                $q = (int) $m[1];

                return $q > 0 ? $q : null;
            }
        }

        return null;
    }

    /** Quita la expresión de cantidad para que su número no se tome como modelo o capacidad. */
    public static function withoutQuantity(string $normalized): string
    {
        foreach (self::QUANTITY_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized, $m, PREG_OFFSET_CAPTURE)) {
                [$num, $offset] = $m[1];
                $normalized = substr_replace($normalized, ' ', $offset, strlen($num));
                break;
            }
        }

        return trim(preg_replace('/\s+/', ' ', $normalized));
    }

    /** @return list<string> */
    public static function tokens(string $normalized): array
    {
        $words = array_filter(explode(' ', $normalized), fn ($w) => $w !== '' && ! in_array($w, self::STOPWORDS, true));

        return array_values(array_unique($words));
    }

    /** @return Collection<int, ProductVariant> */
    public function search(string $text, int $limit = 6): Collection
    {
        $tokens = self::tokens(self::withoutQuantity(self::normalize($text)));
        if ($tokens === []) {
            return collect();
        }
        $numbers = array_values(array_filter($tokens, 'ctype_digit'));
        $words = array_values(array_diff($tokens, $numbers));

        $variants = ProductVariant::query()
            ->with('product')
            ->where('is_active', true)
            ->whereHas('product', fn ($q) => $q->where('is_active', true))
            ->get();

        $scored = $variants->map(function (ProductVariant $v) use ($numbers, $words) {
            $hay = ' '.self::normalize(implode(' ', array_filter([
                $v->product->brand, $v->product->name, $v->product->sku, $v->name, $v->sku,
                is_array($v->attributes) ? implode(' ', array_map(fn ($a) => is_scalar($a) ? (string) $a : '', $v->attributes)) : '',
            ]))).' ';
            foreach ($numbers as $n) {
                if (! str_contains($hay, " {$n} ")) {
                    return null; // un número que no coincide descarta la variante
                }
            }
            $hits = count(array_filter($words, fn ($w) => str_contains($hay, " {$w} ") || (strlen($w) >= 4 && str_contains($hay, " {$w}"))));
            if ($words !== [] && $hits === 0) {
                return null;
            }

            return ['variant' => $v, 'exact' => $hits === count($words), 'hits' => $hits];
        })->filter();

        $exact = $scored->where('exact', true);
        $pool = $exact->isNotEmpty() ? $exact : $scored->filter(fn ($s) => count($words) <= $s['hits'] * 2);

        return $pool
            ->sortBy([fn ($a, $b) => $b['variant']->stock_available <=> $a['variant']->stock_available, fn ($a, $b) => $a['variant']->id <=> $b['variant']->id])
            ->take($limit)
            ->pluck('variant')
            ->values();
    }
}
