<?php
// app/Http/Controllers/Api/ActivityLogController.php
// Activity Log (Aug 22 2026 session) — "Option A" from the audit-log scoping
// conversation: a read-only view over data that ALREADY exists, not a new
// audit_logs table. Every row here comes from a real actor column already
// in the schema — nothing is invented, nothing is duplicated.
//
// SOURCES (9 tables, all already timestamped + attributed):
//   daily_output_logs.logged_by         -> output_logged
//   order_production_tracking.updated_by -> stage_progress
//   qc_checklists.checked_by            -> qc_checked
//   physical_count_logs.counted_by      -> physical_count
//   physical_count_logs.reconciled_by   -> count_reconciled (only reconciled=1 rows)
//   inventory_logs.recorded_by          -> inventory_stock_in / _stock_out / _adjustment / _wastage
//   delivery_tracking.updated_by        -> delivery_updated (only updated_by IS NOT NULL)
//   purchase_orders.created_by          -> po_created
//   rfq_requests.created_by             -> rfq_created (excludes auto_generated=1 — that's the
//                                          daily automation job, not a person's action)
//   company_settings.updated_by         -> settings_updated (only updated_by IS NOT NULL)
//   (usage_rate_set removed Aug 29 2026 — see body comment, source deleted)
//
// EXPLICITLY OUT OF SCOPE (per the scoping conversation):
//   - No login/logout history — no auth_logs table or login timestamp exists
//     anywhere in the schema. Not fabricated here.
//   - Not cryptographically immutable — this is a read view over mutable
//     operational tables, not a tamper-proof ledger.
//
// Manager-only (route group) — matches Reports/Suppliers/Users pattern.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActivityLogController extends Controller
{
    // ── GET /api/admin/activity-log ──────────────────────────────────────────
    // Query params (all optional):
    //   user_id    — filter to one actor
    //   action     — filter to one action_type (see ACTION_TYPES below)
    //   date_from, date_to — YYYY-MM-DD, inclusive
    //   per_page   — default 20, matches the rest of the app's list endpoints
    public function index(Request $request)
    {
        $request->validate([
            'user_id'   => 'sometimes|integer',
            'action'    => 'sometimes|string',
            'date_from' => 'sometimes|date',
            'date_to'   => 'sometimes|date',
            'per_page'  => 'sometimes|integer|min:1|max:100',
        ]);

        $perPage = (int) $request->input('per_page', 20);

        // Each sub-select normalized to the same 6 columns so they can be
        // UNION ALL'd. actor_user_id resolved to name/role in PHP afterward
        // (one bulk query) rather than per-row joins in SQL — cheaper, and
        // keeps this readable.
        $queries = [
            DB::table('daily_output_logs')
                ->selectRaw("'output_logged' as action_type, logged_by as actor_user_id,
                    CONCAT('Logged ', total_output, ' pcs (', stage, ') for order #', order_id) as description,
                    CONCAT('order:', order_id) as target, created_at as occurred_at"),

            DB::table('order_production_tracking')
                ->selectRaw("'stage_progress' as action_type, updated_by as actor_user_id,
                    CONCAT('Updated ', stage, ' progress: ', qty_completed, '/', qty_target, ' for order #', order_id) as description,
                    CONCAT('order:', order_id) as target, updated_at as occurred_at")
                ->whereNotNull('updated_by'),

            DB::table('qc_checklists')
                ->selectRaw("'qc_checked' as action_type, checked_by as actor_user_id,
                    CONCAT('QC ', IF(passed=1,'passed','failed'), ' — ', items_passed, '/', items_checked, ' items — order #', order_id) as description,
                    CONCAT('order:', order_id) as target, COALESCE(checked_at, created_at) as occurred_at"),

            DB::table('physical_count_logs')
                ->selectRaw("'physical_count' as action_type, counted_by as actor_user_id,
                    CONCAT('Counted material #', material_id, ': physical ', physical_qty, ' vs system ', system_qty) as description,
                    CONCAT('material:', material_id) as target, created_at as occurred_at"),

            DB::table('physical_count_logs')
                ->selectRaw("'count_reconciled' as action_type, reconciled_by as actor_user_id,
                    CONCAT('Reconciled count for material #', material_id, IF(stock_adjusted=1, ' (stock adjusted)', '')) as description,
                    CONCAT('material:', material_id) as target, reconciled_at as occurred_at")
                ->where('reconciled', 1)
                ->whereNotNull('reconciled_by'),

            DB::table('inventory_logs')
                ->selectRaw("CONCAT('inventory_', type) as action_type, recorded_by as actor_user_id,
                    CONCAT(type, ' ', change_qty, ' — material #', material_id, IF(reason IS NOT NULL, CONCAT(' (', reason, ')'), '')) as description,
                    CONCAT('material:', material_id) as target, log_date as occurred_at"),

            DB::table('delivery_tracking')
                ->selectRaw("'delivery_updated' as action_type, updated_by as actor_user_id,
                    CONCAT('Delivery status -> ', delivery_status, ' for order #', order_id) as description,
                    CONCAT('order:', order_id) as target, updated_at as occurred_at")
                ->whereNotNull('updated_by'),

            DB::table('purchase_orders')
                ->selectRaw("'po_created' as action_type, created_by as actor_user_id,
                    CONCAT('Created PO ', po_number, ' — total ', total_amount) as description,
                    CONCAT('po:', po_id) as target, created_at as occurred_at"),

            DB::table('rfq_requests')
                ->selectRaw("'rfq_created' as action_type, created_by as actor_user_id,
                    CONCAT('Created RFQ for material #', material_id, ' — qty ', qty_needed) as description,
                    CONCAT('material:', material_id) as target, created_at as occurred_at")
                ->where('auto_generated', 0)
                ->whereNotNull('created_by'),

            // usage_rate_set union arm removed Aug 29 2026 — InventoryController::
            // ratesUpsert() (the only thing that ever wrote material_usage_rates.set_by)
            // was deleted in the Aug 28 2026 no-formula redesign. Left in place, this
            // arm would just run a permanently-empty query forever — removing it is
            // pure cleanup, not a behavior change (it already returned zero rows).

            DB::table('company_settings')
                ->selectRaw("'settings_updated' as action_type, updated_by as actor_user_id,
                    'Updated company settings' as description,
                    'company_settings:1' as target, updated_at as occurred_at")
                ->whereNotNull('updated_by'),
        ];

        $union = array_shift($queries);
        foreach ($queries as $q) {
            $union->unionAll($q);
        }

        // Wrap the union so we can filter/paginate/order on the combined result.
        $wrapped = DB::query()->fromSub($union, 'activity')
            ->whereNotNull('occurred_at');

        if ($request->filled('user_id')) {
            $wrapped->where('actor_user_id', $request->input('user_id'));
        }
        if ($request->filled('action')) {
            $wrapped->where('action_type', $request->input('action'));
        }
        if ($request->filled('date_from')) {
            $wrapped->whereDate('occurred_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $wrapped->whereDate('occurred_at', '<=', $request->input('date_to'));
        }

        $page = $wrapped->orderByDesc('occurred_at')->paginate($perPage);

        // Bulk-resolve actor name + role for every user_id on this page only
        // (never the whole table) — same getRoleName() pattern used in
        // AuthController, just batched instead of per-row.
        $userIds = collect($page->items())->pluck('actor_user_id')->filter()->unique()->values();

        $names = DB::table('users')->whereIn('user_id', $userIds)->pluck('name', 'user_id');
        $roles = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', 'App\\Models\\User')
            ->whereIn('model_has_roles.model_id', $userIds)
            ->pluck('roles.name', 'model_has_roles.model_id');

        $items = collect($page->items())->map(function ($row) use ($names, $roles) {
            return [
                'action_type'   => $row->action_type,
                'description'   => $row->description,
                'target'        => $row->target,
                'occurred_at'   => $row->occurred_at,
                'actor_user_id' => $row->actor_user_id,
                'actor_name'    => $names[$row->actor_user_id] ?? 'Unknown user',
                'actor_role'    => $roles[$row->actor_user_id] ?? 'unknown',
            ];
        });

        return response()->json([
            'data'         => $items,
            'current_page' => $page->currentPage(),
            'last_page'    => $page->lastPage(),
            'total'        => $page->total(),
            'per_page'     => $page->perPage(),
        ]);
    }

    // ── GET /api/admin/activity-log/action-types ─────────────────────────────
    // Powers the filter dropdown on the frontend — static list, matches the
    // action_type values produced above exactly.
    public function actionTypes()
    {
        return response()->json([
            'output_logged', 'stage_progress', 'qc_checked', 'physical_count',
            'count_reconciled', 'inventory_stock_in', 'inventory_stock_out',
            'inventory_adjustment', 'inventory_wastage', 'delivery_updated',
            'po_created', 'rfq_created', 'settings_updated',
        ]);
    }
}
