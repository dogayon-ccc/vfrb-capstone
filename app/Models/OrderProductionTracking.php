<?php
// app/Models/OrderProductionTracking.php
// FIX v10: stageLabel() — added segregation + pressing (was falling to ucfirst())

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderProductionTracking extends Model
{
    protected $table      = 'order_production_tracking';
    protected $primaryKey = 'tracking_id';

    protected $fillable = [
        'order_id', 'stage', 'qty_target', 'qty_completed', 'updated_by', 'notes',
    ];

    protected $casts = [
        'qty_target'    => 'integer',
        'qty_completed' => 'integer',
    ];

    public function getIsCompleteAttribute(): bool
    {
        return $this->qty_target > 0 && $this->qty_completed >= $this->qty_target;
    }

    public function getPctAttribute(): int
    {
        return $this->qty_target > 0
            ? min(100, (int) round($this->qty_completed / $this->qty_target * 100))
            : 0;
    }

    // FIX: added 'segregation' (Fabric Segregation) and 'pressing' (Pressing & Ironing)
    // Source: Ma'am Fe April 30 interview — "segregation of sizes" + "pressing"
    public static function stageLabel(string $stage): string
    {
        return match ($stage) {
            'pattern'     => 'Pattern Making',
            'segregation' => 'Fabric Segregation',
            'cutting'     => 'Fabric Cutting',
            'sewing'      => 'Sewing & Assembly',
            'qc'          => 'Quality Control',
            'pressing'    => 'Pressing & Ironing',
            'packing'     => 'Packing & Finishing',
            default       => ucfirst($stage),
        };
    }

    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'order_id');
    }

    public function updatedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by', 'user_id');
    }
}