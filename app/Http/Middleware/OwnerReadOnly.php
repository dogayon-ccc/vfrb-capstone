<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

// Owner is a read-only admin role: GET/HEAD on the areas below, 403 on everything else.
// Applies to the owner role only; every other role passes straight through.
class OwnerReadOnly
{
    // First path segment after /api/admin/.
    public const AREAS = ['dashboard', 'orders', 'reports', 'transactions', 'invoices', 'inventory', 'materials', 'activity-log', 'audit-logs'];

    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();
        if (!$user) {
            return $next($request);
        }

        // Cached per request by RoleMiddleware, which runs first.
        if (RoleMiddleware::roleOf($request) !== 'owner') {
            return $next($request);
        }

        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            return response()->json(['message' => 'Forbidden. The owner account is read-only.'], 403);
        }
        if (!in_array($request->segment(3), self::AREAS, true)) {
            return response()->json(['message' => 'Forbidden. This area is not available to the owner account.'], 403);
        }

        return $next($request);
    }
}
