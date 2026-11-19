<?php

namespace App\Console\Commands;

use App\Models\Material;
use App\Models\MaterialRecommendation;
use App\Support\MaterialSpec;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only data audit: is there enough trustworthy data to train and evaluate a supervised material model?
 *   php artisan vfrb:ml-readiness [--min-orders=200] [--json]
 * It reports counts from the real database and never trains anything. --min-orders is a planning threshold you can change,
 * not a statistical rule; the verdict is "not ready" until reviewed orders, label balance and spec coverage all clear it.
 */
class VfrbMlReadiness extends Command
{
    protected $signature = 'vfrb:ml-readiness {--min-orders=200 : Distinct reviewed orders wanted before a model is worth training} {--json : Print JSON}';
    protected $description = 'Report whether VFRB has enough verdict and spec data for supervised learning (read-only)';

    public function handle(): int
    {
        $norm = fn ($s) => trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower((string) $s)));

        $materials = Material::query()->get();
        $names     = $materials->map(fn ($m) => $norm($m->material_name));
        $fabrics   = $materials->filter(fn ($m) => str_contains(mb_strtolower((string) $m->category), 'fabric'));
        $specCover = [];
        if (Schema::hasColumn('materials', 'gsm')) {
            foreach (MaterialSpec::VALUE_FIELDS as $f) {
                $specCover[$f] = $fabrics->filter(fn ($m) => $m->{$f} !== null && $m->{$f} !== [])->count();
            }
        }

        // Picks the customer made themselves are not AI/staff-reviewed examples.
        $labelled = MaterialRecommendation::query()->whereNotNull('staff_feedback')
            ->where(fn ($q) => $q->whereNull('ai_note')->orWhere('ai_note', '!=', MaterialRecommendation::CUSTOMER_PICK_NOTE));
        $verdicts = (clone $labelled)->selectRaw('staff_feedback, COUNT(*) c')->groupBy('staff_feedback')->pluck('c', 'staff_feedback')->all();
        $orders   = (clone $labelled)->distinct()->count('order_id');
        $perGarment = (clone $labelled)->join('orders', 'orders.order_id', '=', 'material_recommendations.order_id')
            ->selectRaw('orders.garment_type g, COUNT(DISTINCT material_recommendations.order_id) o, COUNT(*) v')->groupBy('orders.garment_type')->get()
            ->map(fn ($r) => ['garment' => $r->g, 'orders' => (int) $r->o, 'verdicts' => (int) $r->v])->all();
        $conflicts = (clone $labelled)->selectRaw('order_id, material_id')->groupBy('order_id', 'material_id')
            ->havingRaw('COUNT(DISTINCT staff_feedback) > 1')->get()->count();

        $min = max(1, (int) $this->option('min-orders'));
        $accept = (int) ($verdicts['accept'] ?? 0); $reject = (int) ($verdicts['reject'] ?? 0);
        $blockers = array_values(array_filter([
            $orders < $min ? "Only {$orders} reviewed order(s); planning threshold is {$min}." : null,
            min($accept, $reject) === 0 ? 'Verdicts contain no accepts or no rejects, so no label contrast.' : null,
            $fabrics->isNotEmpty() && ($specCover['gsm'] ?? 0) === 0 ? 'No fabric has a recorded GSM: technical features are empty.' : null,
        ]));

        $report = [
            'materials'  => ['total' => $materials->count(), 'distinct_names' => $names->unique()->count(), 'fabrics' => $fabrics->count(),
                             'duplicate_names' => $materials->count() - $names->unique()->count(), 'spec_coverage_fabrics' => $specCover],
            'verdicts'   => ['total' => $accept + $reject, 'accept' => $accept, 'reject' => $reject, 'distinct_orders' => $orders,
                             'conflicting_verdicts_same_order_material' => $conflicts, 'per_garment' => $perGarment],
            'ml_ready'   => !$blockers,
            'blockers'   => $blockers,
            'note'       => 'Counts only. No model is trained and no accuracy is implied.',
        ];

        if ($this->option('json')) { $this->line(json_encode($report, JSON_PRETTY_PRINT)); return self::SUCCESS; }
        $this->info("Materials: {$report['materials']['total']} ({$report['materials']['fabrics']} fabrics); reviewed orders: {$orders}; accept/reject: {$accept}/{$reject}");
        foreach ($blockers as $b) { $this->warn('- ' . $b); }
        $this->line($blockers ? 'ML readiness: NOT READY.' : 'ML readiness: thresholds met; still requires a leakage-safe time split before training.');
        return self::SUCCESS;
    }
}
