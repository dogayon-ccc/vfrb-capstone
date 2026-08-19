<?php
// app/Models/MaterialUsageRate.php
// Staff-configurable, deterministic material consumption rates.
// See migration 2026_08_01_000001 for the full architecture note.
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaterialUsageRate extends Model
{
    protected $primaryKey = 'rate_id';
    protected $fillable = [
        'material_id', 'garment_type', 'qty_per_unit', 'unit', 'set_by',
    ];
    protected $casts = [
        'qty_per_unit' => 'float',
    ];

    public function material() { return $this->belongsTo(Material::class, 'material_id', 'material_id'); }
    public function setter()   { return $this->belongsTo(User::class, 'set_by', 'user_id'); }
}
