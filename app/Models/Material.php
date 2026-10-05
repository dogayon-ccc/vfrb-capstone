<?php
// app/Models/Material.php
// MaterialFormula relationship REMOVED — table dropped in migration
// unit_cost (not unit_price)
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Material extends Model
{
    protected $primaryKey = 'material_id';

    public const CODE_REGEX = '/^MAT-[A-Z]{3}-\\d{3,}$/';

    protected $fillable = [
        'material_code',
        'material_name',
        'category',
        'unit',
        'quantity_in_stock',
        'reorder_threshold',
        'unit_cost',
        'ai_eligible',
        'applies_to',
    ];

    protected $casts = [
        'quantity_in_stock' => 'float',
        'reorder_threshold' => 'float',
        'unit_cost'         => 'float',
        'ai_eligible'       => 'boolean',
        'applies_to'        => 'array',
    ];

    public static function categoryKey(?string $category): string
    {
        $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $category)) ?: 'GEN';
        return str_pad(substr($letters, 0, 3), 3, 'X');
    }

    public static function nextCode(?string $category): string
    {
        $key  = self::categoryKey($category);
        $last = \DB::table('materials')->where('material_code', 'like', "MAT-{$key}-%")
            ->orderByRaw('CAST(SUBSTRING(material_code, 9) AS UNSIGNED) DESC')->value('material_code');
        $n = $last ? (int) substr($last, 8) + 1 : 1;
        return sprintf('MAT-%s-%03d', $key, $n);
    }

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