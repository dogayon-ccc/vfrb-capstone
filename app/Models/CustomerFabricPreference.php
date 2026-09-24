<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerFabricPreference extends Model
{
    protected $table = 'customer_fabric_preferences';
    protected $primaryKey = 'preference_id';

    protected $fillable = ['user_id', 'material_id', 'notes'];

    public function user(): BelongsTo
    { return $this->belongsTo(User::class, 'user_id', 'user_id'); }

    // Controller eager-loads 'material:material_id,material_name,category,unit' —
    // relation name must match that call exactly.
    public function material(): BelongsTo
    { return $this->belongsTo(Material::class, 'material_id', 'material_id'); }
}
