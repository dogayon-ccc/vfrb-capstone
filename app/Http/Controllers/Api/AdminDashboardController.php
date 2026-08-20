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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    // ── GET /api/admin/dashboard ──────────────────────────────────────────────
    public function index()
    {
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
                'pending_deliveries'   => $deliveringCount,
                'unreconciled_counts'  => $unreconciledCounts,
                'monthly_sales'        => $monthlySales,

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
                ],
                'recent_orders' => $recentOrders,
                'generated_at'  => $now->toIso8601String(),
            ];
        });

        return response()->json($data);
    }
}
