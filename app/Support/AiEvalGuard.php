<?php

namespace App\Support;

use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Keeps the offline evaluation (vfrb:ai-eval) honest: an order is scored only with what staff knew before it was answered.
 * Pure helpers, no I/O beyond the query it is handed.
 */
final class AiEvalGuard
{
    /** Verdicts that count as labels. */
    public const VERDICTS = ['accept', 'reject'];

    /**
     * Restricts a material_recommendations query to staff verdicts. Without $asOf: any recorded verdict (live behaviour, unchanged).
     * With $asOf (evaluation): only accept/reject verdicts that name the staff member who gave them and that were recorded strictly
     * before $asOf['before']. A verdict with no timestamp cannot be shown to predate the evaluated order, so it is left out.
     * @template T of EloquentBuilder|QueryBuilder
     * @param T $q
     * @return T
     */
    public static function scopeFeedback($q, ?array $asOf, string $table)
    {
        $q->whereNotNull("{$table}.staff_feedback");
        if ($asOf === null) { return $q; }
        return $q->whereIn("{$table}.staff_feedback", self::VERDICTS)
            ->whereNotNull("{$table}.staff_feedback_by")
            ->whereNotNull("{$table}.staff_feedback_at")
            ->where("{$table}.staff_feedback_at", '<', $asOf['before']);
    }

    /**
     * The one definition of a usable label, shared by vfrb:ai-eval and vfrb:ml-readiness: an accept/reject verdict given by an existing
     * user, timestamped, on an existing order and material, and not the customer's own pick. Pass a query to narrow it further.
     * @param EloquentBuilder|null $q a material_recommendations query
     */
    public static function genuineVerdicts(?EloquentBuilder $q = null): EloquentBuilder
    {
        $q ??= \App\Models\MaterialRecommendation::query();
        // now()->addYear() is the boundary vfrb:ai-eval always used: "everything recorded", minus timestamps absurdly far in the future.
        return self::scopeFeedback($q, ['before' => now()->addYear()], 'material_recommendations')
            ->whereNotNull('material_recommendations.material_id')
            ->whereIn('material_recommendations.order_id', \Illuminate\Support\Facades\DB::table('orders')->select('order_id'))
            ->whereIn('material_recommendations.staff_feedback_by', \Illuminate\Support\Facades\DB::table('users')->select('user_id'))
            ->where(fn ($w) => $w->whereNull('material_recommendations.ai_note')
                ->orWhere('material_recommendations.ai_note', '!=', \App\Models\MaterialRecommendation::CUSTOMER_PICK_NOTE));
    }

    /**
     * Reasons a supervised model is not worth training yet; empty only when every readiness criterion is met.
     * These are planning criteria, not evidence of accuracy.
     * @param array{groups:int,accept:int,reject:int,fabrics:int,gsm_fabrics:int,gsm_verified_fabrics:int} $f groups/accept/reject over independent groups; gsm_* count fabrics with a recorded / staff-verified GSM
     * @return string[]
     */
    public static function readinessBlockers(array $f, int $minGroups): array
    {
        return array_values(array_filter([
            $f['groups'] === 0 ? 'No genuine staff verdicts: nothing to learn from or evaluate.' : null,
            $f['groups'] < $minGroups ? "Only {$f['groups']} independent reviewed group(s); planning threshold is {$minGroups}." : null,
            $f['groups'] > 0 && min($f['accept'], $f['reject']) === 0 ? 'Genuine verdicts contain no accepts or no rejects, so no label contrast.' : null,
            $f['fabrics'] > 0 && $f['gsm_fabrics'] === 0 ? 'No fabric has a recorded GSM: technical features are empty.' : null,
            $f['fabrics'] > 0 && $f['gsm_fabrics'] > 0 && $f['gsm_verified_fabrics'] === 0 ? 'GSM is recorded but no fabric specification is staff-verified: technical features are unconfirmed.' : null,
        ]));
    }

    /**
     * Label counts the way vfrb:ai-eval reads them: the latest verdict per (order, material) wins, so a changed mind is one label, not two.
     * @param iterable<array{order_id:int|string,material_id:int|string,staff_feedback:string}> $rows genuine verdicts, oldest first
     * @return array{accept:int,reject:int,conflicts:int,by_order:array<int,int>} conflicts = order+material pairs that ever held both verdicts
     */
    public static function latestLabels(iterable $rows): array
    {
        $latest = [];
        $seen   = [];
        foreach ($rows as $r) {
            $k          = $r['order_id'] . ':' . $r['material_id'];
            $latest[$k] = ['order' => (int) $r['order_id'], 'verdict' => $r['staff_feedback']];
            $seen[$k][$r['staff_feedback']] = true;
        }
        $out = ['accept' => 0, 'reject' => 0, 'conflicts' => count(array_filter($seen, fn ($v) => count($v) > 1)), 'by_order' => []];
        foreach ($latest as $l) {
            $out[$l['verdict']]++;
            $out['by_order'][$l['order']] = ($out['by_order'][$l['order']] ?? 0) + 1;
        }
        return $out;
    }

    /** Orders that are the same design for the same customer: same garment, use category and zone colours. */
    public static function duplicateKey(Order $order): string
    {
        $studio = is_array($order->studio_config) ? $order->studio_config : [];
        $colours = array_map(fn ($c) => mb_strtolower(trim((string) $c)), $order->designColours());
        ksort($colours);
        $garment = trim((string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower((string) $order->garmentName())));
        $category = is_string($studio['category'] ?? null) ? mb_strtolower(trim($studio['category'])) : '';

        return sha1(json_encode([(int) $order->user_id, $garment, $category, $colours]));
    }

    /**
     * Newest-first order ids reduced to one per duplicate group (the newest), so repeats of one design are not scored as independent.
     * @param int[] $orderIds newest first
     * @param array<int,string> $keys order id => duplicateKey
     * @return array{0:int[],1:int} kept ids, number collapsed
     */
    public static function collapse(array $orderIds, array $keys): array
    {
        $seen = [];
        $kept = [];
        foreach ($orderIds as $id) {
            $k = $keys[$id] ?? "solo:{$id}";
            if (isset($seen[$k])) { continue; }
            $seen[$k] = true;
            $kept[] = (int) $id;
        }
        return [$kept, count($orderIds) - count($kept)];
    }

    /**
     * The "as of" scope for one evaluated order: feedback must predate that order's earliest verdict, and nothing from its own
     * duplicate group may be used.
     * @param array<int,string> $keys order id => duplicateKey, for every reviewed order
     * @return array{before:CarbonInterface,exclude:int[]}
     */
    public static function scopeFor(int $orderId, ?CarbonInterface $earliestVerdict, array $keys): array
    {
        $mine = $keys[$orderId] ?? null;
        $same = $mine === null ? [] : array_keys(array_filter($keys, fn ($k) => $k === $mine));
        return [
            'before'  => $earliestVerdict ?? \Illuminate\Support\Carbon::createFromTimestamp(0),
            'exclude' => array_values(array_unique([...array_map('intval', $same), $orderId])),
        ];
    }

    /**
     * Why precision/recall must not be reported, or null when they may be. A score needs rated picks of both kinds.
     * @param array{accepted_total:int,rejected_total:int,orders:int} $t
     */
    public static function withheldReason(array $t): ?string
    {
        if ($t['orders'] === 0) { return 'no rated orders'; }
        if ($t['accepted_total'] === 0) { return 'no accept verdicts'; }
        if ($t['rejected_total'] === 0) { return 'no reject verdicts'; }
        return null;
    }
}
