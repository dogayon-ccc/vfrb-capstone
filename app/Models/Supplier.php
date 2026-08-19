<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $primaryKey = 'supplier_id';

    protected $fillable = [
        'supplier_name',               // NOT company_name
        'contact_person',
        'email',
        'phone',
        'address',
        'supplier_type',               // ENUM: subcontract | direct
        'materials_supplied',
        'payment_terms_with_supplier', // NOT payment_terms
        'lead_time_days',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active'      => 'boolean',
        'lead_time_days' => 'integer',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    // The user account linked to this supplier (via users.supplier_id FK)
    public function user(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(User::class, 'supplier_id', 'supplier_id');
    }

    public function rfqResponses(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RfqResponse::class, 'supplier_id', 'supplier_id');
    }

    public function purchaseOrders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'supplier_id', 'supplier_id');
    }
}