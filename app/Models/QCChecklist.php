<?php
// app/Models/QCChecklist.php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class QCChecklist extends Model
{
    protected $primaryKey = 'check_id';

    protected $fillable = [
        'order_id','checked_by',
        'chest_cm','length_cm','sleeve_cm','waist_cm','shoulder_cm',
        'stitching_ok','color_ok','size_ok','label_ok','finish_ok','button_ok',
        'passed','notes','items_checked','items_passed','items_failed',
        'checked_at','created_at','updated_at',
    ];

    protected $casts = [
        'passed'       => 'boolean',
        'stitching_ok' => 'boolean',
        'color_ok'     => 'boolean',
        'size_ok'      => 'boolean',
        'label_ok'     => 'boolean',
        'finish_ok'    => 'boolean',
        'button_ok'    => 'boolean',
        'checked_at'   => 'datetime',
        'chest_cm'     => 'decimal:2',
        'length_cm'    => 'decimal:2',
        'sleeve_cm'    => 'decimal:2',
        'waist_cm'     => 'decimal:2',
        'shoulder_cm'  => 'decimal:2',
    ];

    public function order()   { return $this->belongsTo(Order::class, 'order_id', 'order_id'); }
    public function checker() { return $this->belongsTo(User::class, 'checked_by', 'user_id'); }

    // Pass rate as percentage
    public function getPassRateAttribute(): float
    {
        if (!$this->items_checked) return 0;
        return round(($this->items_passed / $this->items_checked) * 100, 1);
    }

    // All boolean checks
    public function getChecklistItemsAttribute(): array
    {
        return [
            'Stitching'   => $this->stitching_ok,
            'Color'       => $this->color_ok,
            'Size'        => $this->size_ok,
            'Labels'      => $this->label_ok,
            'Finish'      => $this->finish_ok,
            'Buttons/Zip' => $this->button_ok,
        ];
    }

    public function scopeForOrder($q, $id) { return $q->where('order_id', $id); }
    public function scopePassed($q)        { return $q->where('passed', 1); }
    public function scopeFailed($q)        { return $q->where('passed', 0); }
}
