<?php
// One row per recorded payment (RCT-YYYY-NNNN). Not a BIR official receipt.
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentReceipt extends Model
{
    protected $primaryKey = 'receipt_id';

    protected $fillable = [
        'order_id', 'invoice_id', 'receipt_number', 'amount', 'payment_method',
        'reference_no', 'balance_after', 'paid_at', 'issued_by', 'notes',
    ];

    protected $casts = [
        'amount'        => 'float',
        'balance_after' => 'float',
        'paid_at'       => 'datetime',
    ];

    public function order()   { return $this->belongsTo(Order::class, 'order_id', 'order_id'); }
    public function invoice() { return $this->belongsTo(Invoice::class, 'invoice_id', 'invoice_id'); }
    public function issuer()  { return $this->belongsTo(User::class, 'issued_by', 'user_id'); }
}
