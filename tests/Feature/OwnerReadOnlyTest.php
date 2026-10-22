<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\CustomResetPasswordNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Read-only owner role. LOCAL database only (rolled back per test); the owner role and user are created inside the transaction.
//   DB_CONNECTION=mysql DB_DATABASE=vfrb_test php artisan test --filter OwnerReadOnlyTest
class OwnerReadOnlyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertNotSame('pgsql', DB::connection()->getDriverName(), 'Point DB_* at the local vfrb_test database.');
    }

    // A fresh verified account, so the seeded manager/staff accounts are never used or changed.
    private function makeUser(string $role): User
    {
        $user = User::create(['name' => ucfirst($role) . ' Test', 'email' => uniqid($role) . '@example.test', 'password' => bcrypt('secret-pass')]);
        $user->forceFill(['email_verified_at' => now()])->save();
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($role);
        return $user;
    }

    public function test_owner_can_read_the_allowed_areas_and_never_write(): void
    {
        $owner = $this->makeUser('owner');
        $this->actingAs($owner, 'sanctum');

        foreach (['dashboard', 'orders', 'reports', 'reports/sales', 'transactions', 'inventory', 'materials', 'activity-log'] as $area) {
            $this->getJson("/api/admin/{$area}")->assertStatus(200);
        }
        // Invoices only exist per id; a missing id must fail as "not found", not as a role refusal.
        $this->assertNotSame(403, $this->getJson('/api/admin/invoices/999999/document')->status());

        // Outside the allowed areas, even a read is refused.
        $this->getJson('/api/admin/users')->assertStatus(403);
        $this->getJson('/api/admin/settings/company')->assertStatus(403);
        $this->getJson('/api/admin/customers')->assertStatus(403);

        $this->postJson('/api/admin/materials', ['material_name' => 'x'])->assertStatus(403);
        $this->putJson('/api/admin/materials/1', ['material_name' => 'x'])->assertStatus(403);
        $this->patchJson('/api/admin/orders/1', ['status' => 'confirmed'])->assertStatus(403);
        $this->deleteJson('/api/admin/materials/1')->assertStatus(403);
        $this->postJson('/api/admin/users', ['role' => 'staff'])->assertStatus(403);
    }

    public function test_only_a_manager_creates_lists_and_edits_owner_accounts(): void
    {
        Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
        $manager = $this->makeUser('manager');

        // Real domain: adminCreate checks the email's DNS.
        $email = uniqid('owner') . '@gmail.com';
        $this->actingAs($manager, 'sanctum')->postJson('/api/admin/users', [
            'name' => 'Owner Account', 'email' => $email, 'role' => 'owner',
            'password' => 'Secret-pass1!', 'password_confirmation' => 'Secret-pass1!',
        ])->assertStatus(201)->assertJsonPath('role', 'owner');
        $owner = User::where('email', $email)->firstOrFail();
        $this->assertNotNull($owner->email_verified_at);

        $list = $this->getJson('/api/admin/users?per_page=500')->assertOk()->json('data');
        $this->assertSame('owner', collect($list)->firstWhere('user_id', $owner->user_id)['role'] ?? null);

        $this->putJson("/api/admin/users/{$owner->user_id}", ['role' => 'owner'])->assertOk();
        $this->patchJson("/api/admin/users/{$owner->user_id}/toggle")->assertOk()->assertJson(['is_active' => false]);
        $this->patchJson("/api/admin/users/{$owner->user_id}/toggle")->assertOk()->assertJson(['is_active' => true]);

        // Staff and the owner itself cannot edit an owner account.
        $this->actingAs($this->makeUser('staff'), 'sanctum')
            ->putJson("/api/admin/users/{$owner->user_id}", ['role' => 'manager'])->assertStatus(403);
        $this->actingAs($owner->fresh(), 'sanctum')
            ->putJson("/api/admin/users/{$owner->user_id}", ['role' => 'manager'])->assertStatus(403);
        $this->assertSame('owner', DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $owner->user_id)->value('roles.name'));
    }

    public function test_a_new_customer_is_emailed_a_password_link_and_no_hash_is_returned(): void
    {
        Notification::fake();
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        $email = uniqid('cust') . '@gmail.com';
        $this->actingAs($this->makeUser('manager'), 'sanctum')->postJson('/api/admin/users', [
            'name' => 'New Customer', 'email' => $email, 'role' => 'customer',
            'organization_name' => 'Test School', 'client_type' => 'school',
            'password' => 'Secret-pass1!', 'password_confirmation' => 'Secret-pass1!',
        ])->assertStatus(201)->assertJsonPath('password_link_sent', true)->assertJsonMissingPath('password');

        Notification::assertSentTo(User::where('email', $email)->firstOrFail(), CustomResetPasswordNotification::class);
    }
}
