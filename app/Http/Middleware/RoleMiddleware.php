<?php
// app/Http/Middleware/RoleMiddleware.php
// FIXES v10:
//   supplier_id fallback removed — column gone from users table
//   Type hint added: string ...$roles (fixes P1132 Intelephense warning)
//   Return type added: mixed

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): mixed
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $userRole = self::roleOf($request);

        // FIX: was '$user->supplier_id ?: customer' — supplier_id column removed in DB v4
        // DB role name is 'customer' (roles.id=3) — do not rename to 'client' here. UI/routes
        // say "client"; the Spatie role identifier does not, and every route guard checks
        // against this exact string via model_has_roles above.
        if (!$userRole) {
            $userRole = 'customer';
        }

        // The staff role is retired: its existing tokens are refused everywhere.
        if ($userRole === 'staff') {
            return response()->json(['message' => 'Staff accounts have been retired. Ask an Administrator for access.'], 403);
        }

        // Admin accounts are auto-verified on creation, so a null email_verified_at
        // means they were deactivated (UserController::adminToggle).
        if (in_array($userRole, ['manager', 'owner'], true) && is_null($user->email_verified_at)) {
            return response()->json(['message' => 'This account has been deactivated.'], 403);
        }

        // Clients confirm their email before using client features (/api/user, resend and logout stay open).
        if ($userRole === 'customer' && is_null($user->email_verified_at)) {
            return response()->json([
                'message' => 'Please verify your email address to continue.',
                'code'    => 'email_unverified',
            ], 403);
        }

        foreach ($roles as $roleGroup) {
            $allowed = explode('|', $roleGroup);
            if (in_array($userRole, $allowed)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'Forbidden. Insufficient permissions.',
            'required_role' => implode(' or ', $roles),
            'your_role'     => $userRole,
        ], 403);
    }

    // The user's DB role, looked up once per request (each DB round trip is ~130 ms on Supabase).
    public static function roleOf(Request $request): ?string
    {
        if ($request->attributes->has('vfrb_role')) {
            return $request->attributes->get('vfrb_role');
        }
        $role = self::roleFor($request->user()->user_id);
        $request->attributes->set('vfrb_role', $role);

        return $role;
    }

    // Cached for 10 minutes; UserController calls forgetRole() whenever it writes a role.
    // Direct query: Spatie's guard_name='web' doesn't match Sanctum's 'api', so getRoleNames() comes back empty.
    public static function roleFor(int $userId): ?string
    {
        return Cache::remember(self::roleKey($userId), 600, fn () => DB::table('model_has_roles as mr')
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->where('mr.model_type', 'App\\Models\\User')
            ->where('mr.model_id', $userId)
            ->value('r.name'));
    }

    public static function forgetRole(int $userId): void
    {
        Cache::forget(self::roleKey($userId));
    }

    // Keyed by database too: the local QA stack shares this file cache with the Supabase setup, and user ids overlap.
    private static function roleKey(int $userId): string
    {
        return 'vfrb_role:' . DB::connection()->getDatabaseName() . ":{$userId}";
    }
}