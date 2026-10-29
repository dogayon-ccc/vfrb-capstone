<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Public "Request access". LOCAL database only (rolled back per test).
//   DB_CONNECTION=mysql DB_DATABASE=vfrb_test php artisan test --filter RequestAccessTest
class RequestAccessTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertNotSame('pgsql', DB::connection()->getDriverName(), 'Point DB_* at the local vfrb_test database.');
    }

    private function makeManager(): User
    {
        $user = User::create(['name' => 'Manager Test', 'email' => uniqid('manager').'@example.test', 'password' => bcrypt('secret-pass')]);
        $user->forceFill(['email_verified_at' => now()])->save();
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $user->assignRole('manager');

        return $user;
    }

    public function test_request_creates_a_contact_and_notifies_managers_but_no_user(): void
    {
        $manager = $this->makeManager();
        $users = DB::table('users')->count();
        $email = uniqid('req').'@example.test';

        $this->postJson('/api/request-access', [
            'name' => 'Ana Cruz', 'organization' => 'St. Mary School', 'email' => strtoupper($email), 'mobile' => '0917 123 4567',
        ])->assertStatus(202);

        $this->assertSame($users, DB::table('users')->count());
        $contact = DB::table('customer_contacts')->where('email', $email)->first();
        $this->assertSame('St. Mary School', $contact->organization_name);
        $this->assertNull($contact->created_by);
        $this->assertNull($contact->user_id);
        $this->assertTrue(DB::table('notifications')->where('user_id', $manager->user_id)->where('type', 'access_request')->exists());

        $this->actingAs($manager, 'sanctum');
        $emails = collect($this->getJson('/api/admin/customer-contacts?requested=1&per_page=100&search='.$email)->assertOk()->json('data'))->pluck('email');
        $this->assertSame([$email], $emails->all());
    }

    public function test_duplicate_email_and_honeypot_create_nothing_but_reply_the_same(): void
    {
        $email = uniqid('dup').'@example.test';
        $this->postJson('/api/request-access', ['name' => 'First', 'email' => $email])->assertStatus(202);
        $this->postJson('/api/request-access', ['name' => 'Second', 'email' => $email])->assertStatus(202);
        $this->assertSame(1, DB::table('customer_contacts')->where('email', $email)->count());

        $bot = uniqid('bot').'@example.test';
        $this->postJson('/api/request-access', ['name' => 'Bot', 'email' => $bot, 'website' => 'http://spam.test'])->assertStatus(202);
        $this->assertFalse(DB::table('customer_contacts')->where('email', $bot)->exists());
    }

    public function test_name_and_valid_email_are_required(): void
    {
        $this->postJson('/api/request-access', ['email' => 'not-an-email'])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'email']);
    }

    public function test_throttle_returns_429_after_five_requests(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/request-access', ['name' => 'T', 'email' => uniqid('t').'@example.test'])->assertStatus(202);
        }
        $this->postJson('/api/request-access', ['name' => 'T', 'email' => uniqid('t').'@example.test'])->assertStatus(429);
    }
}
