<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingProfile extends Model
{
    protected $table = 'billing_profiles';
    protected $primaryKey = 'billing_id';

    protected $fillable = [
        'user_id', 'billing_name', 'tin', 'billing_address', 'billing_email', 'is_default',
    ];

    protected $casts = ['is_default' => 'boolean'];

    public function user(): BelongsTo
    { return $this->belongsTo(User::class, 'user_id', 'user_id'); }
}
