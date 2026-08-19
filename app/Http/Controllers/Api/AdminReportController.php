<?php
// app/Http/Controllers/Api/AdminReportController.php
//
// FIX v10.1 — Two bugs corrected:
//
// BUG A (500 on GET /api/admin/reports):
//   Controller queried SUM(mr.estimated_qty) in the top_materials block.
//   Column 'estimated_qty' does NOT exist in material_recommendations.
//   Actual columns: estimated_range (varchar), total_estimated_range (varchar).
//   These are plain-text strings ("3–4 yards"), not numbers — cannot SUM.
//   FIX: top_materials block removed entirely. The table is also empty in
//   production (no AI recommendations accepted yet), so this loses nothing.
//   When material_recommendations has real data in Month 5, a proper
//   text-based display can be added.
//
// BUG B (Reports.jsx shows 0 for all KPI cards):
//   Controller returned keys: summary.total, revenue.collected, etc.
//   Reports.jsx reads: data.total_revenue, data.total_orders,
//                      data.completed_orders, data.avg_order_value.
//   FIX: Response shape aligned to what the frontend actually reads.
//
// BUG C (salesSummary method missing — GET /api/admin/reports/sales → 500):
//   Route references AdminReportController@salesSummary but the method
//   did not exist. Added below.
//
// Schema rules enforced:
//   - unit_cost (NOT unit_price) on materials
//   - reorder_threshold (NOT reorder_point)
//   - No material_formulas references (table deleted)
//   - No estimated_qty (column never existed)

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class AdminReportController extends Controller
{
    // ── GET /api/admin/reports ────────────────────────────────────────────────
    public function index()
    {
        // 5-minute cache — invalidated by order/transaction writes
        return Cache::remember('admin_reports_index', 300, function () {

            // ── Order status counts ───────────────────────────────────────────
            $statusCounts = DB::table('orders')
                ->selectRaw('status, COUNT(*) as cnt')
                ->groupBy('status')
                ->pluck('cnt', 'status')
                ->toArray();

            $totalOrders     = array_sum($statusCounts);
            $completedOrders = $statusCounts['completed'] ?? 0;
            $cancelledOrders = $statusCounts['cancelled'] ?? 0;

            $production = ($statusCounts['pattern']     ?? 0)
                        + ($statusCounts['segregation'] ?? 0)
                        + ($statusCounts['cutting']     ?? 0)
                        + ($statusCounts['sewing']      ?? 0)
                        + ($statusCounts['qc']          ?? 0)
                        + ($statusCounts['pressing']    ?? 0)
                        + ($statusCounts['packing']     ?? 0);

            // ── Revenue ───────────────────────────────────────────────────────
            $totalRevenue = (float) DB::table('sales_transactions')
                ->sum('amount_paid');

            $avgOrderValue = $completedOrders > 0
                ? round($totalRevenue / $completedOrders, 2)
                : 0.0;

            // ── Inventory ─────────────────────────────────────────────────────
            $lowStock   = DB::table('materials')
                ->whereRaw('quantity_in_stock <= reorder_threshold')
                ->count();
            $invTotal   = DB::table('materials')->count();
            $invValue   = (float) DB::table('materials')
                ->selectRaw('SUM(quantity_in_stock * unit_cost) as val')
                ->value('val');

            // ── 6-month order trends ──────────────────────────────────────────
            $trends = DB::table('orders')
                ->selectRaw("
                    DATE_FORMAT(created_at,'%b %Y') as month,
                    DATE_FORMAT(created_at,'%Y%m')  as sort_key,
                    COUNT(*) as orders,
                    SUM(quantity_ordered) as pieces,
                    SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed
                ")
                ->where('created_at', '>=', now()->subMonths(6))
                ->groupByRaw("DATE_FORMAT(created_at,'%b %Y'), DATE_FORMAT(created_at,'%Y%m')")
                ->orderBy('sort_key')
                ->get()
                ->map(fn($r) => [
                    'month'     => $r->month,
                    'orders'    => (int) $r->orders,
                    'pieces'    => (int) $r->pieces,
                    'completed' => (int) $r->completed,
                ]);

            // ── Order type breakdown ──────────────────────────────────────────
            $typeBreakdown = DB::table('orders')
                ->selectRaw('order_type, COUNT(*) as count, SUM(quantity_ordered) as total_pieces')
                ->whereNotIn('status', ['cancelled'])
                ->groupBy('order_type')
                ->get();

            // ── Pipeline snapshot per stage ───────────────────────────────────
            $pipelineRaw = DB::table('orders')
                ->selectRaw('status, COUNT(*) as count, SUM(quantity_ordered) as total_pieces')
                ->groupBy('status')
                ->get();

            $pipeline = [];
            foreach ($pipelineRaw as $p) {
                $pipeline[$p->status] = [
                    'count'        => (int) $p->count,
                    'total_pieces' => (int) $p->total_pieces,
                ];
            }

            // ── Prescriptive MRP alerts ───────────────────────────────────────
            $alerts = $this->buildPrescriptiveAlerts();

            // ── Response — keys matched to Reports.jsx expectations ───────────
            // Reports.jsx reads: data.total_revenue, data.total_orders,
            //                    data.completed_orders, data.avg_order_value,
            //                    data.prescriptive_alerts, data.top_materials,
            //                    data.summary.* (pipeline tab)
            return response()->json([
                // KPI cards (Reports.jsx lines 42–45)
                'total_revenue'    => $totalRevenue,
                'total_orders'     => $totalOrders,
                'completed_orders' => $completedOrders,
                'avg_order_value'  => $avgOrderValue,

                // Alerts tab
                'prescriptive_alerts' => $alerts,

                // Overview tab — inventory + trends
                'inventory_summary' => [
                    'total'       => $invTotal,
                    'low'         => $lowStock,
                    'total_value' => $invValue,
                ],
                'order_trends'         => $trends,
                'order_type_breakdown' => $typeBreakdown,

                // Orders tab — pipeline
                'pipeline_snapshot' => $pipeline,
                'summary' => [
                    'total'      => $totalOrders,
                    'pending'    => ($statusCounts['pending']   ?? 0)
                                  + ($statusCounts['confirmed'] ?? 0),
                    'production' => $production,
                    'completed'  => $completedOrders,
                    'cancelled'  => $cancelledOrders,
                ],

                // top_materials removed — material_recommendations.estimated_qty
                // column does not exist. Column is estimated_range (varchar text).
                // Will be restored in Month 5 when AI recommendations have real data.
                'top_materials' => [],
            ]);
        });
    }

    // ── GET /api/admin/reports/sales ─────────────────────────────────────────
    // FIX: Method was missing — route existed, controller method did not.
    public function salesSummary()
    {
        $txns = DB::table('sales_transactions')
            ->selectRaw("
                DATE_FORMAT(payment_date,'%b %Y') as month,
                DATE_FORMAT(payment_date,'%Y%m')  as sort_key,
                SUM(amount_paid)  as collected,
                SUM(amount_total) as billed,
                SUM(balance_due)  as outstanding,
                COUNT(*)          as transaction_count
            ")
            ->whereNotNull('payment_date')
            ->where('payment_date', '>=', now()->subMonths(6))
            ->groupByRaw("DATE_FORMAT(payment_date,'%b %Y'), DATE_FORMAT(payment_date,'%Y%m')")
            ->orderBy('sort_key')
            ->get()
            ->map(fn($r) => [
                'month'             => $r->month,
                'collected'         => (float) $r->collected,
                'billed'            => (float) $r->billed,
                'outstanding'       => (float) $r->outstanding,
                'transaction_count' => (int)   $r->transaction_count,
            ]);

        $totals = [
            'total_collected'   => (float) DB::table('sales_transactions')->sum('amount_paid'),
            'total_billed'      => (float) DB::table('sales_transactions')->sum('amount_total'),
            'total_outstanding' => (float) DB::table('sales_transactions')->sum('balance_due'),
            'total_txns'        => DB::table('sales_transactions')->count(),
            'by_method'         => DB::table('sales_transactions')
                ->selectRaw('payment_method, COUNT(*) as count, SUM(amount_paid) as total')
                ->groupBy('payment_method')
                ->get(),
        ];

        return response()->json([
            'monthly_trends' => $txns,
            'totals'         => $totals,
        ]);
    }

    // ── GET /api/admin/reports/prescriptive-alerts ───────────────────────────
    // FIX: route referenced this method but it never existed → 500 if hit.
    // Reports.jsx actually gets its Alerts tab data from prescriptive_alerts
    // inside GET /api/admin/reports (index() above), so this is a thin wrapper
    // around the same private builder for anything that calls this URL directly.
    public function prescriptiveAlerts()
    {
        return response()->json([
            'alerts' => $this->buildPrescriptiveAlerts(),
        ]);
    }

    // ── Private: prescriptive MRP alerts ─────────────────────────────────────
    // Generates alerts for low stock and near-deadline orders.
    // Does NOT touch material_recommendations — uses materials table only.
    private function buildPrescriptiveAlerts(): array
    {
        $alerts = [];

        // Low-stock materials (no active order join needed — just threshold check)
        DB::table('materials')
            ->whereRaw('quantity_in_stock <= reorder_threshold')
            ->orderByRaw('(quantity_in_stock / NULLIF(reorder_threshold,0)) ASC')
            ->get()
            ->each(function ($m) use (&$alerts) {
                $pct          = $m->reorder_threshold > 0
                    ? round(($m->quantity_in_stock / $m->reorder_threshold) * 100)
                    : 0;
                $severity     = $m->quantity_in_stock == 0 ? 'critical' : 'warning';
                $orderQty     = round($m->reorder_threshold * 2, 2);
                $deficit      = max(0, round($m->reorder_threshold - $m->quantity_in_stock, 2));

                // FIX: Reports.jsx reads material_name, current_stock, total_required,
                // recommended_order, category — the previous keys here (material,
                // in_stock, demanded_qty, recommend_order_qty, no category at all)
                // didn't match, so every alert card rendered zeros/nulls.
                $alerts[] = [
                    'severity'          => $severity,
                    'type'              => 'low_stock',
                    'material_name'     => $m->material_name,
                    'category'          => $m->category,
                    'unit'              => $m->unit,
                    'message'           => "{$m->material_name}: {$m->quantity_in_stock} {$m->unit} in stock "
                                          . "({$pct}% of reorder threshold {$m->reorder_threshold}).",
                    'action'            => "Create an RFQ for at least {$orderQty} {$m->unit}.",
                    'current_stock'     => (float) $m->quantity_in_stock,
                    'total_required'    => (float) $m->reorder_threshold,
                    'deficit'           => $deficit,
                    'recommended_order' => $orderQty,
                ];
            });

        // Near-deadline orders still in production
        $nearDeadline = DB::table('orders')
            ->whereIn('status', [
                'pending', 'confirmed', 'pattern', 'segregation',
                'cutting', 'sewing', 'qc', 'pressing', 'packing',
            ])
            ->whereNotNull('target_delivery_date')
            ->where('target_delivery_date', '<=', now()->addDays(7)->toDateString())
            ->pluck('order_id');

        if ($nearDeadline->isNotEmpty()) {
            $alerts[] = [
                'severity'          => 'warning',
                'type'              => 'deadline',
                'material_name'     => null,
                'category'          => null,
                'unit'              => null,
                'message'           => "{$nearDeadline->count()} order(s) have delivery deadlines within 7 days "
                                       . "and are still in production.",
                'action'            => 'Review Orders and prioritize production.',
                'current_stock'     => 0,
                'total_required'    => 0,
                'deficit'           => 0,
                'recommended_order' => 0,
                'affected_orders'   => $nearDeadline->values(),
            ];
        }

        // Sort: critical first, then warning
        usort($alerts, fn($a, $b) =>
            (['critical' => 0, 'warning' => 1, 'info' => 2][$a['severity']])
            - (['critical' => 0, 'warning' => 1, 'info' => 2][$b['severity']])
        );

        return $alerts;
    }
}
