<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ProductionStageService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Station, Administrator and Owner accounts may have no email; customers always need one. LOCAL database only.
//   DB_CONNECTION=mysql DB_DATABASE=vfrb_test php artisan test --filter OptionalStaffEmailTest
class OptionalStaffEmailTest extends TestCase
{
    use DatabaseTransactions;

    private const PASS = 'Station#2026x';

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

    private function create(array $fields)
    {
        return $this->postJson('/api/admin/users', $fields + ['name' => 'No Email', 'password' => self::PASS, 'password_confirmation' => self::PASS]);
    }

    public function test_manager_creates_station_owner_and_admin_with_username_only_and_they_sign_in(): void
    {
        $this->actingAs($this->makeUser('manager'), 'sanctum');
        $stage = ProductionStageService::productionStages()[0];
        $cases = [['staff', ['assigned_stage' => $stage]], ['owner', []], ['manager', []]];

        foreach ($cases as [$role, $extra]) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
            $username = uniqid($role.'_');
            $this->create(['role' => $role, 'username' => strtoupper($username)] + $extra)
                ->assertCreated()->assertJson(['email' => null, 'username' => $username, 'role' => $role]);
        }
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/login', ['login' => $username, 'password' => self::PASS])->assertOk();
    }

    public function test_staff_account_needs_an_email_or_a_username(): void
    {
        $this->actingAs($this->makeUser('manager'), 'sanctum');

        $this->create(['role' => 'owner', 'email' => '', 'username' => ''])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_customer_still_requires_an_email(): void
    {
        $this->actingAs($this->makeUser('manager'), 'sanctum');

        $this->create(['role' => 'customer', 'username' => uniqid('cust_'), 'organization_name' => 'ZZ Org', 'client_type' => 'school'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_owner_cannot_create_accounts(): void
    {
        $this->actingAs($this->makeUser('owner'), 'sanctum');

        $this->create(['role' => 'staff', 'username' => uniqid('st_')])->assertForbidden();
    }

    public function test_edit_can_add_or_remove_email_but_never_both_login_fields(): void
    {
        $this->actingAs($this->makeUser('manager'), 'sanctum');
        $target = $this->makeUser('owner');
        $url = "/api/admin/users/{$target->user_id}";

        $this->putJson($url, ['role' => 'owner', 'email' => ''])->assertUnprocessable()->assertJsonValidationErrors('_login_check');
        $this->putJson($url, ['role' => 'owner', 'email' => '', 'username' => 'zz_owner_edit'])->assertOk()->assertJson(['email' => null, 'username' => 'zz_owner_edit']);
        $this->putJson($url, ['role' => 'owner'])->assertOk()->assertJson(['username' => 'zz_owner_edit']);
    }

    public function test_manager_sets_password_and_signs_the_account_out(): void
    {
        $manager = $this->makeUser('manager');
        $target = $this->makeUser('staff');
        $target->createToken('old');
        $this->actingAs($manager, 'sanctum');

        $this->putJson("/api/admin/users/{$target->user_id}/password", ['password' => self::PASS, 'password_confirmation' => self::PASS])->assertOk();
        $this->assertSame(0, $target->tokens()->count());
        $this->assertTrue(password_verify(self::PASS, $target->fresh()->password));

        $customer = $this->makeUser('customer');
        $this->putJson("/api/admin/users/{$customer->user_id}/password", ['password' => self::PASS, 'password_confirmation' => self::PASS])->assertUnprocessable();

        $this->actingAs($this->makeUser('owner'), 'sanctum');
        $this->putJson("/api/admin/users/{$target->user_id}/password", ['password' => self::PASS, 'password_confirmation' => self::PASS])->assertForbidden();
    }
}
