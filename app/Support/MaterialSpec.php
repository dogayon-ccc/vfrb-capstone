<?php
// app/Support/MaterialSpec.php
namespace App\Support;

/**
 * Validation and canonical units for material technical specs. Pure: no DB, no Eloquent.
 * Canonical storage: gsm, centimetres, percent. Anything not supplied stays null (unknown); nothing is defaulted.
 */
final class MaterialSpec
{
    public const CONSTRUCTIONS = ['woven', 'knit', 'nonwoven'];
    public const DIRECTIONS    = ['lengthwise', 'crosswise', 'both'];
    public const SOURCES       = ['supplier_datasheet', 'lab_test', 'vfrb_measured', 'import', 'other'];
    public const VALUE_FIELDS  = ['fiber_composition', 'gsm', 'usable_width_cm', 'stretch_pct', 'stretch_direction', 'construction', 'finish', 'shrinkage_pct', 'colourfastness_grade'];
    public const NUMERIC       = ['gsm' => [20, 1000], 'usable_width_cm' => [20, 400], 'stretch_pct' => [0, 300], 'shrinkage_pct' => [-10, 30], 'colourfastness_grade' => [1, 5]];
    private const OZ_YD2_TO_GSM = 33.9057;
    private const TO_CM = ['cm' => 1.0, 'in' => 2.54, 'm' => 100.0];

    /** Laravel rules for the raw request (units are converted afterwards by normalize()). */
    public static function rules(): array
    {
        return [
            'gsm'               => 'sometimes|nullable|numeric',
            'gsm_unit'          => 'sometimes|in:gsm,oz_yd2',
            'usable_width'      => 'sometimes|nullable|numeric',
            'usable_width_unit' => 'sometimes|in:cm,in,m',
            'stretch_pct'       => 'sometimes|nullable|numeric',
            'stretch_direction' => 'sometimes|nullable|in:' . implode(',', self::DIRECTIONS),
            'construction'      => 'sometimes|nullable|in:' . implode(',', self::CONSTRUCTIONS),
            'finish'            => 'sometimes|nullable|string|max:60',
            'shrinkage_pct'     => 'sometimes|nullable|numeric',
            'colourfastness_grade' => 'sometimes|nullable|numeric',
            'fiber_composition'            => 'sometimes|nullable|array|max:8',
            'fiber_composition.*.fiber'    => 'required_with:fiber_composition|string|max:40',
            'fiber_composition.*.pct'      => 'required_with:fiber_composition|numeric',
            'spec_source'       => 'sometimes|in:' . implode(',', self::SOURCES),
            'spec_reference'    => 'sometimes|nullable|string|max:200',
            'spec_verified'     => 'sometimes|boolean',
        ];
    }

    /**
     * @param  array<string,mixed> $in raw input; only keys present are touched (present + null = clear to unknown)
     * @return array{values:array<string,mixed>,errors:array<string,string>}
     */
    public static function normalize(array $in): array
    {
        $v = []; $e = [];

        if (array_key_exists('gsm', $in)) {
            $x = self::num($in['gsm']);
            if ($x !== null && ($in['gsm_unit'] ?? 'gsm') === 'oz_yd2') { $x *= self::OZ_YD2_TO_GSM; }
            $v['gsm'] = $x;
        }
        if (array_key_exists('usable_width', $in) || array_key_exists('usable_width_cm', $in)) {
            $x = self::num($in['usable_width'] ?? $in['usable_width_cm']);
            if ($x !== null) { $x *= self::TO_CM[$in['usable_width_unit'] ?? 'cm'] ?? 1.0; }
            $v['usable_width_cm'] = $x;
        }
        foreach (['stretch_pct', 'shrinkage_pct', 'colourfastness_grade'] as $k) {
            if (array_key_exists($k, $in)) { $v[$k] = self::num($in[$k]); }
        }
        foreach (self::NUMERIC as $k => [$lo, $hi]) {
            if (isset($v[$k]) && ($v[$k] < $lo || $v[$k] > $hi)) { $e[$k] = "{$k} must be between {$lo} and {$hi}."; }
            if (isset($v[$k])) { $v[$k] = round($v[$k], 1); }
        }
        foreach (['stretch_direction' => self::DIRECTIONS, 'construction' => self::CONSTRUCTIONS] as $k => $allowed) {
            if (array_key_exists($k, $in)) {
                $x = $in[$k] === null || $in[$k] === '' ? null : mb_strtolower(trim((string) $in[$k]));
                if ($x !== null && !in_array($x, $allowed, true)) { $e[$k] = "{$k} must be one of: " . implode(', ', $allowed) . '.'; }
                $v[$k] = $x;
            }
        }
        if (array_key_exists('finish', $in)) {
            $x = trim((string) $in['finish']);
            $v['finish'] = $x === '' ? null : mb_substr($x, 0, 60);
        }
        if (array_key_exists('fiber_composition', $in)) {
            [$v['fiber_composition'], $err] = self::fibers($in['fiber_composition']);
            if ($err) { $e['fiber_composition'] = $err; }
        }
        if (isset($v['stretch_direction']) && ($v['stretch_pct'] ?? ($in['stretch_pct'] ?? null)) === null) {
            $e['stretch_direction'] = 'stretch_direction needs a stretch_pct.';
        }
        return ['values' => $v, 'errors' => $e];
    }

    /** @return array{0:?array,1:?string} list of {fiber,pct}; percentages must total 100 (±0.5) */
    private static function fibers(mixed $raw): array
    {
        if ($raw === null || $raw === []) { return [null, null]; }
        if (!is_array($raw)) { return [null, 'fiber_composition must be a list.']; }
        $out = []; $sum = 0.0;
        foreach ($raw as $row) {
            $name = is_array($row) ? mb_strtolower(trim((string) ($row['fiber'] ?? ''))) : '';
            $pct  = is_array($row) ? self::num($row['pct'] ?? null) : null;
            if ($name === '' || $pct === null || $pct <= 0 || $pct > 100) { return [null, 'Each fiber needs a name and a percentage above 0 and up to 100.']; }
            if (isset($out[$name])) { return [null, "Fiber \"{$name}\" is listed twice."]; }
            $out[$name] = round($pct, 1); $sum += $pct;
        }
        if (abs($sum - 100) > 0.5) { return [null, 'Fiber percentages must total 100.']; }
        return [array_map(fn ($f, $p) => ['fiber' => $f, 'pct' => $p], array_keys($out), $out), null];
    }

    /**
     * Provenance for the values being written. A value needs a source; verification is only ever set explicitly, and any
     * change to a spec value without an explicit re-verification clears an earlier verification.
     * @param  array<string,mixed> $values result of normalize()['values']
     * @param  array<string,mixed> $existing current provenance map (attribute => entry)
     * @return array{provenance:?array,verified:bool}
     */
    public static function provenance(array $values, array $in, ?array $existing, string $now): array
    {
        $map = $existing ?? [];
        foreach ($values as $attr => $val) {
            if ($val === null) { unset($map[$attr]); continue; }
            $map[$attr] = ['source' => $in['spec_source'] ?? 'other', 'reference' => $in['spec_reference'] ?? null, 'recorded_at' => $now];
        }
        return ['provenance' => $map ?: null, 'verified' => (bool) ($in['spec_verified'] ?? false)];
    }

    public const GARMENT_KINDS = ['top', 'bottom', 'skirt', 'dress'];

    /**
     * One staff-defined requirement row, validated and in canonical units. VFRB's managers set these; nothing is assumed here.
     * @param  array<string,mixed> $r attribute, min_value, max_value, text_value, is_required
     * @return array{row:?array<string,mixed>,error:?string}
     */
    public static function requirement(array $r): array
    {
        $attr = mb_strtolower(trim((string) ($r['attribute'] ?? '')));
        $min  = self::num($r['min_value'] ?? null);
        $max  = self::num($r['max_value'] ?? null);
        $text = isset($r['text_value']) && trim((string) $r['text_value']) !== '' ? mb_strtolower(trim((string) $r['text_value'])) : null;
        $row  = ['attribute' => $attr, 'min_value' => $min, 'max_value' => $max, 'text_value' => $text, 'is_required' => (bool) ($r['is_required'] ?? false)];
        $bad  = fn (string $m) => ['row' => null, 'error' => "{$attr}: {$m}"];

        if (isset(self::NUMERIC[$attr]) || preg_match('/^fiber:[a-z][a-z \-]{0,38}$/', $attr)) {
            [$lo, $hi] = self::NUMERIC[$attr] ?? [0, 100];
            if ($min === null && $max === null) { return $bad('give a min_value, a max_value or both.'); }
            foreach (['min_value' => $min, 'max_value' => $max] as $k => $v) {
                if ($v !== null && ($v < $lo || $v > $hi)) { return $bad("{$k} must be between {$lo} and {$hi}."); }
            }
            if ($min !== null && $max !== null && $min > $max) { return $bad('min_value is above max_value.'); }
            return ['row' => array_merge($row, ['text_value' => null]), 'error' => null];
        }
        if ($attr === 'construction' || $attr === 'finish') {
            if ($text === null) { return $bad('give a text_value.'); }
            if ($attr === 'construction' && !in_array($text, self::CONSTRUCTIONS, true)) { return $bad('text_value must be one of: ' . implode(', ', self::CONSTRUCTIONS) . '.'); }
            return ['row' => array_merge($row, ['min_value' => null, 'max_value' => null]), 'error' => null];
        }
        return $bad('unknown attribute. Use ' . implode(', ', [...array_keys(self::NUMERIC), 'construction', 'finish', 'fiber:<name>']) . '.');
    }

    private static function num(mixed $v): ?float
    {
        return $v === null || $v === '' || !is_numeric($v) ? null : (float) $v;
    }
}
