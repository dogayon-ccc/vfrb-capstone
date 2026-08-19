<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Measurement extends Model
{
    protected $primaryKey = 'measurement_id';

    protected $fillable = [
        'order_id',
        'user_id',
        'type',          // FIX: was missing — DB has `type ENUM('standard','custom') NOT NULL`
        'size_label',
        'neck',
        'chest',
        'waist',
        'hip',
        'sleeve_length', // FIX: was 'sleeve' — actual DB column name is sleeve_length
        // NOTE: 'length' REMOVED — no such column in measurements table
    ];

    protected $casts = [
        'neck'         => 'float',
        'chest'        => 'float',
        'waist'        => 'float',
        'hip'          => 'float',
        'sleeve_length'=> 'float', // FIX: was 'sleeve'
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'order_id');
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}