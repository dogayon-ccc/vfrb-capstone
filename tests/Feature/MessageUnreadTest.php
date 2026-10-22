<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Per-side unread tracking on order threads. LOCAL database only (rolled back per test).
//   DB_CONNECTION=mysql DB_DATABASE=vfrb_test php artisan test --filter MessageUnreadTest
class MessageUnreadTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertNotSame('pgsql', DB::connection()->getDriverName(), 'Point DB_* at the local vfrb_test database.');
        Notification::fake();
    }

    private function makeUser(string $role): User
    {
        $user = User::create(['name' => ucfirst($role).' Test', 'email' => uniqid($role).'@example.test', 'password' => bcrypt('secret-pass')]);
        $user->forceFill(['email_verified_at' => now()])->save();
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($role);

        return $user;
    }

    private function orderFor(User $customer): int
    {
        return DB::table('orders')->insertGetId([
            'user_id' => $customer->user_id, 'garment_type' => 'Polo Shirt', 'quantity_ordered' => 100, 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ], 'order_id');
    }

    private function threadCount(array $payload, int $orderId): int
    {
        return (int) (collect($payload['threads'])->firstWhere('order_id', $orderId)['unread_count'] ?? 0);
    }

    public function test_customer_counts_only_cover_own_threads_and_clear_on_read(): void
    {
        $alice = $this->makeUser('customer');
        $bob = $this->makeUser('customer');
        $staff = $this->makeUser('manager');
        $aliceOrder = $this->orderFor($alice);
        $bobOrder = $this->orderFor($bob);

        $this->actingAs($staff, 'sanctum');
        $this->postJson('/api/admin/messages', ['order_id' => $aliceOrder, 'body' => 'one'])->assertCreated();
        $this->postJson('/api/admin/messages', ['order_id' => $aliceOrder, 'body' => 'two'])->assertCreated();
        $this->postJson('/api/admin/messages', ['order_id' => $bobOrder, 'body' => 'for bob'])->assertCreated();

        $this->actingAs($alice, 'sanctum');
        $this->getJson('/api/customer/messages/unread-count')->assertOk()->assertExactJson([
            'unread_count' => 2,
            'threads' => [['order_id' => $aliceOrder, 'unread_count' => 2]],
        ]);
        $this->getJson("/api/customer/messages/{$bobOrder}")->assertNotFound();
        $this->assertSame(1, DB::table('order_messages')->where('order_id', $bobOrder)->where('is_read', 0)->count());

        $this->getJson("/api/customer/messages/{$aliceOrder}")->assertOk();
        $this->getJson('/api/customer/messages/unread-count')->assertOk()->assertExactJson(['unread_count' => 0, 'threads' => []]);
    }

    public function test_staff_reading_a_thread_does_not_clear_the_customers_unread_replies(): void
    {
        $customer = $this->makeUser('customer');
        $staff = $this->makeUser('manager');
        $otherStaff = $this->makeUser('manager');
        $order = $this->orderFor($customer);

        $this->actingAs($customer, 'sanctum');
        $this->postJson('/api/customer/messages', ['order_id' => $order, 'body' => 'question'])->assertCreated();
        $this->actingAs($staff, 'sanctum');
        $this->postJson('/api/admin/messages', ['order_id' => $order, 'body' => 'answer'])->assertCreated();

        $this->actingAs($otherStaff, 'sanctum');
        $this->assertSame(1, $this->threadCount($this->getJson('/api/admin/messages/unread-count')->assertOk()->json(), $order));
        $this->getJson("/api/admin/messages/{$order}")->assertOk();
        $this->assertSame(0, $this->threadCount($this->getJson('/api/admin/messages/unread-count')->json(), $order));

        $this->actingAs($customer, 'sanctum');
        $this->assertSame(1, $this->getJson('/api/customer/messages/unread-count')->json('unread_count'));
    }

    public function test_reply_notifies_the_other_side(): void
    {
        $customer = $this->makeUser('customer');
        $staff = $this->makeUser('manager');
        $order = $this->orderFor($customer);

        $this->actingAs($customer, 'sanctum');
        $this->postJson('/api/customer/messages', ['order_id' => $order, 'body' => 'hello'])->assertCreated();
        $this->assertTrue(DB::table('notifications')->where(['user_id' => $staff->user_id, 'order_id' => $order, 'type' => 'message'])->exists());
        $this->assertFalse(DB::table('notifications')->where(['user_id' => $customer->user_id, 'order_id' => $order])->exists());

        $this->actingAs($staff, 'sanctum');
        $this->postJson('/api/admin/messages', ['order_id' => $order, 'body' => 'hi'])->assertCreated();
        $this->assertTrue(DB::table('notifications')->where(['user_id' => $customer->user_id, 'order_id' => $order, 'type' => 'message'])->exists());
    }
}
