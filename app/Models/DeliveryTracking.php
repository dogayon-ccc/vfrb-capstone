<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class DeliveryTracking extends Model
{
    protected $table      = 'delivery_tracking'; // CRITICAL: no trailing 's'
    protected $primaryKey = 'tracking_id';

    protected $fillable = [
        'order_id','delivery_method','delivery_status',
        'courier_name','tracking_number','delivery_address',
        'estimated_delivery_date','actual_delivery_date','updated_by','notes',
    ];

    protected $casts = [
        'estimated_delivery_date' => 'date',
        'actual_delivery_date'    => 'date',
    ];

    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    { return $this->belongsTo(Order::class, 'order_id', 'order_id'); }

    public function updater(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    { return $this->belongsTo(User::class, 'updated_by', 'user_id'); }
}