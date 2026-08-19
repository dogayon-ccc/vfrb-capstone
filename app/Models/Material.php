<?php
// app/Models/Material.php
// MaterialFormula relationship REMOVED — table dropped in migration
// unit_cost (not unit_price)
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Material extends Model
{
    protected $primaryKey = 'material_id';

    protected $fillable = [
        'material_name',
        'category',
        'unit',
        'quantity_in_stock',
        'reorder_threshold',
        'unit_cost',
    ];

    protected $casts = [
        'quantity_in_stock' => 'float',
        'reorder_threshold' => 'float',
        'unit_cost'         => 'float',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────
    public function inventoryLogs()
    {
        return $this->hasMany(InventoryLog::class, 'material_id', 'material_id');
    }

    public function recommendations()
    {
        return $this->hasMany(MaterialRecommendation::class, 'material_id', 'material_id');
    }

    public function physicalCounts()
    {
        return $this->hasMany(PhysicalCountLog::class, 'material_id', 'material_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────
    public function scopeLowStock($q)
    {
        return $q->whereRaw('quantity_in_stock <= reorder_threshold');
    }

    public function scopeByCategory($q, string $cat)
    {
        return $q->where('category', $cat);
    }

    // ── Accessors ─────────────────────────────────────────────────────────────
    public function getIsLowStockAttribute(): bool
    {
        return $this->quantity_in_stock <= $this->reorder_threshold;
    }
}