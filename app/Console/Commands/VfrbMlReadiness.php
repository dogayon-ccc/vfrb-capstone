<?php

namespace App\Console\Commands;

use App\Models\Material;
use App\Models\MaterialRecommendation;
use App\Models\Order;
use App\Support\AiEvalGuard;
use App\Support\MaterialSpec;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only data audit: is there enough trustworthy data to train and evaluate a supervised material model?
 *   php artisan vfrb:ml-readiness [--min-orders=200] [--json]
 * Counts only, from the real database; nothing is trained. A label counts only if vfrb:ai-eval would use it (AiEvalGuard::genuineVerdicts),
 * and near-duplicate orders count once. --min-orders is a planning criterion, not a statistical rule and not evidence of accuracy.
 */
class VfrbMlReadiness extends Command
{
    protected $signature = 'vfrb:ml-readiness {--min-orders=200 : Independent reviewed order groups wanted before a model is worth training} {--json : Print JSON}';
    protected $description = 'Report whether VFRB has enough genuine verdict and spec data for supervised learning (read-only)';

    public function handle(): int
    {
        $norm      = fn ($s) => trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower((string) $s)));
        $materials = Material::query()->get();
        $names     = $materials->map(fn ($m) => $norm($m->material_name));
        $fabrics   = $materials->filter(fn ($m) => str_contains(mb_strtolower((string) $m->category), 'fabric'));
        $has       = fn ($m, $f) => $m->{$f} !== null && $m->{$f} !== [];
        $specCover = [];
        $verified  = 0;
        $gsmVerified = 0;
        if (Schema::hasColumn('materials', 'gsm')) {
            foreach (MaterialSpec::VALUE_FIELDS as $f) { $specCover[$f] = $fabrics->filter(fn ($m) => $has($m, $f))->count(); }
            $verified    = $fabrics->filter(fn ($m) => $m->spec_verified_at !== null)->count();
            $gsmVerified = $fabrics->filter(fn ($m) => $has($m, 'gsm') && $m->spec_verified_at !== null)->count();
        }

        // Raw feedback, and why some of it is not a usable label (reasons can overlap on one row).
        $raw      = fn () => MaterialRecommendation::query()->whereNotNull('staff_feedback');
        $rawCount = $raw()->count();
        $genuine  = AiEvalGuard::genuineVerdicts()->count();
        $excluded = [
            'invalid_label'  => $raw()->whereNotIn('staff_feedback', AiEvalGuard::VERDICTS)->count(),
            'unattributed'   => $raw()->whereNull('staff_feedback_by')->count(),
            'undated'        => $raw()->whereNull('staff_feedback_at')->count(),
            'customer_picks' => $raw()->where('ai_note', MaterialRecommendation::CUSTOMER_PICK_NOTE)->count(),
            'no_material'    => $raw()->whereNull('material_id')->count(),
            'unknown_user'   => $raw()->whereNotNull('staff_feedback_by')->whereNotIn('staff_feedback_by', DB::table('users')->select('user_id'))->count(),
        ];

        // Independent groups: near-duplicate orders (same customer, garment, category, colours) count once, newest kept.
        $orderIds = AiEvalGuard::genuineVerdicts()->groupBy('order_id')->orderByRaw('MAX(rec_id) DESC')->pluck('order_id')->map(fn ($i) => (int) $i)->all();
        $orders   = Order::whereIn('order_id', $orderIds)->get()->keyBy('order_id');
        $keys     = $orders->mapWithKeys(fn ($o) => [(int) $o->order_id => AiEvalGuard::duplicateKey($o)])->all();
        [$kept, $collapsed] = AiEvalGuard::collapse($orderIds, $keys);

        // Same reading as vfrb:ai-eval: oldest first, so the latest verdict per order+material wins.
        $rows = $kept ? AiEvalGuard::genuineVerdicts()->whereIn('material_recommendations.order_id', $kept)
            ->orderBy('staff_feedback_at')->orderBy('rec_id')->get(['material_recommendations.order_id', 'material_recommendations.material_id', 'material_recommendations.staff_feedback'])->toArray() : [];
        $labels    = AiEvalGuard::latestLabels($rows);
        $accept    = $labels['accept'];
        $reject    = $labels['reject'];
        $conflicts = $labels['conflicts'];
        $perGarment = [];
        foreach ($labels['by_order'] as $id => $n) {
            $g = (string) ($orders[$id]->garment_type ?? '');
            $perGarment[$g] ??= ['garment' => $g, 'groups' => 0, 'verdicts' => 0];
            $perGarment[$g]['groups']++;
            $perGarment[$g]['verdicts'] += $n;
        }
        $perGarment = array_values($perGarment);

        $min      = max(1, (int) $this->option('min-orders'));
        $blockers = AiEvalGuard::readinessBlockers(['groups' => count($kept), 'accept' => $accept, 'reject' => $reject,
            'fabrics' => $fabrics->count(), 'gsm_fabrics' => $specCover['gsm'] ?? 0, 'gsm_verified_fabrics' => $gsmVerified], $min);

        $report = [
            'materials' => ['total' => $materials->count(), 'fabrics' => $fabrics->count(), 'distinct_names' => $names->unique()->count(),
                            'duplicate_names' => $materials->count() - $names->unique()->count(),
                            'spec_coverage_fabrics' => $specCover, 'spec_verified_fabrics' => $verified, 'gsm_verified_fabrics' => $gsmVerified],
            'feedback'  => ['raw_observations' => $rawCount, 'genuine_verdicts' => $genuine, 'excluded_total' => $rawCount - $genuine, 'excluded_reasons_may_overlap' => $excluded],
            'orders'    => ['eligible' => count($orderIds), 'duplicates_collapsed' => $collapsed, 'independent_groups' => count($kept)],
            'labels'    => ['accept' => $accept, 'reject' => $reject, 'both_classes' => min($accept, $reject) > 0,
                            'conflicting_same_order_material' => $conflicts, 'per_garment' => $perGarment],
            'threshold' => ['min_independent_groups' => $min, 'meaning' => 'A planning criterion for whether training is worth attempting, not evidence of accuracy.'],
            'ml_ready'  => !$blockers,
            'blockers'  => $blockers,
            'note'      => 'Counts only. No model is trained and no accuracy is implied.',
        ];

        if ($this->option('json')) { $this->line(json_encode($report, JSON_PRETTY_PRINT)); return self::SUCCESS; }
        $m = $report['materials']; $o = $report['orders'];
        $this->info("Materials: {$m['total']} ({$m['fabrics']} fabrics, {$verified} with verified specs)");
        $this->line("Feedback: {$rawCount} raw, {$genuine} genuine staff verdicts | eligible orders {$o['eligible']}, independent groups {$o['independent_groups']} (duplicates collapsed {$collapsed})");
        $this->line("Labels over independent groups: accept {$accept}, reject {$reject}");
        foreach ($blockers as $b) { $this->warn('- ' . $b); }
        $this->line($blockers ? 'ML readiness: NOT READY.' : 'ML readiness: criteria met; a model still needs a leakage-safe time split and a held-out test before any accuracy claim.');
        return self::SUCCESS;
    }
}
