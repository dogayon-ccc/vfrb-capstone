<?php
// app/Models/MaterialRecommendation.php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class MaterialRecommendation extends Model
{
    protected $primaryKey = 'rec_id';
    // NOTE (Aug 28 2026 scope correction): estimated_range/total_estimated_range
    // removed from $fillable — no formula/BOM exists in this system, nothing
    // computes or writes these anymore. DB columns intentionally left in
    // place (not part of this change) in case any legacy row still has data
    // in them; just no code path reads or writes them now.
    protected $fillable = [
        'order_id','material_name','material_id','category',
        'unit','ai_note',
        'display_order','status','customer_accepted','accepted_at',
        'customer_note','linked_by','linked_at','actual_qty_issued',
        'issued_at','created_at','updated_at',
    ];
    protected $casts = [
        'customer_accepted' => 'boolean',
        'accepted_at'       => 'datetime',
        'linked_at'         => 'datetime',
        'issued_at'         => 'datetime',
    ];
    public function order()    { return $this->belongsTo(Order::class, 'order_id', 'order_id'); }
    public function material() { return $this->belongsTo(Material::class, 'material_id', 'material_id'); }
    public function linker()   { return $this->belongsTo(User::class, 'linked_by', 'user_id'); }
    public function scopeAccepted($q)         { return $q->where('customer_accepted', 1); }
    public function scopeForOrder($q, $id)    { return $q->where('order_id', $id); }
}
