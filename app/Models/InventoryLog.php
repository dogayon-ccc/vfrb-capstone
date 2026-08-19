<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryLog extends Model
{
    protected $primaryKey = 'log_id';

    protected $fillable = [
        'material_id',
        'recorded_by',  // FK → users.user_id
        'type',         // ENUM: stock_in | stock_out | adjustment
        'change_qty',   // positive = in, negative = out
        'reason',       // audit note
        'log_date',     // timestamp
    ];

    protected $casts = [
        'change_qty' => 'float',
        'log_date'   => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function material(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Material::class, 'material_id', 'material_id');
    }

    public function recorder(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by', 'user_id');
    }
}