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
//                            defect_count, alteration_count, log_date, notes
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
//   - Real output logging goes through OutputLogController::store(), which
//     delegates to App\Services\ProductionStageService::logOutput() — see
//     that service's file header for the full history.
//   - This controller previously had a second entry point, logProgress()
//     (routed at /log-output and /log-progress), never actually invoked
//     by any frontend page, with no unique logic of its own after the
//     Aug 28 2026 merge — removed as confirmed-dead code (re-applied
//     Aug 29 2026 after being found missing from this branch).
//   - stages() returns aggregate per stage for ProductionTracking.jsx pipeline display
//   - advance() is a direct manager override (bypasses qty check, keeps gate for QC)
//     — gated by ProductionStageService::qcBlocked() (same rule as output logging); notifyStageAdvance() stays local,
//     since it's a different operation (force-advance with no qty write) that
//     was never part of the two-path duplication being merged here.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Order;
use App\Notifications\OrderStatusNotification;
use App\Services\ClientMaterialService;
use App\Services\MaterialShortageService;
use App\Services\ProductionStageService;
use App\Support\EmailNotify;
use App\Support\StudioSet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProductionController extends Controller
{
    // ── Stage sequence (locked — never reorder) ───────────────────────────────
    const STAGE_MAP = [
        'confirmed' => 'pattern',
        'pattern' => 'segregation',
        'segregation' => 'cutting',
        'cutting' => 'sewing',
        'sewing' => 'qc',
        'qc' => 'pressing',
        'pressing' => 'packing',
        'packing' => 'for_delivery',
    ];

    // QC pass threshold (80% per master prompt interview)

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

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        // Get all tracking rows for this order
        $trackingRows = DB::table('order_production_tracking')
            ->where('order_id', $orderId)
            ->get()
            ->keyBy('stage');

        $stageNames = array_keys(array_filter(self::STAGE_MAP, fn ($v) => $v !== 'for_delivery'));
        // All 7 production stages
        $productionStages = ['pattern', 'segregation', 'cutting', 'sewing', 'qc', 'pressing', 'packing'];

        $stages = [];
        foreach ($productionStages as $stage) {
            $row = $trackingRows[$stage] ?? null;
            $qtyTarget = $row->qty_target ?? $order->quantity_ordered ?? 0;
            $qtyCompleted = $row->qty_completed ?? 0;
            $isComplete = $qtyCompleted >= $qtyTarget && $qtyTarget > 0;
            $pct = $qtyTarget > 0 ? min(100, round($qtyCompleted / $qtyTarget * 100)) : 0;

            $stages[] = [
                'stage' => $stage,
                'qty_target' => (int) $qtyTarget,
                'qty_completed' => (int) $qtyCompleted,
                'is_complete' => $isComplete,
                'pct' => $pct,
                'is_current' => $order->status === $stage,
                'tracking_id' => $row->tracking_id ?? null,
            ];
        }

        return response()->json([
            'order' => $order,
            'stages' => $stages,
        ]);
    }

    // ── POST /api/admin/orders/{id}/advance ───────────────────────────────────
    // Manager override — force-advance stage (still gates on QC)
    public function advance(Request $request, $orderId)
    {
        $order = DB::table('orders')->where('order_id', $orderId)->first();
        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $nextStage = self::STAGE_MAP[$order->status] ?? null;
        if (! $nextStage) {
            return response()->json(['message' => "Order status '{$order->status}' cannot be advanced."], 422);
        }
        if ($order->order_type !== 'subcontract' && empty($order->down_payment_received_at)) {
            return response()->json(['message' => "Production cannot start on order #{$orderId} until the down payment is recorded."], 422);
        }

        // Override skips the auto-advance timing, never the counts: the current stage and every earlier one must be complete.
        $open = (new ProductionStageService)->incompleteStages((int) $orderId, $order->status, true);
        if ($open) {
            return response()->json([
                'message' => 'Finish logging output before advancing: '.implode(', ', $open).'.',
                'incomplete_stages' => $open,
            ], 422);
        }

        // QC gate still applies for manager override too
        if ($order->status === 'qc' && ProductionStageService::qcBlocked($order)) {
            return response()->json([
                'message' => 'The latest QC checklist has not passed. Submit a passing QC checklist before advancing to Pressing.',
                'qc_blocked' => true,
            ], 422);
        }

        // Override never skips material consumption: Cutting deducts what Pattern recorded.
        if ($order->status === 'pattern') {
            $missing = DB::table('material_recommendations')
                ->where('order_id', $orderId)->where('customer_accepted', 1)
                ->whereNotNull('material_id')->whereNull('actual_qty_issued')->whereNull('issued_at')
                ->pluck('material_name');
            if ($missing->isNotEmpty()) {
                return response()->json([
                    'message' => 'Enter material consumption in the Daily Output Log before advancing past Pattern. Missing: '.$missing->implode(', ').'.',
                    'materials_blocked' => true,
                ], 422);
            }
        }

        // Override never skips client supply either: Cutting needs the client's materials on hand.
        if (in_array('cutting', [$order->status, $nextStage], true)) {
            if ($hold = ProductionStageService::colorHold((int) $orderId)) {
                return response()->json(['message' => $hold, 'color_hold' => true], 422);
            }
            $clientSvc = new ClientMaterialService;
            if ($short = $clientSvc->shortages((int) $orderId)) {
                return response()->json([
                    'message' => $clientSvc->shortageMessage((int) $orderId, $short),
                    'client_materials_short' => $short,
                ], 422);
            }
        }

        $deductionLog = [];
        DB::transaction(function () use ($orderId, $nextStage, $order, &$deductionLog) {
            DB::table('orders')
                ->where('order_id', $orderId)
                ->update([
                    'status' => $nextStage,
                    'updated_at' => now(),
                ]);
            // Same Goods Issue as the auto-advance: leaving Cutting deducts the Pattern consumption once.
            if ($order->status === 'cutting') {
                $deductionLog = (new ProductionStageService)->issueMaterialsToProduction((int) $orderId, (int) Auth::id())['log'];
            }
        });
        AuditLog::record('order_status_changed', 'orders', (int) $orderId);

        if ($order->status === 'qc') {
            DB::table('orders')
                ->where('order_id', $orderId)
                ->update(['qc_passed_at' => now()]);
        }

        $this->notifyStageAdvance($orderId, $order->user_id, $order->status, $nextStage);
        Cache::forget('dashboard_stats');

        return response()->json([
            'message' => "Order #{$orderId} advanced to ".ProductionStageService::stageLabel($nextStage).'.'
                .($deductionLog ? ' '.count($deductionLog).' material(s) deducted from stock.' : ''),
            'new_stage' => $nextStage,
            'deduction_log' => $deductionLog,
        ]);
    }

    // ── Notify: stage advance ─────────────────────────────────────────────────
    // Inserts into notifications for customer + all managers.
    // notifications schema: notif_id (AI), user_id, order_id, message, type, title, is_read, date_sent
    // type and title have defaults in DB (type = 'general', title = NULL) — safe to omit them,
    // but we include type for clarity since the column exists with DEFAULT 'general'.
    private function notifyStageAdvance(int $orderId, int $customerId, string $fromStage, string $toStage): void
    {
        $fromLabel = ucfirst($fromStage);
        $toLabel = ucfirst($toStage);
        $now = now();

        // Clients are not told about production stages: that is VFRB's decision (Oct 18 2026).
        // All managers
        $managers = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('roles.name', 'manager')
            ->pluck('model_has_roles.model_id');

        foreach ($managers as $managerId) {
            DB::table('notifications')->insert([
                'user_id' => $managerId,
                'order_id' => $orderId,
                'message' => "Order #{$orderId} advanced: {$fromLabel} → {$toLabel}.",
                'type' => 'production',
                'is_read' => 0,
                'date_sent' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    // ── PATCH /api/admin/orders/{id}/confirm ──────────────────────────────────
    // Moves order from pending → confirmed. Manager only (enforced in route middleware).
    // ProductionTracking.jsx / Orders.jsx call: PATCH /api/admin/orders/{orderId}/confirm
    //
    // MATERIAL FEASIBILITY CHECK — RETIRED Aug 29 2026 (Dave, confirmed via
    // Account 1). The hybrid hard-block design below (2026-08-02, citing
    // Ma'am Fe's real interview requirement — "make sure we have materials
    // we can use before accepting a PO") depended on estimated_range
    // carrying a real, trustworthy number. As of the Aug 28 2026 no-formula
    // redesign, estimated_range is never populated by any code path —
    // $needed always parses to 0, every material always lands in
    // $unverified, $shortages can never populate, and the hard-block below
    // was silently unreachable (not removed, just permanently dead) between
    // Aug 28 and this fix. That's a real regression against a cited
    // business rule, not a cosmetic gap — caught and reported by two
    // separate sessions before this fix landed.
    //
    // No replacement automated check exists, because there is no longer a
    // trustworthy number to check pre-confirmation against — actual
    // material usage isn't known until staff enter it at Pattern-stage
    // completion (ProductionStageService::checkMaterialActualsGate()),
    // which happens AFTER confirmation, not before. Ma'am Fe's underlying
    // need (avoid discovering a shortage mid-production) is now served by
    // that later gate instead: an order can't advance past Pattern without
    // staff confirming real usage against real stock, which is a stronger
    // check than the old estimate-based one, just later in the flow.
    //
    // materialCheck() (GET /orders/{id}/material-check) removed entirely —
    // confirmed zero live callers in the frontend (Orders.jsx calls
    // PATCH .../confirm directly and reads its 422 body, never this GET
    // endpoint). Its route removed from routes/api.php in this same change.
    //
    // KNOWN DOWNSTREAM EFFECT, NOT FIXED HERE (out of this file's scope):
    // Orders.jsx's MaterialWarningModal + override-reason UI was built
    // around confirm()'s old shortages/can_override 422 response. That
    // response shape no longer exists (confirm() now always succeeds or
    // 404s/422s only on order-state validation, never on material
    // shortage), so that modal is now permanently unreachable dead
    // frontend code. Flagging for whoever owns Orders.jsx next — not
    // touched here to stay inside this file's scope.
    public function confirm(Request $request, $orderId)
    {
        $order = DB::table('orders')->where('order_id', $orderId)->first();
        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }
        if ($order->status !== 'pending') {
            return response()->json([
                'message' => "Order is '{$order->status}' — only pending orders can be confirmed.",
            ], 422);
        }
        if ($err = Order::promisedDateError($order->promised_date ?? null)) {
            return response()->json(['message' => $err], 422);
        }

        DB::table('orders')
            ->where('order_id', $orderId)
            ->update(['status' => 'confirmed', 'updated_at' => now()]);
        AuditLog::record('order_status_changed', 'orders', (int) $orderId);

        // Notify customer
        DB::table('notifications')->insert([
            'user_id' => $order->user_id,
            'order_id' => $orderId,
            'message' => "Your order #{$orderId} has been confirmed. Production will begin soon.",
            'type' => 'order',
            'is_read' => 0,
            'date_sent' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Same email as confirming from Order Detail (OrderController::adminUpdate).
        EmailNotify::users([$order->user_id], new OrderStatusNotification((int) $orderId, 'confirmed'));

        $setConfirmed = StudioSet::confirmPendingSiblings((int) $orderId);
        (new ProductionStageService)->startProduction((int) $orderId);
        Cache::forget('dashboard_stats');

        return response()->json([
            'message' => "Order #{$orderId} confirmed.".($setConfirmed ? ' Same uniform set: order #'.implode(', #', $setConfirmed).' confirmed too.' : ''),
            'new_stage' => 'confirmed',
            'set_confirmed_ids' => $setConfirmed,
            'material_warnings' => app(MaterialShortageService::class)->forOrder((int) $orderId),
        ]);
    }
}
