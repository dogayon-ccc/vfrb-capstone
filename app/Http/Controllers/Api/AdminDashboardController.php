<?php
// app/Http/Controllers/Api/AdminDashboardController.php
// VFRB Enterprise — Admin Dashboard Stats
//
// SCHEMA VERIFIED against vfrb_db.sql:
//   orders: order_id, status (enum 11), user_id, quantity_ordered,
//           garment_type, color, created_at, updated_at
//   materials: material_id, material_name, unit, quantity_in_stock,
//              reorder_threshold, unit_cost   ← unit_cost not unit_price
//   delivery_tracking: delivery_status (NOT status), order_id
//   sales_transactions: transaction_id, order_id, amount_paid, payment_date
//   daily_output_logs: total_output (GENERATED — safe to SUM/SELECT, not to INSERT)
//
// Cache: Cache::remember('dashboard_stats', 120) — 2 min TTL
//        Cache::forget on every mutation (called from other controllers)
//
// RULES:
//   - delivery_status (never "status") for delivery_tracking
//   - unit_cost (never unit_price) for materials
//   - paginate(20) on all list sub-queries

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KpiDailySnapshot;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    // ── GET /api/admin/dashboard ──────────────────────────────────────────────
    public function index()
    {
        // Lazy snapshot capture — see KpiDailySnapshot::captureToday()'s own
        // doc comment. Cheap (early-returns once today's row exists), so
        // safe to call on every dashboard load rather than only from the
        // scheduler.
        KpiDailySnapshot::captureToday();

        $data = Cache::remember('dashboard_stats', 120, function () {
            $now         = now();
            $startMonth  = $now->copy()->startOfMonth();
            $startWeek   = $now->copy()->startOfWeek();

            // ── Order counts ─────────────────────────────────────────────────
            $totalOrders     = DB::table('orders')->count();
            $pendingOrders   = DB::table('orders')->where('status', 'pending')->count();
            $confirmedOrders = DB::table('orders')->where('status', 'confirmed')->count();
            $inProduction    = DB::table('orders')
                ->whereIn('status', ['pattern','segregation','cutting','sewing','qc','pressing','packing'])
                ->count();
            $completedOrders = DB::table('orders')->where('status', 'completed')->count();
            $cancelledOrders = DB::table('orders')->where('status', 'cancelled')->count();
            $newThisMonth    = DB::table('orders')->where('created_at', '>=', $startMonth)->count();
            $newThisWeek     = DB::table('orders')->where('created_at', '>=', $startWeek)->count();

            // ── Revenue ──────────────────────────────────────────────────────
            $revenueTotal = DB::table('sales_transactions')->sum('amount_paid');
            $revenueMonth = DB::table('sales_transactions')
                ->where('payment_date', '>=', $startMonth->toDateString())
                ->sum('amount_paid');
            $revenueWeek  = DB::table('sales_transactions')
                ->where('payment_date', '>=', $startWeek->toDateString())
                ->sum('amount_paid');

            // ── Inventory alerts ─────────────────────────────────────────────
            $lowStockCount = DB::table('materials')
                ->whereRaw('quantity_in_stock <= reorder_threshold')
                ->count();

            // Stock health % — this exact field keeps getting dropped when this
            // file gets resynced from an older branch; re-verify it's still
            // here before assuming Dashboard.jsx's Stock Health KPI card works.
            $materialCount  = DB::table('materials')->count();
            $stockHealthPct = $materialCount > 0
                ? round((($materialCount - $lowStockCount) / $materialCount) * 100, 2)
                : 0;

            // ── QC / color hold alerts ────────────────────────────────────────
            $qcBlockedCount = DB::table('orders')->where('status', 'qc')->count();

            $colorHoldCount = DB::table('purchase_orders')
                ->where('color_mismatch', 1)
                ->where('color_confirmed', 0)
                ->count();

            // ── Production output today (uses GENERATED total_output) ─────────
            $outputToday = (int) DB::table('daily_output_logs')
                ->whereDate('log_date', $now->toDateString())
                ->sum('total_output');

            // ── Delivery counts ───────────────────────────────────────────────
            // delivery_status — NOT "status"
            // Definition aligned with Dashboard.jsx's "Upcoming Deliveries" block
            // (client/src/pages/admin/Dashboard.jsx, loadDeliveries()), which
            // treats anything not yet delivered/returned as pending — including
            // 'preparing'. Previously this only counted dispatched/in_transit,
            // which caused the KPI card ("0 Deliveries") to disagree with the
            // Upcoming Deliveries action block ("(1)") for the same table.
            $deliveringCount = DB::table('delivery_tracking')
                ->whereNotIn('delivery_status', ['delivered', 'returned'])
                ->count();

            // ── Recent orders (last 10) ───────────────────────────────────────
            $recentOrders = DB::table('orders')
                ->join('users', 'orders.user_id', '=', 'users.user_id')
                ->select(
                    'orders.order_id',
                    'orders.status',
                    'orders.garment_type',
                    'orders.color',
                    'orders.quantity_ordered',
                    'orders.created_at',
                    'users.name as customer_name',
                    'users.organization_name'
                )
                ->orderByDesc('orders.created_at')
                ->limit(10)
                ->get();

            // ── Low-stock materials ───────────────────────────────────────────
            $lowStockMaterials = DB::table('materials')
                ->whereRaw('quantity_in_stock <= reorder_threshold')
                ->select('material_id', 'material_name', 'quantity_in_stock',
                         'reorder_threshold', 'unit', 'unit_cost')
                ->orderByRaw('quantity_in_stock / reorder_threshold ASC')
                ->limit(5)
                ->get()
                ->map(function ($m) {
                    $m->low_stock = true;
                    return $m;
                });

            // ── Stage distribution ────────────────────────────────────────────
            $stageDist = DB::table('orders')
                ->whereIn('status', ['pattern','segregation','cutting','sewing','qc','pressing','packing'])
                ->select('status as stage', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->orderBy('status')
                ->get();

            // ── Delayed orders (Aug 24 2026 — closes a real gap: Dashboard.jsx
            // was previously showing this card as permanently "Not available
            // yet"). Real definition, not a naive threshold on orders.updated_at
            // (which could be touched by unrelated edits, not just stage
            // progress). A real per-stage timestamp already exists:
            // order_production_tracking has one row per (order, stage), each
            // with its own updated_at. An order counts as delayed if:
            //   1. it's actively in one of the 7 production stages (not
            //      pending/confirmed/completed/cancelled)
            //   2. the tracking row for its EXACT current stage hasn't been
            //      touched in 3+ days
            //   3. it's genuinely still incomplete for that stage
            //      (qty_completed < qty_target) — a fully-done stage sitting
            //      briefly before auto-advance isn't "delayed."
            // 3 days is a first-pass, explainable threshold, not a fully
            // modeled SLA system — worth saying exactly that in a defense.
            $delayedThresholdDays = 3;
            $delayedOrders = DB::table('orders')
                ->join('order_production_tracking', function ($join) {
                    $join->on('order_production_tracking.order_id', '=', 'orders.order_id')
                         ->on('order_production_tracking.stage', '=', 'orders.status');
                })
                ->join('users', 'users.user_id', '=', 'orders.user_id')
                ->whereIn('orders.status', ['pattern','segregation','cutting','sewing','qc','pressing','packing'])
                ->whereColumn('order_production_tracking.qty_completed', '<', 'order_production_tracking.qty_target')
                ->where('order_production_tracking.updated_at', '<=', $now->copy()->subDays($delayedThresholdDays))
                ->select(
                    'orders.order_id',
                    'orders.status as stage',
                    'orders.garment_type',
                    'users.name as customer_name',
                    'order_production_tracking.qty_completed',
                    'order_production_tracking.qty_target',
                    'order_production_tracking.updated_at as stage_updated_at'
                )
                ->orderBy('order_production_tracking.updated_at', 'asc')
                ->limit(10)
                ->get()
                ->map(function ($o) use ($now) {
                    $o->days_stalled = (int) $now->diffInDays($o->stage_updated_at);
                    return $o;
                });

            // ── Physical count reconciliation pending ──────────────────────────
            // "Needs reconciliation" = not yet reconciled AND variance over the
            // 5% threshold (matches the rule documented on the Physical Count
            // page itself). No such thing as a needs_reconciliation column.
            $unreconciledCounts = DB::table('physical_count_logs')
                ->where('reconciled', 0)
                ->whereRaw('ABS(variance_pct) > 5')
                ->count();

            // ── Monthly revenue — last 6 months, for the Dashboard.jsx bar chart ──
            $monthlySales = DB::table('sales_transactions')
                ->selectRaw("
                    DATE_FORMAT(payment_date,'%b %Y') as month,
                    DATE_FORMAT(payment_date,'%Y%m')  as sort_key,
                    SUM(amount_paid) as total
                ")
                ->whereNotNull('payment_date')
                ->where('payment_date', '>=', $now->copy()->subMonths(6)->toDateString())
                ->groupByRaw("DATE_FORMAT(payment_date,'%b %Y'), DATE_FORMAT(payment_date,'%Y%m')")
                ->orderBy('sort_key')
                ->get()
                ->map(fn($r) => ['month' => $r->month, 'total' => (float) $r->total])
                ->values();

            // ── Order trend — last 6 months, for the Dashboard.jsx trend chart.
            // Same DATE_FORMAT/groupBy pattern as monthly_sales above, so the
            // two charts stay consistent with each other.
            $orderTrends = DB::table('orders')
                ->selectRaw("
                    DATE_FORMAT(created_at,'%b %Y') as month,
                    DATE_FORMAT(created_at,'%Y%m')  as sort_key,
                    COUNT(*) as orders
                ")
                ->where('created_at', '>=', $now->copy()->subMonths(6)->toDateString())
                ->groupByRaw("DATE_FORMAT(created_at,'%b %Y'), DATE_FORMAT(created_at,'%Y%m')")
                ->orderBy('sort_key')
                ->get()
                ->map(fn($r) => ['month' => $r->month, 'orders' => (int) $r->orders])
                ->values();

            // ── KPI sparkline history — real daily snapshots, not invented
            // trends (see KpiDailySnapshot::captureToday()). Kept to the same
            // 6-month window as the other two trend queries above.
            $kpiHistory = KpiDailySnapshot::since($now->copy()->subMonths(6)->toDateString())
                ->get(['snapshot_date', 'revenue', 'orders_total', 'in_production', 'stock_health_pct'])
                ->map(fn($r) => [
                    'date'             => $r->snapshot_date->toDateString(),
                    'revenue'          => (float) $r->revenue,
                    'orders_total'     => (int) $r->orders_total,
                    'in_production'    => (int) $r->in_production,
                    'stock_health_pct' => (float) $r->stock_health_pct,
                ])
                ->values();

            return [
                // FIX: Dashboard.jsx reads these as flat top-level fields
                // (s.total_orders, s.active_orders, s.monthly_revenue, etc.) —
                // the nested-only shape below meant every KPI card fell back to
                // its `?? 0` default. Both shapes are now present; nothing that
                // already reads the nested version breaks.
                'total_orders'         => $totalOrders,
                'active_orders'        => $inProduction,
                'monthly_revenue'      => (float) $revenueMonth,
                'low_stock_count'      => $lowStockCount,
                'stock_health_pct'     => $stockHealthPct,
                'pending_deliveries'   => $deliveringCount,
                'unreconciled_counts'  => $unreconciledCounts,
                'monthly_sales'        => $monthlySales,
                'order_trends'         => $orderTrends,
                'kpi_history'          => $kpiHistory,

                'orders' => [
                    'total'         => $totalOrders,
                    'pending'       => $pendingOrders,
                    'confirmed'     => $confirmedOrders,
                    'in_production' => $inProduction,
                    'completed'     => $completedOrders,
                    'cancelled'     => $cancelledOrders,
                    'new_this_month'=> $newThisMonth,
                    'new_this_week' => $newThisWeek,
                ],
                'revenue' => [
                    'total'    => (float) $revenueTotal,
                    'month'    => (float) $revenueMonth,
                    'week'     => (float) $revenueWeek,
                    'currency' => 'PHP',
                ],
                'inventory' => [
                    'low_stock_count'     => $lowStockCount,
                    'low_stock_materials' => $lowStockMaterials,
                ],
                'production' => [
                    'output_today'   => $outputToday,
                    'qc_blocked'     => $qcBlockedCount,
                    'color_hold'     => $colorHoldCount,
                    'delivering'     => $deliveringCount,
                    'stage_dist'     => $stageDist,
                    'delayed_orders' => $delayedOrders,
                    'delayed_count'  => $delayedOrders->count(),
                    'delayed_threshold_days' => $delayedThresholdDays,
                ],
                'recent_orders' => $recentOrders,
                'generated_at'  => $now->toIso8601String(),
            ];
        });

        return response()->json($data);
    }

    // ── GET /api/admin/dashboard/staff — non-manager staff home, shaped by
    // job_function. Manager keeps using index() above, unchanged, so this
    // adds nothing that could regress the demo-critical manager view.
    // "Assigned to me" has no dedicated assignment column in this schema —
    // it's derived from order_production_tracking.updated_by on the order's
    // CURRENT stage (the real, honest proxy: orders this staff member most
    // recently logged progress on), not a fabricated assignment system.
    public function staffIndex()
    {
        $user = Auth::user();
        $fn   = $user->job_function ?? 'general';
        $now  = now();

        return response()->json(match ($fn) {
            'sales'      => $this->salesStaffDashboard($now),
            'production' => $this->productionStaffDashboard($user, $now),
            default      => $this->generalStaffDashboard($now), // general, inventory
        });
    }

    private function generalStaffDashboard($now): array
    {
        $activeOrders = DB::table('orders')
            ->whereIn('status', ['pattern','segregation','cutting','sewing','qc','pressing','packing'])
            ->count();
        $lowStockMaterials = DB::table('materials')
            ->whereRaw('quantity_in_stock <= reorder_threshold')
            ->select('material_id','material_name','quantity_in_stock','reorder_threshold','unit')
            ->orderByRaw('quantity_in_stock / reorder_threshold ASC')
            ->limit(5)->get();

        // "Urgent" = same delayed-stage definition the manager dashboard
        // uses (stalled 3+ days on its current stage), just staff-facing.
        $urgentOrders = DB::table('orders')
            ->join('order_production_tracking', function ($j) {
                $j->on('order_production_tracking.order_id', '=', 'orders.order_id')
                  ->on('order_production_tracking.stage', '=', 'orders.status');
            })
            ->join('users', 'users.user_id', '=', 'orders.user_id')
            ->whereIn('orders.status', ['pattern','segregation','cutting','sewing','qc','pressing','packing'])
            ->whereColumn('order_production_tracking.qty_completed', '<', 'order_production_tracking.qty_target')
            ->where('order_production_tracking.updated_at', '<=', $now->copy()->subDays(3))
            ->select('orders.order_id','users.name as customer_name','orders.status as stage')
            ->limit(5)->get();

        return [
            'stats' => [
                'active_orders' => $activeOrders,
                'low_stock'     => $lowStockMaterials->count(),
                'urgent'        => $urgentOrders->count(),
            ],
            'urgent_orders'      => $urgentOrders,
            'low_stock_materials'=> $lowStockMaterials,
            'generated_at'       => $now->toIso8601String(),
        ];
    }

    private function salesStaffDashboard($now): array
    {
        // Balance per order = agreed_total minus whatever's actually been
        // paid so far (sales_transactions.amount_paid). No agreed_total yet
        // (deal not finalized) is excluded — nothing to follow up on yet.
        $orders = DB::table('orders')
            ->join('users', 'users.user_id', '=', 'orders.user_id')
            ->leftJoin(DB::raw('(select order_id, sum(amount_paid) as paid from sales_transactions group by order_id) as p'), 'p.order_id', '=', 'orders.order_id')
            ->whereNotNull('orders.agreed_total')
            ->whereNotIn('orders.status', ['cancelled'])
            ->select('orders.order_id','users.name as customer_name','orders.status',
                'orders.agreed_total', DB::raw('COALESCE(p.paid,0) as paid'))
            ->get()
            ->map(function ($o) {
                $o->balance = round($o->agreed_total - $o->paid, 2);
                $o->dp_pct  = $o->agreed_total > 0 ? round(($o->paid / $o->agreed_total) * 100) : 0;
                return $o;
            });

        $needsFollowUp = $orders->filter(fn($o) => $o->balance > 0)->values();

        $funnelMap = ['pending'=>'Pending','confirmed'=>'Confirmed',
            'pattern'=>'In Production','segregation'=>'In Production','cutting'=>'In Production',
            'sewing'=>'In Production','qc'=>'In Production','pressing'=>'In Production','packing'=>'In Production',
            'completed'=>'Delivered'];
        $funnel = ['Pending'=>0,'Confirmed'=>0,'In Production'=>0,'Delivered'=>0];
        foreach ($orders as $o) {
            $bucket = $funnelMap[$o->status] ?? null;
            if ($bucket) $funnel[$bucket]++;
        }

        return [
            'stats' => [
                'total_orders'    => $orders->count(),
                'needs_follow_up' => $needsFollowUp->count(),
                'total_value'     => round($orders->sum('agreed_total'), 2),
            ],
            'needs_follow_up' => $needsFollowUp->take(5)->values(),
            'order_funnel'    => $funnel,
            'payment_status'  => $orders->sortByDesc('order_id')->take(10)->values(),
            'generated_at'    => $now->toIso8601String(),
        ];
    }

    private function productionStaffDashboard($user, $now): array
    {
        $STAGES = ['pattern','segregation','cutting','sewing','qc','pressing','packing'];

        // Orders whose CURRENT stage this staff member most recently logged.
        $myOrderIds = DB::table('orders')
            ->join('order_production_tracking', function ($j) {
                $j->on('order_production_tracking.order_id', '=', 'orders.order_id')
                  ->on('order_production_tracking.stage', '=', 'orders.status');
            })
            ->whereIn('orders.status', $STAGES)
            ->where('order_production_tracking.updated_by', $user->user_id)
            ->pluck('orders.order_id');

        $orders = DB::table('orders')
            ->join('users', 'users.user_id', '=', 'orders.user_id')
            ->whereIn('orders.order_id', $myOrderIds)
            ->select('orders.order_id','orders.garment_type','orders.status','orders.target_delivery_date','users.name as customer_name')
            ->get();

        $tracking = DB::table('order_production_tracking')
            ->whereIn('order_id', $myOrderIds)
            ->get()
            ->groupBy('order_id');

        $assignedToday = $orders->map(function ($o) use ($tracking, $STAGES) {
            $rows = $tracking->get($o->order_id, collect());
            $o->stages = collect($STAGES)->map(function ($stage) use ($rows) {
                $row = $rows->firstWhere('stage', $stage);
                return ['stage'=>$stage,'completed'=>(int)($row->qty_completed ?? 0),'target'=>(int)($row->qty_target ?? 0)];
            })->values();
            return $o;
        })->values();

        return [
            'stats' => [
                'assigned_today' => $assignedToday->count(),
                'at_my_stage'    => $assignedToday->where('status', '!=', 'completed')->count(),
                'completed'      => DB::table('order_production_tracking')
                    ->where('updated_by', $user->user_id)
                    ->whereColumn('qty_completed', '>=', 'qty_target')
                    ->whereDate('updated_at', $now->toDateString())->count(),
            ],
            'assigned_orders' => $assignedToday,
            'generated_at'    => $now->toIso8601String(),
        ];
    }
}
