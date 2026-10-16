<?php
// app/Services/MaterialCatalogMatcher.php
namespace App\Services;

/**
 * Keeps the AI inside VFRB's controlled materials catalog.
 *   eligible()  -> which catalog rows the AI may even see for this garment and design colours, each with its colour match and stock flag
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
        'any color' => 'any', 'any colour' => 'any', 'assorted' => 'any', 'off white' => 'white', 'off-white' => 'white',
        'old rose' => 'pink', 'ultra violet' => 'purple', 'light blue' => 'blue', 'sky blue' => 'blue', 'royal blue' => 'blue',
        'baby blue' => 'blue', 'powder blue' => 'blue', 'midnight blue' => 'navy', 'dark blue' => 'navy', 'navy blue' => 'navy',
        'forest green' => 'green', 'hunter green' => 'green', 'army green' => 'green', 'olive' => 'green', 'mint' => 'green', 'lime' => 'green',
        'teal' => 'teal', 'turquoise' => 'teal', 'aqua' => 'teal', 'cyan' => 'teal', 'jade' => 'teal',
        'orange' => 'orange', 'coral' => 'orange', 'peach' => 'orange', 'salmon' => 'orange', 'rust' => 'orange', 'tangerine' => 'orange',
        'gold' => 'yellow', 'lemon' => 'yellow', 'champagne' => 'white', 'pearl' => 'white', 'bone' => 'white', 'eggshell' => 'white',
        'cobalt' => 'blue', 'indigo' => 'navy', 'denim' => 'blue', 'plum' => 'purple', 'mauve' => 'purple', 'lilac' => 'purple',
        'chocolate' => 'brown', 'coffee' => 'brown', 'mocha' => 'brown', 'taupe' => 'brown', 'sand' => 'brown', 'oatmeal' => 'brown',
        'graphite' => 'gray', 'slate' => 'gray', 'heather' => 'gray', 'jet' => 'black', 'onyx' => 'black',
        'crimson' => 'red', 'scarlet' => 'red', 'cherry' => 'red',
        'navy' => 'navy', 'black' => 'black', 'charcoal' => 'black', 'gray' => 'gray', 'grey' => 'gray', 'ash' => 'gray', 'silver' => 'gray',
        'white' => 'white', 'cream' => 'white', 'ivory' => 'white', 'maroon' => 'maroon', 'burgundy' => 'maroon', 'wine' => 'maroon',
        'red' => 'red', 'magenta' => 'pink', 'fuchsia' => 'pink', 'pink' => 'pink', 'rose' => 'pink', 'violet' => 'purple',
        'purple' => 'purple', 'lavender' => 'purple', 'camel' => 'brown', 'khaki' => 'brown', 'brown' => 'brown', 'beige' => 'brown',
        'tan' => 'brown', 'mustard' => 'yellow', 'yellow' => 'yellow', 'green' => 'green', 'emerald' => 'green', 'blue' => 'blue',
    ];

    // Colour families close enough that a fabric of one can serve a design in the other (a navy fabric for a sky-blue order is
    // offered and labelled "near", not hidden). Anything not listed is a different colour.
    private const NEAR = ['navy' => ['blue'], 'blue' => ['navy', 'teal'], 'teal' => ['blue', 'green'], 'green' => ['teal'], 'maroon' => ['red'], 'red' => ['maroon']];

    /**
     * @param  iterable<array<string,mixed>> $materials rows from `materials` (toArray())
     * @param  array<string,mixed> $designColours zone => hex, rgb() or colour name (body first); empty = no colour filter
     * @param  string[] $aliases other names the garment answers to in `applies_to` (its piece kind, e.g. "Pants" for any trousers)
     * @return array<int,array{material_id:int,material_code:string,material_name:string,category:string,unit:string,colour_match:?string,in_stock:?bool}> keyed by material_id
     */
    public function eligible(iterable $materials, ?string $garment, array $designColours = [], array $aliases = []): array
    {
        $families = $this->families($designColours);

        $out = [];
        foreach ($materials as $m) {
            $id = (int) ($m['material_id'] ?? 0);
            if ($id <= 0 || !$this->isAiEligible($m['ai_eligible'] ?? null) || !$this->appliesTo($m['applies_to'] ?? null, $garment, $aliases)) {
                continue;
            }
            $match = $this->colourMatch($m, $families);
            if ($match === false) { continue; }
            $out[$id] = [
                'material_id'   => $id,
                'material_code' => (string) ($m['material_code'] ?? ''),
                'material_name' => (string) ($m['material_name'] ?? ''),
                'category'      => (string) ($m['category'] ?? 'Other'),
                'unit'          => (string) ($m['unit'] ?? ''),
                'colour_match'  => $match,
                'in_stock'      => $this->inStock($m),
            ];
        }
        return $out;
    }

    /** Design colours (zone => value) -> zone => colour family, unreadable values dropped. */
    public function families(array $designColours): array
    {
        return array_filter(array_map(fn ($c) => is_string($c) ? $this->colourFamily($c) : null, $designColours));
    }

    /**
     * How a material's colour relates to the design, or false when it is a fabric/thread of another colour.
     *   'body' same family as the body | 'near' a neighbouring family of the body (navy for blue) | '<zone>' matches that zone only
     *   (a contrast collar, a stripe colour) | 'any' made in any colour | null no colour in its name, or no design colour to compare.
     * Only fabric and thread carry a colour rule; everything else is null.
     */
    public function colourMatch(array $m, array $families): string|false|null
    {
        $cat = mb_strtolower((string) ($m['category'] ?? ''));
        $isFabric = str_contains($cat, 'fabric');
        if (!$isFabric && !str_contains($cat, 'thread')) { return null; }
        $own = $this->colourFamily((string) ($m['material_name'] ?? ''));
        if ($own === 'any') { return 'any'; }
        if ($own === null || !$families) { return null; }

        $body = $families['body'] ?? $families['all'] ?? reset($families);
        if ($own === $body) { return 'body'; }
        if (in_array($own, self::NEAR[$body] ?? [], true)) { return 'near'; }
        $zone = array_search($own, $families, true);
        return $zone === false ? false : (string) $zone;
    }

    /** true / false from quantity_in_stock, null when the row records no stock. */
    public function inStock(array $m): ?bool
    {
        $q = $m['quantity_in_stock'] ?? null;
        return is_numeric($q) ? (float) $q > 0 : null;
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

    // What a construction need looks like in a catalog name or category (garment features -> closures and support materials).
    public const NEEDS = [
        'button' => '/button/i', 'zipper' => '/zip/i', 'elastic' => '/elastic/i',
        'interfacing' => '/interfac|interlining/i', 'lining' => '/(?<!inter)lining/i', 'piping' => '/piping|bias/i',
    ];
    private const NEED_REASON = [
        'button' => 'Closure for the buttoned opening this design has.', 'zipper' => 'Closure for the fly or back opening this design has.',
        'elastic' => 'For the elasticated waist or cuffs this design has.', 'interfacing' => 'Gives the collar, front edges and waistband their shape.',
        'lining' => 'Lining for a tailored garment of this kind.', 'piping' => 'For the contrast edge or piping in this design.',
    ];
    private const MATCH_RANK = ['body' => 0, 'any' => 1, 'near' => 2];
    private const MAIN_MATCH = ['body', 'any', 'near', null];

    /**
     * Deterministic picks for when Gemini fails: the main fabric (body colour first), a contrast fabric for a trim zone of another
     * colour, a matching thread, and one material per construction need (buttons, zipper, elastic...). Within each: in-stock rows
     * first, then the customer's saved preferences, then colour fit, then lowest material_id. Never a quantity.
     * @param  int[] $preferredIds
     * @param  string[] $needs keys of NEEDS the garment's features call for
     * @param  int[] $avoidIds materials staff rejected for this garment: used only when nothing else fills that role
     * @return array{selections:array<int,array{material_id:int,reason:string}>,narration:string}
     */
    public function fallback(array $eligible, array $preferredIds = [], array $needs = [], array $avoidIds = []): array
    {
        $rank  = array_flip(array_map('intval', $preferredIds));
        $avoid = array_flip(array_map('intval', $avoidIds));
        $key   = fn ($m) => [isset($avoid[$m['material_id']]) ? 1 : 0, ($m['in_stock'] ?? null) === false ? 1 : 0, isset($rank[$m['material_id']]) ? 0 : 1,
                            self::MATCH_RANK[$m['colour_match'] ?? ''] ?? 3, $m['material_id']];
        $rows = array_values($eligible);
        usort($rows, fn ($a, $b) => $key($a) <=> $key($b));
        $is     = fn ($m, $kind) => str_contains(mb_strtolower($m['category']), $kind);
        $lining = fn ($m) => (bool) preg_match('/lining|interfac/i', $m['material_name']);
        $main   = fn ($m) => in_array($m['colour_match'] ?? null, self::MAIN_MATCH, true);

        $picks = [];
        $add   = function (?array $m, string $reason) use (&$picks) {
            if ($m && !isset($picks[$m['material_id']])) { $picks[$m['material_id']] = $reason; }
        };
        $first = function (callable $ok) use ($rows) {
            foreach ($rows as $m) { if ($ok($m)) { return $m; } }
            return null;
        };

        $add($first(fn ($m) => $is($m, 'fabric') && !$lining($m) && $main($m)), "Main fabric from VFRB's approved list for this garment.");
        $add($first(fn ($m) => $is($m, 'fabric') && !$lining($m) && !$main($m)), 'Contrast fabric in the colour of a trim zone of this design.');
        $add($first(fn ($m) => $is($m, 'thread')), "Thread from VFRB's approved list for this design's colours.");
        foreach ($needs as $need) {
            if (isset(self::NEEDS[$need])) {
                $add($first(fn ($m) => (bool) preg_match(self::NEEDS[$need], $m['material_name'] . ' ' . $m['category'])), self::NEED_REASON[$need]);
            }
        }
        if (!$picks && $rows) { $add($rows[0], "Approved material from VFRB's list for this garment."); }

        $selections = [];
        foreach ($picks as $id => $reason) {
            $selections[] = ['material_id' => $id, 'reason' => $reason];
        }
        return [
            'selections' => $selections,
            'narration'  => $selections
                ? "AI suggestions are unavailable right now, so these are VFRB's default approved materials for this garment. Our team will confirm them."
                : '',
        ];
    }

    /**
     * What kind of piece a garment is, the applies_to names it also answers to, and the construction materials its features call for.
     * Read from the garment name and the Studio state only (no fixed list of designs): a new family or a build-your-own piece gets a
     * profile from the same words and settings, and an unrecognised name is treated as a top with no extra needs.
     * @param  array<string,mixed> $studio studio_config (custom spec, colours)
     * @return array{kind:string,aliases:string[],needs:string[]}
     */
    public function garmentProfile(?string $garment, array $studio = []): array
    {
        $n      = $this->norm((string) $garment);
        $custom = is_array($studio['custom'] ?? null) ? $studio['custom'] : [];
        $has    = fn (string $re) => (bool) preg_match($re, $n);

        $kind = match (true) {
            $has('/skirt/')                                          => 'skirt',
            $has('/\b(pants?|trousers?|slacks|shorts|jogger|bottom)\b/') => 'bottom',
            $has('/\b(dress|coverall|jumpsuit)\b/')                 => 'dress',
            default                                                  => 'top',
        };

        $needs = [];
        if ($kind === 'bottom') {
            $needs = $has('/jogger|scrub|track|drawstring/') || ($custom['style'] ?? null) === 'jogger' ? ['elastic'] : ['zipper', 'button'];
        } elseif ($kind === 'skirt') {
            $needs = ['zipper'];
        } elseif ($kind === 'dress') {
            $needs = $has('/coverall|jumpsuit/') ? ['zipper', 'button'] : ['zipper'];
        } else {
            $collared = in_array($custom['neckline'] ?? null, ['polo', 'point', 'mandarin', 'notch'], true);
            if (!$has('/t shirt|\btee\b|round neck|scrub top|v neck/') && ($collared || $has('/polo|shirt|blouse|button|mandarin|barong|blazer|jacket|coat|notch|peplum/'))) {
                $needs[] = 'button';
            }
            if ($collared || $has('/polo|shirt|blouse|button|mandarin|barong|blazer|jacket|coat/') && !$has('/t shirt|\btee\b/')) { $needs[] = 'interfacing'; }
            if ($has('/blazer|jacket|coat/') && !$has('/lab coat/')) { $needs[] = 'lining'; }
        }

        $aliases = match ($kind) {
            'bottom' => array_merge(['bottom', 'Pants', 'Trousers'], $has('/shorts/') ? ['Shorts'] : []),
            'skirt'  => ['bottom', 'Skirt'],
            'dress'  => ['Dress'],
            default  => array_merge(['top'], array_keys(array_filter([
                'Polo Shirt' => $has('/polo/'), 'Button-Down' => $has('/button|barong|shirt/') && !$has('/t shirt|polo/'),
                'Blouse' => $has('/blouse|peplum/'), 'Blazer' => $has('/blazer|jacket/'),
            ]))),
        };
        return ['kind' => $kind, 'aliases' => $aliases, 'needs' => array_values(array_unique($needs))];
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

    // A garment matches an applies_to entry by its own name or one of its aliases (its piece kind), so a new garment family is not
    // shut out of materials VFRB approved for that kind of piece.
    private function appliesTo(mixed $list, ?string $garment, array $aliases = []): bool
    {
        if (is_string($list)) { $list = json_decode($list, true); }
        if (!is_array($list) || $list === []) { return true; } // null/empty = every garment
        $want = array_filter(array_map(fn ($n) => is_string($n) ? $this->norm($n) : '', [$garment, ...$aliases]));
        if (!$want) { return false; }
        foreach ($list as $g) {
            if (is_string($g) && in_array($this->norm($g), $want, true)) { return true; }
        }
        return false;
    }

    /** Colour family of a hex (#rgb / #rrggbb / #rrggbbaa), rgb()/rgba(), or a colour name; null when no colour can be read. */
    public function colourFamily(string $colour): ?string
    {
        $c = mb_strtolower(trim($colour));
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $c, $h)) { return $this->hexFamily($h[1] . $h[1] . $h[2] . $h[2] . $h[3] . $h[3]); }
        if (preg_match('/^#([0-9a-f]{6})(?:[0-9a-f]{2})?$/', $c, $hex)) { return $this->hexFamily($hex[1]); }
        if (preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})/', $c, $rgb)) {
            return $this->hexFamily(implode('', array_map(fn ($v) => sprintf('%02x', min(255, (int) $v)), array_slice($rgb, 1, 3))));
        }
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
            $h < 45  => $l >= 0.45 && $s >= 0.6 ? 'orange' : 'brown',
            $h < 50  => 'brown',
            $h < 70  => 'yellow',
            $h < 165 => 'green',
            $h < 195 => 'teal',
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
