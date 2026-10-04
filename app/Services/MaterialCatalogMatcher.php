<?php
// app/Services/MaterialCatalogMatcher.php
namespace App\Services;

/**
 * Keeps the AI inside VFRB's controlled materials catalog.
 *   eligible()  -> which catalog rows the AI may even see for this garment
 *   schema()    -> JSON schema that limits material_id to those rows
 *   normalize() -> re-validates the reply; nothing the model says is trusted
 * Works on plain arrays only (no Eloquent) so it can be unit-tested in isolation.
 */
final class MaterialCatalogMatcher
{
    public const MAX_SELECTIONS   = 8;
    public const MAX_REASON_CHARS = 280;
    public const MAX_NARRATION    = 600;

    /**
     * @param  iterable<array<string,mixed>> $materials rows from `materials` (toArray())
     * @return array<int,array{material_id:int,material_name:string,category:string,unit:string}> keyed by material_id
     */
    public function eligible(iterable $materials, ?string $garment): array
    {
        $out = [];
        foreach ($materials as $m) {
            $id = (int) ($m['material_id'] ?? 0);
            if ($id <= 0 || !$this->isAiEligible($m['ai_eligible'] ?? null) || !$this->appliesTo($m['applies_to'] ?? null, $garment)) {
                continue;
            }
            $out[$id] = [
                'material_id'   => $id,
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
     * @return array{selections:array<int,array{material_id:int,reason:string}>,narration:string,dropped:array{unknown:int,duplicate:int,sanitized:int}}
     */
    public function normalize(?array $parsed, array $eligible): array
    {
        $dropped    = ['unknown' => 0, 'duplicate' => 0, 'sanitized' => 0];
        $selections = [];
        $seen       = [];
        $names      = array_column($eligible, 'material_name');

        foreach ((array) ($parsed['selections'] ?? []) as $sel) {
            if (!is_array($sel)) { $dropped['unknown']++; continue; }
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
