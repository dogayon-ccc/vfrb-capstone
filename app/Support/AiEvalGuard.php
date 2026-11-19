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
