<?php
// app/Models/DailyOutputLog.php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class DailyOutputLog extends Model
{
    protected $primaryKey = 'log_id';

    protected $fillable = [
        'order_id','stage','logged_by',
        'qty_xs','qty_s','qty_m','qty_l','qty_xl','qty_xxl','qty_xxxl','qty_custom',
        'defect_count','alteration_count','defect_notes',
        'log_date','notes','created_at','updated_at',
    ];

    protected $casts = [
        'log_date'       => 'date',
        'total_output'   => 'integer', // GENERATED ALWAYS — read-only
    ];

    // GENERATED columns — never set manually
    protected $guarded_generated = ['total_output'];

    public function order()  { return $this->belongsTo(Order::class, 'order_id', 'order_id'); }
    public function logger() { return $this->belongsTo(User::class, 'logged_by', 'user_id'); }

    // All size columns as array
    public function getSizesAttribute(): array
    {
        return [
            'XS'     => $this->qty_xs,
            'S'      => $this->qty_s,
            'M'      => $this->qty_m,
            'L'      => $this->qty_l,
            'XL'     => $this->qty_xl,
            'XXL'    => $this->qty_xxl,
            'XXXL'   => $this->qty_xxxl,
            'Custom' => $this->qty_custom,
        ];
    }

    public function scopeForOrder($q, $id)  { return $q->where('order_id', $id); }
    public function scopeForDate($q, $date) { return $q->whereDate('log_date', $date); }
    public function scopeForStage($q, $s)   { return $q->where('stage', $s); }
}
