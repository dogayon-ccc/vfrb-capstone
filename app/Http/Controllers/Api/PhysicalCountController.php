<?php
// physical_count_logs: count_id, material_id, counted_by, system_qty, physical_qty,
// variance + variance_pct (GENERATED, never insert), reason, count_date (date),
// reconciled, reconciled_by, reconciled_at, reconciliation_note, stock_adjusted.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PhysicalCountController extends Controller
{
    // ── GET /api/admin/physical-counts ───────────────────────────────────────
    public function index(Request $request)
    {
        $perPage      = (int) $request->input('per_page', 20);
        $materialId   = $request->input('material_id');
        $unreconciled = $request->boolean('unreconciled', false);

        $query = DB::table('physical_count_logs')
            ->join('materials', 'physical_count_logs.material_id', '=', 'materials.material_id')
            ->join('users',     'physical_count_logs.counted_by',   '=', 'users.user_id')
            ->select(
                'physical_count_logs.*',  // GENERATED variance + variance_pct safe to SELECT
                'materials.material_name',
                'materials.unit',
                'materials.unit_cost',
                'users.name as counted_by_name'
            );

        if ($materialId) {
            $query->where('physical_count_logs.material_id', $materialId);
        }

        if ($unreconciled) {
            $query->where('physical_count_logs.reconciled', 0);
        }

        return response()->json(
            $query->orderByDesc('physical_count_logs.count_date')->orderByDesc('physical_count_logs.count_id')->paginate($perPage)
        );
    }

    // ── POST /api/admin/physical-counts ──────────────────────────────────────
    // Staff submits a physical count reading for a material
    public function store(Request $request)
    {
        $request->validate([
            'material_id'  => 'required|integer|exists:materials,material_id',
            'counted_qty'  => 'required|numeric|min:0',
            'notes'        => 'nullable|string|max:1000',
            'counted_at'   => 'nullable|date',
        ]);

        $material = DB::table('materials')
            ->where('material_id', $request->input('material_id'))
            ->select('material_id', 'quantity_in_stock', 'material_name')
            ->first();

        $systemQty  = (float) $material->quantity_in_stock;
        $countedQty = (float) $request->input('counted_qty');

        $id = DB::table('physical_count_logs')->insertGetId([
            'material_id' => $material->material_id,
            'counted_by'  => Auth::id(),
            'system_qty'  => $systemQty,
            'physical_qty'=> $countedQty,
            'reconciled'  => 0,
            'reason'      => $request->input('notes'),
            'count_date'  => $request->input('counted_at', now()->toDateString()),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $log = DB::table('physical_count_logs')
            ->where('count_id', $id)
            ->first();

        // Notify manager if variance is significant (> 5% absolute)
        $absVariancePct = abs((float) ($log->variance_pct ?? 0));
        if ($absVariancePct > 5) {
            $this->notifyManagers(
                "Physical count variance alert: {$material->material_name} — "
                . "System: {$systemQty}, Counted: {$countedQty} "
                . "(" . round((float)($log->variance ?? 0), 2) . " difference)."
            );
        }

        Cache::forget('dashboard_stats');
        Cache::forget('mrp_alerts');

        return response()->json($log, 201);
    }

    // ── POST /api/admin/physical-counts/{id}/reconcile ───────────────────────
    // Manager approves reconciliation — updates actual stock to counted qty
    public function reconcile(Request $request, int $id)
    {
        $log = DB::table('physical_count_logs')->where('count_id', $id)->first();
        if (!$log) {
            return response()->json(['message' => 'Physical count record not found.'], 404);
        }

        if ($log->reconciled) {
            return response()->json(['message' => 'Already reconciled.'], 409);
        }

        $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        // Update actual stock in materials to the counted value
        DB::table('materials')
            ->where('material_id', $log->material_id)
            ->update([
                'quantity_in_stock' => $log->physical_qty,
                'updated_at'        => now(),
            ]);

        // Log the adjustment in inventory_logs
        $delta = $log->physical_qty - $log->system_qty;
        if ($delta != 0) {
            DB::table('inventory_logs')->insert([
                'material_id' => $log->material_id,
                'recorded_by' => Auth::id(),
                'type'        => 'adjustment',
                'change_qty'  => $delta,
                'reason'      => 'Physical count reconciliation — Count ID #' . $id,
                'log_date'    => now(),
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        // Mark count as reconciled — use reconciliation_note column (confirmed from vfrb_db.sql)
        DB::table('physical_count_logs')
            ->where('count_id', $id)
            ->update([
                'reconciled'           => 1,
                'reconciled_by'        => Auth::id(),
                'reconciled_at'        => now(),
                'reconciliation_note'  => $request->input('notes'),  // reconciliation_note column
                'stock_adjusted'       => 1,                          // mark stock was adjusted
                'updated_at'           => now(),
            ]);

        Cache::forget('materials_list');
        Cache::forget('dashboard_stats');
        Cache::forget('mrp_alerts');

        return response()->json([
            'message'     => 'Physical count reconciled. Stock updated.',
            'new_qty'     => $log->physical_qty,
            'material_id' => $log->material_id,
        ]);
    }

    // ── GET /api/admin/physical-counts/summary ──────────────────────────────────
    // PhysicalCount.jsx stats strip: flagged_variances, unreconciled_counts, overdue_materials
    public function summary()
    {
        $flaggedVariances   = DB::table('physical_count_logs')
            ->whereRaw('ABS(variance_pct) > 5')
            ->where('reconciled', 0)
            ->count();

        $unreconciledCounts = DB::table('physical_count_logs')
            ->where('reconciled', 0)
            ->count();

        // Overdue: materials not counted in last 30 days
        $overdueMaterials = DB::table('materials')
            ->leftJoin('physical_count_logs', function ($join) {
                $join->on('materials.material_id', '=', 'physical_count_logs.material_id')
                     ->where('physical_count_logs.count_date', '>=', now()->subDays(30)->toDateString());
            })
            ->whereNull('physical_count_logs.count_id')
            ->select('materials.material_id', 'materials.material_name')
            ->get();

        return response()->json([
            'flagged_variances'   => $flaggedVariances,
            'unreconciled_counts' => $unreconciledCounts,
            'overdue_materials'   => $overdueMaterials,
        ]);
    }

    // ── GET /api/admin/physical-counts/sheet ─────────────────────────────────────
    // PhysicalCount.jsx printable count sheet: all materials with system_qty
    public function sheet()
    {
        $countSheet = DB::table('materials')
            ->leftJoin('physical_count_logs', function ($join) {
                $join->on('materials.material_id', '=', 'physical_count_logs.material_id')
                     ->whereRaw('physical_count_logs.count_id = (
                         SELECT MAX(count_id) FROM physical_count_logs pcl2
                         WHERE pcl2.material_id = materials.material_id
                     )');
            })
            ->select(
                'materials.material_id',
                'materials.material_name',
                'materials.category',
                'materials.unit',
                'materials.quantity_in_stock as system_qty',
                'physical_count_logs.physical_qty as last_counted_qty',
                'physical_count_logs.count_date as last_counted_at',
                'physical_count_logs.variance',
                'physical_count_logs.reconciled'
            )
            ->orderBy('materials.category')
            ->orderBy('materials.material_name')
            ->get();

        return response()->json([
            'count_sheet'   => $countSheet,
            // FIX (Aug 31 2026): these two were never in the response at
            // all — PhysicalCount.jsx's print sheet has always been
            // reading r.data.generated_at/generated_by, and both came
            // back undefined because nothing on this side ever sent
            // them. Not a rename like system_qty below, a genuine gap.
            'generated_at'  => now()->format('n/j/y, g:i A'),
            'generated_by'  => auth()->user()->name ?? 'Unknown',
        ]);
    }

    // ── Private: manager notification ────────────────────────────────────────
    private function notifyManagers(string $message): void
    {
        $now = now();

        $managers = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('roles.name', 'manager')
            ->pluck('model_has_roles.model_id');

        foreach ($managers as $uid) {
            DB::table('notifications')->insert([
                'user_id'    => $uid,
                'order_id'   => null,
                'message'    => $message,
                'type'       => 'physical_count',
                'is_read'    => 0,
                'date_sent'  => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
