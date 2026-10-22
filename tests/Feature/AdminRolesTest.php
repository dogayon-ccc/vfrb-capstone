<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Owner is read-only; the manager manages accounts; staff is retired. LOCAL database only (rolled back per test).
//   DB_CONNECTION=mysql DB_DATABASE=vfrb_test php artisan test --filter AdminRolesTest
class AdminRolesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertNotSame('pgsql', DB::connection()->getDriverName(), 'Point DB_* at the local vfrb_test database.');
    }

    private function makeUser(string $role): User
    {
        $user = User::create(['name' => ucfirst($role).' Test', 'email' => uniqid($role).'@example.test', 'password' => bcrypt('secret-pass')]);
        $user->forceFill(['email_verified_at' => now()])->save();
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($role);

        return $user;
    }

    public function test_owner_cannot_write_in_manager_areas(): void
    {
        $this->actingAs($this->makeUser('owner'), 'sanctum');
        $this->getJson('/api/admin/users')->assertForbidden();
        $this->postJson('/api/admin/suppliers', [])->assertForbidden();
    }

    public function test_manager_manages_admin_accounts_and_owner_cannot(): void
    {
        $other = $this->makeUser('manager');
        $this->actingAs($this->makeUser('owner'), 'sanctum');
        $this->patchJson("/api/admin/users/{$other->user_id}/toggle")->assertForbidden();

        $this->actingAs($this->makeUser('manager'), 'sanctum');
        $this->patchJson("/api/admin/users/{$other->user_id}/toggle")->assertOk()->assertJson(['is_active' => false]);
    }

    public function test_staff_role_is_rejected_for_new_accounts(): void
    {
        $this->actingAs($this->makeUser('manager'), 'sanctum');
        $this->postJson('/api/admin/users', ['name' => 'X', 'role' => 'staff'])->assertStatus(422)->assertJsonValidationErrors('role');
    }
}
