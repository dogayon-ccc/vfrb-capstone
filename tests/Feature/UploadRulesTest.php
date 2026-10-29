<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// GET /api/customer/upload-rules is what the Studio and Order Wizard pickers read, so it must match the validator.
class UploadRulesTest extends TestCase
{
    use DatabaseTransactions;

    public function test_customer_gets_reference_types_and_5_mb(): void
    {
        $user = User::create(['name' => 'Rules Test', 'email' => uniqid('rules').'@example.test', 'password' => bcrypt('secret-pass')]);
        $user->forceFill(['email_verified_at' => now()])->save();
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        $user->assignRole('customer');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/customer/upload-rules')
            ->assertOk()
            ->assertExactJson(['reference' => ['mimes' => ['jpg', 'jpeg', 'png', 'pdf'], 'max_kb' => 5120]]);
    }

    public function test_unauthenticated_request_is_refused(): void
    {
        $this->getJson('/api/customer/upload-rules')->assertStatus(401);
    }
}
