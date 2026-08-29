<?php
// app/Http/Middleware/JobFunctionMiddleware.php
// Server-side enforcement for staff sub-roles (job_function), on top of the
// existing role:staff|manager check. Register alongside 'role' in
// bootstrap/app.php, e.g.:
//   Route::middleware(['auth:sanctum','role:staff,manager','jobfn:inventory'])
// Managers always pass (see User::hasJobAccess). Staff with job_function =
// 'general' also always pass — this is additive, not a narrowing of
// anyone's access unless a manager explicitly assigns a specific function.

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class JobFunctionMiddleware
{
    public function handle(Request $request, Closure $next, string $area): mixed
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (!$user->hasJobAccess($area)) {
            return response()->json([
                'message' => 'Forbidden. This area is outside your assigned job function.',
                'required_area' => $area,
                'your_job_function' => $user->job_function ?? 'general',
            ], 403);
        }

        return $next($request);
    }
}
