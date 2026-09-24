<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Regression coverage for the Sept 21 role-guard bug: a migration zip changed
// routes/api.php's customer guard to role:client while the real DB role
// (roles.name) stayed 'customer' and RoleMiddleware's fallback also drifted
// to 'client' — every normally-provisioned customer got 403'd. Fixed by
// reverting both to 'customer'; this test is what should have caught it.
class CustomerRoleGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_role_is_authorized_on_customer_routes(): void
    {
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('customer');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/customer/designs')
            ->assertStatus(200);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/customer/designs')->assertStatus(401);
    }

    public function test_unrelated_role_is_rejected_on_customer_routes(): void
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('manager');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/customer/designs')
            ->assertStatus(403);
    }

    public function test_roleless_user_falls_back_to_customer_not_client(): void
    {
        // No assignRole() call — exercises RoleMiddleware's fallback path.
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/customer/designs')
            ->assertStatus(200);
    }
}
