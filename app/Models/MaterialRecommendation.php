<?php
// app/Models/MaterialRecommendation.php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class MaterialRecommendation extends Model
{
    protected $primaryKey = 'rec_id';

    // Note on lines the customer picked themselves; AI quality metrics leave these out.
    public const CUSTOMER_PICK_NOTE = 'Selected directly by customer, not AI-recommended.';

    /**
     * The note as a customer may read it. A stored note that fails today's AI text guard (written before the guard existed)
     * reads as the generic line instead. Every catalog name and code is ignored, so a swap note naming the old material survives.
     * Stock is VFRB-internal: any stock remark is removed here; the stored note (read by staff) keeps it.
     */
    public static function customerNote(?string $note, ?string $category): ?string
    {
        if ($note === null || trim($note) === '') { return $note; }
        $generic = 'Suitable ' . mb_strtolower($category ?: 'material') . ' for this design.';
        $note = self::withoutStockTalk($note);
        if ($note === '') { return $generic; }
        $terms = once(fn () => Material::query()->get(['material_name', 'material_code'])
            ->flatMap(fn ($m) => [(string) $m->material_name, (string) $m->material_code])->filter()->values()->all());
        return app(\App\Services\MaterialCatalogMatcher::class)->isUnsafeAiText($note, $terms) ? $generic : $note;
    }

    /** Drops every bracketed aside and sentence that mentions stock ("(Note: Currently out of stock)", "It is restocked weekly."). */
    public static function withoutStockTalk(string $text): string
    {
        $stock = '\b(?:re)?stock(?:ed|s)?\b';
        $text = preg_replace("/\\s*\\([^()]*{$stock}[^()]*\\)/iu", '', $text) ?? $text;
        $text = preg_replace("/(?:^|(?<=[.!?]))\\s*[^.!?]*{$stock}[^.!?]*(?:[.!?]+|$)/iu", '', $text) ?? $text;
        return trim(preg_replace('/\s{2,}/u', ' ', $text) ?? $text);
    }

    public const CUSTOMER_COLUMNS = [
        'rec_id','order_id','material_id','material_name','category','unit','ai_note',
        'display_order','status','customer_accepted','accepted_at','customer_note','created_at','updated_at',
    ];
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
        'staff_feedback','staff_feedback_note','staff_feedback_by','staff_feedback_at',
    ];
    protected $casts = [
        'customer_accepted' => 'boolean',
        'accepted_at'       => 'datetime',
        'linked_at'         => 'datetime',
        'issued_at'         => 'datetime',
        'staff_feedback_at' => 'datetime',
    ];
    public function order()    { return $this->belongsTo(Order::class, 'order_id', 'order_id'); }
    public function material() { return $this->belongsTo(Material::class, 'material_id', 'material_id'); }
    public function linker()   { return $this->belongsTo(User::class, 'linked_by', 'user_id'); }
    public function scopeAccepted($q)         { return $q->where('customer_accepted', 1); }
    public function scopeForOrder($q, $id)    { return $q->where('order_id', $id); }
}
