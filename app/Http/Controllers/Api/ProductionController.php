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
//     — this one still uses checkQCGate()/notifyStageAdvance() locally below,
//     since it's a different operation (force-advance with no qty write) that
//     was never part of the two-path duplication being merged here.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\StageAdvancedNotification;
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

        // Real email to the customer only (Aug 25 2026) — managers already
        // get their own in-app notification above and don't need an email
        // for every stage move across every order; that would be far too
        // noisy for day-to-day staff use. Wrapped in try/catch for the same
        // reliability reason as OrderStatusNotification: the actual stage
        // advance already succeeded in the database by this point, so a
        // Mailtrap hiccup should never surface as a failed production
        // action to the staff member who just logged the output.
        try {
            $customer = User::find($customerId);
            if ($customer) {
                $customer->notify(new StageAdvancedNotification($orderId, $fromStage, $toStage));
            }
        } catch (\Throwable $e) {
            \Log::warning("StageAdvancedNotification failed for order #{$orderId}: " . $e->getMessage());
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
        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }
        if ($order->status !== 'pending') {
            return response()->json([
                'message' => "Order is '{$order->status}' — only pending orders can be confirmed.",
            ], 422);
        }

        DB::table('orders')
            ->where('order_id', $orderId)
            ->update(['status' => 'confirmed', 'updated_at' => now()]);

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
            'message'   => "Order #{$orderId} confirmed.",
            'new_stage' => 'confirmed',
        ]);
    }
}
