<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingAddress extends Model
{
    protected $table = 'shipping_addresses';
    protected $primaryKey = 'shipping_id';

    protected $fillable = [
        'user_id', 'label', 'recipient_name', 'contact_number',
        'address_line', 'city', 'province', 'postal_code', 'is_default',
    ];

    protected $casts = ['is_default' => 'boolean'];

    public function user(): BelongsTo
    { return $this->belongsTo(User::class, 'user_id', 'user_id'); }
}
