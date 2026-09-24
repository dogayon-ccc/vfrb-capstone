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
//   qc_checklists:             passed (explicit flag) OR items_passed/
//                               items_checked >= 80% — either clears the gate
//   material_recommendations:  actual_qty_issued, issued_at — written here,
//                               now from staff-entered actual usage (see
//                               issueMaterialsToProduction()), not a parsed
//                               estimate. No formula/BOM exists in this
//                               system as of Aug 28 2026.
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
        'packing'     => 'completed',
    ];

    const QC_PASS_THRESHOLD = 0.80;

    /**
     * Log production output for one order+stage and, if the running total
     * now meets the target, advance the order's stage — with the QC gate,
     * inventory deduction (Pattern), auto-delivery creation (Packing), and
     * notifications all as one atomic unit.
     *
     * @param int   $orderId
     * @param string $stage      one of the 7 production stages; caller has
     *                           already validated this against the enum
     * @param array $qty         ['qty_xs'=>int, 'qty_s'=>int, ... 'qty_custom'=>int]
     * @param array $meta        ['staff_id'=>int, 'notes'=>?string,
     *                            'log_date'=>string, 'defect_count'=>int,
     *                            'alteration_count'=>int, 'defect_notes'=>?string]
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

        if ($thisBatch <= 0) {
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
                'defect_count'     => (int) ($meta['defect_count'] ?? 0),
                'alteration_count' => (int) ($meta['alteration_count'] ?? 0),
                'defect_notes'     => $meta['defect_notes'] ?? null,
                'notes'            => $notes,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            $loggedRow = DB::table('daily_output_logs')->where('log_id', $logId)->first(['log_id', 'total_output']);

            // ── 2. Upsert order_production_tracking row, locked ────────────────
            $existing = DB::table('order_production_tracking')
                ->where('order_id', $orderId)
                ->where('stage', $stage)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $newCompleted = $existing->qty_completed + $thisBatch;
                DB::table('order_production_tracking')
                    ->where('tracking_id', $existing->tracking_id)
                    ->update([
                        'qty_completed' => $newCompleted,
                        'updated_by'    => $staffId,
                        'notes'         => $notes ?? $existing->notes,
                        'updated_at'    => now(),
                    ]);
                $qtyTarget = $existing->qty_target > 0 ? $existing->qty_target : $order->quantity_ordered;
            } else {
                $qtyTarget = (int) $order->quantity_ordered;
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

            $pct = $qtyTarget > 0 ? min(100, round($newCompleted / $qtyTarget * 100)) : 0;
            $advanced            = false;
            $qcBlocked           = false;
            $materialsBlocked    = false;
            $materialsNeeding    = [];
            $newStage            = $stage;
            $deductionLog        = [];
            $lowStockAlerts      = [];
            $message = "{$newCompleted}/{$qtyTarget} pieces logged for " . ucfirst($stage) . ".";

            // ── 3. Auto-advance check ──────────────────────────────────────────
            if ($newCompleted >= $qtyTarget) {
                if ($stage === 'qc') {
                    $qcBlocked = $this->checkQCGate($order);
                    if ($qcBlocked) {
                        $message = "QC complete but checklist not passed (80% rule). Fix failing items before advancing to Pressing.";
                    }
                }

                // ── Material-actuals gate — Pattern only ─────────────────────
                // No formula/BOM anywhere in this system (locked design
                // decision, Aug 28 2026): Gemini recommends material TYPES
                // only. Automated deduction still happens on Pattern
                // completion, but the QUANTITY now comes from staff manually
                // entering actual usage, not a computed estimate. If there
                // are accepted+linked recommendations on this order and the
                // caller didn't supply an actual for every one of them, hold
                // the advance — same shape as the QC gate above — rather
                // than deduct a wrong/missing quantity or silently skip
                // inventory movement.
                if (!$qcBlocked && $stage === 'pattern') {
                    $materialsNeeding = $this->checkMaterialActualsGate($orderId, $actualsByMaterialId);
                    if (!empty($materialsNeeding)) {
                        $materialsBlocked = true;
                        $names = implode(', ', array_column($materialsNeeding, 'material_name'));
                        $message = "Pattern quantity complete, but actual material usage must be entered before advancing to Segregation. Missing: {$names}.";
                    }
                }

                if (!$qcBlocked && !$materialsBlocked) {
                    $nextStage = self::STAGE_MAP[$stage] ?? null;
                    if ($nextStage) {
                        DB::table('orders')
                            ->where('order_id', $orderId)
                            ->update(['status' => $nextStage, 'updated_at' => now()]);

                        if ($stage === 'qc') {
                            DB::table('orders')->where('order_id', $orderId)->update(['qc_passed_at' => now()]);
                        }

                        // ── Goods Issue to Production ──────────
                        // Fires ONLY when Pattern completes (locked rule, was
                        // previously only reachable via OutputLogController).
                        // Quantity source: staff-entered $actualsByMaterialId,
                        // gated above — never a parsed/computed estimate.
                        if ($stage === 'pattern') {
                            $result         = $this->issueMaterialsToProduction($orderId, $staffId, $actualsByMaterialId);
                            $deductionLog   = $result['log'];
                            $lowStockAlerts = $result['low_stock'];
                        }

                        // ── Auto-create delivery record on order completion ──
                        if ($nextStage === 'completed') {
                            $this->autoCreateDelivery($orderId, $staffId);
                            $this->archiveCompletedDesign($orderId, $order);
                        }

                        $advanced  = true;
                        $newStage  = $nextStage;
                        $stageLabel = ucfirst($nextStage);
                        $message = "🎉 All {$qtyTarget} pieces completed — Order #{$orderId} advanced to {$stageLabel}!"
                            . (count($deductionLog) > 0 ? " " . count($deductionLog) . " materials issued to production." : "");

                        $this->notifyStageAdvance($orderId, $order->user_id, $stage, $nextStage, count($deductionLog));
                    }
                }
            }

            return [
                'error'                    => false,
                'log_id'                   => $logId,
                'total_output'             => $loggedRow->total_output ?? $thisBatch,
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

    // ── QC Gate: 80/20 rule ───────────────────────────────────────────────────
    // Ported verbatim from ProductionController::checkQCGate() — that version
    // was chosen as canonical over OutputLogController's inline duplicate
    // because it also honors an explicit `passed` flag (not just the 80%
    // rate) and correctly short-circuits when qc_required = 0.
    // Takes the already-locked $order row (from the transaction above)
    // instead of re-querying it, since we already have it under lock.
    private function checkQCGate(object $order): bool
    {
        if (!$order->qc_required) {
            return false;
        }

        $checklist = DB::table('qc_checklists')
            ->where('order_id', $order->order_id)
            ->orderByDesc('checked_at')
            ->first();

        if (!$checklist) {
            return true; // no checklist submitted yet = blocked
        }

        if ($checklist->passed) {
            return false; // explicit pass
        }

        if ($checklist->items_checked > 0) {
            $passRate = $checklist->items_passed / $checklist->items_checked;
            if ($passRate >= self::QC_PASS_THRESHOLD) {
                return false;
            }
        }

        return true; // blocked
    }

    // ── Goods Issue to Production ─────────────────────────────
    // REWRITTEN Aug 28 2026 (scope correction, not a syntax fix): this used
    // to parse a quantity out of estimated_range with a regex. That's gone —
    // there is no formula/BOM anywhere in this system now, per the locked
    // design decision. Gemini recommends material TYPES only; the actual
    // quantity deducted here is exactly what staff typed in
    // $actualsByMaterialId, gated by checkMaterialActualsGate() before this
    // method is ever called (every accepted+linked material is guaranteed
    // to have an entry by the time we get here — the ?? -1 fallback below is
    // defensive only, it should never actually fire).
    // Only deducts recommendations the customer explicitly accepted
    // (customer_accepted = 1) AND that staff already linked to a real
    // inventory item (material_id not null).
    private function issueMaterialsToProduction(int $orderId, int $actorId, array $actualsByMaterialId): array
    {
        $recs = DB::table('material_recommendations')
            ->where('order_id', $orderId)
            ->where('customer_accepted', 1)
            ->whereNotNull('material_id')
            ->get();

        $log      = [];
        $lowStock = [];

        if ($recs->isEmpty()) {
            Log::warning("No accepted+linked material recommendations for Order #{$orderId}");
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

            // Staff-entered actual usage — NOT a computed estimate. -1
            // sentinel ("no value given") should be unreachable here because
            // checkMaterialActualsGate() already blocked the advance if any
            // accepted+linked material lacked an actual; if it somehow still
            // shows up, skip that material entirely (don't guess a quantity)
            // and log it loudly rather than silently deduct 0.
            $qty = $actualsByMaterialId[$rec->material_id] ?? -1;
            if ($qty < 0) {
                Log::warning("material_id {$rec->material_id} on Order #{$orderId} reached issueMaterialsToProduction() with no actual — gate should have caught this. Skipped, not deducted.");
                continue;
            }

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
                    'reason'      => "Issued to production — Order #{$orderId} (Pattern stage, staff-entered actual usage)",
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

    // ── Auto-create delivery record on order completion ────────────────────
    // Ported verbatim from OutputLogController::autoCreateDelivery().
    // Guards against a duplicate delivery row if ever triggered twice.
    private function autoCreateDelivery(int $orderId, int $actorId): void
    {
        $exists = DB::table('delivery_tracking')->where('order_id', $orderId)->exists();
        if ($exists) return;

        $order   = DB::table('orders')->where('order_id', $orderId)->first();
        $address = DB::table('users')->where('user_id', $order->user_id)->value('address');

        DB::table('delivery_tracking')->insert([
            'order_id'                => $orderId,
            'delivery_method'         => 'vfrb_deliver',
            'delivery_status'         => 'preparing',
            'delivery_address'        => $address ?? 'Address pending',
            'estimated_delivery_date' => now()->addDays(3)->toDateString(),
            'updated_by'              => $actorId,
            'notes'                   => 'Auto-created on order completion.',
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);

        DB::table('notifications')->insert([
            'user_id'   => $order->user_id,
            'order_id'  => $orderId,
            'type'      => 'success',
            'title'     => "Order #{$orderId} Ready for Delivery",
            'message'   => "🎉 Order #{$orderId} is complete! Delivery is being prepared.",
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
        ]);

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
        $fromLabel = ucfirst($fromStage);
        $toLabel   = ucfirst($toStage);
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
