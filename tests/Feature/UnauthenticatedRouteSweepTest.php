<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

// Every /api route is either on the explicit public allowlist below or must refuse an
// anonymous request with 401. New routes are protected by default; a new public route
// has to be added to PUBLIC deliberately, which a reviewer will see.
//   php artisan test --filter UnauthenticatedRouteSweepTest
class UnauthenticatedRouteSweepTest extends TestCase
{
    private const PUBLIC = [
        'POST api/login',
        'POST api/admin/login',
        'POST api/request-access',
        'POST api/password/forgot',
        'POST api/password/reset',
        'GET api/version', // health check: commit short-sha + DB reachability only (pinned below)
        'GET api/email/verify/{id}/{hash}',
        'GET api/cron/run',
        'POST api/cron/run',
    ];

    public function test_every_non_public_api_route_refuses_an_anonymous_request(): void
    {
        $offenders = [];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (!str_starts_with($uri, 'api/')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true) || in_array("$method $uri", self::PUBLIC, true)) {
                    continue;
                }
                $checked++;
                $status = $this->json($method, '/' . preg_replace('/\{[^}]+\}/', '1', $uri))->getStatusCode();
                if ($status !== 401) {
                    $offenders[] = "$method /$uri -> $status";
                }
            }
        }

        $this->assertGreaterThan(150, $checked, 'the sweep found suspiciously few routes');
        $this->assertSame([], $offenders, "Anonymous requests were not refused with 401:\n" . implode("\n", $offenders));
    }

    public function test_public_version_endpoint_exposes_no_runtime_versions_outside_debug(): void
    {
        config(['app.debug' => false]);

        $payload = $this->getJson('/api/version')->assertOk()->json();

        $this->assertArrayNotHasKey('php_version', $payload);
        $this->assertArrayNotHasKey('laravel_version', $payload);
        $this->assertSame(['app', 'commit', 'deploy_id', 'database', 'checked_at_utc'], array_keys($payload));
    }
}
