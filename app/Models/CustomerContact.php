<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class CustomerContact extends Model
{
    protected $table = 'customer_contacts';

    protected $primaryKey = 'contact_id';

    protected $fillable = [
        'name', 'email', 'mobile', 'address', 'organization_name', 'created_by',
    ];

    protected $casts = ['linked_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    // Links unlinked contacts only if $userId is a verified customer holding $email; returns rows linked.
    public static function linkVerifiedUser(int $userId, ?string $email): int
    {
        $email = strtolower(trim((string) $email));
        if ($email === '' || static::verifiedCustomerIdFor($email) !== $userId) {
            return 0;
        }

        return static::whereNull('user_id')->where('email', $email)
            ->update(['user_id' => $userId, 'linked_at' => now(), 'updated_at' => now()]);
    }

    // Verified customer account holding this email, if any; never a staff/manager/owner account.
    public static function verifiedCustomerIdFor(string $email): ?int
    {
        $id = DB::table('users')
            ->join('model_has_roles as mr', function ($join) {
                $join->on('mr.model_id', '=', 'users.user_id')->where('mr.model_type', 'App\\Models\\User');
            })
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->where('r.name', 'customer')
            ->whereNotNull('users.email_verified_at')
            ->whereRaw('LOWER(users.email) = ?', [strtolower(trim($email))])
            ->value('users.user_id');

        return $id === null ? null : (int) $id;
    }
}
