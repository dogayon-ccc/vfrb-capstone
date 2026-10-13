<?php
// app/Http/Controllers/Api/QCChecklistController.php
// VFRB Enterprise — QC Checklist
//
// SCHEMA VERIFIED against vfrb_db.sql:
//   qc_checklists: check_id (PK), order_id, checked_by,
//     chest_cm, length_cm, sleeve_cm, waist_cm, shoulder_cm,
//     stitching_ok, color_ok, size_ok, label_ok, finish_ok, button_ok,
//     passed (tinyint default 0),
//     notes, items_checked, items_passed, items_failed, checked_at
//
// 80/20 RULE (from Ma'am Fe interview — hard requirement):
//   items_passed / items_checked >= 0.80 → passed = 1
//   If all boolean checks (stitching_ok, color_ok, etc.) are 1 → also passed
//
// QCChecklist.jsx sends:
//   { stitching_ok, color_ok, size_ok, label_ok, finish_ok, button_ok,
//     chest_cm, length_cm, sleeve_cm, waist_cm, shoulder_cm,
//     items_checked, items_passed, items_failed, notes }
//
// Response: { checklist, passed, pass_rate }

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class QCChecklistController extends Controller
{
    const QC_PASS_THRESHOLD = \App\Services\ProductionStageService::QC_PASS_THRESHOLD;
    // Inspection checks. button_ok is optional: left unanswered (no fasteners) it is stored NULL and does not block a pass; answered Fail it does.
    const CHECK_LABELS = ['stitching_ok' => 'Stitching', 'color_ok' => 'Color match', 'size_ok' => 'Size / fit',
                          'label_ok' => 'Labels', 'finish_ok' => 'Finish', 'button_ok' => 'Buttons / zip'];

    // ── GET /api/admin/orders/{orderId}/qc ────────────────────────────────────
    // DailyOutputLog.jsx loads the latest checklist when the QC stage is selected
    public function show(int $orderId)
    {
        $order = DB::table('orders')->where('order_id', $orderId)->first();
        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $checklist = DB::table('qc_checklists')
            ->leftJoin('users', 'qc_checklists.checked_by', '=', 'users.user_id')
            ->where('qc_checklists.order_id', $orderId)
            ->select('qc_checklists.*', DB::raw("COALESCE(users.name, 'Unknown user') as checked_by_name"))
            ->orderByDesc('qc_checklists.checked_at')
            ->first();

        return response()->json([
            'checklist'  => $checklist,
            'order_status' => $order->status,
            'qc_required'  => $order->qc_required,
        ]);
    }

    // ── POST /api/admin/orders/{orderId}/qc ───────────────────────────────────
    // QCChecklist.jsx submits the checklist
    public function store(Request $request, int $orderId)
    {
        $request->validate([
            'items_checked'  => 'required|integer|min:1',
            // FIX (Task A, Aug 31 2026): items_passed/items_failed had no
            // upper bound tied to items_checked — a checker could submit
            // items_checked=10, items_passed=500 and it would validate
            // fine, inflating pass_rate past 100% and letting `passed`
            // flip to true on bad data. lte:items_checked closes both
            // halves of this (passed count and failed count each can't
            // exceed the sample size). Deliberately NOT requiring
            // items_passed + items_failed === items_checked — partial/
            // in-progress counts during a QC pass are a real workflow
            // per QCChecklist.jsx, not a data-entry error.
            'items_passed'   => 'required|integer|min:0|lte:items_checked',
            'items_failed'   => 'required|integer|min:0|lte:items_checked',
            'stitching_ok'   => 'nullable|boolean',
            'color_ok'       => 'nullable|boolean',
            'size_ok'        => 'nullable|boolean',
            'label_ok'       => 'nullable|boolean',
            'finish_ok'      => 'nullable|boolean',
            'button_ok'      => 'nullable|boolean',
            'chest_cm'       => 'nullable|numeric|min:0',
            'length_cm'      => 'nullable|numeric|min:0',
            'sleeve_cm'      => 'nullable|numeric|min:0',
            'waist_cm'       => 'nullable|numeric|min:0',
            'shoulder_cm'    => 'nullable|numeric|min:0',
            'notes'          => 'nullable|string|max:1000',
        ]);

        $order = DB::table('orders')->where('order_id', $orderId)->first();
        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $itemsChecked = (int) $request->input('items_checked');
        $itemsPassed  = (int) $request->input('items_passed',  0);
        $itemsFailed  = (int) $request->input('items_failed',  0);

        // 80/20 rule: pass rate must be >= 80%
        $passRate = $itemsChecked > 0 ? $itemsPassed / $itemsChecked : 0;

        // BUG FIX (Aug 29 2026, confirmed by direct code read, not narrative):
        // button_ok was validated above and stored below, but never included
        // in this array — meaning it had zero effect on whether QC actually
        // passed. A checker could leave "button" failed and QC would still
        // pass on the other 5 checks + pass rate. Adding it here is the
        // actual fix; the DB write below already had the column right.
        $boolChecks = [
            $request->boolean('stitching_ok', false),
            $request->boolean('color_ok',     false),
            $request->boolean('size_ok',      false),
            $request->boolean('label_ok',     false),
            $request->boolean('finish_ok',    false),
            $request->input('button_ok') === null ? true : $request->boolean('button_ok'),
        ];
        $allBoolsPassed = !in_array(false, $boolChecks, true);
        $failedChecks   = array_keys(array_filter(self::CHECK_LABELS, fn ($label, $key) => !($key === 'button_ok' && $request->input($key) === null) && !$request->boolean($key, false), ARRAY_FILTER_USE_BOTH));

        $passed = ($passRate >= self::QC_PASS_THRESHOLD && $allBoolsPassed) ? 1 : 0;

        $id = DB::table('qc_checklists')->insertGetId([
            'order_id'     => $orderId,
            'checked_by'   => Auth::id(),
            'chest_cm'     => $request->input('chest_cm'),
            'length_cm'    => $request->input('length_cm'),
            'sleeve_cm'    => $request->input('sleeve_cm'),
            'waist_cm'     => $request->input('waist_cm'),
            'shoulder_cm'  => $request->input('shoulder_cm'),
            'stitching_ok' => $request->boolean('stitching_ok', false) ? 1 : 0,
            'color_ok'     => $request->boolean('color_ok',     false) ? 1 : 0,
            'size_ok'      => $request->boolean('size_ok',      false) ? 1 : 0,
            'label_ok'     => $request->boolean('label_ok',     false) ? 1 : 0,
            'finish_ok'    => $request->boolean('finish_ok',    false) ? 1 : 0,
            'button_ok'    => $request->input('button_ok') === null ? null : ($request->boolean('button_ok') ? 1 : 0),
            'passed'       => $passed,
            'items_checked'=> $itemsChecked,
            'items_passed' => $itemsPassed,
            'items_failed' => $itemsFailed,
            'notes'        => $request->input('notes'),
            'checked_at'   => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ], 'check_id');

        // If passed, update qc_passed_at on the order
        if ($passed) {
            DB::table('orders')
                ->where('order_id', $orderId)
                ->update(['qc_passed_at' => now(), 'updated_at' => now()]);
        }

        $checklist = DB::table('qc_checklists')->where('check_id', $id)->first();

        // QC result to managers: in-app (existing 'order_update' type) and by email.
        $pct = round($passRate * 100, 1);
        $qcMessage = $passed
            ? "QC passed on order #{$orderId} ({$itemsPassed}/{$itemsChecked} pieces, {$pct}%)."
            : "QC not passed on order #{$orderId} ({$pct}%, {$itemsFailed} failed). Corrections needed before pressing.";
        $managerIds = DB::table('model_has_roles')->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('roles.name', 'manager')->pluck('model_has_roles.model_id');
        $now = now();
        foreach ($managerIds as $uid) {
            DB::table('notifications')->insert([
                'user_id' => $uid, 'order_id' => $orderId, 'message' => $qcMessage, 'type' => 'order_update',
                'is_read' => 0, 'date_sent' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        \App\Support\EmailNotify::users($managerIds, new \App\Notifications\PortalMailNotification(
            ($passed ? 'QC passed' : 'QC not passed') . " on order #{$orderId}", [$qcMessage], 'Open order', "/admin/orders/{$orderId}"));

        // A pass after every QC piece was logged moves the order to Pressing now (no dummy re-log needed).
        $release = $passed ? (new \App\Services\ProductionStageService())->releaseQcHold($orderId) : ['advanced' => false, 'new_stage' => $order->status, 'message' => null];
        $checklist->checked_by_name = Auth::user()?->name;

        return response()->json([
            'checklist' => $checklist,
            'passed'    => (bool) $passed,
            'pass_rate' => round($passRate * 100, 1),
            'advanced'  => $release['advanced'],
            'new_stage' => $release['new_stage'],
            'message'   => $passed
                ? ($release['message'] ?? "QC passed ({$itemsPassed}/{$itemsChecked} pieces, " . round($passRate * 100) . "%). The order moves to Pressing once all QC pieces are logged.")
                : ($passRate < self::QC_PASS_THRESHOLD
                    ? "QC not passed. Pass rate " . round($passRate * 100, 1) . "% (need " . (self::QC_PASS_THRESHOLD * 100) . "%). Correct failing pieces and resubmit."
                    : 'QC not passed: ' . implode(', ', array_map(fn ($k) => self::CHECK_LABELS[$k], $failedChecks)) . ' failed. Correct them and resubmit.'),
        ], 201);
    }

    // ── GET /api/admin/qc-checklists ──────────────────────────────────────────
    // QCChecklist.jsx history (read-only): every submitted checklist, newest first, with who recorded it and when.
    public function index(Request $request)
    {
        $request->validate([
            'order_id' => 'nullable|integer',
            'result'   => 'nullable|in:passed,failed',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $q = DB::table('qc_checklists')
            ->join('orders', 'orders.order_id', '=', 'qc_checklists.order_id')
            ->leftJoin('users', 'users.user_id', '=', 'qc_checklists.checked_by')
            ->select('qc_checklists.*', 'orders.garment_type', 'orders.status as order_status',
                DB::raw("COALESCE(users.name, 'Unknown user') as checked_by_name"))
            ->orderByDesc('qc_checklists.checked_at')->orderByDesc('qc_checklists.check_id');
        if ($request->filled('order_id')) $q->where('qc_checklists.order_id', (int) $request->input('order_id'));
        if ($request->filled('result'))   $q->where('qc_checklists.passed', $request->input('result') === 'passed' ? 1 : 0);
        return response()->json($q->paginate((int) $request->input('per_page', 25)));
    }
}