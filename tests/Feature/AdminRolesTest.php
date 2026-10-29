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

    public function test_customer_summary_counts_every_type_and_untyped_up_to_the_total(): void
    {
        foreach (['government', 'organization', null] as $type) {
            $this->makeUser('customer')->forceFill(['client_type' => $type])->save();
        }
        $this->actingAs($this->makeUser('manager'), 'sanctum');
        $summary = $this->getJson('/api/admin/customers')->assertOk()->json('summary');

        $this->assertSame(['total', 'individual', 'corporate', 'school', 'medical', 'government', 'organization', 'untyped'], array_keys($summary));
        $this->assertSame($summary['total'], array_sum($summary) - $summary['total']);
        $this->assertGreaterThanOrEqual(1, $summary['untyped']);
    }

    public function test_manager_manages_admin_accounts_and_owner_cannot(): void
    {
        $other = $this->makeUser('manager');
        $this->actingAs($this->makeUser('owner'), 'sanctum');
        $this->patchJson("/api/admin/users/{$other->user_id}/toggle")->assertForbidden();

        $this->actingAs($this->makeUser('manager'), 'sanctum');
        $this->patchJson("/api/admin/users/{$other->user_id}/toggle")->assertOk()->assertJson(['is_active' => false]);
    }

    public function test_staff_accounts_need_an_assigned_stage_and_others_must_not_have_one(): void
    {
        $this->actingAs($this->makeUser('manager'), 'sanctum');
        $this->postJson('/api/admin/users', ['name' => 'X', 'role' => 'staff'])->assertStatus(422)
            ->assertJsonValidationErrors('assigned_stage')->assertJsonMissingValidationErrors('role');
        $this->postJson('/api/admin/users', ['name' => 'X', 'role' => 'staff', 'assigned_stage' => 'ironing'])->assertJsonValidationErrors('assigned_stage');
        $this->postJson('/api/admin/users', ['name' => 'X', 'role' => 'manager', 'assigned_stage' => 'sewing'])->assertJsonValidationErrors('assigned_stage');

        $station = $this->makeUser('manager');
        $this->putJson("/api/admin/users/{$station->user_id}", ['role' => 'staff', 'assigned_stage' => 'cutting'])->assertOk()->assertJsonPath('role', 'staff');
        $this->assertSame('cutting', DB::table('users')->where('user_id', $station->user_id)->value('assigned_stage'));
    }
}
