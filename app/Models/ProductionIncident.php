<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionIncident extends Model
{
    protected $primaryKey = 'incident_id';

    protected $fillable = [
        'order_id', 'incident_type', 'stage', 'reported_by', 'description',
        'qty_affected', 'status', 'acknowledged_by', 'acknowledged_at',
        'resolved_at', 'resolution_notes',
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime',
        'resolved_at'     => 'datetime',
    ];

    public function order(): BelongsTo
    { return $this->belongsTo(Order::class, 'order_id', 'order_id'); }

    public function reporter(): BelongsTo
    { return $this->belongsTo(User::class, 'reported_by', 'user_id'); }

    public function acknowledger(): BelongsTo
    { return $this->belongsTo(User::class, 'acknowledged_by', 'user_id'); }
}
