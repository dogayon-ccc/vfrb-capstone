<?php
// app/Support/AiReviewStats.php
namespace App\Support;

/**
 * Pure statistics for the AI recommendation review (thesis Table 4-x). No DB, no network.
 * Rating scale (one rating per suggested material): appropriate | acceptable (with changes) | inappropriate.
 */
final class AiReviewStats
{
    public const RATINGS = ['appropriate', 'acceptable', 'inappropriate'];
    private const ALIAS = ['a' => 'appropriate', 'appropriate' => 'appropriate', 'ac' => 'acceptable', 'acceptable' => 'acceptable',
        'acceptable with changes' => 'acceptable', 'acceptable_with_changes' => 'acceptable', 'i' => 'inappropriate', 'inappropriate' => 'inappropriate'];

    public static function normalizeRating(?string $v): ?string
    {
        return self::ALIAS[mb_strtolower(trim((string) $v))] ?? null;
    }

    /**
     * @param  array<int,array<string,mixed>> $rows rating-sheet rows (keys: garment, rating)
     * @return array{rated:int,unrated:int,invalid:int,counts:array<string,int>,percent:array<string,?float>,by_garment:array<string,array<string,int>>}
     */
    public static function ratings(array $rows): array
    {
        $counts = array_fill_keys(self::RATINGS, 0); $by = []; $unrated = 0; $invalid = 0;
        foreach ($rows as $r) {
            $raw = trim((string) ($r['rating'] ?? ''));
            if ($raw === '') { $unrated++; continue; }
            $n = self::normalizeRating($raw);
            if ($n === null) { $invalid++; continue; }
            $counts[$n]++;
            $g = (string) ($r['garment'] ?? 'unknown');
            $by[$g] ??= array_fill_keys(self::RATINGS, 0);
            $by[$g][$n]++;
        }
        $rated = array_sum($counts);
        return ['rated' => $rated, 'unrated' => $unrated, 'invalid' => $invalid, 'counts' => $counts,
                'percent' => array_map(fn ($c) => $rated ? round(100 * $c / $rated, 1) : null, $counts), 'by_garment' => $by];
    }

    /**
     * How the module behaved on the run itself.
     * @param  array<int,array<string,mixed>> $orders per-order run records (source, skipped, ai_empty, dropped{unknown,duplicate,sanitized,forbidden_field})
     * @return array<string,int|float|null>
     */
    public static function run(array $orders): array
    {
        $ran = array_values(array_filter($orders, fn ($o) => empty($o['skipped'])));
        $n   = count($ran);
        $sum = fn (string $k) => array_sum(array_map(fn ($o) => (int) ($o['dropped'][$k] ?? 0), $ran));
        $fallback = count(array_filter($ran, fn ($o) => ($o['source'] ?? '') === 'catalog_default'));
        $withDrop = count(array_filter($ran, fn ($o) => array_sum($o['dropped'] ?? []) > 0));
        return ['orders_total' => count($orders), 'orders_skipped' => count($orders) - $n, 'orders_run' => $n,
            'catalog_fallback' => $fallback, 'catalog_fallback_pct' => $n ? round(100 * $fallback / $n, 1) : null,
            'gemini_no_usable_reply' => count(array_filter($ran, fn ($o) => !empty($o['ai_empty']))),
            'orders_with_rejected_items' => $withDrop, 'orders_with_rejected_items_pct' => $n ? round(100 * $withDrop / $n, 1) : null,
            'rejected_not_in_allowed_list' => $sum('unknown'), 'rejected_duplicate' => $sum('duplicate'),
            'rejected_forbidden_field' => $sum('forbidden_field'), 'reasons_rewritten_by_text_guard' => $sum('sanitized')];
    }

    /**
     * Overlap with what VFRB actually used on past orders (rows with a non-empty vfrb_used column, ';'-separated material codes).
     * @return array{orders_compared:int,suggested_that_vfrb_used:int,suggested_total:int,vfrb_materials_covered:int,vfrb_materials_total:int}
     */
    public static function againstPast(array $rows): array
    {
        $by = [];
        foreach ($rows as $r) {
            $used = array_filter(array_map(fn ($c) => mb_strtoupper(trim($c)), explode(';', (string) ($r['vfrb_used'] ?? ''))));
            if (!$used) { continue; }
            $by[(string) $r['order_id']]['used'] = $used;
            $by[(string) $r['order_id']]['sug'][] = mb_strtoupper(trim((string) ($r['material_code'] ?? '')));
        }
        $hit = $sug = $cov = $tot = 0;
        foreach ($by as $o) {
            $sug += count($o['sug']); $hit += count(array_intersect($o['sug'], $o['used']));
            $tot += count($o['used']); $cov += count(array_intersect($o['used'], $o['sug']));
        }
        return ['orders_compared' => count($by), 'suggested_that_vfrb_used' => $hit, 'suggested_total' => $sug,
                'vfrb_materials_covered' => $cov, 'vfrb_materials_total' => $tot];
    }
}
