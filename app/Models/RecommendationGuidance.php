<?php
// app/Models/RecommendationGuidance.php
// Manager-written rules that ground the AI material recommendation prompt
// (e.g. "medical: prefer fluid-resistant poly-cotton"). Never a quantity or price.
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecommendationGuidance extends Model
{
    protected $table      = 'recommendation_guidance';
    protected $primaryKey = 'guidance_id';

    protected $fillable = ['scope_category', 'scope_garment', 'rule', 'is_active', 'updated_by'];
    protected $casts    = ['is_active' => 'boolean'];

    public function updater() { return $this->belongsTo(User::class, 'updated_by', 'user_id'); }

    /** Active rules for this design: unscoped, or scoped to its category / garment (case-insensitive). */
    public function scopeApplicable($q, ?string $category, ?string $garment)
    {
        $norm = fn ($s) => mb_strtolower(trim((string) $s));
        return $q->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('scope_category')->orWhereRaw('lower(scope_category) = ?', [$norm($category)]))
            ->where(fn ($w) => $w->whereNull('scope_garment')->orWhereRaw('lower(scope_garment) = ?', [$norm($garment)]));
    }
}
