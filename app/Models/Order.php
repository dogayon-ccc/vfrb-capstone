<?php
// app/Models/Order.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $primaryKey = 'order_id';

    protected $fillable = [
        // Original columns
        'user_id','design_id','status','order_type','payment_method',
        'payment_terms','down_payment_amount','discount_rate','discount_pct',
        'agreed_total',
        'estimated_completion_date','deadline_negotiated','quantity_ordered',
        'sizing_type','target_delivery_date','negotiated_delivery_date',
        'notes','client_design_notes','client_design_ref_file',
        'client_design_preview_file',
        'po_reference','color',
        // New columns from migration
        'garment_type','collar_type','sleeve_type','pocket_type',
        'studio_config',
        'ai_recommendation_status',
        'ai_recommendation_requested_at',
        'ai_recommendation_accepted_at',
        'qc_required','qc_passed_at',
    ];

    protected $casts = [
        'studio_config'                     => 'array',
        'deadline_negotiated'               => 'boolean',
        'qc_required'                       => 'boolean',
        'down_payment_amount'               => 'decimal:2',
        'discount_rate'                     => 'decimal:2',
        'agreed_total'                      => 'decimal:2',
        'ai_recommendation_requested_at'    => 'datetime',
        'ai_recommendation_accepted_at'     => 'datetime',
        'qc_passed_at'                      => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────
    public function user()            { return $this->belongsTo(User::class, 'user_id', 'user_id'); }
    public function design()          { return $this->belongsTo(Design::class, 'design_id', 'design_id'); }
    public function recommendations() { return $this->hasMany(MaterialRecommendation::class, 'order_id', 'order_id'); }
    public function messages()        { return $this->hasMany(OrderMessage::class, 'order_id', 'order_id'); }
    public function production()      { return $this->hasMany(OrderProductionTracking::class, 'order_id', 'order_id'); }
    public function delivery()        { return $this->hasOne(DeliveryTracking::class, 'order_id', 'order_id'); }
    public function transaction()     { return $this->hasOne(SalesTransaction::class, 'order_id', 'order_id'); }
    public function qcChecklists()    { return $this->hasMany(QCChecklist::class, 'order_id', 'order_id'); }
    public function outputLogs()      { return $this->hasMany(DailyOutputLog::class, 'order_id', 'order_id'); }

    // ── Scopes ────────────────────────────────────────────────────────────────
    public function scopeForCustomer($q, $userId) { return $q->where('user_id', $userId); }
    public function scopeActive($q) {
        return $q->whereNotIn('status', ['completed','cancelled']);
    }
    public function scopeInProduction($q) {
        return $q->whereIn('status', ['pattern','segregation','cutting','sewing','qc','pressing','packing']);
    }
}