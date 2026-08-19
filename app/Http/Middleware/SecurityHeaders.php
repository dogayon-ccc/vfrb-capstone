<?php
// app/Http/Middleware/SecurityHeaders.php
// Task DD — HTTP security headers on every API response
//
// Register in bootstrap/app.php:
//   $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
//
// Headers added:
//   X-Content-Type-Options: nosniff       → stops MIME-sniffing
//   X-Frame-Options: SAMEORIGIN           → blocks clickjacking
//   X-XSS-Protection: 0                   → disable legacy IE filter (use CSP)
//   Referrer-Policy: strict-origin-...    → no full URL leakage
//   Permissions-Policy: camera=(),...     → restrict browser features
//   Content-Security-Policy (API only)    → API returns JSON not HTML

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options',  'nosniff');
        $response->headers->set('X-Frame-Options',         'SAMEORIGIN');
        $response->headers->set('X-XSS-Protection',        '0');
        $response->headers->set('Referrer-Policy',         'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy',      'camera=(), microphone=(), geolocation=()');

        // API responses are JSON — not HTML/JS — so block all content sources
        if ($request->is('api/*')) {
            $response->headers->set(
                'Content-Security-Policy',
                "default-src 'none'; frame-ancestors 'none'"
            );
        }

        return $response;
    }
}