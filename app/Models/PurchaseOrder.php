<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    protected $primaryKey = 'po_id';
    protected $fillable = [
        'po_number','supplier_id','created_by','order_id','status',
        'items','total_amount','expected_delivery_date','actual_delivery_date','notes',
    ];
    protected $casts = [
        'total_amount'           => 'float',
        'expected_delivery_date' => 'date',
        'actual_delivery_date'   => 'date',
        'items'                  => 'array',
    ];

    public function supplier(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    { return $this->belongsTo(Supplier::class, 'supplier_id', 'supplier_id'); }

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    { return $this->belongsTo(User::class, 'created_by', 'user_id'); }

    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    { return $this->belongsTo(Order::class, 'order_id', 'order_id'); }
}