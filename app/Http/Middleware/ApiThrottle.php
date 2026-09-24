<?php
// app/Http/Middleware/ApiThrottle.php
// Custom rate limiter per route group.
// Protects against abuse, brute force, and DDoS patterns.

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiThrottle
{
    public function __construct(private RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next, string $key = 'api', int $maxAttempts = 60): Response
    {
        /**
         * Build a unique rate key:
         * - Authenticated users → user ID
         * - Guests → IP address
         */
        $identifier = $request->user()?->user_id ?? $request->ip();

        // Final rate key (prevents collisions between route groups)
        $rateKey = "{$key}:{$identifier}";

        // 🚫 Too many attempts
        if ($this->limiter->tooManyAttempts($rateKey, $maxAttempts)) {

            $retryAfter = $this->limiter->availableIn($rateKey);

            return response()->json([
                'message'     => 'Too many requests. Please slow down.',
                'retry_after' => $retryAfter,
            ], 429)->withHeaders([
                'Retry-After'           => $retryAfter,
                'X-RateLimit-Limit'     => $maxAttempts,
                'X-RateLimit-Remaining' => 0,
            ]);
        }

        // ✅ Record this hit (60-second decay window)
        $this->limiter->hit($rateKey, 60);

        // Continue request
        $response = $next($request);

        // Remaining attempts
        $remaining = max(0, $maxAttempts - $this->limiter->attempts($rateKey));

        return $response->withHeaders([
            'X-RateLimit-Limit'     => $maxAttempts,
            'X-RateLimit-Remaining' => $remaining,
        ]);
    }
}