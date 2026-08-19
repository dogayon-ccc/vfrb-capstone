<?php
// app/Models/SalesTransaction.php
// UNIQUE KEY on order_id → always use updateOrCreate, never create()
// DB: transaction_id, order_id, processed_by, amount_total, amount_paid,
//     balance_due, payment_method, payment_terms, payment_date, or_number,
//     completion_status, notes, date_processed

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class SalesTransaction extends Model
{
    protected $primaryKey = 'transaction_id';
    protected $fillable = [
        'order_id','processed_by','amount_total','amount_paid','balance_due',
        'payment_method','payment_terms','payment_date','or_number',
        'completion_status','notes','date_processed',
    ];
    protected $casts = [
        'amount_total'   => 'float',
        'amount_paid'    => 'float',
        'balance_due'    => 'float',
        'payment_date'   => 'date',
        'date_processed' => 'datetime',
    ];

    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    { return $this->belongsTo(Order::class, 'order_id', 'order_id'); }

    public function processor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    { return $this->belongsTo(User::class, 'processed_by', 'user_id'); }
}