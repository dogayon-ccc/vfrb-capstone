<?php
// app/Models/NotificationPreference.php
// One row per user. sms_enabled has no real provider behind it yet —
// see migration note. Do not treat sms_enabled=true as "SMS works."
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    protected $table = 'notification_preferences';
    protected $primaryKey = 'user_id';
    public $incrementing = false;

    protected $fillable = ['user_id', 'email_enabled', 'sms_enabled'];

    protected $casts = [
        'email_enabled' => 'boolean',
        'sms_enabled'   => 'boolean',
    ];

    public function user() { return $this->belongsTo(User::class, 'user_id', 'user_id'); }
}
