<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class OrderMessage extends Model
{
    protected $primaryKey = 'message_id';
    protected $fillable   = ['order_id', 'sender_id', 'body', 'is_read', 'sent_at'];
    protected $casts      = ['is_read' => 'boolean', 'sent_at' => 'datetime'];

    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    { return $this->belongsTo(Order::class, 'order_id', 'order_id'); }

    public function sender(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    { return $this->belongsTo(User::class, 'sender_id', 'user_id'); }
}