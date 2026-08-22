<?php
// app/Models/CompanySetting.php
// Single-row company branding/contact config. Always id=1 — enforced
// in SettingsController, not a DB constraint (matches repo convention
// of app-level guards, e.g. suppliers.is_active soft-toggle pattern).
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanySetting extends Model
{
    protected $table = 'company_settings';
    public $incrementing = true; // still a real PK column, just always 1
    protected $keyType = 'int';

    protected $fillable = [
        'company_name', 'logo_path', 'address', 'contact_number',
        'contact_email', 'updated_by',
    ];

    public function updater() { return $this->belongsTo(User::class, 'updated_by', 'user_id'); }
}
