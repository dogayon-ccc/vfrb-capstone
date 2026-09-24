<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Design extends Model
{

    protected $primaryKey = 'design_id';

    protected $fillable = [
        'design_name',
        'garment_type',
        'category',
        'collar_type',
        'sleeve_type',
        'pocket_type',
        'color',
        'pattern',
        'logo_path',
        'photo_path',
        'is_active',
        'custom_builder_config',
        'parent_design_id',
        'showcase_status',
        'showcase_label',
        'submitted_by_user_id',
        'showcase_approved_by',
        'showcase_approved_at',
    ];

    protected $casts = [
        'is_active'            => 'boolean',
        'showcase_approved_at' => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function orders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Order::class, 'design_id', 'design_id');
    }

    // Self-referential: child design → parent design
    public function parent(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Design::class, 'parent_design_id', 'design_id');
    }

    // Parent design → child designs (variants)
    public function children(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Design::class, 'parent_design_id', 'design_id');
    }

    public function showcaseAudit(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DesignShowcaseAudit::class, 'design_id', 'design_id');
    }
}