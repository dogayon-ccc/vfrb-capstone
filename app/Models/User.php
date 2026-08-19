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
        'email_verified_at',
    ];

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