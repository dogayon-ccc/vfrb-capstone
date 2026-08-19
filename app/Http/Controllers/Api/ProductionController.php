<?php
// app/Http/Controllers/Api/ProductionController.php
// VFRB Enterprise — Production Stage Advance Engine
//
// Built against vfrb_db.sql (the source of truth).
//
// SCHEMA FACTS (confirmed from vfrb_db.sql):
//   orders:                  order_id, status (enum 11 values), quantity_ordered, qc_required, qc_passed_at
//   order_production_tracking: tracking_id, order_id, stage (enum 7), qty_target, qty_completed, updated_by, notes
//   daily_output_logs:       log_id, order_id, stage, logged_by,
//                            qty_xs/s/m/l/xl/xxl/xxxl/custom (individual columns),
//                            total_output (GENERATED — never insert),
//                            defect_count, alteration_count, log_date, notes, scanned_via_qr
//   qc_checklists:           check_id, order_id, checked_by, passed, items_checked, items_passed, items_failed
//   notifications:           notif_id, user_id, order_id, message, type (varchar default 'general'),
//                            title (varchar nullable), is_read, date_sent
//   NO production_logs table — does NOT exist.
//
// STAGE SEQUENCE (7 production stages — locked per master prompt):
//   pattern → segregation → cutting → sewing → qc → pressing → packing
//   orders.status also has: pending, confirmed, completed, cancelled
//
// AUTO-ADVANCE RULE:
//   When SUM(qty_completed for this order+stage in order_production_tracking) >= quantity_ordered
//   → UPDATE orders.status to the next stage
//   → Fire notifications to customer + all managers
//   Exception: QC → Pressing is BLOCKED unless qc_checklists.passed = 1 (80/20 rule)
//
// KEY DESIGN:
//   - logProgress() writes to BOTH daily_output_logs AND order_production_tracking
//     daily_output_logs: the per-size, per-date record (for staff audit trail)
//     order_production_tracking: the aggregate target/completed for auto-advance
//   - stages() returns aggregate per stage for ProductionTracking.jsx pipeline display
//   - advance() is a direct manager override (bypasses qty check, keeps gate for QC)

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class ProductionController extends Controller
{
    // ── Stage sequence (locked — never reorder) ───────────────────────────────
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

    // QC pass threshold (80% per master prompt interview)
    const QC_PASS_THRESHOLD = 0.80;

    // ── GET /api/admin/orders/{id}/production ─────────────────────────────────
    // Returns per-stage aggregate data for ProductionTracking.jsx pipeline display.
    // Response shape: { order: {...}, stages: [ { stage, qty_target, qty_completed, is_complete, pct } ] }
    public function stages(Request $request, $orderId)
    {
        $order = DB::table('orders')
            ->join('users', 'orders.user_id', '=', 'users.user_id')
            ->where('orders.order_id', $orderId)
            ->select(
                'orders.order_id',
                'orders.status',
                'orders.quantity_ordered',
                'orders.garment_type',
                'orders.color',
                'orders.qc_required',
                'orders.qc_passed_at',
                'users.name as customer_name'
            )
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        // Get all tracking rows for this order
        $trackingRows = DB::table('order_production_tracking')
            ->where('order_id', $orderId)
            ->get()
            ->keyBy('stage');

        $stageNames = array_keys(array_filter(self::STAGE_MAP, fn($v) => $v !== 'completed'));
        // All 7 production stages
        $productionStages = ['pattern','segregation','cutting','sewing','qc','pressing','packing'];

        $stages = [];
        foreach ($productionStages as $stage) {
            $row = $trackingRows[$stage] ?? null;
            $qtyTarget    = $row->qty_target    ?? $order->quantity_ordered ?? 0;
            $qtyCompleted = $row->qty_completed ?? 0;
            $isComplete   = $qtyCompleted >= $qtyTarget && $qtyTarget > 0;
            $pct          = $qtyTarget > 0 ? min(100, round($qtyCompleted / $qtyTarget * 100)) : 0;

            $stages[] = [
                'stage'         => $stage,
                'qty_target'    => (int) $qtyTarget,
                'qty_completed' => (int) $qtyCompleted,
                'is_complete'   => $isComplete,
                'pct'           => $pct,
                'is_current'    => $order->status === $stage,
                'tracking_id'   => $row->tracking_id ?? null,
            ];
        }

        return response()->json([
            'order'  => $order,
            'stages' => $stages,
        ]);
    }

    // ── POST /api/admin/orders/{id}/log-progress ──────────────────────────────
    // Staff logs qty per size for the current stage.
    // Writes to:
    //   1. daily_output_logs   — per-size audit trail (SIZE COLUMNS: qty_xs, qty_s, ..., qty_custom)
    //   2. order_production_tracking — aggregate for auto-advance
    // Returns: { completed, total, pct, advanced, new_stage, qc_blocked, message, size_breakdown }
    public function logProgress(Request $request, $orderId)
    {
        $request->validate([
            'stage'       => 'required|string|in:pattern,segregation,cutting,sewing,qc,pressing,packing',
            'qty_xs'      => 'integer|min:0',
            'qty_s'       => 'integer|min:0',
            'qty_m'       => 'integer|min:0',
            'qty_l'       => 'integer|min:0',
            'qty_xl'      => 'integer|min:0',
            'qty_xxl'     => 'integer|min:0',
            'qty_xxxl'    => 'integer|min:0',
            'qty_custom'  => 'integer|min:0',
            'notes'       => 'nullable|string|max:500',
            'log_date'    => 'nullable|date',
            'defect_count'     => 'integer|min:0',
            'alteration_count' => 'integer|min:0',
            'scanned_via_qr'   => 'boolean',
        ]);

        $order = DB::table('orders')->where('order_id', $orderId)->first();
        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $stage = $request->input('stage');

        // Validate stage matches current order status
        if ($order->status !== $stage) {
            return response()->json([
                'message'       => "Order is at stage '{$order->status}', not '{$stage}'.",
                'current_stage' => $order->status,
            ], 422);
        }

        $staffId = Auth::id();

        // Individual size quantities
        $qtyXs     = (int) $request->input('qty_xs',     0);
        $qtyS      = (int) $request->input('qty_s',      0);
        $qtyM      = (int) $request->input('qty_m',      0);
        $qtyL      = (int) $request->input('qty_l',      0);
        $qtyXl     = (int) $request->input('qty_xl',     0);
        $qtyXxl    = (int) $request->input('qty_xxl',    0);
        $qtyXxxl   = (int) $request->input('qty_xxxl',   0);
        $qtyCustom = (int) $request->input('qty_custom', 0);

        // total_output is GENERATED — compute here only for logic, never insert it
        $thisBatch = $qtyXs + $qtyS + $qtyM + $qtyL + $qtyXl + $qtyXxl + $qtyXxxl + $qtyCustom;

        if ($thisBatch <= 0) {
            return response()->json(['message' => 'At least 1 piece must be logged.'], 422);
        }

        $logDate = $request->input('log_date', now()->toDateString());

        // ── 1. Write to daily_output_logs (per-size audit trail) ─────────────
        // NEVER insert total_output — it is GENERATED ALWAYS AS (sum of all qty_* columns)
        DB::table('daily_output_logs')->insert([
            'order_id'         => $orderId,
            'stage'            => $stage,
            'logged_by'        => $staffId,
            'qty_xs'           => $qtyXs,
            'qty_s'            => $qtyS,
            'qty_m'            => $qtyM,
            'qty_l'            => $qtyL,
            'qty_xl'           => $qtyXl,
            'qty_xxl'          => $qtyXxl,
            'qty_xxxl'         => $qtyXxxl,
            'qty_custom'       => $qtyCustom,
            'defect_count'     => (int) $request->input('defect_count', 0),
            'alteration_count' => (int) $request->input('alteration_count', 0),
            'notes'            => $request->input('notes'),
            'log_date'         => $logDate,
            'scanned_via_qr'   => (int) $request->boolean('scanned_via_qr', false),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        // ── 2. Upsert order_production_tracking row ───────────────────────────
        // Use updateOrInsert so partial-progress rows accumulate correctly
        $existing = DB::table('order_production_tracking')
            ->where('order_id', $orderId)
            ->where('stage', $stage)
            ->first();

        if ($existing) {
            DB::table('order_production_tracking')
                ->where('tracking_id', $existing->tracking_id)
                ->update([
                    'qty_completed' => $existing->qty_completed + $thisBatch,
                    'updated_by'    => $staffId,
                    'notes'         => $request->input('notes') ?? $existing->notes,
                    'updated_at'    => now(),
                ]);
            $newCompleted = $existing->qty_completed + $thisBatch;
            $qtyTarget    = $existing->qty_target > 0 ? $existing->qty_target : $order->quantity_ordered;
        } else {
            $qtyTarget = (int) $order->quantity_ordered;
            DB::table('order_production_tracking')->insert([
                'order_id'      => $orderId,
                'stage'         => $stage,
                'qty_target'    => $qtyTarget,
                'qty_completed' => $thisBatch,
                'updated_by'    => $staffId,
                'notes'         => $request->input('notes'),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
            $newCompleted = $thisBatch;
        }

        $pct = $qtyTarget > 0 ? min(100, round($newCompleted / $qtyTarget * 100)) : 0;
        $advanced  = false;
        $qcBlocked = false;
        $newStage  = $stage;
        $message   = "{$newCompleted}/{$qtyTarget} pieces logged for " . ucfirst($stage) . ".";

        // ── 3. Auto-advance check ──────────────────────────────────────────────
        if ($newCompleted >= $qtyTarget) {
            // QC → Pressing gate (80/20 rule per interview)
            if ($stage === 'qc') {
                $qcBlocked = $this->checkQCGate($orderId);
                if ($qcBlocked) {
                    $message = "QC complete but checklist not passed (80% rule). Fix failing items before advancing to Pressing.";
                }
            }

            if (!$qcBlocked) {
                $nextStage = self::STAGE_MAP[$stage] ?? null;
                if ($nextStage) {
                    DB::table('orders')
                        ->where('order_id', $orderId)
                        ->update([
                            'status'     => $nextStage,
                            'updated_at' => now(),
                        ]);

                    // Mark QC passed timestamp
                    if ($stage === 'qc') {
                        DB::table('orders')
                            ->where('order_id', $orderId)
                            ->update(['qc_passed_at' => now()]);
                    }

                    $advanced  = true;
                    $newStage  = $nextStage;
                    $stageLabel = ucfirst($nextStage);
                    $message = "🎉 All {$qtyTarget} pieces completed — Order #{$orderId} advanced to {$stageLabel}!";

                    // Notify customer + all managers
                    $this->notifyStageAdvance($orderId, $order->user_id, $stage, $nextStage);
                }
            }
        }

        // Invalidate dashboard cache
        Cache::forget('dashboard_stats');

        return response()->json([
            'completed'      => (int) $newCompleted,
            'total'          => (int) $qtyTarget,
            'pct'            => $pct,
            'advanced'       => $advanced,
            'new_stage'      => $newStage,
            'qc_blocked'     => $qcBlocked,
            'message'        => $message,
            'size_breakdown' => [
                'xs'     => $qtyXs,
                's'      => $qtyS,
                'm'      => $qtyM,
                'l'      => $qtyL,
                'xl'     => $qtyXl,
                'xxl'    => $qtyXxl,
                'xxxl'   => $qtyXxxl,
                'custom' => $qtyCustom,
            ],
        ]);
    }

    // ── POST /api/admin/orders/{id}/advance ───────────────────────────────────
    // Manager override — force-advance stage (still gates on QC)
    public function advance(Request $request, $orderId)
    {
        $order = DB::table('orders')->where('order_id', $orderId)->first();
        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $nextStage = self::STAGE_MAP[$order->status] ?? null;
        if (!$nextStage) {
            return response()->json(['message' => "Order status '{$order->status}' cannot be advanced."], 422);
        }

        // QC gate still applies for manager override too
        if ($order->status === 'qc' && $this->checkQCGate($orderId)) {
            return response()->json([
                'message'    => 'QC checklist has not passed the 80% threshold. Submit a passing QC checklist before advancing to Pressing.',
                'qc_blocked' => true,
            ], 422);
        }

        DB::table('orders')
            ->where('order_id', $orderId)
            ->update([
                'status'     => $nextStage,
                'updated_at' => now(),
            ]);

        if ($order->status === 'qc') {
            DB::table('orders')
                ->where('order_id', $orderId)
                ->update(['qc_passed_at' => now()]);
        }

        $this->notifyStageAdvance($orderId, $order->user_id, $order->status, $nextStage);
        Cache::forget('dashboard_stats');

        return response()->json([
            'message'   => "Order #{$orderId} advanced to " . ucfirst($nextStage) . ".",
            'new_stage' => $nextStage,
        ]);
    }

    // ── QC Gate: 80/20 rule ───────────────────────────────────────────────────
    // Returns true if QC is BLOCKED (not passed), false if clear to advance.
    private function checkQCGate(int $orderId): bool
    {
        // QC not required = always passes
        $order = DB::table('orders')
            ->where('order_id', $orderId)
            ->select('qc_required')
            ->first();
        if (!$order || !$order->qc_required) {
            return false;
        }

        // Get latest QC checklist for this order
        $checklist = DB::table('qc_checklists')
            ->where('order_id', $orderId)
            ->orderByDesc('checked_at')
            ->first();

        if (!$checklist) {
            // No checklist submitted yet = blocked
            return true;
        }

        // Hard passed flag: explicit pass
        if ($checklist->passed) {
            return false;
        }

        // 80/20 rule: items_passed / items_checked >= 0.80
        if ($checklist->items_checked > 0) {
            $passRate = $checklist->items_passed / $checklist->items_checked;
            if ($passRate >= self::QC_PASS_THRESHOLD) {
                return false; // passes the 80% threshold
            }
        }

        return true; // blocked
    }

    // ── Notify: stage advance ─────────────────────────────────────────────────
    // Inserts into notifications for customer + all managers.
    // notifications schema: notif_id (AI), user_id, order_id, message, type, title, is_read, date_sent
    // type and title have defaults in DB (type = 'general', title = NULL) — safe to omit them,
    // but we include type for clarity since the column exists with DEFAULT 'general'.
    private function notifyStageAdvance(int $orderId, int $customerId, string $fromStage, string $toStage): void
    {
        $fromLabel = ucfirst($fromStage);
        $toLabel   = ucfirst($toStage);
        $now       = now();

        // Customer notification
        DB::table('notifications')->insert([
            'user_id'    => $customerId,
            'order_id'   => $orderId,
            'message'    => "Your order #{$orderId} has advanced from {$fromLabel} to {$toLabel}.",
            'type'       => 'production',
            'is_read'    => 0,
            'date_sent'  => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // All managers
        $managers = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('roles.name', 'manager')
            ->pluck('model_has_roles.model_id');

        foreach ($managers as $managerId) {
            DB::table('notifications')->insert([
                'user_id'    => $managerId,
                'order_id'   => $orderId,
                'message'    => "Order #{$orderId} advanced: {$fromLabel} → {$toLabel}.",
                'type'       => 'production',
                'is_read'    => 0,
                'date_sent'  => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    // ── PATCH /api/admin/orders/{id}/confirm ──────────────────────────────────
    // Moves order from pending → confirmed. Manager only (enforced in route middleware).
    // ProductionTracking.jsx / Orders.jsx call: PATCH /api/admin/orders/{orderId}/confirm
    //
    // MATERIAL FEASIBILITY — HYBRID DESIGN (2026-08-02):
    // Ma'am Fe, interview Apr 30 2026 (Bahagi J — Shortage at Readiness):
    //   "Hindi tayo kailangang magka-meron ng shortage ng materyales dahil
    //    bago natin i-receive ang PO, see to it na mayroon tayong materyales
    //    na magagamit." (We shouldn't have material shortages, because before
    //    we accept the PO, make sure we have materials we can use.)
    // Two states, not one:
    //   - Rate CONFIGURED (material_usage_rates has an entry) and stock is
    //     short → this is a real, trustworthy number. HARD BLOCK, same as
    //     the off-shade-fabric gate elsewhere in this codebase. A manager can
    //     override, but must type a reason — logged to orders.notes, all
    //     managers notified. This directly matches what Ma'am Fe described.
    //   - Rate NOT configured yet → estimated_range is a placeholder string,
    //     not a real number. Blocking on data we don't trust would be wrong.
    //     Confirm proceeds, but the response carries an `unverified` list so
    //     staff know feasibility wasn't actually checked for those items —
    //     never silently treated as "fine".
    // This check is now INLINE in confirm() itself — not a separate call the
    // frontend has to remember to make first. materialCheck() below still
    // exists as a read-only informational endpoint (harmless to keep), but
    // it is no longer what keeps a real shortage from being confirmed past.
    public function materialCheck(int $orderId)
    {
        $recs = DB::table('material_recommendations')
            ->join('materials', 'materials.material_id', '=', 'material_recommendations.material_id')
            ->where('material_recommendations.order_id', $orderId)
            ->where('material_recommendations.customer_accepted', 1)
            ->whereNotNull('material_recommendations.material_id')
            ->select(
                'materials.material_name',
                'materials.unit',
                'materials.quantity_in_stock',
                'material_recommendations.estimated_range'
            )
            ->get();

        if ($recs->isEmpty()) {
            // No accepted, linked recommendation yet — nothing to check
            // against. Silent pass, not a warning: this is the normal state
            // for an order that hasn't gone through AI/BOM acceptance.
            return response()->json(['has_shortage' => false, 'items' => [], 'checked' => false]);
        }

        $items = [];
        $hasShortage = false;

        foreach ($recs as $rec) {
            // Same parsing rule as OutputLogController::issueMaterialsToProduction() —
            // estimated_range is a string like "3.5 yards", extract the numeric part.
            $needed = (float) preg_replace('/[^0-9.]/', '', $rec->estimated_range ?? '0');
            if ($needed <= 0) continue;

            $stock = (float) $rec->quantity_in_stock;
            $sufficient = $stock >= $needed;
            if (!$sufficient) $hasShortage = true;

            $items[] = [
                'material_name'  => $rec->material_name,
                'unit'           => $rec->unit,
                'estimated_qty'  => $needed,
                'current_stock'  => $stock,
                'shortfall'      => $sufficient ? 0 : round($needed - $stock, 4),
                'sufficient'     => $sufficient,
            ];
        }

        return response()->json([
            'has_shortage' => $hasShortage,
            'items'        => $items,
            'checked'      => true,
        ]);
    }

    public function confirm(Request $request, $orderId)
    {
        $request->validate([
            'override'        => 'nullable|boolean',
            'override_reason' => 'required_if:override,true|nullable|string|max:500',
        ]);

        $order = DB::table('orders')->where('order_id', $orderId)->first();
        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }
        if ($order->status !== 'pending') {
            return response()->json([
                'message' => "Order is '{$order->status}' — only pending orders can be confirmed.",
            ], 422);
        }

        $recs = DB::table('material_recommendations')
            ->join('materials', 'materials.material_id', '=', 'material_recommendations.material_id')
            ->where('material_recommendations.order_id', $orderId)
            ->where('material_recommendations.customer_accepted', 1)
            ->whereNotNull('material_recommendations.material_id')
            ->select(
                'materials.material_id', 'materials.material_name', 'materials.unit',
                'materials.quantity_in_stock',
                'material_recommendations.estimated_range'
            )
            ->get();

        $shortages  = [];
        $unverified = [];

        foreach ($recs as $rec) {
            // A non-numeric estimated_range (e.g. "Not yet configured...")
            // parses to 0 here — that's how a genuinely-unset rate is told
            // apart from a real, trustworthy shortfall.
            $needed = (float) preg_replace('/[^0-9.]/', '', $rec->estimated_range ?? '0');

            if ($needed <= 0) {
                $unverified[] = [
                    'material_id'   => $rec->material_id,
                    'material_name' => $rec->material_name,
                ];
                continue;
            }

            $available = (float) $rec->quantity_in_stock;
            if ($needed > $available) {
                $shortages[] = [
                    'material_id'   => $rec->material_id,
                    'material_name' => $rec->material_name,
                    'unit'          => $rec->unit,
                    'needed'        => round($needed, 4),
                    'available'     => round($available, 4),
                    'short_by'      => round($needed - $available, 4),
                ];
            }
        }

        // Real, known shortage — hard block unless the manager overrides.
        if (!empty($shortages) && !$request->boolean('override')) {
            return response()->json([
                'message'      => 'Insufficient material stock for ' . count($shortages) . ' item(s). '
                    . 'Resolve the shortage (e.g. via RFQ) or confirm with an override reason.',
                'shortages'    => $shortages,
                'can_override' => true,
            ], 422);
        }

        DB::table('orders')
            ->where('order_id', $orderId)
            ->update(['status' => 'confirmed', 'updated_at' => now()]);

        // Log + notify managers when confirmed despite a real, known shortage
        if (!empty($shortages) && $request->boolean('override')) {
            $reason = $request->input('override_reason');
            $shortageSummary = implode('; ', array_map(
                fn($s) => "{$s['material_name']}: short by {$s['short_by']} {$s['unit']}",
                $shortages
            ));

            $now = now();
            DB::table('orders')->where('order_id', $orderId)->update([
                'notes' => trim(($order->notes ? $order->notes . "\n" : '')
                    . "[MATERIAL SHORTAGE OVERRIDE — {$now}] "
                    . "Confirmed despite shortage ({$shortageSummary}). Reason: {$reason}"),
            ]);

            $managers = DB::table('model_has_roles')
                ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                ->where('roles.name', 'manager')
                ->pluck('model_has_roles.model_id');

            foreach ($managers as $managerId) {
                DB::table('notifications')->insert([
                    'user_id'    => $managerId,
                    'order_id'   => $orderId,
                    'message'    => "Order #{$orderId} confirmed with a material shortage override ({$shortageSummary}). Reason given: \"{$reason}\".",
                    'type'       => 'production',
                    'is_read'    => 0,
                    'date_sent'  => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // Notify customer
        DB::table('notifications')->insert([
            'user_id'    => $order->user_id,
            'order_id'   => $orderId,
            'message'    => "Your order #{$orderId} has been confirmed. Production will begin soon.",
            'type'       => 'order',
            'is_read'    => 0,
            'date_sent'  => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Cache::forget('dashboard_stats');

        return response()->json([
            'message'    => "Order #{$orderId} confirmed.",
            'new_stage'  => 'confirmed',
            // Informational only — never blocks. Materials with no configured
            // usage rate, so feasibility genuinely couldn't be checked for them.
            'unverified' => $unverified,
        ]);
    }
}