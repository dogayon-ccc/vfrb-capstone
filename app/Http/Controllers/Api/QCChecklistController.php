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
    const QC_PASS_THRESHOLD = 0.80;

    // ── GET /api/admin/orders/{orderId}/qc ────────────────────────────────────
    // QCChecklist.jsx loads the existing checklist on mount
    public function show(int $orderId)
    {
        $order = DB::table('orders')->where('order_id', $orderId)->first();
        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $checklist = DB::table('qc_checklists')
            ->join('users', 'qc_checklists.checked_by', '=', 'users.user_id')
            ->where('qc_checklists.order_id', $orderId)
            ->select('qc_checklists.*', 'users.name as checked_by_name')
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
            'items_passed'   => 'required|integer|min:0',
            'items_failed'   => 'required|integer|min:0',
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

        // Boolean checks: all 6 must be true for an auto-pass
        $boolChecks = [
            $request->boolean('stitching_ok', false),
            $request->boolean('color_ok',     false),
            $request->boolean('size_ok',      false),
            $request->boolean('label_ok',     false),
            $request->boolean('finish_ok',    false),
        ];
        $allBoolsPassed = !in_array(false, $boolChecks, true);

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
            'button_ok'    => $request->boolean('button_ok',    false) ? 1 : 0,
            'passed'       => $passed,
            'items_checked'=> $itemsChecked,
            'items_passed' => $itemsPassed,
            'items_failed' => $itemsFailed,
            'notes'        => $request->input('notes'),
            'checked_at'   => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // If passed, update qc_passed_at on the order
        if ($passed) {
            DB::table('orders')
                ->where('order_id', $orderId)
                ->update(['qc_passed_at' => now(), 'updated_at' => now()]);
        }

        $checklist = DB::table('qc_checklists')->where('check_id', $id)->first();

        return response()->json([
            'checklist' => $checklist,
            'passed'    => (bool) $passed,
            'pass_rate' => round($passRate * 100, 1),
            'message'   => $passed
                ? "QC passed ({$itemsPassed}/{$itemsChecked} pieces, " . round($passRate * 100) . "%). Order can advance to Pressing."
                : "QC not yet passed. Pass rate: " . round($passRate * 100, 1) . "% (need " . (self::QC_PASS_THRESHOLD * 100) . "%). Correct failing items and resubmit.",
        ], 201);
    }
}
