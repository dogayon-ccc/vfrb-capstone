<?php
// One live (non-void) invoice per order. billed_to_* and total_amount are frozen at issue time.
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $primaryKey = 'invoice_id';

    protected $fillable = [
        'order_id', 'invoice_number', 'status', 'billing_profile_id',
        'billed_to_name', 'billed_to_tin', 'billed_to_address', 'billed_to_email',
        'total_amount', 'due_date', 'notes', 'issued_at', 'issued_by',
        'paid_at', 'marked_paid_by', 'voided_at',
    ];

    protected $casts = [
        'total_amount' => 'float',
        'due_date'     => 'date',
        'issued_at'    => 'datetime',
        'paid_at'      => 'datetime',
        'voided_at'    => 'datetime',
    ];

    public function order()    { return $this->belongsTo(Order::class, 'order_id', 'order_id'); }
    public function receipts() { return $this->hasMany(PaymentReceipt::class, 'invoice_id', 'invoice_id'); }
    public function issuer()   { return $this->belongsTo(User::class, 'issued_by', 'user_id'); }

    public function scopeLive($q) { return $q->where('status', '!=', 'void'); }
}
