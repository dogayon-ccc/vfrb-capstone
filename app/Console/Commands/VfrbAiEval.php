<?php

namespace App\Console\Commands;

use App\Models\MaterialRecommendation;
use App\Models\Order;
use App\Services\MaterialRecommender;
use Illuminate\Console\Command;

/**
 * Scores material picks against what VFRB staff actually accepted or rejected on past orders, before a model or prompt change.
 *   php artisan vfrb:ai-eval --fallback-only            (no Gemini calls: scores the catalog fallback as a baseline)
 *   php artisan vfrb:ai-eval --limit=30 --model=<name>  (calls Gemini once per order, with this model for this run only)
 * Each order is scored with its own verdicts left out of the prompt, so it is never shown the answer.
 * Read-only: writes nothing, skips the cache, logs nothing. AI mode sends each order's design to Google, as the live endpoint does.
 */
class VfrbAiEval extends Command
{
    protected $signature = 'vfrb:ai-eval
                            {--limit=50 : Reviewed orders to score, newest first (max 500)}
                            {--model= : Gemini model for this run only; GEMINI_MODEL when empty}
                            {--fallback-only : Score the catalog fallback; makes no Gemini calls}
                            {--sleep=4 : Seconds between Gemini calls, to stay under free-tier rate limits}
                            {--json : Print the result as JSON}';

    protected $description = 'Score AI material picks against past staff accept/reject verdicts (read-only)';

    public function handle(MaterialRecommender $recommender): int
    {
        if ($model = trim((string) $this->option('model'))) {
            config(['services.gemini.model' => $model]);
        }
        $fallbackOnly = (bool) $this->option('fallback-only');
        $limit        = max(1, min(500, (int) $this->option('limit')));
        $sleep        = max(0, (int) $this->option('sleep'));

        $orderIds = MaterialRecommendation::query()
            ->whereNotNull('staff_feedback')
            ->where(fn ($q) => $q->whereNull('ai_note')->orWhere('ai_note', '!=', MaterialRecommendation::CUSTOMER_PICK_NOTE))
            ->groupBy('order_id')->orderByRaw('MAX(rec_id) DESC')->limit($limit)->pluck('order_id');

        $norm    = fn ($s) => trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower((string) $s)));
        $blank   = ['orders' => 0, 'picks' => 0, 'accepted_picked' => 0, 'rejected_picked' => 0, 'accepted_total' => 0];
        $groups  = [];
        $skipped = 0;
        $failed  = 0;
        $calls   = 0;

        foreach ($orderIds as $orderId) {
            $order = Order::find($orderId);
            $prep  = $order ? $recommender->prepare($order) : 'missing';
            if (is_string($prep)) { $skipped++; continue; }
            $eligible = $prep['eligible'];

            // Latest verdict per material on this order (ascending, so a later verdict overwrites an earlier one).
            $verdicts = MaterialRecommendation::where('order_id', $orderId)->whereNotNull('staff_feedback')
                ->orderBy('staff_feedback_at')->orderBy('rec_id')->pluck('staff_feedback', 'material_id')->all();
            $accepted = array_keys(array_intersect_key(array_filter($verdicts, fn ($v) => $v === 'accept'), $eligible));
            $rejected = array_keys(array_filter($verdicts, fn ($v) => $v === 'reject'));

            $context = $recommender->context($order, $eligible, $prep['profile'], (int) $orderId);
            if ($fallbackOnly) {
                $picks = $recommender->fallback($context, $eligible)['selections'];
            } else {
                if ($calls++ > 0 && $sleep) { sleep($sleep); }
                $picks = $recommender->ask($context, $eligible)['selections'];
                if (!$picks) { $failed++; continue; } // provider failure: not a judgement on the model's choices
            }
            $ids = array_map(fn ($s) => (int) $s['material_id'], $picks);

            $g = $norm($order->garmentName()) ?: 'unspecified';
            $groups[$g] ??= $blank;
            $groups[$g]['orders']++;
            $groups[$g]['picks']           += count($ids);
            $groups[$g]['accepted_picked'] += count(array_intersect($ids, $accepted));
            $groups[$g]['rejected_picked'] += count(array_intersect($ids, $rejected));
            $groups[$g]['accepted_total']  += count($accepted);
        }

        $total = $blank;
        foreach ($groups as $s) { foreach ($s as $k => $v) { $total[$k] += $v; } }
        $score = function (array $s): array {
            $judged = $s['accepted_picked'] + $s['rejected_picked'];
            return $s + [
                // Of the picks staff judged, the share they accepted.
                'precision'     => $judged ? round($s['accepted_picked'] / $judged, 3) : null,
                // Of the materials staff accepted, the share the picks found.
                'recall'        => $s['accepted_total'] ? round($s['accepted_picked'] / $s['accepted_total'], 3) : null,
                // Share of all picks that staff had rejected (lower is better).
                'rejected_rate' => $s['picks'] ? round($s['rejected_picked'] / $s['picks'], 3) : null,
            ];
        };

        $result = [
            'mode'        => $fallbackOnly ? 'catalog_fallback' : 'ai',
            'model'       => $fallbackOnly ? null : (string) config('services.gemini.model'),
            'scored'      => $total['orders'],
            'skipped'     => $skipped,
            'ai_failed'   => $failed,
            'total'       => $score($total),
            'by_garment'  => array_map(fn ($g, $s) => ['garment' => ucwords($g)] + $score($s), array_keys($groups), $groups),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }
        $this->info("Mode: {$result['mode']}" . ($result['model'] ? " ({$result['model']})" : '')
            . " | scored {$result['scored']}, skipped {$result['skipped']}, AI failed {$result['ai_failed']}");
        $row = fn (string $name, array $s) => [$name, $s['orders'], $s['picks'], $s['precision'] ?? '-', $s['recall'] ?? '-', $s['rejected_rate'] ?? '-'];
        $this->table(['Garment', 'Orders', 'Picks', 'Precision', 'Recall', 'Rejected rate'],
            [...array_map(fn ($g) => $row($g['garment'], $g), $result['by_garment']), $row('ALL', $result['total'])]);
        return self::SUCCESS;
    }
}
