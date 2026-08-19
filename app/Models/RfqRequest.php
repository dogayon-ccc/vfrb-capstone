<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RfqRequest extends Model
{
    protected $primaryKey = 'rfq_id';

    protected $fillable = [
        'material_id',
        'qty_needed',
        'needed_by_date',
        'status',      // ENUM: open | closed (NOT 'responded' — invalid enum)
        'created_by',
    ];

    protected $casts = [
        'qty_needed'     => 'float',
        'needed_by_date' => 'date',
    ];

    public function material(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    { return $this->belongsTo(Material::class, 'material_id', 'material_id'); }

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    { return $this->belongsTo(User::class, 'created_by', 'user_id'); }

    public function responses(): \Illuminate\Database\Eloquent\Relations\HasMany
    { return $this->hasMany(RfqResponse::class, 'rfq_id', 'rfq_id'); }
}