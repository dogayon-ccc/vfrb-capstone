<?php
// app/Models/PhysicalCountLog.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhysicalCountLog extends Model
{
    protected $primaryKey = 'count_id';

    protected $fillable = [
        'material_id','counted_by','system_qty','physical_qty',
        'reason','count_date','reconciled','reconciled_at',
        'reconciled_by','reconciliation_note','stock_adjusted',
        'created_at','updated_at',
    ];

    protected $casts = [
        'system_qty'    => 'decimal:2',
        'physical_qty'  => 'decimal:2',
        'variance'      => 'decimal:2',   // GENERATED ALWAYS column
        'variance_pct'  => 'decimal:2',   // GENERATED ALWAYS column
        'reconciled'    => 'boolean',
        'stock_adjusted'=> 'boolean',
        'reconciled_at' => 'datetime',
        'count_date'    => 'date',
    ];

    // Prevent mass-assignment of computed columns
    protected $guarded = ['variance','variance_pct'];

    // ── Relationships ─────────────────────────────────────────────────────────
    public function material()   { return $this->belongsTo(Material::class, 'material_id', 'material_id'); }
    public function counter()    { return $this->belongsTo(User::class, 'counted_by', 'user_id'); }
    public function reconciler() { return $this->belongsTo(User::class, 'reconciled_by', 'user_id'); }

    // ── Scopes ────────────────────────────────────────────────────────────────
    public function scopeUnreconciled($q) { return $q->where('reconciled', 0); }
    public function scopeFlagged($q)      { return $q->whereRaw('ABS(variance_pct) > 5'); }
    public function scopeForMaterial($q, $id) { return $q->where('material_id', $id); }

    // ── Accessors ─────────────────────────────────────────────────────────────
    public function getIsFlaggedAttribute(): bool
    {
        return abs($this->variance_pct ?? 0) > 5;
    }
    public function getVarianceDirectionAttribute(): string
    {
        $v = $this->variance ?? 0;
        return $v > 0 ? 'surplus' : ($v < 0 ? 'shortage' : 'exact');
    }
}
