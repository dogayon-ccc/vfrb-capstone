<?php
// app/Services/MaterialCatalogMatcher.php
namespace App\Services;

/**
 * Keeps the AI inside VFRB's controlled materials catalog.
 *   eligible()  -> which catalog rows the AI may even see for this garment and design colour
 *   schema()    -> JSON schema that limits material_id to those rows
 *   normalize() -> re-validates the reply; nothing the model says is trusted
 *   fallback()  -> deterministic picks when Gemini fails or times out
 *   designHash()-> cache key for one design context
 * Works on plain arrays only (no Eloquent) so it can be unit-tested in isolation.
 */
final class MaterialCatalogMatcher
{
    public const MAX_SELECTIONS   = 8;
    public const MAX_REASON_CHARS = 280;
    public const MAX_NARRATION    = 600;
    // Any reply key matching this is a quantity/price/formula field the AI must never produce.
    public const FORBIDDEN_KEY = '/qty|quantit|price|cost|amount|total|yard|meter|metre|length|weight|bom|quota|formula|estimat|usage/i';

    // Colour words in catalog names and order colours -> family; first match wins, so multi-word names come first.
    private const COLOUR_WORDS = [
        'custom color' => 'any', 'custom colour' => 'any', 'matching color' => 'any', 'matching colour' => 'any',
        'old rose' => 'pink', 'ultra violet' => 'purple', 'light blue' => 'blue', 'sky blue' => 'blue', 'royal blue' => 'blue',
        'navy' => 'navy', 'black' => 'black', 'charcoal' => 'black', 'gray' => 'gray', 'grey' => 'gray', 'ash' => 'gray', 'silver' => 'gray',
        'white' => 'white', 'cream' => 'white', 'ivory' => 'white', 'maroon' => 'maroon', 'burgundy' => 'maroon', 'wine' => 'maroon',
        'red' => 'red', 'magenta' => 'pink', 'fuchsia' => 'pink', 'pink' => 'pink', 'rose' => 'pink', 'violet' => 'purple',
        'purple' => 'purple', 'lavender' => 'purple', 'camel' => 'brown', 'khaki' => 'brown', 'brown' => 'brown', 'beige' => 'brown',
        'tan' => 'brown', 'mustard' => 'yellow', 'yellow' => 'yellow', 'green' => 'green', 'emerald' => 'green', 'blue' => 'blue',
    ];

    /**
     * @param  iterable<array<string,mixed>> $materials rows from `materials` (toArray())
     * @param  array<string,mixed> $designColours zone => hex or colour name (body first); empty = no colour filter
     * @return array<int,array{material_id:int,material_code:string,material_name:string,category:string,unit:string}> keyed by material_id
     */
    public function eligible(iterable $materials, ?string $garment, array $designColours = []): array
    {
        $families = array_filter(array_map(fn ($c) => is_string($c) ? $this->colourFamily($c) : null, $designColours));
        $body     = $families['body'] ?? $families['all'] ?? reset($families) ?: null;

        $out = [];
        foreach ($materials as $m) {
            $id = (int) ($m['material_id'] ?? 0);
            if ($id <= 0 || !$this->isAiEligible($m['ai_eligible'] ?? null) || !$this->appliesTo($m['applies_to'] ?? null, $garment)
                || !$this->colourFits($m, $body, $families)) {
                continue;
            }
            $out[$id] = [
                'material_id'   => $id,
                'material_code' => (string) ($m['material_code'] ?? ''),
                'material_name' => (string) ($m['material_name'] ?? ''),
                'category'      => (string) ($m['category'] ?? 'Other'),
                'unit'          => (string) ($m['unit'] ?? ''),
            ];
        }
        return $out;
    }

    /** JSON schema for Gemini's responseJsonSchema; material_id is an enum of the eligible ids only. */
    public function schema(array $eligible): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'selections' => [
                    'type'     => 'array',
                    'maxItems' => self::MAX_SELECTIONS,
                    'items'    => [
                        'type'       => 'object',
                        'properties' => [
                            'material_id' => ['type' => 'integer', 'enum' => array_values(array_keys($eligible))],
                            'reason'      => ['type' => 'string'],
                        ],
                        'required' => ['material_id', 'reason'],
                    ],
                ],
                'narration' => ['type' => 'string'],
            ],
            'required' => ['selections', 'narration'],
        ];
    }

    /**
     * @return array{selections:array<int,array{material_id:int,reason:string}>,narration:string,dropped:array{unknown:int,duplicate:int,sanitized:int,forbidden_field:int}}
     */
    public function normalize(?array $parsed, array $eligible): array
    {
        $dropped    = ['unknown' => 0, 'duplicate' => 0, 'sanitized' => 0, 'forbidden_field' => 0];
        $selections = [];
        $seen       = [];
        $names      = array_column($eligible, 'material_name');

        // A quantity/price field anywhere at the top level voids the whole reply.
        if (is_array($parsed) && $this->forbiddenKeys($parsed)) {
            $dropped['forbidden_field']++;
            return ['selections' => [], 'narration' => '', 'dropped' => $dropped];
        }

        foreach ((array) ($parsed['selections'] ?? []) as $sel) {
            if (!is_array($sel)) { $dropped['unknown']++; continue; }
            if ($this->forbiddenKeys($sel)) { $dropped['forbidden_field']++; continue; }
            $id = $sel['material_id'] ?? null;
            if (!is_int($id) && !(is_string($id) && ctype_digit($id))) { $dropped['unknown']++; continue; }
            $id = (int) $id;
            if (!isset($eligible[$id])) { $dropped['unknown']++; continue; }
            if (isset($seen[$id]))      { $dropped['duplicate']++; continue; }
            if (count($selections) >= self::MAX_SELECTIONS) { break; }

            $seen[$id] = true;
            $reason    = $this->cleanText($sel['reason'] ?? '');
            if ($reason === '' || $this->hasForbiddenTerms($reason, $names)) {
                $reason = 'Suitable ' . strtolower($eligible[$id]['category']) . ' for this design.';
                $dropped['sanitized']++;
            }
            $selections[] = ['material_id' => $id, 'reason' => mb_substr($reason, 0, self::MAX_REASON_CHARS)];
        }

        $narration = $this->cleanText($parsed['narration'] ?? '');
        if ($narration === '' || mb_strlen($narration) > self::MAX_NARRATION || $this->hasForbiddenTerms($narration, $names)) {
            $narration = $selections
                ? 'Recommended materials for this design: ' . $this->joinNames(array_map(fn ($s) => $eligible[$s['material_id']]['material_name'], $selections)) . '.'
                : '';
            if ($narration !== '') { $dropped['sanitized']++; }
        }

        return ['selections' => $selections, 'narration' => $narration, 'dropped' => $dropped];
    }

    /**
     * Deterministic picks for when Gemini fails: one main fabric and one thread from the eligible rows,
     * the customer's saved preferences first, then lowest material_id. Never a quantity.
     * @param  int[] $preferredIds
     * @return array{selections:array<int,array{material_id:int,reason:string}>,narration:string}
     */
    public function fallback(array $eligible, array $preferredIds = []): array
    {
        $rank = array_flip(array_map('intval', $preferredIds));
        $rows = array_values($eligible);
        usort($rows, fn ($a, $b) => [isset($rank[$a['material_id']]) ? 0 : 1, $a['material_id']]
                                  <=> [isset($rank[$b['material_id']]) ? 0 : 1, $b['material_id']]);

        $picks = [];
        foreach (['fabric', 'thread'] as $kind) {
            foreach ($rows as $m) {
                if (str_contains(mb_strtolower($m['category']), $kind)) { $picks[$m['material_id']] = $m; break; }
            }
        }
        if (!$picks && $rows) { $picks[$rows[0]['material_id']] = $rows[0]; }

        $selections = [];
        foreach ($picks as $id => $m) {
            $selections[] = [
                'material_id' => $id,
                'reason'      => 'Standard ' . strtolower($m['category'] ?: 'material') . " from VFRB's approved list for this garment.",
            ];
        }
        return [
            'selections' => $selections,
            'narration'  => $selections
                ? "AI suggestions are unavailable right now, so these are VFRB's default approved materials for this garment. Our team will confirm them."
                : '',
        ];
    }

    /** Stable cache key for one design context; key order does not matter. */
    public function designHash(array $context): string
    {
        $sort = function (array $a) use (&$sort): array {
            if (!array_is_list($a)) { ksort($a); }
            return array_map(fn ($v) => is_array($v) ? $sort($v) : $v, $a);
        };
        return hash('sha256', json_encode($sort($context), JSON_UNESCAPED_UNICODE));
    }

    private function forbiddenKeys(array $obj): bool
    {
        foreach (array_keys($obj) as $k) {
            if (is_string($k) && preg_match(self::FORBIDDEN_KEY, $k)) { return true; }
        }
        return false;
    }

    /** True when text states a quantity, price, or formula, which the AI must never produce. Catalog names are ignored. */
    public function hasForbiddenTerms(string $text, array $catalogNames = []): bool
    {
        foreach ($catalogNames as $n) {
            if ($n !== '') { $text = str_ireplace($n, ' ', $text); }
        }
        $patterns = [
            '/\d+(?:[.,]\d+)?\s*(?:yards?|yds?|meters?|metres?|m|cm|mm|inch(?:es)?|in|kg|kilos?|grams?|g|lbs?|pcs?|pieces?|sets?|rolls?|cones?|dozens?)\b/iu',
            '/[₱$€£]|\b(?:php|pesos?|price[sd]?|pricing|costs?|costly|cheap(?:er|est)?|budget|discount)\b/iu',
            '/\b(?:yardage|quota|formula|bill of materials|bom|per piece|per unit|per garment|total of|quantit(?:y|ies)|how (?:many|much))\b/iu',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $text)) { return true; }
        }
        return false;
    }

    private function isAiEligible(mixed $flag): bool
    {
        return $flag === null || !in_array($flag, [false, 0, '0', 'false'], true);
    }

    private function appliesTo(mixed $list, ?string $garment): bool
    {
        if (is_string($list)) { $list = json_decode($list, true); }
        if (!is_array($list) || $list === []) { return true; } // null/empty = every garment
        if ($garment === null || trim($garment) === '') { return false; }
        $want = $this->norm($garment);
        foreach ($list as $g) {
            if (is_string($g) && $this->norm($g) === $want) { return true; }
        }
        return false;
    }

    // Fabric must be the body's colour family and thread one of the design's; "any" and colourless rows always fit.
    private function colourFits(array $m, ?string $body, array $families): bool
    {
        $cat = mb_strtolower((string) ($m['category'] ?? ''));
        $isFabric = str_contains($cat, 'fabric');
        if (!$body || (!$isFabric && !str_contains($cat, 'thread'))) { return true; }

        $own = $this->colourFamily((string) ($m['material_name'] ?? ''));
        if ($own === null || $own === 'any') { return true; }
        return $isFabric ? $own === $body : in_array($own, $families, true);
    }

    /** Colour family of a hex (#rrggbb) or a colour name; null when no colour can be read. */
    public function colourFamily(string $colour): ?string
    {
        $c = mb_strtolower(trim($colour));
        if (preg_match('/^#([0-9a-f]{6})$/', $c, $hex)) { return $this->hexFamily($hex[1]); }
        foreach (self::COLOUR_WORDS as $word => $family) {
            if (preg_match('/(?<![a-z])' . preg_quote($word, '/') . 's?(?![a-z])/', $c)) { return $family; }
        }
        return null;
    }

    // Low saturation -> black/gray/white by lightness; otherwise hue buckets, with dark red = maroon and dark blue = navy.
    private function hexFamily(string $hex): string
    {
        [$r, $g, $b] = array_map(fn ($p) => hexdec($p) / 255, str_split($hex, 2));
        $max = max($r, $g, $b); $min = min($r, $g, $b); $l = ($max + $min) / 2; $d = $max - $min;
        $s = $d == 0 ? 0 : $d / (1 - abs(2 * $l - 1));

        if ($s < 0.15 || $l < 0.08) { return $l < 0.25 ? 'black' : ($l > 0.85 ? 'white' : 'gray'); }
        $h = match (true) {
            $max == $r => fmod(($g - $b) / $d + 6, 6) * 60,
            $max == $g => (($b - $r) / $d + 2) * 60,
            default    => (($r - $g) / $d + 4) * 60,
        };
        return match (true) {
            $h < 15 || $h >= 345 => $l < 0.35 ? 'maroon' : 'red',
            $h < 50  => 'brown',
            $h < 70  => 'yellow',
            $h < 170 => 'green',
            $h < 255 => $l < 0.30 && $h >= 200 ? 'navy' : 'blue',
            $h < 290 => 'purple',
            default  => 'pink',
        };
    }

    private function norm(string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($s)));
    }

    private function cleanText(mixed $v): string
    {
        return is_string($v) ? trim(preg_replace('/\s+/u', ' ', strip_tags($v))) : '';
    }

    private function joinNames(array $names): string
    {
        $n = count($names);
        if ($n <= 1) { return implode('', $names); }
        if ($n === 2) { return $names[0] . ' and ' . $names[1]; }
        return implode(', ', array_slice($names, 0, -1)) . ', and ' . end($names);
    }
}
