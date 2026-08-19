<?php
// app/Http/Controllers/Api/InventoryController.php
//
// FIX v10.1 — One bug corrected:
//
// BUG (500 on GET /api/admin/inventory/logs):
//   Route: GET /api/admin/inventory/logs → InventoryController@logs
//   Method signature: public function logs(int $id)
//   The route passes NO $id — PHP receives null for a typed int parameter → fatal.
//   Inventory.jsx calls /api/admin/inventory/logs as a GLOBAL log feed
//   (all materials, all types, recent 50) for the "Transaction Log" tab.
//
//   FIX: Added allLogs() method (no parameter) for the global feed.
//        The existing logs(int $id) method is kept for per-material log detail.
//        Route must be updated: GET /inventory/logs → allLogs()
//                               GET /materials/{id}/logs → logs($id)
//
// All other methods unchanged. Schema rules enforced:
//   - unit_cost (NOT unit_price)
//   - reorder_threshold (NOT reorder_point)
//   - inventory_logs.type enum: stock_in|stock_out|adjustment|wastage

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    // ── GET /api/admin/inventory (/api/admin/materials) ───────────────────────
    public function index(Request $request)
    {
        return Cache::remember('materials_list', 300, function () use ($request) {
            $q        = $request->input('search', '');
            $category = $request->input('category', '');
            $lowStock = $request->boolean('low_stock', false);

            $query = DB::table('materials');

            if ($q) {
                $query->where('material_name', 'like', "%{$q}%");
            }
            if ($category) {
                $query->where('category', $category);
            }
            if ($lowStock) {
                $query->whereRaw('quantity_in_stock <= reorder_threshold');
            }

            // FIX (Aug 1 2026 audit): was hardcoded paginate(20), ignoring
            // any per_page request. With 22 real materials in the catalog,
            // every dropdown built from this endpoint (PhysicalCount.jsx,
            // PurchaseOrders.jsx RFQ picker, MaterialRates.jsx) silently
            // lost the last 2 materials. Dropdowns now pass ?per_page=200
            // to get everything; the paginated table view (Materials.jsx)
            // still defaults to 20 since it has real pagination controls.
            $perPage = min((int) $request->input('per_page', 20), 200);
            $items = $query->orderBy('material_name')->paginate($perPage);

            $items->getCollection()->transform(function ($m) {
                $m->low_stock = $m->quantity_in_stock <= $m->reorder_threshold;
                return $m;
            });

            return $items;
        });
    }

    // ── GET /api/admin/materials/{id} ─────────────────────────────────────────
    public function show(int $id)
    {
        $material = DB::table('materials')->where('material_id', $id)->first();
        if (!$material) {
            return response()->json(['message' => 'Material not found.'], 404);
        }

        $logs = DB::table('inventory_logs')
            ->join('users', 'inventory_logs.recorded_by', '=', 'users.user_id')
            ->where('inventory_logs.material_id', $id)
            ->select('inventory_logs.*', 'users.name as recorded_by_name')
            ->orderByDesc('inventory_logs.log_date')
            ->limit(30)
            ->get();

        $material->low_stock = $material->quantity_in_stock <= $material->reorder_threshold;
        $material->logs      = $logs;

        return response()->json($material);
    }

    // ── POST /api/admin/materials ─────────────────────────────────────────────
    public function store(Request $request)
    {
        $request->validate([
            'material_name'     => 'required|string|max:100',
            'category'          => 'required|string|max:50',
            'unit'              => 'required|string|max:20',
            'quantity_in_stock' => 'required|numeric|min:0',
            'reorder_threshold' => 'required|numeric|min:0',
            'unit_cost'         => 'required|numeric|min:0',
        ]);

        $id = DB::table('materials')->insertGetId([
            'material_name'     => $request->input('material_name'),
            'category'          => $request->input('category'),
            'unit'              => $request->input('unit'),
            'quantity_in_stock' => $request->input('quantity_in_stock'),
            'reorder_threshold' => $request->input('reorder_threshold'),
            'unit_cost'         => $request->input('unit_cost'),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        Cache::forget('materials_list');
        Cache::forget('dashboard_stats');
        Cache::forget('mrp_alerts');
        Cache::forget('admin_reports_index');

        return response()->json(
            DB::table('materials')->where('material_id', $id)->first(),
            201
        );
    }

    // ── PUT /api/admin/materials/{id} ─────────────────────────────────────────
    public function update(Request $request, int $id)
    {
        $material = DB::table('materials')->where('material_id', $id)->first();
        if (!$material) {
            return response()->json(['message' => 'Material not found.'], 404);
        }

        $request->validate([
            'material_name'     => 'sometimes|string|max:100',
            'category'          => 'sometimes|string|max:50',
            'unit'              => 'sometimes|string|max:20',
            'quantity_in_stock' => 'sometimes|numeric|min:0',
            'reorder_threshold' => 'sometimes|numeric|min:0',
            'unit_cost'         => 'sometimes|numeric|min:0',
        ]);

        $fields = collect($request->only([
            'material_name', 'category', 'unit',
            'quantity_in_stock', 'reorder_threshold', 'unit_cost',
        ]))->filter(fn($v) => !is_null($v))->toArray();

        $fields['updated_at'] = now();

        DB::table('materials')->where('material_id', $id)->update($fields);

        Cache::forget('materials_list');
        Cache::forget('dashboard_stats');
        Cache::forget('mrp_alerts');
        Cache::forget('admin_reports_index');

        $updated = DB::table('materials')->where('material_id', $id)->first();

        if ($updated->quantity_in_stock <= $updated->reorder_threshold) {
            $this->notifyLowStock($updated->material_id, $updated->material_name, $updated->quantity_in_stock, $updated->reorder_threshold, $updated->unit);
        }

        return response()->json($updated);
    }

    // ── DELETE /api/admin/materials/{id} ──────────────────────────────────────
    // ── DELETE /api/admin/materials/{id} ──────────────────────────────────────
    // AUDIT FIX (2026-08-01): materials with real history (inventory_logs,
    // rfq_requests) can no longer be deleted — see migration
    // 2026_08_01_000002_fix_material_fk_cascade_deletes.php, which changed
    // those two FKs from CASCADE to RESTRICT. Checked here first so staff get
    // a clear, actionable message instead of a raw SQL constraint error.
    // (material_usage_rates.material_id is deliberately left CASCADE — it's
    // configuration, not a log; nothing worth preserving there.)
    public function destroy(int $id)
    {
        $material = DB::table('materials')->where('material_id', $id)->first();
        if (!$material) {
            return response()->json(['message' => 'Material not found.'], 404);
        }

        $hasLogs = DB::table('inventory_logs')->where('material_id', $id)->exists();
        $hasRfqs = DB::table('rfq_requests')->where('material_id', $id)->exists();

        if ($hasLogs || $hasRfqs) {
            return response()->json([
                'message' => "\"{$material->material_name}\" has recorded history ("
                    . implode(' and ', array_filter([
                        $hasLogs ? 'stock movement logs' : null,
                        $hasRfqs ? 'RFQ requests' : null,
                    ]))
                    . ') and can\'t be deleted — that would destroy the audit trail. '
                    . 'Set its stock to 0 and stop using it in new orders instead.',
            ], 422);
        }

        $deleted = DB::table('materials')->where('material_id', $id)->delete();
        if (!$deleted) {
            return response()->json(['message' => 'Material not found.'], 404);
        }

        Cache::forget('materials_list');
        Cache::forget('dashboard_stats');
        Cache::forget('mrp_alerts');
        Cache::forget('admin_reports_index');

        return response()->json(['message' => 'Material deleted.']);
    }

    // ── POST /api/admin/materials/{id}/stock-in ───────────────────────────────
    public function stockIn(Request $request, int $id)
    {
        return $this->adjustStock($request, $id, 'stock_in');
    }

    // ── POST /api/admin/materials/{id}/stock-out ──────────────────────────────
    public function stockOut(Request $request, int $id)
    {
        return $this->adjustStock($request, $id, 'stock_out');
    }

    // ── POST /api/admin/materials/{id}/adjust ─────────────────────────────────
    public function adjust(Request $request, int $id)
    {
        $type = $request->input('type', 'adjustment');
        if (!in_array($type, ['adjustment', 'wastage'])) {
            return response()->json(['message' => 'Invalid type. Use: adjustment | wastage'], 422);
        }
        return $this->adjustStock($request, $id, $type);
    }

    // ── GET /api/admin/inventory/logs  ← FIX: new method, no $id parameter ───
    // Global transaction log for Inventory.jsx "Transaction Log" tab.
    // Returns the 50 most recent inventory_logs entries across all materials.
    // Joins materials table to provide material_name and unit for display.
    public function allLogs(Request $request)
    {
        $perPage = (int) $request->input('per_page', 50);

        $logs = DB::table('inventory_logs as il')
            ->join('materials as m', 'm.material_id', '=', 'il.material_id')
            ->join('users as u', 'u.user_id', '=', 'il.recorded_by')
            ->select(
                'il.log_id',
                'il.material_id',
                'il.type',
                'il.change_qty',
                'il.reason',
                'il.log_date',
                'il.created_at',
                'u.name as recorded_by_name',
                // Nest material info so Inventory.jsx l.material?.material_name works
                DB::raw("JSON_OBJECT(
                    'material_id',   m.material_id,
                    'material_name', m.material_name,
                    'unit',          m.unit,
                    'category',      m.category
                ) as material")
            )
            ->orderByDesc('il.log_date')
            ->orderByDesc('il.log_id')
            ->paginate($perPage);

        // Decode the JSON material object so it arrives as an object, not a string
        $logs->getCollection()->transform(function ($row) {
            $row->material = json_decode($row->material);
            return $row;
        });

        return response()->json($logs);
    }

    // ── GET /api/admin/materials/{id}/logs ────────────────────────────────────
    // Per-material log (used by show() and Materials.jsx detail drawer).
    // This method REQUIRES $id — only called from /materials/{id}/logs route.
    public function logs(int $id)
    {
        $logs = DB::table('inventory_logs')
            ->join('users', 'inventory_logs.recorded_by', '=', 'users.user_id')
            ->where('inventory_logs.material_id', $id)
            ->select('inventory_logs.*', 'users.name as recorded_by_name')
            ->orderByDesc('inventory_logs.log_date')
            ->paginate(20);

        return response()->json($logs);
    }

    // ── Private: shared stock adjustment ─────────────────────────────────────
    private function adjustStock(Request $request, int $id, string $type): \Illuminate\Http\JsonResponse
    {
        $material = DB::table('materials')->where('material_id', $id)->first();
        if (!$material) {
            return response()->json(['message' => 'Material not found.'], 404);
        }

        $request->validate([
            'change_qty' => 'required|numeric|min:0.01',
            'reason'     => 'nullable|string|max:255',
        ]);

        $qty   = (float) $request->input('change_qty');
        $delta = in_array($type, ['stock_out', 'wastage']) ? -abs($qty) : abs($qty);

        $newStock = max(0, $material->quantity_in_stock + $delta);

        DB::table('materials')
            ->where('material_id', $id)
            ->update([
                'quantity_in_stock' => $newStock,
                'updated_at'        => now(),
            ]);

        DB::table('inventory_logs')->insert([
            'material_id' => $id,
            'recorded_by' => Auth::id(),
            'type'        => $type,
            'change_qty'  => $delta,
            'reason'      => $request->input('reason'),
            'log_date'    => now(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        Cache::forget('materials_list');
        Cache::forget('dashboard_stats');
        Cache::forget('mrp_alerts');
        Cache::forget('admin_reports_index');

        $isLowStock = $newStock <= $material->reorder_threshold;
        if ($isLowStock) {
            $this->notifyLowStock($material->material_id, $material->material_name, $newStock, $material->reorder_threshold, $material->unit);
        }

        return response()->json([
            'message'           => ucfirst(str_replace('_', ' ', $type)) . ' recorded.',
            'material_id'       => $id,
            'quantity_in_stock' => $newStock,
            'low_stock'         => $isLowStock,
        ]);
    }

    // ── Private: low-stock notification (deduped) ─────────────────────────────
    // Fires whenever a stock movement leaves a material at/below reorder_threshold.
    // Deduped per material within a 24h window so every stock-out on an already-low
    // item doesn't spam a fresh notification row.
    private function notifyLowStock(int $materialId, string $materialName, float $stock, float $threshold, string $unit): void
    {
        $recentlyNotified = DB::table('notifications')
            ->where('type', 'low_stock')
            ->where('message', 'like', "{$materialName}%")
            ->where('date_sent', '>=', now()->subDay())
            ->exists();

        if ($recentlyNotified) {
            return;
        }

        $managers = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->whereIn('roles.name', ['manager', 'staff'])
            ->pluck('model_has_roles.model_id');

        $message = "{$materialName} stock ({$stock} {$unit}) is below reorder threshold ({$threshold} {$unit}).";
        $now     = now();

        foreach ($managers as $userId) {
            DB::table('notifications')->insert([
                'user_id'    => $userId,
                'order_id'   => null,
                'message'    => $message,
                'type'       => 'low_stock',
                'title'      => 'Low Stock Alert',
                'is_read'    => 0,
                'date_sent'  => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    // ── Material usage rates (deterministic BOM engine config) ─────────────
    // See database/migrations/2026_08_01_000001_create_material_usage_rates_table.php
    // for the full architecture note. Staff set these; the AI never touches
    // them. AIController::computeDeterministicQty() reads from this table.

    // GET /api/admin/material-rates — list all configured rates, joined to
    // material name for display
    public function ratesIndex()
    {
        $rates = DB::table('material_usage_rates')
            ->join('materials', 'materials.material_id', '=', 'material_usage_rates.material_id')
            ->select(
                'material_usage_rates.*',
                'materials.material_name',
                'materials.category'
            )
            ->orderBy('materials.category')
            ->orderBy('materials.material_name')
            ->get();

        return response()->json(['rates' => $rates]);
    }

    // POST /api/admin/material-rates — create or update a rate for
    // (material_id, garment_type). Upsert on the unique constraint.
    public function ratesUpsert(Request $request)
    {
        $request->validate([
            'material_id'  => 'required|integer|exists:materials,material_id',
            'garment_type' => 'required|string|max:50',
            'qty_per_unit' => 'required|numeric|min:0.0001',
            'unit'         => 'required|string|max:20',
        ]);

        DB::table('material_usage_rates')->updateOrInsert(
            [
                'material_id'  => $request->material_id,
                'garment_type' => $request->garment_type,
            ],
            [
                'qty_per_unit' => $request->qty_per_unit,
                'unit'         => $request->unit,
                'set_by'       => Auth::id(),
                'updated_at'   => now(),
                'created_at'   => now(),
            ]
        );

        Cache::forget('material_rates_all');

        return response()->json(['message' => 'Usage rate saved.']);
    }

    // DELETE /api/admin/material-rates/{id}
    public function ratesDestroy(int $id)
    {
        DB::table('material_usage_rates')->where('rate_id', $id)->delete();
        Cache::forget('material_rates_all');
        return response()->json(['message' => 'Usage rate removed.']);
    }
}
