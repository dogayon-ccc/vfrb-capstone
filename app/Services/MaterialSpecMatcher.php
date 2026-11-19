<?php
// app/Services/MaterialSpecMatcher.php
namespace App\Services;

/**
 * Compares a material's recorded technical specs with what VFRB managers require of that kind of garment's fabric.
 * Works on plain arrays (no Eloquent) so it can be unit-tested in isolation. It never fills a missing value:
 * an attribute the material does not record is "unknown", and an unverified value is reported as unconfirmed.
 *
 * Per-attribute status:
 *   match | mismatch        the material's spec was verified by staff and does / does not meet the requirement
 *   match_unconfirmed | mismatch_unconfirmed   a value is recorded but staff have not verified it
 *   unknown                 the material records nothing for this attribute
 * Only a verified mismatch on a required attribute removes a material (hard_fail). Everything else stays ranked, with the gap shown.
 * spec_fit is a transparent coverage ratio (verified matches = 1, unconfirmed matches = 0.5, otherwise 0) over the requirements.
 * It is a rules score, not an ML probability, and is never shown as a percentage.
 * risk counts REQUIRED attributes whose recorded but unverified value fails the requirement. It never removes a material, but a recorded
 * value that fails is evidence against it, so ranking treats it as worse than a material with no data (which scores the same spec_fit).
 */
final class MaterialSpecMatcher
{
    public const LABELS = [
        'gsm' => 'Fabric weight', 'usable_width_cm' => 'Usable width', 'stretch_pct' => 'Stretch', 'construction' => 'Construction',
        'finish' => 'Finish', 'shrinkage_pct' => 'Shrinkage', 'colourfastness_grade' => 'Colourfastness', 'fiber' => 'Fibre content',
    ];

    /**
     * @param  array<string,mixed> $material row from `materials` (toArray())
     * @param  array<int,array<string,mixed>> $requirements rows of material_spec_requirements for this garment kind
     * @return array{checks:array<int,array<string,mixed>>,spec_fit:?float,hard_fail:bool,complete:bool,verified:bool,risk:int}
     */
    public function evaluate(array $material, array $requirements): array
    {
        $verified = !empty($material['spec_verified_at']);
        $checks = []; $points = 0.0; $hard = false; $complete = true; $risk = 0;

        foreach ($requirements as $r) {
            $attr   = (string) ($r['attribute'] ?? '');
            $actual = $this->actual($material, $attr);
            $meets  = $actual === null ? null : $this->meets($actual, $r);
            $status = match (true) {
                $meets === null => 'unknown',
                $meets          => $verified ? 'match' : 'match_unconfirmed',
                default         => $verified ? 'mismatch' : 'mismatch_unconfirmed',
            };
            if ($status === 'unknown' || str_ends_with($status, 'unconfirmed')) { $complete = false; }
            $points += ['match' => 1.0, 'match_unconfirmed' => 0.5][$status] ?? 0.0;
            $hard = $hard || ($status === 'mismatch' && !empty($r['is_required']));
            $risk += $status === 'mismatch_unconfirmed' && !empty($r['is_required']) ? 1 : 0;
            $checks[] = ['attribute' => $attr, 'required' => !empty($r['is_required']), 'status' => $status, 'actual' => $actual,
                         'min' => $r['min_value'] ?? null, 'max' => $r['max_value'] ?? null, 'text' => $r['text_value'] ?? null];
        }
        return ['checks' => $checks, 'spec_fit' => $checks ? round($points / count($checks), 3) : null,
                'hard_fail' => $hard, 'complete' => $checks ? $complete : false, 'verified' => $verified, 'risk' => $risk];
    }

    /** Status words only, no thresholds and no spec values: what a customer may see. */
    public function customerView(array $eval): array
    {
        $say = ['match' => 'meets VFRB\'s requirement (verified)', 'match_unconfirmed' => 'appears to meet the requirement; VFRB will confirm',
                'mismatch' => 'does not meet the requirement', 'mismatch_unconfirmed' => 'may not meet the requirement; VFRB will confirm',
                'unknown' => 'not yet confirmed by VFRB'];
        return ['complete' => $eval['complete'], 'items' => array_map(
            fn ($c) => ['label' => $this->label($c['attribute']), 'status' => $c['status'], 'text' => $this->label($c['attribute']) . ' ' . $say[$c['status']]],
            $eval['checks']
        )];
    }

    /** One line for the AI prompt: statuses only (the prompt must never carry quantities or thresholds). */
    public function promptLine(array $eval): string
    {
        if (!$eval['checks']) { return 'none defined'; }
        return implode('; ', array_map(fn ($c) => $this->label($c['attribute']) . ': ' . str_replace('_', ' ', $c['status']), $eval['checks']));
    }

    private function label(string $attr): string
    {
        return self::LABELS[str_starts_with($attr, 'fiber:') ? 'fiber' : $attr] ?? ucfirst(str_replace('_', ' ', $attr));
    }

    /** The material's recorded value for a requirement attribute, or null when it records nothing. */
    private function actual(array $m, string $attr): float|string|null
    {
        if (str_starts_with($attr, 'fiber:')) {
            $want = mb_strtolower(substr($attr, 6));
            $list = $m['fiber_composition'] ?? null;
            if (is_string($list)) { $list = json_decode($list, true); }
            if (!is_array($list) || $list === []) { return null; }
            foreach ($list as $f) { if (mb_strtolower((string) ($f['fiber'] ?? '')) === $want) { return (float) $f['pct']; } }
            return 0.0; // a recorded composition that lacks the fibre means 0 %
        }
        $v = $m[$attr] ?? null;
        return $v === null || $v === '' ? null : (is_numeric($v) ? (float) $v : mb_strtolower((string) $v));
    }

    private function meets(float|string $actual, array $r): bool
    {
        if (is_string($actual)) { return $actual === mb_strtolower((string) ($r['text_value'] ?? '')); }
        $lo = $r['min_value'] ?? null; $hi = $r['max_value'] ?? null;
        return ($lo === null || $actual >= (float) $lo) && ($hi === null || $actual <= (float) $hi);
    }
}
