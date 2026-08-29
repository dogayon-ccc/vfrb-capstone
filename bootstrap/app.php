<?php
// bootstrap/app.php — VFRB Enterprise
// ═══════════════════════════════════════════════════════════════════════════
// SOURCE OF TRUTH: This is the complete bootstrap/app.php for VFRB Enterprise.
// Generated from the actual zip source (June 18, 2026) — not a template.
//
// All middleware already registered and verified against the actual zip:
//   - SecurityHeaders   → app/Http/Middleware/SecurityHeaders.php  ✅
//   - SanitizeInput     → app/Http/Middleware/SanitizeInput.php    ✅
//   - RoleMiddleware    → app/Http/Middleware/RoleMiddleware.php    ✅
//   - statefulApi()     → DISABLED (causes 419 with Sanctum token auth)
//
// Task EE additions:
//   - trustProxies enabled for Railway.app load balancer
//   - ValidationException → 422 JSON (explicit for Railway production env)
//   - ModelNotFoundException → 404 JSON (explicit for Railway production env)
//
// BUG FIX (Aug 17 2026): redirectGuestsTo(fn () => null) added — see comment
// below. Fixes "Route [login] not defined" 500 crash on unauthenticated
// requests that don't send Accept: application/json.
//
// DEPLOY: replace C:/laragon/www/vfrb-capstone/bootstrap/app.php
// ═══════════════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web:      __DIR__.'/../routes/web.php',
        api:      __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health:   '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {

        // ── Security headers on ALL responses ────────────────────────────────
        // Adds: X-Content-Type-Options, X-Frame-Options, X-XSS-Protection: 0,
        //       Referrer-Policy, Permissions-Policy, Content-Security-Policy
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // ── Input sanitization on API group only ─────────────────────────────
        $middleware->appendToGroup('api', [
            \App\Http\Middleware\SanitizeInput::class,
        ]);

        // ── Trust proxies — REQUIRED for Railway.app ─────────────────────────
        // Railway sits behind a load balancer. Without trustProxies:
        //   - HTTPS detection breaks (HTTP_X_FORWARDED_PROTO not trusted)
        //   - IP-based rate limiting uses load balancer IP (not client IP)
        //   - APP_URL generation produces http:// instead of https://
        // '*' trusts all proxies — safe because Railway controls the network.
        $middleware->trustProxies(at: '*');

        // ── statefulApi() DISABLED ────────────────────────────────────────────
        // DO NOT uncomment. Causes 419 CSRF mismatch with Sanctum token auth.
        // We use Bearer tokens exclusively — stateful cookies not needed.
        // $middleware->statefulApi();

        // ── Middleware aliases ────────────────────────────────────────────────
        $middleware->alias([
            // Rate limiting (custom — wraps Laravel throttle with JSON responses)
            'auth.throttle' => \App\Http\Middleware\ApiThrottle::class,

            // Role gate — reads from model_has_roles (Spatie RBAC) via direct
            // DB query to bypass guard_name='web' vs Sanctum 'api' mismatch.
            // Usage: ->middleware('role:manager') or ->middleware('role:staff,manager')
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'jobfn' => \App\Http\Middleware\JobFunctionMiddleware::class,
        ]);

        // ── Never redirect unauthenticated guests to a 'login' route ──────────
        // BUG FIX: Laravel's ApplicationBuilder registers a DEFAULT
        // Authenticate::redirectUsing() callback that calls route('login')
        // whenever $request->expectsJson() is false (e.g. a request that
        // didn't send Accept: application/json). This app has no named
        // 'login' route — it's API-only, React handles all UI — so that
        // default callback threw RouteNotFoundException and crashed with a
        // raw 500 BEFORE the AuthenticationException JSON handler below ever
        // ran (the crash happened while building the exception, not after
        // it was thrown). Returning null here means guests are never given
        // a redirect target; AuthenticationException is thrown with
        // redirectTo=null instead, and the $exceptions->render() handler in
        // withExceptions() below cleanly returns the 401 JSON as intended.
        $middleware->redirectGuestsTo(fn () => null);
    })

    ->withExceptions(function (Exceptions $exceptions) {

        // ── Unauthenticated → 401 JSON (not redirect to /login) ──────────────
        // Default Laravel behavior redirects unauthenticated API requests to
        // /login (HTML page). This overrides that for our React SPA.
        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });

        // ── Global JSON error handler for all API routes ──────────────────────
        // Without this, 404s and 500s on /api/* return HTML (Vite error overlay,
        // or Railway's nginx 502 page) which React JSON.parse() crashes on.
        // In production (Railway): 500 messages are sanitized to prevent info leak.
        $exceptions->render(function (\Throwable $e, $request) {

            // AuthenticationException already handled above — skip
            if ($e instanceof AuthenticationException) {
                return null; // let the handler above take it
            }

            if ($request->is('api/*') || $request->wantsJson()) {

                $status = method_exists($e, 'getStatusCode')
                    ? $e->getStatusCode()
                    : 500;

                // Sanitize 500 messages in production — never leak stack traces
                $message = (app()->environment('production') && $status >= 500)
                    ? 'An internal server error occurred. Please try again.'
                    : $e->getMessage();

                // Validation errors get their errors bag
                if ($e instanceof \Illuminate\Validation\ValidationException) {
                    return response()->json([
                        'message' => 'Validation failed.',
                        'errors'  => $e->errors(),
                    ], 422);
                }

                // Model not found → clean 404
                if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                    return response()->json([
                        'message' => 'Resource not found.',
                    ], 404);
                }

                return response()->json([
                    'message' => $message,
                    'status'  => $status,
                ], $status);
            }
        });

    })
    ->create();