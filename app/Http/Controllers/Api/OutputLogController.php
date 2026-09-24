<?php
// app/Http/Controllers/Api/OutputLogController.php
// VFRB Enterprise — Daily Output Log (out-putter record)
//
// SCHEMA VERIFIED against vfrb_db.sql:
//   daily_output_logs: log_id (PK), order_id, stage (enum 7 stages),
//     logged_by, qty_xs, qty_s, qty_m, qty_l, qty_xl, qty_xxl, qty_xxxl,
//     qty_custom (all int), total_output (GENERATED ALWAYS — NEVER INSERT),
//     defect_count (int), alteration_count (int), log_date (date),
//     notes (text), created_at, updated_at
//
// CRITICAL: total_output is GENERATED ALWAYS AS
//   (qty_xs+qty_s+qty_m+qty_l+qty_xl+qty_xxl+qty_xxxl+qty_custom) STORED
//   MySQL 8 throws error if you try to INSERT it. NEVER include it in inserts.
//
// This controller is the AUDIT TRAIL read path (index/show/summary) AND the
// write path (store()) for staff logging daily output.
//
// AS OF Aug 28 2026, store()'s actual stage-advance logic — including the
// goods-issue deduction on Pattern completion and auto-delivery
// creation on Packing completion — was extracted into
// App\Services\ProductionStageService, shared with
// ProductionController::logProgress(). See that service's file header for
// the full history of why (two independently-drifted code paths, only one
// of which had transaction locking, only one of which triggered real
// inventory movement). store() below just validates the request and
// delegates.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
            // See ProductionController::logProgress() for the full comment
            // on why this is shape-only validation, not required_if.
            'material_actuals'               => 'nullable|array',
            'material_actuals.*.material_id' => 'required_with:material_actuals|integer|exists:materials,material_id',
            'material_actuals.*.qty_used'    => 'required_with:material_actuals|numeric|min:0',
        ]);

        // Fast-path existence check only — kept here so a plainly-missing
        // order 404s before any qty parsing happens. NOT relied on for the
        // stage-match check any more: that check (and the order fetch used
        // for the actual mutation) now happens again inside
        // ProductionStageService::logOutput(), under lockForUpdate(), which
        // is the authoritative check. This one is a convenience early-exit,
        // not a security or correctness boundary.
        $order = DB::table('orders')->where('order_id', $request->order_id)->first();
        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        // CONSOLIDATED (Aug 28 2026): everything that used to run inline here
        // (unlocked read-modify-write of qty_completed, QC gate, auto-advance,
        // inventory deduction, auto-delivery creation, notify) now
        // lives in ProductionStageService::logOutput(), shared with
        // ProductionController::logProgress(). The real fix that comes along
        // with this merge for THIS endpoint specifically: the read-modify-write
        // of qty_completed is now inside DB::transaction() + lockForUpdate() on
        // both the orders row and the order_production_tracking row — this
        // endpoint never had that locking before (it used a plain
        // DB::beginTransaction()/commit() with no row locks at all), only
        // logProgress() did. The stage-mismatch check that already existed
        // here is preserved — the service performs the same check again,
        // authoritatively, under lock.
        // DailyOutputLog.jsx needs no changes — the request shape and this
        // response shape are both unchanged below.
        $result = (new \App\Services\ProductionStageService())->logOutput(
            $request->order_id,
            $request->stage,
            [
                'qty_xs'     => $request->input('qty_xs',     0),
                'qty_s'      => $request->input('qty_s',      0),
                'qty_m'      => $request->input('qty_m',      0),
                'qty_l'      => $request->input('qty_l',      0),
                'qty_xl'     => $request->input('qty_xl',     0),
                'qty_xxl'    => $request->input('qty_xxl',    0),
                'qty_xxxl'   => $request->input('qty_xxxl',   0),
                'qty_custom' => $request->input('qty_custom', 0),
            ],
            [
                'staff_id'         => Auth::id(),
                'log_date'         => $request->log_date,
                'notes'            => $request->input('notes'),
                'defect_count'     => $request->input('defect_count',     0),
                'alteration_count' => $request->input('alteration_count', 0),
                'defect_notes'     => $request->input('defect_notes'),
            ],
            $request->input('material_actuals', [])
        );

        if ($result['error']) {
            // NOTE (correcting my own earlier comment in this exact spot):
            // the pre-merge code here DID already have a stage-mismatch
            // check (compared $order->status to $request->stage, returned
            // 422 on mismatch) — I mis-stated that it didn't, in an earlier
            // pass. What's actually new here is the LOCKING around that
            // check: the pre-merge check read $order unlocked, so a second
            // concurrent request could still slip past it before the first
            // committed. The service now performs the same check
            // authoritatively, inside lockForUpdate().
            return response()->json([
                'message' => $result['message'],
            ], $result['status']);
        }

        return response()->json([
            'message'                  => 'Output logged successfully.',
            'log_id'                   => $result['log_id'],
            'total_output'             => $result['total_output'],
            'stage_total'              => $result['completed'],
            'qty_ordered'              => $result['total'],
            'pct'                      => $result['pct'],
            'advanced'                 => $result['advanced'],
            'new_status'               => $result['new_stage'],
            'materials_blocked'        => $result['materials_blocked'],
            'materials_needing_actual' => $result['materials_needing_actual'],
            'deduction_log'            => $result['deduction_log'],
            'low_stock'                => $result['low_stock'],
        ], 201);
    }

    // issueMaterialsToProduction() and autoCreateDelivery() moved to
    // App\Services\ProductionStageService as part of the Aug 28 2026 merge —
    // both ProductionController::logProgress() and this controller's store()
    // now call that one shared implementation instead of each having (or, in
    // logProgress()'s case, lacking) their own copy.

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