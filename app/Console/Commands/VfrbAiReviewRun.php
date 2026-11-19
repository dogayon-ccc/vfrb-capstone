<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\MaterialRecommender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Step 1 of the AI recommendation review: run the live recommender on chosen orders and write a rating sheet for the
 * person who decides materials (owner / production supervisor).
 *   php artisan vfrb:ai-review-run --orders=9,12,15      (or --latest=20)
 * Output (storage/app/ai-review): run-<stamp>.json (module behaviour) and rating-sheet-<stamp>.csv (one row per suggested material;
 * the reviewer fills `rating` and, where records exist, `vfrb_used`). Writes nothing to the database, skips the cache, and
 * sends each order's design context to Google exactly as the live endpoint does. Customer names are never written.
 */
class VfrbAiReviewRun extends Command
{
    protected $signature = 'vfrb:ai-review-run {--orders= : Comma-separated order ids} {--latest=20 : Otherwise the newest orders with a Studio design} {--sleep=4 : Seconds between Gemini calls}';
    protected $description = 'Run the material recommender on test orders and write a rating sheet for staff review (no DB writes)';

    public function handle(MaterialRecommender $recommender): int
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('orders')))));
        $orders = $ids
            ? Order::whereIn('order_id', $ids)->get()
            : Order::whereNotNull('studio_config')->orderByDesc('order_id')->limit(max(1, min(50, (int) $this->option('latest'))))->get();
        if ($orders->isEmpty()) { $this->error('No orders found.'); return self::FAILURE; }

        $stamp = now()->format('Ymd-His');
        $dir   = storage_path('app/ai-review');
        File::ensureDirectoryExists($dir);
        $runs  = []; $sheet = []; $sleep = max(0, (int) $this->option('sleep'));

        foreach ($orders as $order) {
            $rec = ['order_id' => $order->order_id, 'garment' => (string) $order->garmentName(), 'colours' => $order->designColours()];
            $prep = $recommender->prepare($order);
            if (is_string($prep)) { $runs[] = $rec + ['skipped' => $prep]; $this->warn("#{$order->order_id} skipped: {$prep}"); continue; }
            ['eligible' => $eligible, 'profile' => $profile] = $prep;
            $ctx = $recommender->context($order, $eligible, $profile);

            $res = $recommender->ask($ctx, $eligible);   // never throws; provider failure comes back empty
            $source = 'ai';
            $dropped = $res['dropped'];
            if (!$res['selections']) { $source = 'catalog_default'; $res = $recommender->fallback($ctx, $eligible) + ['dropped' => $dropped, 'empty' => $res['empty']]; }
            $runs[] = $rec + ['source' => $source, 'eligible' => count($eligible), 'ai_empty' => (bool) ($res['empty'] ?? false),
                              'dropped' => $dropped, 'picked' => array_column($res['selections'], 'material_id')];
            foreach ($res['selections'] as $s) {
                $m = $eligible[$s['material_id']];
                $sheet[] = [$stamp, $order->order_id, $rec['garment'], $source, $m['material_code'], $m['material_name'], $m['category'], $s['reason'], '', '', ''];
            }
            $this->line("#{$order->order_id} {$rec['garment']}: {$source}, " . count($res['selections']) . ' picks');
            if ($sleep) { sleep($sleep); }
        }

        $meta = ['run_id' => $stamp, 'tested_at' => now()->toIso8601String(), 'model' => (string) config('services.gemini.model'),
                 'fallback_model' => (string) config('services.gemini.fallback_model'),
                 'note' => 'The fallback model answers only if the primary call fails; the run does not record which one answered. Outputs can vary between runs.'];
        File::put("{$dir}/run-{$stamp}.json", json_encode(['meta' => $meta, 'orders' => $runs], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $fh = fopen("{$dir}/rating-sheet-{$stamp}.csv", 'w');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['run_id', 'order_id', 'garment', 'source', 'material_code', 'material_name', 'category', 'reason', 'rating', 'reviewer_notes', 'vfrb_used']);
        foreach ($sheet as $row) { fputcsv($fh, $row); }
        fclose($fh);

        $this->info("Wrote {$dir}\\run-{$stamp}.json and rating-sheet-{$stamp}.csv");
        $this->line('Reviewer: fill `rating` with appropriate / acceptable / inappropriate. `vfrb_used`: material codes (;-separated) VFRB used on that past order, if records exist.');
        return self::SUCCESS;
    }
}
