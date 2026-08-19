<?php
// app/Http/Controllers/Api/OutputLogController.php
// VFRB Enterprise — Daily Output Log (out-putter record)
//
// SCHEMA VERIFIED against vfrb_db.sql:
//   daily_output_logs: log_id (PK), order_id, stage (enum 7 stages),
//     logged_by, qty_xs, qty_s, qty_m, qty_l, qty_xl, qty_xxl, qty_xxxl,
//     qty_custom (all int), total_output (GENERATED ALWAYS — NEVER INSERT),
//     defect_count (int), alteration_count (int), log_date (date),
//     notes (text), scanned_via_qr (tinyint), created_at, updated_at
//
// CRITICAL: total_output is GENERATED ALWAYS AS
//   (qty_xs+qty_s+qty_m+qty_l+qty_xl+qty_xxl+qty_xxxl+qty_custom) STORED
//   MySQL 8 throws error if you try to INSERT it. NEVER include it in inserts.
//
// This controller is the AUDIT TRAIL read path AND the write path that
// drives the auto-advance engine in order_production_tracking (see store()).
//
// PORTED FROM AdminOrderController (dead code, never wired to any route):
//   - issueMaterialsToProduction() — MIGO MT-261 Goods Issue. Fires when
//     the Pattern stage completes. Deducts only material_recommendations
//     rows the customer accepted AND staff already linked to real stock
//     (material_id not null). Row-locks each material to stay safe under
//     concurrent completions. This never ran anywhere before this port —
//     inventory only ever moved via manual stock-in/out or PO receipt.
//   - autoCreateDelivery() — creates the delivery_tracking row the moment
//     Packing completes (order status -> 'completed'). Never ran before
//     either — staff had to remember to create it manually.
// Both run inside the SAME DB transaction as the output-log write itself,
// so a failure anywhere rolls back everything atomically.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OutputLogController extends Controller
{
    // ── POST /api/admin/output-logs ───────────────────────────────────────────
    // DailyOutputLog.jsx submits here: POST /api/admin/output-logs
    // Inserts a daily_output_logs row AND updates order_production_tracking.
    // Also triggers auto-advance if running total >= quantity_ordered.
    //
    // CRITICAL: total_output is GENERATED ALWAYS — NEVER insert it.
    // We read it back after insert using SELECT total_output.
    public function store(Request $request)
    {
        $request->validate([
            'order_id'         => 'required|integer|exists:orders,order_id',
            'stage'            => 'required|in:pattern,segregation,cutting,sewing,qc,pressing,packing',
            'log_date'         => 'required|date',
            'qty_xs'           => 'nullable|integer|min:0',
            'qty_s'            => 'nullable|integer|min:0',
            'qty_m'            => 'nullable|integer|min:0',
            'qty_l'            => 'nullable|integer|min:0',
            'qty_xl'           => 'nullable|integer|min:0',
            'qty_xxl'          => 'nullable|integer|min:0',
            'qty_xxxl'         => 'nullable|integer|min:0',
            'qty_custom'       => 'nullable|integer|min:0',
            'defect_count'     => 'nullable|integer|min:0',
            'alteration_count' => 'nullable|integer|min:0',
            'defect_notes'     => 'nullable|string|max:1000',
            'notes'            => 'nullable|string|max:500',
            'scanned_via_qr'   => 'nullable|boolean',
        ]);

        $order = DB::table('orders')
            ->where('order_id', $request->order_id)
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        // Must match current stage
        if ($order->status !== $request->stage) {
            return response()->json([
                'message' => "Order is in '{$order->status}' stage, not '{$request->stage}'. Update the stage field to match.",
            ], 422);
        }

        $qtyFields = [
            'qty_xs'     => (int) $request->input('qty_xs',     0),
            'qty_s'      => (int) $request->input('qty_s',      0),
            'qty_m'      => (int) $request->input('qty_m',      0),
            'qty_l'      => (int) $request->input('qty_l',      0),
            'qty_xl'     => (int) $request->input('qty_xl',     0),
            'qty_xxl'    => (int) $request->input('qty_xxl',    0),
            'qty_xxxl'   => (int) $request->input('qty_xxxl',   0),
            'qty_custom' => (int) $request->input('qty_custom', 0),
        ];

        $batchTotal = array_sum($qtyFields);
        if ($batchTotal === 0) {
            return response()->json(['message' => 'At least one size quantity must be greater than 0.'], 422);
        }

        DB::beginTransaction();
        try {
            // INSERT — NEVER include total_output (GENERATED column)
            $logId = DB::table('daily_output_logs')->insertGetId([
                'order_id'         => $request->order_id,
                'stage'            => $request->stage,
                'logged_by'        => Auth::id(),
                'log_date'         => $request->log_date,
                'qty_xs'           => $qtyFields['qty_xs'],
                'qty_s'            => $qtyFields['qty_s'],
                'qty_m'            => $qtyFields['qty_m'],
                'qty_l'            => $qtyFields['qty_l'],
                'qty_xl'           => $qtyFields['qty_xl'],
                'qty_xxl'          => $qtyFields['qty_xxl'],
                'qty_xxxl'         => $qtyFields['qty_xxxl'],
                'qty_custom'       => $qtyFields['qty_custom'],
                // total_output OMITTED — GENERATED
                'defect_count'     => (int) $request->input('defect_count',     0),
                'alteration_count' => (int) $request->input('alteration_count', 0),
                'defect_notes'     => $request->input('defect_notes'),
                'scanned_via_qr'   => $request->boolean('scanned_via_qr') ? 1 : 0,
                'notes'            => $request->input('notes'),
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            // Read back the GENERATED total_output for this row
            $row = DB::table('daily_output_logs')
                ->where('log_id', $logId)
                ->first(['log_id', 'total_output']);

            // Running total for this order+stage (sum all qty columns — not total_output to avoid GENERATED issues)
            $runningTotal = (int) DB::table('daily_output_logs')
                ->where('order_id', $request->order_id)
                ->where('stage', $request->stage)
                ->sum(DB::raw('qty_xs+qty_s+qty_m+qty_l+qty_xl+qty_xxl+qty_xxxl+qty_custom'));

            // Update order_production_tracking
            DB::table('order_production_tracking')->updateOrInsert(
                ['order_id' => $request->order_id, 'stage' => $request->stage],
                [
                    'qty_target'    => $order->quantity_ordered,
                    'qty_completed' => min($runningTotal, $order->quantity_ordered),
                    'updated_by'    => Auth::id(),
                    'updated_at'    => now(),
                    'created_at'    => now(),
                ]
            );

            $advanced       = false;
            $newStatus      = $order->status;
            $deductionLog   = [];
            $lowStockAlerts = [];

            // Auto-advance when running total >= quantity ordered
            if ($runningTotal >= $order->quantity_ordered) {
                // FIX: this used to be a local $stageSeq array of only the 7
                // production stages with no 'completed' entry, so
                // $stageSeq[$curIdx + 1] was always null once $request->stage
                // was 'packing' — the advance block below was skipped
                // entirely, meaning orders NEVER reached 'completed' through
                // this path and autoCreateDelivery() (below) never fired,
                // despite being fully implemented and correctly gated.
                // ProductionController::STAGE_MAP is the locked, canonical
                // source of truth and already maps packing => completed —
                // reusing it here instead of a second, drifted copy.
                $next = ProductionController::STAGE_MAP[$request->stage] ?? null;

                // QC hold: block advance from QC → Pressing unless the checklist
                // passed the 80/20 rule (or QC isn't required for this order).
                // NOTE: this must key off $request->stage === 'qc' (the stage that
                // just hit 100%), never 'sewing' — qc_passed_at cannot exist yet
                // when sewing finishes, so gating there would block every order
                // from ever reaching QC.
                $qcHold = false;
                if ($request->stage === 'qc' && $order->qc_required) {
                    $checklist = DB::table('qc_checklists')
                        ->where('order_id', $request->order_id)
                        ->orderByDesc('checked_at')
                        ->first();

                    if (!$checklist) {
                        $qcHold = true; // no checklist submitted yet
                    } elseif (!$checklist->passed) {
                        $passRate = $checklist->items_checked > 0
                            ? $checklist->items_passed / $checklist->items_checked
                            : 0;
                        $qcHold = $passRate < 0.80; // 80/20 rule
                    }
                }

                if ($next && !$qcHold) {
                    DB::table('orders')
                        ->where('order_id', $request->order_id)
                        ->update(['status' => $next, 'updated_at' => now()]);

                    if ($request->stage === 'qc') {
                        DB::table('orders')
                            ->where('order_id', $request->order_id)
                            ->update(['qc_passed_at' => now()]);
                    }

                    $advanced  = true;
                    $newStatus = $next;

                    // ── MIGO MT-261 — Goods Issue to Production ──────────────
                    // Fires ONLY when Pattern completes — the one stage flagged
                    // for deduction (matches AdminOrderController::STAGE_MAP,
                    // which this project already treats as the source of truth
                    // for when materials are consumed).
                    if ($request->stage === 'pattern') {
                        $result         = $this->issueMaterialsToProduction($request->order_id, Auth::id());
                        $deductionLog   = $result['log'];
                        $lowStockAlerts = $result['low_stock'];
                    }

                    // ── Auto-create delivery record on order completion ──────
                    if ($next === 'completed') {
                        $this->autoCreateDelivery($request->order_id, Auth::id());
                    }

                    // Notify customer + manager
                    $label = ucfirst($next);
                    DB::table('notifications')->insert([
                        'user_id'   => $order->user_id,
                        'type'      => 'success',
                        'title'     => "Order #{$request->order_id} Advanced",
                        'message'   => "All pieces for {$request->stage} complete. Order advanced to {$label}."
                            . (count($deductionLog) > 0 ? " · MIGO MT-261: " . count($deductionLog) . " materials deducted." : ""),
                        'is_read'   => 0,
                        'date_sent' => now(),
                    ]);
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to log output. Please try again.'], 500);
        }

        return response()->json([
            'message'      => 'Output logged successfully.',
            'log_id'       => $logId,
            'total_output' => $row->total_output ?? $batchTotal,
            'stage_total'  => $runningTotal,
            'qty_ordered'  => (int) $order->quantity_ordered,
            'pct'          => round(($runningTotal / max($order->quantity_ordered, 1)) * 100),
            'advanced'     => $advanced,
            'new_status'   => $newStatus,
            'deduction_log'=> $deductionLog,
            'low_stock'    => $lowStockAlerts,
        ], 201);
    }

    // ── PRIVATE: MIGO MT-261 — Goods Issue to Production ───────────────────────
    // Ported from AdminOrderController::issueMaterialsToProduction() (dead code,
    // never wired to a route — this logic has never actually run in this system).
    //
    // Only deducts recommendations the customer explicitly accepted
    // (customer_accepted = 1) AND that staff already linked to a real
    // inventory item (material_id not null). A pending/rejected AI suggestion,
    // or one never linked to actual stock, is not a valid basis for touching
    // inventory — this is a real fix vs. the original dead code, which deducted
    // every recommendation regardless of acceptance status.
    private function issueMaterialsToProduction(int $orderId, int $actorId): array
    {
        $recs = DB::table('material_recommendations')
            ->where('order_id', $orderId)
            ->where('customer_accepted', 1)
            ->whereNotNull('material_id')
            ->get();

        $log      = [];
        $lowStock = [];

        if ($recs->isEmpty()) {
            Log::warning("MIGO MT-261: No accepted+linked material recommendations for Order #{$orderId}");
            return ['log' => [], 'low_stock' => []];
        }

        foreach ($recs as $rec) {
            // Row-lock — safe under concurrent stage completions (this
            // method runs inside store()'s existing DB transaction).
            $material = DB::table('materials')
                ->where('material_id', $rec->material_id)
                ->lockForUpdate()
                ->first();

            if (!$material) continue;

            // estimated_range is a string like "3.5 yards" — extract the numeric portion.
            $qty = (float) preg_replace('/[^0-9.]/', '', $rec->estimated_range ?? '0');
            if ($qty <= 0) continue;

            $before = (float) $material->quantity_in_stock;
            $after  = $before - $qty; // allowed to go negative — that's what triggers the low-stock flag below

            DB::table('materials')
                ->where('material_id', $material->material_id)
                ->update([
                    'quantity_in_stock' => $after,
                    'updated_at'        => now(),
                ]);

            DB::table('inventory_logs')->insert([
                'material_id' => $material->material_id,
                'recorded_by' => $actorId,
                'type'        => 'stock_out',
                'change_qty'  => -$qty,
                'reason'      => "MIGO MT-261 — Goods Issue to Production — Order #{$orderId}",
                'log_date'    => now(),
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            // Mark the recommendation as issued — completes the audit trail
            // the schema already has columns for (actual_qty_issued, issued_at)
            // but that were never actually being written before this port.
            DB::table('material_recommendations')
                ->where('rec_id', $rec->rec_id)
                ->update([
                    'actual_qty_issued' => $qty,
                    'issued_at'         => now(),
                    'updated_at'        => now(),
                ]);

            $item = [
                'material_id'   => $material->material_id,
                'material_name' => $material->material_name,
                'unit'          => $material->unit,
                'deducted'      => round($qty, 4),
                'before'        => round($before, 4),
                'after'         => round($after, 4),
                'low_stock'     => $after <= (float) $material->reorder_threshold,
            ];
            $log[] = $item;
            if ($item['low_stock']) $lowStock[] = $item;
        }

        Log::info("MIGO MT-261 complete — Order #{$orderId} — " . count($log) . " materials deducted");
        return ['log' => $log, 'low_stock' => $lowStock];
    }

    // ── PRIVATE: auto-create delivery record on order completion ───────────────
    // Ported from AdminOrderController::autoCreateDelivery() (dead code, never
    // wired to a route). Guards against duplicate delivery rows if this were
    // ever somehow triggered twice for the same order.
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

    // ── GET /api/admin/output-logs/summary/{orderId} ──────────────────────────
    // DailyOutputLog.jsx calls: GET /api/admin/output-logs/summary/{form.order_id}
    // Route param version — summary() uses query param version above
    public function summaryByOrder(int $orderId)
    {
        $order = DB::table('orders')->where('order_id', $orderId)->first();
        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $byStage = DB::table('daily_output_logs')
            ->where('order_id', $orderId)
            ->select(
                'stage',
                DB::raw('SUM(qty_xs+qty_s+qty_m+qty_l+qty_xl+qty_xxl+qty_xxxl+qty_custom) as stage_total'),
                DB::raw('SUM(defect_count)     as total_defects'),
                DB::raw('SUM(alteration_count) as total_alterations'),
                DB::raw('MAX(log_date)         as last_logged')
            )
            ->groupBy('stage')
            ->orderByRaw("FIELD(stage,'pattern','segregation','cutting','sewing','qc','pressing','packing')")
            ->get();

        return response()->json([
            'order_id'       => $orderId,
            'quantity_ordered'=> $order->quantity_ordered,
            'current_stage'  => $order->status,
            'by_stage'       => $byStage,
        ]);
    }

    // ── GET /api/admin/output-logs ────────────────────────────────────────────
    // List all output logs (optionally filtered by order or stage)
    public function index(Request $request)
    {
        $orderId = $request->input('order_id');
        $stage   = $request->input('stage');
        $perPage = (int) $request->input('per_page', 20);

        $query = DB::table('daily_output_logs')
            ->join('orders', 'daily_output_logs.order_id', '=', 'orders.order_id')
            ->join('users',  'daily_output_logs.logged_by', '=', 'users.user_id')
            ->select(
                'daily_output_logs.*',  // includes GENERATED total_output — safe to SELECT
                'orders.garment_type',
                'orders.color',
                'users.name as logged_by_name'
            );

        if ($orderId) {
            $query->where('daily_output_logs.order_id', $orderId);
        }

        if ($stage) {
            $query->where('daily_output_logs.stage', $stage);
        }

        return response()->json(
            $query->orderByDesc('daily_output_logs.log_date')
                  ->orderByDesc('daily_output_logs.log_id')
                  ->paginate($perPage)
        );
    }

    // ── GET /api/admin/output-logs/{id} ───────────────────────────────────────
    public function show(int $id)
    {
        $row = DB::table('daily_output_logs')
            ->join('orders', 'daily_output_logs.order_id', '=', 'orders.order_id')
            ->join('users',  'daily_output_logs.logged_by', '=', 'users.user_id')
            ->where('daily_output_logs.log_id', $id)
            ->select(
                'daily_output_logs.*',
                'orders.garment_type', 'orders.color', 'orders.quantity_ordered',
                'users.name as logged_by_name'
            )
            ->first();

        if (!$row) {
            return response()->json(['message' => 'Log entry not found.'], 404);
        }

        return response()->json($row);
    }

    // ── GET /api/admin/output-logs/summary ───────────────────────────────────
    // Daily summary: total output per stage per date for a given order
    public function summary(Request $request)
    {
        $request->validate([
            'order_id' => 'required|integer|exists:orders,order_id',
        ]);

        $orderId = $request->input('order_id');

        // Aggregate by stage + log_date using total_output (GENERATED column — safe to read)
        $rows = DB::table('daily_output_logs')
            ->where('order_id', $orderId)
            ->select(
                'stage',
                'log_date',
                DB::raw('SUM(total_output) as daily_total'),
                DB::raw('SUM(defect_count) as total_defects'),
                DB::raw('SUM(alteration_count) as total_alterations'),
                DB::raw('COUNT(*) as log_count')
            )
            ->groupBy('stage', 'log_date')
            ->orderBy('log_date')
            ->get();

        return response()->json($rows);
    }

    // ── DELETE /api/admin/output-logs/{id} ────────────────────────────────────
    // Manager only — remove a mis-entered log
    public function destroy(int $id)
    {
        $row = DB::table('daily_output_logs')->where('log_id', $id)->first();
        if (!$row) {
            return response()->json(['message' => 'Log entry not found.'], 404);
        }

        DB::table('daily_output_logs')->where('log_id', $id)->delete();

        // Recalculate order_production_tracking for this order+stage
        $newCompleted = DB::table('daily_output_logs')
            ->where('order_id', $row->order_id)
            ->where('stage', $row->stage)
            ->sum('total_output');   // safe to read GENERATED column

        DB::table('order_production_tracking')
            ->where('order_id', $row->order_id)
            ->where('stage', $row->stage)
            ->update([
                'qty_completed' => max(0, (int) $newCompleted),
                'updated_at'    => now(),
            ]);

        return response()->json(['message' => 'Log entry deleted and production tracking recalculated.']);
    }
}