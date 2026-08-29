<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $primaryKey = 'notif_id';
    // BUG FIX (pre-deployment audit): 'type' and 'title' both exist as real
    // columns on this table (see vfrb_db.sql) but were missing here, so any
    // Notification::create([...'type'=>..., 'title'=>...]) call silently
    // dropped those two fields via Eloquent's mass-assignment guard — no
    // exception, nothing logged. All current call sites now use
    // DB::table('notifications')->insert() instead (bypasses $fillable
    // entirely), but this is fixed too so the model is safe if anyone uses
    // it directly in the future.
    protected $fillable   = ['user_id','order_id','message','type','title','is_read','date_sent'];
    protected $casts      = ['is_read'=>'boolean','date_sent'=>'datetime'];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    { return $this->belongsTo(User::class, 'user_id', 'user_id'); }

    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    { return $this->belongsTo(Order::class, 'order_id', 'order_id'); }
}