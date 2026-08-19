<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RfqResponse extends Model
{
    protected $primaryKey = 'response_id';

    protected $fillable = [
        'rfq_id',
        'supplier_id',
        'unit_price',
        'qty_available',
        'lead_time_days',
        'notes',
        'responded_at',
    ];

    protected $casts = [
        'unit_price'     => 'float',
        'qty_available'  => 'float',
        'lead_time_days' => 'integer',
        'responded_at'   => 'datetime',
    ];

    public function rfq(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    { return $this->belongsTo(RfqRequest::class, 'rfq_id', 'rfq_id'); }

    public function supplier(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    { return $this->belongsTo(Supplier::class, 'supplier_id', 'supplier_id'); }
}