<?php
// app/Http/Middleware/RoleMiddleware.php
// FIXES v10:
//   supplier_id fallback removed — column gone from users table
//   Type hint added: string ...$roles (fixes P1132 Intelephense warning)
//   Return type added: mixed

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): mixed
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Direct DB query — bypasses Spatie guard_name='web' vs Sanctum guard='api' mismatch.
        // getRoleNames() returns empty because Spatie uses guard_name='web' while Sanctum uses 'api'.
        // This is the correct fix: skip Spatie's in-memory lookup, query the junction table directly.
        $userRole = DB::table('model_has_roles as mr')
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->where('mr.model_type', 'App\\Models\\User')
            ->where('mr.model_id', $user->user_id)
            ->value('r.name');

        // FIX: was '$user->supplier_id ?: customer' — supplier_id column removed in DB v4
        // DB role name is 'customer' (roles.id=3) — do not rename to 'client' here. UI/routes
        // say "client"; the Spatie role identifier does not, and every route guard checks
        // against this exact string via model_has_roles above.
        if (!$userRole) {
            $userRole = 'customer';
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
}