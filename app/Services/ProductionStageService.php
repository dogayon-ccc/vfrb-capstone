<?php
// app/Services/ProductionStageService.php
// VFRB Enterprise — Shared Production Stage-Advance Engine
//
// CONSOLIDATION (Aug 28 2026): this project used to have TWO independent
// controller methods that each wrote to daily_output_logs +
// order_production_tracking and could each flip orders.status:
//   - ProductionController::logProgress()  (routes: /log-progress, /log-output)
//   - OutputLogController::store()          (route:  /output-logs)
// They had drifted: only logProgress() had DB::transaction()+lockForUpdate()
// protecting the qty_completed read-modify-write (a real double-count race
// on double-click/retry), and only store() called
// issueMaterialsToProduction() (inventory deduction on Pattern
// completion) and autoCreateDelivery() (on Packing completion). Which UI a
// staff member happened to use determined whether real inventory moved.
//
// This service is the single implementation both controllers now call.
// logProgress()'s transaction + lockForUpdate() pattern — already proven
// correct — is the locking strategy used here. Neither
// ProductionTracking.jsx nor DailyOutputLog.jsx needed to change; this is
// a backend-only consolidation.
//
// SCHEMA FACTS (confirmed from vfrb_db.sql — see ProductionController.php
// header for the fuller list):
//   order_production_tracking: tracking_id, order_id, stage, qty_target,
//                               qty_completed, updated_by, notes
//   daily_output_logs:         total_output is GENERATED — never insert it
//   qc_checklists:             the latest row's passed flag clears the gate (set by QCChecklistController:
//                               80% of the sample AND every required check passes)
//   material_recommendations:  actual_qty_issued, issued_at — written here.
//                               actual_qty_issued = fabric consumption staff
//                               enter at Pattern (marker/yield is worked out
//                               there); issued_at = when Cutting completed and
//                               that quantity left stock. Staff-entered, never
//                               a parsed estimate; no formula/BOM exists.
//
// INVENTORY TIMING (Oct 17 2026): consumption is RECORDED at Pattern and
// DEDUCTED at Cutting, when the fabric is physically cut. Sewing and later
// stages never deduct again. Orders that were already deducted at Pattern
// under the old rule have issued_at set, so Cutting skips them (no double
// deduction).
//
// STILL OPEN, deliberately not touched in this pass — flagged to Dave,
// awaiting a decision separate from this file: ProductionController's
// materialCheck() and confirm() methods still regex-parse estimated_range
// for pre-confirm shortage checking. That data source is gone under the
// no-formula design (estimated_range no longer carries a real quantity),
// so those two methods need either a redesign or removal — out of scope
// for this consolidation, which only covers the Pattern-completion
// deduction path.

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductionStageService
{
    // Stage sequence — mirrors ProductionController::STAGE_MAP exactly.
    // Kept as a second copy (not a shared const) only because
    // ProductionController::STAGE_MAP is still referenced directly by
    // OutputLogController in a couple of read-only spots; both must be
    // kept in sync if the sequence ever changes. Locked per master prompt.
    const STAGE_MAP = [
        'confirmed'   => 'pattern',
        'pattern'     => 'segregation',
        'segregation' => 'cutting',
        'cutting'     => 'sewing',
        'sewing'      => 'qc',
        'qc'          => 'pressing',
        'pressing'    => 'packing',
        'packing'     => 'for_delivery', // automatic once packed_total == ordered; admin then marks delivered (completed)
    ];

    const QC_PASS_THRESHOLD = 0.80;

    // daily_output_logs size columns, as qty_{col}.
    const SIZE_COLS = ['xs', 's', 'm', 'l', 'xl', 'xxl', 'xxxl', 'custom'];

    /**
     * Log production output for one order+stage and, if the running total
     * now meets the target, advance the order's stage — with the QC gate,
     * consumption recording (Pattern), inventory deduction (Cutting),
     * auto-delivery creation (Packing), and
     * notifications all as one atomic unit.
     *
     * @param int   $orderId
     * @param string $stage      one of the 7 production stages; caller has
     *                           already validated this against the enum
     * @param array $qty         ['qty_xs'=>int, 'qty_s'=>int, ... 'qty_custom'=>int]
     * @param array $meta        ['staff_id'=>int, 'notes'=>?string,
     *                            'log_date'=>string] — defects go through reportDefect()
     * @param array $materialActuals  [{'material_id'=>int, 'qty_used'=>float}, ...]
     *              — staff-entered ACTUAL quantities used, one entry per
     *              accepted+linked material_recommendations row on this
     *              order. Only meaningful when this call is the one that
     *              completes the Pattern stage (there is no formula/BOM in
     *              this system — Gemini recommends material TYPES only, per
     *              the locked customer-facing rule, which as of Aug 28 2026
     *              also applies internally: nothing computes a quantity
     *              anywhere in this codebase anymore). If Pattern completes
     *              on this call and the order has accepted+linked
     *              recommendations but this array doesn't cover all of them,
     *              the STAGE ADVANCE (and the deduction) is held — same
     *              gating pattern as the QC 80/20 check — while the output
     *              log itself still commits, since the physical count staff
     *              just reported is real regardless of whether the
     *              inventory paperwork is finished yet.
     * @return array result — see keys below. 'error'=>true means the caller
     *               should translate 'status'/'message' into an HTTP response
     *               without committing anything (the transaction rolled back
     *               / never wrote for validation failures caught before any
     *               write).
     */
    public function logOutput(int $orderId, string $stage, array $qty, array $meta, array $materialActuals = []): array
    {
        $qtyXs     = (int) ($qty['qty_xs']     ?? 0);
        $qtyS      = (int) ($qty['qty_s']      ?? 0);
        $qtyM      = (int) ($qty['qty_m']      ?? 0);
        $qtyL      = (int) ($qty['qty_l']      ?? 0);
        $qtyXl     = (int) ($qty['qty_xl']     ?? 0);
        $qtyXxl    = (int) ($qty['qty_xxl']    ?? 0);
        $qtyXxxl   = (int) ($qty['qty_xxxl']   ?? 0);
        $qtyCustom = (int) ($qty['qty_custom'] ?? 0);
        $thisBatch = $qtyXs + $qtyS + $qtyM + $qtyL + $qtyXl + $qtyXxl + $qtyXxxl + $qtyCustom;

        // Zero pieces is allowed only to supply material consumption for a
        // Pattern stage that is already complete but held (checked below).
        if ($thisBatch <= 0 && empty($materialActuals)) {
            return ['error' => true, 'status' => 422, 'message' => 'At least 1 piece must be logged.', 'current_stage' => null];
        }

        $staffId  = $meta['staff_id'] ?? Auth::id();
        $logDate  = $meta['log_date'] ?? now()->toDateString();
        $notes    = $meta['notes'] ?? null;

        // Normalize to material_id => qty_used for O(1) lookup inside the
        // transaction. Sentinel -1 (not a raw missing key) so an explicit
        // qty_used of 0 (legitimately "none of this material used") is
        // distinguishable from "staff never provided a value at all".
        $actualsByMaterialId = [];
        foreach ($materialActuals as $a) {
            $mid = (int) ($a['material_id'] ?? 0);
            if ($mid > 0) {
                $actualsByMaterialId[$mid] = isset($a['qty_used']) ? (float) $a['qty_used'] : -1;
            }
        }

        return DB::transaction(function () use (
            $orderId, $stage, $staffId, $logDate, $notes, $meta, $actualsByMaterialId,
            $qtyXs, $qtyS, $qtyM, $qtyL, $qtyXl, $qtyXxl, $qtyXxxl, $qtyCustom, $thisBatch
        ) {
            // ── Lock the order row first — this is the pattern proven
            // correct in the former logProgress(): serializes concurrent
            // requests for the SAME order so the second request blocks
            // until the first commits, then reads the true post-update state.
            $order = DB::table('orders')->where('order_id', $orderId)->lockForUpdate()->first();
            if (!$order) {
                return ['error' => true, 'status' => 404, 'message' => 'Order not found.', 'current_stage' => null];
            }
            if ($order->status !== $stage) {
                return [
                    'error'         => true,
                    'status'        => 422,
                    'message'       => "Order is at stage '{$order->status}', not '{$stage}'.",
                    'current_stage' => $order->status,
                ];
            }

            // Tracking row read under lock before any write, so a resubmit can't push a stage past its target.
            $existing = DB::table('order_production_tracking')
                ->where('order_id', $orderId)
                ->where('stage', $stage)
                ->lockForUpdate()
                ->first();
            $qtyTarget = $existing && $existing->qty_target > 0 ? (int) $existing->qty_target : (int) $order->quantity_ordered;
            $done      = (int) ($existing->qty_completed ?? 0);
            $label     = self::stageLabel($stage);

            // Duplicate submit on a finished stage: log nothing, only retry the held advance below.
            $alreadyComplete = $qtyTarget > 0 && $done >= $qtyTarget;
            if ($thisBatch <= 0 && !$alreadyComplete) {
                return ['error' => true, 'status' => 422, 'message' => 'At least 1 piece must be logged.', 'current_stage' => $order->status];
            }
            if (!$alreadyComplete && $qtyTarget > 0 && $done + $thisBatch > $qtyTarget) {
                $left = $qtyTarget - $done;
                return [
                    'error'         => true,
                    'status'        => 422,
                    'message'       => "{$label} is at {$done}/{$qtyTarget}; only {$left} more piece" . ($left === 1 ? '' : 's') . " can be logged.",
                    'current_stage' => $order->status,
                ];
            }

            if (!$alreadyComplete && ($limit = $this->sizeLimitError($orderId, $stage, [
                'xs' => $qtyXs, 's' => $qtyS, 'm' => $qtyM, 'l' => $qtyL,
                'xl' => $qtyXl, 'xxl' => $qtyXxl, 'xxxl' => $qtyXxxl, 'custom' => $qtyCustom,
            ]))) {
                return ['error' => true, 'status' => 422, 'current_stage' => $order->status] + $limit;
            }

            $logId     = null;
            $loggedRow = null;
            $newCompleted = $done;
            if (!$alreadyComplete) {
                // ── 1. Write to daily_output_logs (per-size audit trail) ─────────
                // total_output is GENERATED ALWAYS — never insert it.
                $logId = DB::table('daily_output_logs')->insertGetId([
                    'order_id'         => $orderId,
                    'stage'            => $stage,
                    'logged_by'        => $staffId,
                    'log_date'         => $logDate,
                    'qty_xs'           => $qtyXs,
                    'qty_s'            => $qtyS,
                    'qty_m'            => $qtyM,
                    'qty_l'            => $qtyL,
                    'qty_xl'           => $qtyXl,
                    'qty_xxl'          => $qtyXxl,
                    'qty_xxxl'         => $qtyXxxl,
                    'qty_custom'       => $qtyCustom,
                    'defect_count'     => 0,
                    'notes'            => $notes,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ], 'log_id');

                $loggedRow = DB::table('daily_output_logs')->where('log_id', $logId)->first(['log_id', 'total_output']);

                // ── 2. Upsert order_production_tracking row (locked above) ─────────
                if ($existing) {
                    $newCompleted = $done + $thisBatch;
                    DB::table('order_production_tracking')
                        ->where('tracking_id', $existing->tracking_id)
                        ->update([
                            'qty_completed' => $newCompleted,
                            'updated_by'    => $staffId,
                            'notes'         => $notes ?? $existing->notes,
                            'updated_at'    => now(),
                        ]);
                } else {
                    DB::table('order_production_tracking')->insert([
                        'order_id'      => $orderId,
                        'stage'         => $stage,
                        'qty_target'    => $qtyTarget,
                        'qty_completed' => $thisBatch,
                        'updated_by'    => $staffId,
                        'notes'         => $notes,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ]);
                    $newCompleted = $thisBatch;
                }

                // Partial delivery needs a delivery record as soon as anything is packed.
                if ($stage === 'packing') {
                    $this->autoCreateDelivery($orderId, $staffId, false);
                }
            }

            $pct = $qtyTarget > 0 ? min(100, round($newCompleted / $qtyTarget * 100)) : 0;
            $advanced            = false;
            $qcBlocked           = false;
            $materialsBlocked    = false;
            $materialsNeeding    = [];
            $newStage            = $stage;
            $deductionLog        = [];
            $lowStockAlerts      = [];
            $message = $alreadyComplete
                ? "{$label} is already complete ({$newCompleted}/{$qtyTarget}); nothing new was logged."
                : "{$newCompleted}/{$qtyTarget} pieces logged for {$label}.";

            // ── 3. Auto-advance check ──────────────────────────────────────────
            $earlierOpen = $newCompleted >= $qtyTarget ? $this->incompleteStages($orderId, $stage) : [];
            if ($earlierOpen) {
                $message = "{$label} is complete, but earlier stages are not: " . implode(', ', $earlierOpen) . ". The order stays at {$label}.";
            }
            if ($newCompleted >= $qtyTarget && !$earlierOpen) {
                if ($stage === 'qc') {
                    $qcBlocked = self::qcBlocked($order);
                    if ($qcBlocked) {
                        $message = "QC complete but checklist not passed (80% rule). Fix failing items before advancing to Pressing.";
                    }
                }

                // ── Material-consumption gate — Pattern only ─────────────────
                // Fabric yield is worked out at Pattern (marker planning), so
                // staff enter the consumption here. No formula/BOM: Gemini
                // recommends material TYPES only. If any accepted+linked
                // material lacks a quantity, hold the advance (same shape as
                // the QC gate) rather than record a wrong/missing quantity.
                if (!$qcBlocked && $stage === 'pattern') {
                    $materialsNeeding = $this->checkMaterialActualsGate($orderId, $actualsByMaterialId);
                    if (!empty($materialsNeeding)) {
                        $materialsBlocked = true;
                        $names = implode(', ', array_column($materialsNeeding, 'material_name'));
                        $message = "Pattern quantity complete, but material consumption must be entered before advancing to Segregation. Missing: {$names}.";
                    }
                }

                if (!$qcBlocked && !$materialsBlocked) {
                    $nextStage = self::STAGE_MAP[$stage] ?? null;
                    if ($nextStage) {
                        $this->moveStage($orderId, $stage, $nextStage);

                        // Pattern completes: record consumption only.
                        if ($stage === 'pattern') {
                            $this->recordMaterialConsumption($orderId, $actualsByMaterialId);
                        }

                        // ── Goods Issue to Production ──────────
                        // Cutting completes: the fabric is cut, so the
                        // consumption recorded at Pattern leaves stock now.
                        if ($stage === 'cutting') {
                            $result         = $this->issueMaterialsToProduction($orderId, $staffId);
                            $deductionLog   = $result['log'];
                            $lowStockAlerts = $result['low_stock'];
                        }

                        // ── Fully packed: ready for delivery ──
                        if ($nextStage === 'for_delivery') {
                            $this->autoCreateDelivery($orderId, $staffId, true);
                            $this->archiveCompletedDesign($orderId, $order);
                        }

                        $advanced  = true;
                        $newStage  = $nextStage;
                        $stageLabel = self::stageLabel($nextStage);
                        $message = "🎉 All {$qtyTarget} pieces completed — Order #{$orderId} advanced to {$stageLabel}!"
                            . (count($deductionLog) > 0 ? " " . count($deductionLog) . " materials issued to production." : "");

                        $this->notifyStageAdvance($orderId, $order->user_id, $stage, $nextStage, count($deductionLog));
                    }
                }
            }

            return [
                'error'                    => false,
                'already_complete'         => $alreadyComplete,
                'log_id'                   => $logId,
                'total_output'             => $alreadyComplete ? 0 : ($loggedRow->total_output ?? $thisBatch),
                'completed'                => (int) $newCompleted,
                'total'                    => (int) $qtyTarget,
                'pct'                      => $pct,
                'advanced'                 => $advanced,
                'new_stage'                => $newStage,
                'qc_blocked'               => $qcBlocked,
                'materials_blocked'        => $materialsBlocked,
                'materials_needing_actual' => $materialsNeeding,
                'message'                  => $message,
                'deduction_log'            => $deductionLog,
                'low_stock'                => $lowStockAlerts,
                'size_breakdown' => [
                    'xs' => $qtyXs, 's' => $qtyS, 'm' => $qtyM, 'l' => $qtyL,
                    'xl' => $qtyXl, 'xxl' => $qtyXxl, 'xxxl' => $qtyXxxl, 'custom' => $qtyCustom,
                ],
            ];
        });
    }

    public static function stageLabel(string $stage): string
    {
        return $stage === 'qc' ? 'QC' : ucwords(str_replace('_', ' ', $stage));
    }

    /** @return string[] pattern … packing */
    public static function productionStages(): array
    {
        return array_slice(array_keys(self::STAGE_MAP), 1);
    }

    public static function sizeCol(string $label): string
    {
        return match (strtoupper(trim($label))) {
            'XS' => 'xs', 'S' => 's', 'M' => 'm', 'L' => 'l', 'XL' => 'xl',
            'XXL', '2XL' => 'xxl', 'XXXL', '3XL' => 'xxxl',
            default => 'custom',
        };
    }

    // Ordered pieces per size column from measurements (standard rows' qty; a custom-measurement row takes the rest
    // of quantity_ordered). Null when the order has no size breakdown at all (older orders): only the total is enforced.
    public static function orderedBySize(int $orderId): ?array
    {
        $map       = array_fill_keys(self::SIZE_COLS, 0);
        $hasCustom = false;
        foreach (DB::table('measurements')->where('order_id', $orderId)->get(['type', 'size_label', 'qty']) as $m) {
            if ($m->type === 'custom') {
                $hasCustom = true;
            } elseif ((int) $m->qty > 0) {
                $map[self::sizeCol((string) $m->size_label)] += (int) $m->qty;
            }
        }
        if ($hasCustom) {
            $ordered        = (int) DB::table('orders')->where('order_id', $orderId)->value('quantity_ordered');
            $map['custom'] += max(0, $ordered - array_sum($map));
        }
        return array_sum($map) > 0 ? $map : null;
    }

    // Logged pieces as [stage => [size col => n]] for every production stage.
    public static function completedBySize(int $orderId): array
    {
        $out  = array_fill_keys(self::productionStages(), array_fill_keys(self::SIZE_COLS, 0));
        $sums = array_map(fn ($c) => DB::raw("COALESCE(SUM(qty_{$c}), 0) as {$c}"), self::SIZE_COLS);
        $rows = DB::table('daily_output_logs')->where('order_id', $orderId)->groupBy('stage')->select('stage', ...$sums)->get();
        foreach ($rows as $r) {
            if (!isset($out[$r->stage])) continue;
            foreach (self::SIZE_COLS as $c) $out[$r->stage][$c] = (int) $r->{$c};
        }
        return $out;
    }

    // Per order, stage and size: only sizes the order contains, never more than ordered, and after the first stage
    // never more than the previous stage has logged. Returns ['message', 'size', 'remaining'] or null when the batch fits.
    public function sizeLimitError(int $orderId, string $stage, array $batch): ?array
    {
        $ordered = self::orderedBySize($orderId);
        $done    = self::completedBySize($orderId);
        $stages  = self::productionStages();
        $prev    = $stages[array_search($stage, $stages, true) - 1] ?? null;
        $label   = self::stageLabel($stage);

        foreach ($batch as $col => $n) {
            if ($n <= 0) continue;
            $size = strtoupper($col);
            if ($ordered !== null && $ordered[$col] <= 0) {
                return ['message' => "Size {$size} is not in this order.", 'size' => $size, 'remaining' => 0];
            }
            $caps = [];
            if ($ordered !== null) $caps[] = $ordered[$col];
            if ($prev) $caps[] = $done[$prev][$col];
            if (!$caps) continue;
            $left = max(0, min($caps) - $done[$stage][$col]);
            if ($n > $left) {
                $why = $prev && ($ordered === null || $done[$prev][$col] < $ordered[$col])
                    ? self::stageLabel($prev) . " has finished {$done[$prev][$col]}"
                    : "the order has {$ordered[$col]}";
                return [
                    'message'   => "Size {$size}: only {$left} more can be logged at {$label} ({$why}; {$label} has {$done[$stage][$col]}).",
                    'size'      => $size,
                    'remaining' => $left,
                ];
            }
        }
        return null;
    }

    // Per stage {completed, total_ordered, in_progress = completed_prev - completed}, per size too, plus packed_total.
    public function productionSummary(int $orderId): ?array
    {
        $order = DB::table('orders')->where('order_id', $orderId)->first(['order_id', 'status', 'quantity_ordered']);
        if (!$order) return null;

        $ordered = self::orderedBySize($orderId);
        $done    = self::completedBySize($orderId);
        $total   = (int) $order->quantity_ordered;
        $sizes   = array_filter(self::SIZE_COLS, fn ($c) => $ordered ? $ordered[$c] > 0 : array_sum(array_column($done, $c)) > 0);

        $stages = [];
        $prev   = null;
        foreach (self::productionStages() as $s) {
            $completed = array_sum($done[$s]);
            $bySize    = [];
            foreach ($sizes as $c) {
                $bySize[strtoupper($c)] = [
                    'completed'     => $done[$s][$c],
                    'total_ordered' => $ordered[$c] ?? null,
                    'in_progress'   => $prev ? max(0, $done[$prev][$c] - $done[$s][$c]) : 0,
                ];
            }
            $stages[] = [
                'stage'         => $s,
                'label'         => self::stageLabel($s),
                'completed'     => $completed,
                'total_ordered' => $total,
                'in_progress'   => $prev ? max(0, array_sum($done[$prev]) - $completed) : 0,
                'sizes'         => $bySize,
            ];
            $prev = $s;
        }

        $packed    = array_sum($done['packing']);
        $delivered = (int) DB::table('delivery_tracking')->where('order_id', $orderId)->value('delivered_qty');
        $defects   = DB::table('daily_output_logs')->where('order_id', $orderId)->where('defect_count', '>', 0)
            ->groupBy('defect_size')->select('defect_size', DB::raw('SUM(defect_count) as qty'))->pluck('qty', 'defect_size');

        return [
            'order_id'           => (int) $order->order_id,
            'status'             => $order->status,
            'total_ordered'      => $total,
            'ordered_by_size'    => $ordered ? array_change_key_case(array_filter($ordered), CASE_UPPER) : null,
            'stages'             => $stages,
            'packed_total'       => $packed,
            'delivered_total'    => $delivered,
            'ready_for_delivery' => max(0, $packed - $delivered),
            'defects_by_size'    => $defects->map(fn ($q) => (int) $q),
        ];
    }

    // Only the staff who sewed pieces of this size on this order may report them defective, up to what they sewed.
    // Stored as a zero-output sewing row in daily_output_logs (defect_count, defect_size, defect_notes = reason).
    public function reportDefect(int $orderId, int $staffId, string $size, int $qty, string $reason): array
    {
        $col  = self::sizeCol($size);
        $size = strtoupper($col);

        return DB::transaction(function () use ($orderId, $staffId, $col, $size, $qty, $reason) {
            if (!DB::table('orders')->where('order_id', $orderId)->lockForUpdate()->exists()) {
                return ['error' => true, 'status' => 404, 'message' => 'Order not found.'];
            }
            $mine = DB::table('daily_output_logs')->where('order_id', $orderId)->where('stage', 'sewing')->where('logged_by', $staffId);
            $sewn = (int) (clone $mine)->sum("qty_{$col}");
            if ($sewn <= 0) {
                return ['error' => true, 'status' => 403, 'message' => "Only the staff who sewed size {$size} pieces on order #{$orderId} can report a defect on them."];
            }
            $left = max(0, $sewn - (int) (clone $mine)->where('defect_size', $size)->sum('defect_count'));
            if ($qty > $left) {
                return ['error' => true, 'status' => 422, 'message' => "You can report at most {$left} more size {$size} defect" . ($left === 1 ? '' : 's') . " ({$sewn} sewn by you).", 'remaining' => $left];
            }

            $logId = DB::table('daily_output_logs')->insertGetId([
                'order_id'     => $orderId,
                'stage'        => 'sewing',
                'logged_by'    => $staffId,
                'log_date'     => now()->toDateString(),
                'qty_xs' => 0, 'qty_s' => 0, 'qty_m' => 0, 'qty_l' => 0, 'qty_xl' => 0, 'qty_xxl' => 0, 'qty_xxxl' => 0, 'qty_custom' => 0,
                'defect_count' => $qty,
                'defect_size'  => $size,
                'defect_notes' => $reason,
                'created_at'   => now(),
                'updated_at'   => now(),
            ], 'log_id');

            return ['error' => false, 'log_id' => $logId, 'size' => $size, 'qty' => $qty, 'remaining' => $left - $qty];
        });
    }

    // Production stages before $stage (and $stage itself when $inclusive) that haven't met their target, e.g. ["QC 0/100"].
    public function incompleteStages(int $orderId, string $stage, bool $inclusive = false): array
    {
        $stages = array_slice(self::STAGE_MAP, 1, null, true); // pattern … packing
        $upTo   = array_search($stage, array_keys($stages), true);
        if ($upTo === false) return [];
        $check  = array_slice(array_keys($stages), 0, $upTo + ($inclusive ? 1 : 0));

        $ordered = (int) DB::table('orders')->where('order_id', $orderId)->value('quantity_ordered');
        $rows    = DB::table('order_production_tracking')->where('order_id', $orderId)->whereIn('stage', $check)->get()->keyBy('stage');

        $open = [];
        foreach ($check as $s) {
            $target = ($rows[$s]->qty_target ?? 0) > 0 ? (int) $rows[$s]->qty_target : $ordered;
            $done   = (int) ($rows[$s]->qty_completed ?? 0);
            if ($done < $target) $open[] = self::stageLabel($s) . " {$done}/{$target}";
        }
        return $open;
    }

    // ── Material-Actuals Gate — Pattern stage only ────────────────────────────
    // No formula/BOM exists anywhere in this system as of Aug 28 2026 — this
    // replaces what used to be a regex-parsed estimated_range quantity.
    // Returns an array of {material_id, material_name} for every
    // accepted+linked material_recommendations row on this order that the
    // caller did NOT supply a qty_used for (sentinel -1 in
    // $actualsByMaterialId means "key present in the lookup but no value was
    // given", 0 or not appearing in the lookup at all both also count as
    // missing — see logOutput()'s normalization step). Empty array = clear
    // to advance.
    private function checkMaterialActualsGate(int $orderId, array $actualsByMaterialId): array
    {
        $recs = DB::table('material_recommendations')
            ->where('order_id', $orderId)
            ->where('customer_accepted', 1)
            ->whereNotNull('material_id')
            ->get(['material_id', 'material_name']);

        if ($recs->isEmpty()) {
            return []; // nothing accepted+linked — nothing to gate on
        }

        $missing = [];
        foreach ($recs as $rec) {
            $qty = $actualsByMaterialId[$rec->material_id] ?? -1;
            if ($qty < 0) {
                $missing[] = ['material_id' => $rec->material_id, 'material_name' => $rec->material_name];
            }
        }
        return $missing;
    }

    // ── QC Gate ───────────────────────────────────────────────────────────────
    // Shared by logOutput() and ProductionController's manager override. Blocked unless QC is not required for the
    // order or its latest checklist passed (QCChecklistController sets passed: 80% of the sample AND every required check).
    public static function qcBlocked(object $order): bool
    {
        if (!$order->qc_required) {
            return false;
        }
        $latest = DB::table('qc_checklists')->where('order_id', $order->order_id)->orderByDesc('checked_at')->orderByDesc('check_id')->first();
        return !($latest && $latest->passed);
    }

    // A passing checklist submitted after every QC piece was already logged releases the held advance to Pressing,
    // so staff never have to log a dummy piece. Same checks as logOutput(): order at QC, QC and earlier stages complete.
    public function releaseQcHold(int $orderId): array
    {
        return DB::transaction(function () use ($orderId) {
            $order = DB::table('orders')->where('order_id', $orderId)->lockForUpdate()->first();
            if (!$order || $order->status !== 'qc') {
                return ['advanced' => false, 'new_stage' => $order->status ?? null, 'message' => null];
            }
            $open = $this->incompleteStages($orderId, 'qc', true);
            if ($open) {
                return ['advanced' => false, 'new_stage' => 'qc', 'message' => 'QC passed. The order moves to Pressing once these are logged: ' . implode(', ', $open) . '.'];
            }
            if (self::qcBlocked($order)) {
                return ['advanced' => false, 'new_stage' => 'qc', 'message' => null];
            }
            $next = self::STAGE_MAP['qc'];
            $this->moveStage($orderId, 'qc', $next);
            $this->notifyStageAdvance($orderId, $order->user_id, 'qc', $next);
            return ['advanced' => true, 'new_stage' => $next, 'message' => "QC passed. Order #{$orderId} advanced to " . self::stageLabel($next) . '.'];
        });
    }

    // The one place an order's stage changes; leaving QC stamps qc_passed_at.
    private function moveStage(int $orderId, string $from, string $to): void
    {
        DB::table('orders')->where('order_id', $orderId)->update(array_merge(
            ['status' => $to, 'updated_at' => now()],
            $from === 'qc' ? ['qc_passed_at' => now()] : []
        ));
        AuditLog::record('order_status_changed', 'orders', $orderId);
    }

    // ── Consumption at Pattern ────────────────────────────────
    // Stores the staff-entered fabric/material consumption (worked out from
    // the marker at Pattern) on each accepted+linked recommendation. Stock is
    // NOT touched here; issueMaterialsToProduction() deducts it at Cutting.
    // Rows already issued (issued_at set) are left alone.
    private function recordMaterialConsumption(int $orderId, array $actualsByMaterialId): void
    {
        $recs = DB::table('material_recommendations')
            ->where('order_id', $orderId)
            ->where('customer_accepted', 1)
            ->whereNotNull('material_id')
            ->whereNull('issued_at')
            ->get(['rec_id', 'material_id']);

        foreach ($recs as $rec) {
            $qty = $actualsByMaterialId[$rec->material_id] ?? -1;
            if ($qty < 0) continue; // gate already blocked a missing value; never guess one
            DB::table('material_recommendations')
                ->where('rec_id', $rec->rec_id)
                ->update(['actual_qty_issued' => $qty, 'updated_at' => now()]);
        }
    }

    // ── Goods Issue to Production (Cutting) ───────────────────
    // Deducts the consumption recorded at Pattern (actual_qty_issued) once
    // Cutting completes, then stamps issued_at so it can never deduct twice.
    // Only recommendations the customer accepted AND staff linked to a real
    // inventory item; no formula/BOM, the quantity is exactly what staff entered.
    // Public so ProductionController::advance() (manager override) issues the same way.
    public function issueMaterialsToProduction(int $orderId, int $actorId): array
    {
        $recs = DB::table('material_recommendations')
            ->where('order_id', $orderId)
            ->where('customer_accepted', 1)
            ->whereNotNull('material_id')
            ->whereNotNull('actual_qty_issued')
            ->whereNull('issued_at')
            ->get();

        $log      = [];
        $lowStock = [];

        if ($recs->isEmpty()) {
            Log::info("Order #{$orderId}: nothing left to issue at Cutting (no recorded consumption, or already issued)");
            return ['log' => [], 'low_stock' => []];
        }

        // Interview transcript (Ma'am Fe, Apr 30 2026), Bahagi D: for
        // subcontract jobs "Ang tela, supply ng OTG yan. Ang sinulid, garter
        // at iba pa, ino-order namin sa ibang supplier" — OTG supplies the
        // fabric; VFRB only sources thread/notions itself for those orders.
        // Without this, every subcontract order silently drained fabric
        // from VFRB's own materials.quantity_in_stock for stock VFRB never
        // actually bought for that job.
        $orderType = DB::table('orders')->where('order_id', $orderId)->value('order_type');

        foreach ($recs as $rec) {
            $material = DB::table('materials')
                ->where('material_id', $rec->material_id)
                ->lockForUpdate()
                ->first();

            if (!$material) continue;

            // Consumption staff entered at Pattern — never a computed estimate.
            $qty = (float) $rec->actual_qty_issued;

            // Client-supplied fabric on a subcontract order — record the
            // usage on the recommendation for the audit trail, but don't
            // touch VFRB's own stock or the inventory log; VFRB never held
            // this fabric. Non-fabric categories (thread, elastic, trims)
            // still deduct normally even on a subcontract order — those are
            // the materials VFRB sources itself regardless of order type.
            $isClientSuppliedFabric = $orderType === 'subcontract' && $material->category === 'Fabric';

            $before = (float) $material->quantity_in_stock;
            $after  = max(0, $before - $qty); // clamped — a typo'd qty_used can't push stock negative

            // Only touch stock + write a stock_out log if something was
            // actually used. A confirmed-zero usage still gets recorded on
            // the recommendation itself (below) so the audit trail shows it
            // was reviewed, not just skipped.
            if ($qty > 0 && !$isClientSuppliedFabric) {
                DB::table('materials')
                    ->where('material_id', $material->material_id)
                    ->update(['quantity_in_stock' => $after, 'updated_at' => now()]);

                DB::table('inventory_logs')->insert([
                    'material_id' => $material->material_id,
                    'recorded_by' => $actorId,
                    'type'        => 'stock_out',
                    'change_qty'  => -$qty,
                    'reason'      => "Issued to production — Order #{$orderId} (Cutting stage, consumption recorded at Pattern)",
                    'log_date'    => now(),
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }

            DB::table('material_recommendations')
                ->where('rec_id', $rec->rec_id)
                ->update([
                    'actual_qty_issued' => $qty,
                    'issued_at'         => now(),
                    'updated_at'        => now(),
                ]);

            // Client-supplied fabric never leaves this method's $log/$lowStock
            // — it never touched VFRB's stock, so it has no "before/after" or
            // low-stock state to report, and DailyOutputLog.jsx's "N
            // material(s) deducted from stock" count would be wrong if it did.
            if ($isClientSuppliedFabric) {
                continue;
            }

            $item = [
                'material_id'   => $material->material_id,
                'material_name' => $material->material_name,
                'unit'          => $material->unit,
                'deducted'      => round($qty, 4),
                'before'        => round($before, 4),
                'after'         => round($after, 4),
                'low_stock'     => $qty > 0 && $after <= (float) $material->reorder_threshold,
                'over_issued'   => $qty > $before, // qty_used exceeded stock on hand — likely a typo, needs a look
            ];
            $log[] = $item;
            if ($item['low_stock'] || $item['over_issued']) $lowStock[] = $item;
        }

        Log::info("Deduction complete — Order #{$orderId} — " . count($log) . " materials deducted");
        Cache::forget('materials_list');
        Cache::forget('admin_reports_index');
        Cache::forget('dashboard_stats');

        return ['log' => $log, 'low_stock' => $lowStock];
    }

    // ── Delivery record: created on the first packing log; customer notified once fully packed ──
    // Guards against a duplicate delivery row if ever triggered twice.
    private function autoCreateDelivery(int $orderId, int $actorId, bool $notify): void
    {
        $order = DB::table('orders')->where('order_id', $orderId)->first();

        if (!DB::table('delivery_tracking')->where('order_id', $orderId)->exists()) {
            $address = DB::table('users')->where('user_id', $order->user_id)->value('address');

            DB::table('delivery_tracking')->insert([
                'order_id'                => $orderId,
                'delivery_method'         => 'vfrb_deliver',
                'delivery_status'         => 'preparing',
                'delivery_address'        => $address ?? 'Address pending',
                'estimated_delivery_date' => now()->addDays(3)->toDateString(),
                'updated_by'              => $actorId,
                'notes'                   => 'Auto-created when packing started.',
                'created_at'              => now(),
                'updated_at'              => now(),
            ]);
        }
        if (!$notify) return;

        DB::table('notifications')->insert([
            'user_id'   => $order->user_id,
            'order_id'  => $orderId,
            'type'      => 'success',
            'title'     => "Order #{$orderId} Ready for Delivery",
            'message'   => "🎉 Order #{$orderId} is fully packed and ready for delivery.",
            'is_read'   => 0,
            'date_sent' => now(),
            'created_at'=> now(),
            'updated_at'=> now(),
        ]);
    }

    // ── Auto-archive design on order completion ─────────────────────────────
    // Realistic VFRB flow: a client (school, hospital) orders once a year,
    // reordering next year is "reconfigure last year's design", not
    // start-from-scratch. Snapshot studio_config into designs.custom_builder_config
    // so InspoGallery.jsx can list + reload it for this customer's future orders.
    private function archiveCompletedDesign(int $orderId, object $order): void
    {
        if (empty($order->studio_config)) return; // upload-only orders have no builder config to archive

        $configJson = (string) $order->studio_config;

        // Skip if this exact config is already archived for this customer —
        // a reorder of the same design shouldn't clutter their gallery with
        // near-identical rows. Link the order to the existing one instead.
        $duplicate = DB::table('designs')
            ->where('custom_builder_config', $configJson)
            ->whereIn('source_order_id', function ($q) use ($order) {
                $q->select('order_id')->from('orders')->where('user_id', $order->user_id);
            })
            ->first();

        if ($duplicate) {
            if (!$order->design_id) {
                DB::table('orders')->where('order_id', $orderId)->update(['design_id' => $duplicate->design_id]);
            }
            return;
        }

        $designId = DB::table('designs')->insertGetId([
            'design_name'           => "{$order->garment_type} — Order #{$orderId}",
            'garment_type'          => $order->garment_type,
            'category'              => null,
            'collar_type'           => $order->collar_type,
            'sleeve_type'           => $order->sleeve_type,
            'pocket_type'           => $order->pocket_type,
            'color'                 => $order->color,
            'photo_path'            => $order->client_design_preview_file,
            'custom_builder_config' => $configJson,
            'source_order_id'       => $orderId,
            'parent_design_id'      => $order->design_id,
            'is_active'             => 1,
            'created_at'            => now(),
            'updated_at'            => now(),
        ], 'design_id');

        if (!$order->design_id) {
            DB::table('orders')->where('order_id', $orderId)->update(['design_id' => $designId]);
        }
    }

    // ── Notify: stage advance ─────────────────────────────────────────────────
    // Ported verbatim from ProductionController::notifyStageAdvance() — chosen
    // as canonical over OutputLogController's inline version because it also
    // notifies all managers, not just the customer.
    private function notifyStageAdvance(int $orderId, int $customerId, string $fromStage, string $toStage, int $materialsDeductedCount = 0): void
    {
        $fromLabel = self::stageLabel($fromStage);
        $toLabel   = self::stageLabel($toStage);
        $now       = now();
        $suffix    = $materialsDeductedCount > 0 ? " · {$materialsDeductedCount} materials issued to production." : "";

        DB::table('notifications')->insert([
            'user_id'    => $customerId,
            'order_id'   => $orderId,
            'message'    => "Your order #{$orderId} has advanced from {$fromLabel} to {$toLabel}.{$suffix}",
            'type'       => 'production',
            'is_read'    => 0,
            'date_sent'  => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $managers = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('roles.name', 'manager')
            ->pluck('model_has_roles.model_id');

        foreach ($managers as $managerId) {
            DB::table('notifications')->insert([
                'user_id'    => $managerId,
                'order_id'   => $orderId,
                'message'    => "Order #{$orderId} advanced: {$fromLabel} → {$toLabel}.{$suffix}",
                'type'       => 'production',
                'is_read'    => 0,
                'date_sent'  => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
