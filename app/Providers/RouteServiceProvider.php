<?php
// app/Providers/RouteServiceProvider.php
// Configures named rate limiters for different endpoint categories.
// Auth endpoints get 10/min, general API gets 120/min, BOM engine gets 30/min.

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    protected function configureRateLimiting(): void
    {
        // Auth endpoints — 10 attempts per minute per IP
        // Prevents credential stuffing and brute-force attacks
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many login attempts. Please wait 1 minute.',
                    ], 429);
                });
        });

        // General API — 120 requests per minute per authenticated user or IP
        RateLimiter::for('api', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(120)->by($request->user()->user_id)
                : Limit::perMinute(60)->by($request->ip());
        });

        // BOM computation — computationally expensive, limit to 30/min
        RateLimiter::for('bom', function (Request $request) {
            return Limit::perMinute(30)->by(
                $request->user()?->user_id ?? $request->ip()
            );
        });

        // Supplier RFQ responses — prevent response flooding
        RateLimiter::for('rfq', function (Request $request) {
            return Limit::perMinute(20)->by(
                $request->user()?->user_id ?? $request->ip()
            );
        });

        RateLimiter::for('ai', function (Request $request) {
    return Limit::perMinute(10)->by(
        $request->user()?->user_id ?? $request->ip()
    )->response(function () {
        return response()->json([
            'message' => 'AI description rate limit reached. Please wait a moment.',
        ], 429);
    });
});
    }
}