<?php

namespace Tests\Feature;

use App\Models\CustomerContact;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Walk-in customer contacts. LOCAL database only (rolled back per test).
//   DB_CONNECTION=mysql DB_DATABASE=vfrb_test php artisan test --filter CustomerContactTest
class CustomerContactTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertNotSame('pgsql', DB::connection()->getDriverName(), 'Point DB_* at the local vfrb_test database.');
    }

    private function makeUser(string $role, bool $verified = true, ?string $email = null): User
    {
        $user = User::create(['name' => ucfirst($role).' Test', 'email' => $email ?? uniqid($role).'@example.test', 'password' => bcrypt('secret-pass')]);
        if ($verified) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($role);

        return $user;
    }

    public function test_administrator_creates_a_contact_without_a_user_account(): void
    {
        $this->actingAs($this->makeUser('manager'), 'sanctum');
        $users = DB::table('users')->count();
        $email = uniqid('walkin').'@example.test';

        $this->postJson('/api/admin/customer-contacts', [
            'name' => 'Walk-in Client', 'email' => strtoupper($email), 'mobile' => '0917 123 4567',
            'address' => 'Cebu City', 'organization_name' => 'St. Mary School',
        ])->assertCreated()->assertJson(['email' => $email, 'user_id' => null]);

        $this->assertSame($users, DB::table('users')->count());
        $this->assertTrue(DB::table('audit_logs')->where('action', 'customer_contact_created')->exists());
    }

    public function test_contact_needs_email_or_mobile_and_a_unique_email(): void
    {
        $this->actingAs($this->makeUser('manager'), 'sanctum');
        $this->postJson('/api/admin/customer-contacts', ['name' => 'No Details'])
            ->assertStatus(422)->assertJsonValidationErrors(['email', 'mobile']);

        $email = uniqid('dup').'@example.test';
        $this->postJson('/api/admin/customer-contacts', ['name' => 'A', 'email' => $email])->assertCreated();
        $this->postJson('/api/admin/customer-contacts', ['name' => 'B', 'email' => $email])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_administrator_lists_and_searches_contacts(): void
    {
        $this->actingAs($this->makeUser('manager'), 'sanctum');
        $org = uniqid('Org');
        $this->postJson('/api/admin/customer-contacts', ['name' => 'Listed Client', 'mobile' => '09171234567', 'organization_name' => $org])->assertCreated();

        $rows = $this->getJson('/api/admin/customer-contacts?search='.$org)->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('Listed Client', $rows[0]['name']);
        $this->assertSame([], $this->getJson('/api/admin/customer-contacts?search='.$org.'&linked=1')->json('data'));
    }

    public function test_contact_links_only_after_the_customer_verifies_the_same_email(): void
    {
        $this->actingAs($this->makeUser('manager'), 'sanctum');
        $email = uniqid('later').'@example.test';
        $contactId = $this->postJson('/api/admin/customer-contacts', ['name' => 'Later Client', 'email' => $email])->json('contact_id');

        // Registered but unverified: the record must not be claimable yet.
        $customer = $this->makeUser('customer', verified: false, email: strtoupper($email));
        $this->assertSame(0, CustomerContact::linkVerifiedUser($customer->user_id, $customer->email));
        $this->assertNull(CustomerContact::find($contactId)->user_id);

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $customer->user_id, 'hash' => sha1($customer->getEmailForVerification())]);
        $this->get($url)->assertRedirect();

        $contact = CustomerContact::find($contactId);
        $this->assertSame($customer->user_id, (int) $contact->user_id);
        $this->assertNotNull($contact->linked_at);
    }

    public function test_contact_never_links_to_an_administrator_account(): void
    {
        $staff = $this->makeUser('manager');
        $this->actingAs($this->makeUser('manager'), 'sanctum');
        $this->postJson('/api/admin/customer-contacts', ['name' => 'Same Email', 'email' => $staff->email])
            ->assertCreated()->assertJson(['user_id' => null]);
        $this->assertSame(0, CustomerContact::linkVerifiedUser($staff->user_id, $staff->email));
    }

    public function test_customer_and_read_only_owner_are_forbidden(): void
    {
        foreach (['customer', 'owner'] as $role) {
            $this->actingAs($this->makeUser($role), 'sanctum');
            $this->getJson('/api/admin/customer-contacts')->assertForbidden();
            $this->postJson('/api/admin/customer-contacts', ['name' => 'X', 'mobile' => '09171234567'])->assertForbidden();
        }
        $this->assertFalse(DB::table('customer_contacts')->where('name', 'X')->exists());
    }
}
