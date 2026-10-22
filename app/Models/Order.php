<?php

// app/Models/Order.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Order extends Model
{
    protected $primaryKey = 'order_id';

    protected $fillable = [
        // Original columns
        'user_id', 'design_id', 'status', 'order_type', 'payment_method',
        'payment_terms', 'down_payment_amount', 'discount_rate', 'discount_pct',
        'agreed_total',
        'estimated_completion_date', 'deadline_negotiated', 'quantity_ordered',
        'sizing_type', 'target_delivery_date', 'negotiated_delivery_date',
        'notes', 'client_design_notes', 'client_design_ref_file',
        'client_design_preview_file', 'client_design_3d_snapshot_file',
        'po_reference', 'color',
        // New columns from migration
        'garment_type', 'collar_type', 'sleeve_type', 'pocket_type',
        'studio_config',
        'ai_recommendation_status',
        'ai_recommendation_requested_at',
        'ai_recommendation_accepted_at',
        'qc_required', 'qc_passed_at',
        // Proposal flow (2026_10_17_000002)
        'promised_date', 'proposal_note', 'proposal_status', 'fabric_type',
        'delivery_address', 'down_payment_received_at',
    ];

    // Fields only a manager may set; a customer request carrying any of them is rejected.
    public const MANAGER_ONLY_FIELDS = [
        'promised_date', 'total', 'agreed_total', 'status', 'down_payment_amount',
        'down_payment_received_at', 'proposal_status', 'proposal_note',
    ];

    protected $casts = [
        'studio_config' => 'array',
        'deadline_negotiated' => 'boolean',
        'qc_required' => 'boolean',
        'down_payment_amount' => 'decimal:2',
        'discount_rate' => 'decimal:2',
        'agreed_total' => 'decimal:2',
        'ai_recommendation_requested_at' => 'datetime',
        'ai_recommendation_accepted_at' => 'datetime',
        'qc_passed_at' => 'datetime',
        'promised_date' => 'date',
        'down_payment_received_at' => 'datetime',
    ];

    // ── Proposal rules (shared by OrderController and ProductionController; $order is a DB row) ──
    // Every order: the promised date must be at least min_promised_days after confirmation (today when checked); no rush orders.
    public static function promisedDateError(?string $promisedDate): ?string
    {
        $days = (int) config('vfrb.min_promised_days', 7);
        $min = now()->startOfDay()->addDays($days);
        if (! $promisedDate) {
            return 'Set a promised date before the order is confirmed (send the customer a proposal first).';
        }

        return Carbon::parse($promisedDate)->lt($min)
            ? "The promised date must be at least {$days} days after confirmation (earliest: {$min->toDateString()})."
            : null;
    }

    // Suggested promised date: lead days per 100 pieces (config, admin may override), never earlier than min_promised_days.
    public static function suggestedPromisedDate(int $quantity, ?int $leadDaysPer100 = null): Carbon
    {
        $lead = $leadDaysPer100 ?? (int) config('vfrb.lead_days_per_100', 14);
        $days = max((int) ceil(max($quantity, 1) / 100) * $lead, (int) config('vfrb.min_promised_days', 7));

        return now()->startOfDay()->addDays($days);
    }

    // ── Relationships ─────────────────────────────────────────────────────────
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function design()
    {
        return $this->belongsTo(Design::class, 'design_id', 'design_id');
    }

    public function recommendations()
    {
        return $this->hasMany(MaterialRecommendation::class, 'order_id', 'order_id');
    }

    public function messages()
    {
        return $this->hasMany(OrderMessage::class, 'order_id', 'order_id');
    }

    public function production()
    {
        return $this->hasMany(OrderProductionTracking::class, 'order_id', 'order_id');
    }

    public function delivery()
    {
        return $this->hasOne(DeliveryTracking::class, 'order_id', 'order_id');
    }

    public function transaction()
    {
        return $this->hasOne(SalesTransaction::class, 'order_id', 'order_id');
    }

    public function qcChecklists()
    {
        return $this->hasMany(QCChecklist::class, 'order_id', 'order_id');
    }

    public function outputLogs()
    {
        return $this->hasMany(DailyOutputLog::class, 'order_id', 'order_id');
    }

    // ── Design facts the material matcher reads ───────────────────────────────
    // Order columns first, then the Design Studio snapshot, so studio-only orders still match applies_to.
    public function garmentName(): ?string
    {
        $studio = is_array($this->studio_config) ? $this->studio_config : [];
        $g = $this->garment_type ?: ($studio['garment'] ?? $studio['garmentType'] ?? null);

        return is_string($g) && $g !== '' ? $g : null;
    }

    // Design Studio zone colours (plus each woven pattern's second colour as "<zone>_pattern"), else the order's colour.
    public function designColours(): array
    {
        $studio = is_array($this->studio_config) ? $this->studio_config : [];
        $colours = collect($studio['colors'] ?? [])->filter(fn ($c) => is_string($c) && trim($c) !== '')->all();
        foreach ((array) ($studio['patternParams'] ?? []) as $zone => $params) {
            $c2 = is_array($params) ? ($params['c2'] ?? null) : null;
            if (is_string($zone) && is_string($c2) && trim($c2) !== '' && ! empty($studio['patterns'][$zone])) {
                $colours["{$zone}_pattern"] = $c2;
            }
        }

        return $colours ?: ['all' => $this->color ?? 'any'];
    }

    // ── Scopes ────────────────────────────────────────────────────────────────
    public function scopeForCustomer($q, $userId)
    {
        return $q->where('user_id', $userId);
    }

    public function scopeActive($q)
    {
        return $q->whereNotIn('status', ['completed', 'cancelled']);
    }

    public function scopeInProduction($q)
    {
        return $q->whereIn('status', ['pattern', 'segregation', 'cutting', 'sewing', 'qc', 'pressing', 'packing']);
    }
}
