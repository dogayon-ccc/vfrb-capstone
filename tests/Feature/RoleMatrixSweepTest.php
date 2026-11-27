<?php

namespace Tests\Feature;

use App\Http\Middleware\OwnerReadOnly;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Role matrix over every /api/admin route, so a route added later cannot silently skip its guard:
//   customer -> refused everywhere; owner -> refused on every write and outside OwnerReadOnly::AREAS;
//   station staff -> refused on every route the manager group owns (role:manager,owner).
// Requires the MySQL schema loaded from vfrb_db.sql, like the other feature tests (rolled back per test).
//   php artisan test --filter RoleMatrixSweepTest
class RoleMatrixSweepTest extends TestCase
{
    use DatabaseTransactions;

    private const PUBLIC = ['POST api/admin/login'];

    private function makeUser(string $role, array $extra = []): User
    {
        $user = User::create(['name' => ucfirst($role) . ' Sweep', 'email' => uniqid($role) . '@example.test', 'password' => bcrypt('secret-pass')] + $extra);
        $user->forceFill(['email_verified_at' => now()])->save();
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($role);

        return $user;
    }

    /** @return list<array{0: string, 1: string, 2: LaravelRoute}> */
    private function adminRoutes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (!str_starts_with($uri, 'api/admin/')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true) || in_array("$method $uri", self::PUBLIC, true)) {
                    continue;
                }
                $out[] = [$method, '/' . preg_replace('/\{[^}]+\}/', '1', $uri), $route];
            }
        }

        return $out;
    }

    private function statusFor(string $method, string $url): int
    {
        return $this->json($method, $url)->getStatusCode();
    }

    public function test_a_customer_is_refused_on_every_admin_route(): void
    {
        Sanctum::actingAs($this->makeUser('customer'), ['*']);
        $offenders = [];
        $routes = $this->adminRoutes();

        foreach ($routes as [$method, $url]) {
            if (($s = $this->statusFor($method, $url)) !== 403) {
                $offenders[] = "$method $url -> $s";
            }
        }

        $this->assertGreaterThan(100, count($routes));
        $this->assertSame([], $offenders, "A customer reached an admin route:\n" . implode("\n", $offenders));
    }

    public function test_the_owner_is_refused_on_every_write_and_outside_the_read_areas(): void
    {
        Sanctum::actingAs($this->makeUser('owner'), ['*']);
        $offenders = [];

        foreach ($this->adminRoutes() as [$method, $url]) {
            $area = explode('/', trim($url, '/'))[2] ?? '';
            $mustRefuse = $method !== 'GET' || !in_array($area, OwnerReadOnly::AREAS, true);
            if ($mustRefuse && ($s = $this->statusFor($method, $url)) !== 403) {
                $offenders[] = "$method $url -> $s";
            }
        }

        $this->assertSame([], $offenders, "The read-only owner got past a guard:\n" . implode("\n", $offenders));
    }

    public function test_station_staff_are_refused_on_every_manager_only_route(): void
    {
        Sanctum::actingAs($this->makeUser('staff', ['assigned_stage' => 'sewing']), ['*']);
        $offenders = [];
        $checked = 0;

        foreach ($this->adminRoutes() as [$method, $url, $route]) {
            $managerOnly = collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'role:') && !str_contains($m, 'staff'));
            if (!$managerOnly) {
                continue;
            }
            $checked++;
            if (($s = $this->statusFor($method, $url)) !== 403) {
                $offenders[] = "$method $url -> $s";
            }
        }

        $this->assertGreaterThan(50, $checked);
        $this->assertSame([], $offenders, "Station staff reached a manager-only route:\n" . implode("\n", $offenders));
    }
}
