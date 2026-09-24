<?php
// app/Models/KpiDailySnapshot.php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class KpiDailySnapshot extends Model
{
    protected $primaryKey = 'snapshot_id';

    protected $fillable = [
        'snapshot_date', 'revenue', 'orders_total', 'in_production', 'stock_health_pct',
    ];

    protected $casts = [
        'snapshot_date'    => 'date',
        'revenue'          => 'float',
        'orders_total'     => 'integer',
        'in_production'    => 'integer',
        'stock_health_pct' => 'float',
    ];

    public function scopeSince($q, $date) { return $q->where('snapshot_date', '>=', $date)->orderBy('snapshot_date'); }

    // Idempotent — safe to call from both the dashboard endpoint (lazy,
    // every load) and the daily scheduler (routes/console.php).
    public static function captureToday(): void
    {
        $today = now()->toDateString();
        if (static::where('snapshot_date', $today)->exists()) return;

        $materialCount = DB::table('materials')->count();
        $lowStock      = DB::table('materials')->whereRaw('quantity_in_stock <= reorder_threshold')->count();

        static::create([
            'snapshot_date'    => $today,
            'revenue'          => (float) DB::table('sales_transactions')->whereDate('payment_date', $today)->sum('amount_paid'),
            'orders_total'     => DB::table('orders')->count(),
            'in_production'    => DB::table('orders')->whereIn('status', ['pattern','segregation','cutting','sewing','qc','pressing','packing'])->count(),
            'stock_health_pct' => $materialCount > 0 ? round((($materialCount - $lowStock) / $materialCount) * 100, 2) : 0,
        ]);
    }
}
