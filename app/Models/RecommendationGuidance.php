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

    /**
     * The rules the AI sees for one design, most specific first: garment + category, garment, category, then general; newest first
     * within each. Scopes are compared normalised and as whole words, so "Polo Shirt" matches an order stored as "polo_shirt"
     * and "medical" matches the Studio category "Medical / Scrubs". Filtered in PHP: the table is small and this stays portable.
     * @return string[]
     */
    public static function forDesign(?string $category, ?string $garment, int $limit = 12): array
    {
        $norm  = fn ($s) => trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower((string) $s)));
        $fits  = fn ($scope, $value) => $norm($scope) === '' || str_contains(' ' . $norm($value) . ' ', ' ' . $norm($scope) . ' ');
        return static::query()->where('is_active', true)->get(['guidance_id', 'scope_category', 'scope_garment', 'rule', 'updated_at'])
            ->filter(fn ($g) => $fits($g->scope_category, $category) && $fits($g->scope_garment, $garment))
            ->sortBy([
                fn ($a, $b) => ((int) ($norm($b->scope_garment) !== '') * 2 + (int) ($norm($b->scope_category) !== ''))
                             <=> ((int) ($norm($a->scope_garment) !== '') * 2 + (int) ($norm($a->scope_category) !== '')),
                fn ($a, $b) => [$b->updated_at?->getTimestamp() ?? 0, $b->guidance_id] <=> [$a->updated_at?->getTimestamp() ?? 0, $a->guidance_id],
            ])
            ->take($limit)->pluck('rule')->values()->all();
    }

    /** Active rules for this design: unscoped, or scoped to its category / garment (case-insensitive, exact). Prefer forDesign(). */
    public function scopeApplicable($q, ?string $category, ?string $garment)
    {
        $norm = fn ($s) => mb_strtolower(trim((string) $s));
        return $q->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('scope_category')->orWhereRaw('lower(scope_category) = ?', [$norm($category)]))
            ->where(fn ($w) => $w->whereNull('scope_garment')->orWhereRaw('lower(scope_garment) = ?', [$norm($garment)]));
    }
}
