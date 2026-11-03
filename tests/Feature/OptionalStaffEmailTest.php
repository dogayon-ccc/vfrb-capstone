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

    private function setPassword(User $target)
    {
        return $this->putJson("/api/admin/users/{$target->user_id}/password", ['password' => self::PASS, 'password_confirmation' => self::PASS]);
    }

    private function withoutEmail(User $user): User
    {
        DB::table('users')->where('user_id', $user->user_id)->update(['email' => null, 'username' => uniqid('u_')]);

        return $user->fresh();
    }

    public function test_manager_sets_password_on_emailless_account_signs_it_out_and_audits(): void
    {
        $manager = $this->makeUser('manager');
        $target = $this->withoutEmail($this->makeUser('staff'));
        $target->createToken('old');
        $this->actingAs($manager, 'sanctum');

        $this->setPassword($target)->assertOk();
        $this->assertSame(0, $target->tokens()->count());
        $this->assertTrue(password_verify(self::PASS, $target->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user_password_set', 'entity_id' => $target->user_id, 'user_id' => $manager->user_id]);

        $this->actingAs($this->makeUser('owner'), 'sanctum');
        $this->setPassword($target)->assertForbidden();
    }

    public function test_set_password_refuses_customers_own_account_and_accounts_with_email(): void
    {
        $manager = $this->withoutEmail($this->makeUser('manager'));
        $this->actingAs($manager, 'sanctum');

        $this->setPassword($this->withoutEmail($this->makeUser('customer')))->assertUnprocessable();
        $this->setPassword($manager)->assertUnprocessable();
        $this->setPassword($this->makeUser('staff'))->assertUnprocessable();
        $this->setPassword($this->makeUser('manager'))->assertUnprocessable();
    }

    public function test_username_is_unique_case_insensitively_across_roles(): void
    {
        $this->actingAs($this->makeUser('manager'), 'sanctum');
        $taken = $this->withoutEmail($this->makeUser('customer'))->username;

        $this->create(['role' => 'owner', 'username' => strtoupper($taken)])->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->create(['role' => 'owner', 'username' => 'a@b.c'])->assertUnprocessable()->assertJsonValidationErrors('username');
    }

    public function test_login_finds_a_mixed_case_stored_email(): void
    {
        $user = $this->makeUser('staff');
        DB::table('users')->where('user_id', $user->user_id)->update(['email' => $email = 'Mixed.'.uniqid().'@Example.Test']);

        $this->postJson('/api/login', ['login' => strtolower($email), 'password' => 'secret-pass'])->assertOk();
        $this->postJson('/api/login', ['login' => $email, 'password' => 'secret-pass'])->assertOk();
    }

    public function test_username_and_email_share_one_lockout_per_account(): void
    {
        $user = $this->makeUser('staff');
        DB::table('users')->where('user_id', $user->user_id)->update(['username' => $name = uniqid('lk_')]);

        foreach (range(1, 5) as $i) {
            $this->postJson('/api/login', ['login' => $i % 2 ? $name : strtoupper($user->email), 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->postJson('/api/login', ['login' => $name, 'password' => 'secret-pass'])->assertStatus(429);
        $this->postJson('/api/login', ['login' => $user->email, 'password' => 'secret-pass'])->assertStatus(429);
    }
}
