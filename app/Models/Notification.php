<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $primaryKey = 'notif_id';
    protected $fillable   = ['user_id','order_id','message','is_read','date_sent'];
    protected $casts      = ['is_read'=>'boolean','date_sent'=>'datetime'];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    { return $this->belongsTo(User::class, 'user_id', 'user_id'); }

    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    { return $this->belongsTo(Order::class, 'order_id', 'order_id'); }
}