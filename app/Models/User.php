<?php
// app/Models/User.php
// FIXES v10:
//   supplier_id removed from $fillable — column dropped in DB v4
//   supplier() BelongsTo relationship removed — no linked user per supplier anymore

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasRoles, Notifiable;

    protected $primaryKey = 'user_id';

    // FIX: 'supplier_id' removed — column dropped in DB v4
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'avatar',
        'contact_number',
        'organization_name',
        'address',
        'client_type',
        'job_function',
        'email_verified_at',
    ];

    // area => job_functions allowed to touch it. 'general' always passes
    // (checked separately in hasJobAccess) — this map only needs the
    // restricted functions. Manager role bypasses this entirely.
    public const JOB_FUNCTION_AREAS = [
        'production' => ['production', 'general'],
        'inventory'  => ['inventory', 'general'],
        'sales'      => ['sales', 'general'],
    ];

    // Raw query: Spatie hasRole() returns false for Sanctum-authenticated users (guard mismatch).
    public function isManager(): bool
    {
        return \Illuminate\Support\Facades\DB::table('model_has_roles as mr')
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->where('mr.model_type', self::class)
            ->where('mr.model_id', $this->user_id)
            ->where('r.name', 'manager')
            ->exists();
    }

    public function hasJobAccess(string $area): bool
    {
        if ($this->isManager()) {
            return true;
        }
        if (($this->job_function ?? 'general') === 'general') {
            return true; // unset/general staff keep today's full access
        }
        return in_array($this->job_function, self::JOB_FUNCTION_AREAS[$area] ?? [], true);
    }

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password'          => 'hashed',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function orders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Order::class, 'user_id', 'user_id');
    }

    // FIX: supplier() BelongsTo removed — users no longer have supplier_id FK

    public function messages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OrderMessage::class, 'sender_id', 'user_id');
    }

    public function notifications(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Notification::class, 'user_id', 'user_id');
    }

    // ─── Custom Notifications ─────────────────────────────────────────────────

    // Points password reset link to React frontend (not Blade)
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new \App\Notifications\CustomResetPasswordNotification($token));
    }

    // Points email verification to API route → redirects to React
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new \App\Notifications\CustomVerifyEmailNotification());
    }
}