<?php
// app/Models/NotificationPreference.php
// One row per user: notification channels plus appearance (theme_mode, theme_accent).
// sms_enabled is the user's opt-in; texts only go out when SMS_ENABLED is also on (App\Support\SmsNotify).
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    public const THEME_MODES   = ['light', 'dark', 'system'];
    public const THEME_ACCENTS = ['teal', 'navy', 'royal', 'bottle', 'maroon', 'charcoal', 'red']; // mirrors ACCENT_PRESETS

    protected $table = 'notification_preferences';
    protected $primaryKey = 'user_id';
    public $incrementing = false;

    protected $fillable = ['user_id', 'email_enabled', 'sms_enabled', 'theme_mode', 'theme_accent'];

    protected $casts = [
        'email_enabled' => 'boolean',
        'sms_enabled'   => 'boolean',
    ];

    public function user() { return $this->belongsTo(User::class, 'user_id', 'user_id'); }

    // Saved appearance, or nulls (app default) when the user has no row yet.
    public static function appearanceFor(int $userId): array
    {
        $pref = self::find($userId);
        return ['theme_mode' => $pref?->theme_mode, 'theme_accent' => $pref?->theme_accent];
    }
}
