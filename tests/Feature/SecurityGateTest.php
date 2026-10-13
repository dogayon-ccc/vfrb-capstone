<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Run against a MySQL database loaded from vfrb_db.sql (NOT RefreshDatabase — the schema is not migration-built).
// Seed assumed: users 1=manager, 4=staff, 5/6=customers; order 1 -> user 5, order 2 -> user 6.
// Every test runs inside a rolled-back transaction.
//   php artisan test --filter SecurityGateTest
class SecurityGateTest extends TestCase
{
    use DatabaseTransactions;

    private function actAs(int $userId): void
    {
        Sanctum::actingAs(User::findOrFail($userId), ['*']);
    }

    private function design(int $sourceOrderId): int
    {
        $id = DB::table('designs')->insertGetId([
            'source_order_id' => $sourceOrderId,
            'design_name'     => 'sec-test',
            'garment_type'    => 'Polo Shirt',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
        // Ownership is orders.design_id (what archiveCompletedDesign sets), not designs.source_order_id.
        DB::table('orders')->where('order_id', $sourceOrderId)->update(['design_id' => $id]);
        return $id;
    }

    public function test_customer_reads_own_order_but_not_another_customers(): void
    {
        $this->actAs(5);
        $this->getJson('/api/customer/orders/1')->assertOk();
        $this->getJson('/api/customer/orders/2')->assertNotFound();
    }

    public function test_customer_order_list_contains_only_own_orders(): void
    {
        $this->actAs(5);
        $ids = collect($this->getJson('/api/customer/orders?per_page=100')->assertOk()->json('data'))->pluck('user_id')->unique()->all();
        $this->assertSame([5], array_values($ids));
    }

    public function test_customer_messages_are_isolated_per_order_owner(): void
    {
        $this->actAs(5);
        $this->getJson('/api/customer/messages/1')->assertOk();
        $this->getJson('/api/customer/messages/2')->assertNotFound();
        $this->postJson('/api/customer/messages', ['order_id' => 1, 'body' => 'hello'])->assertCreated();
        $this->postJson('/api/customer/messages', ['order_id' => 2, 'body' => 'intrusion'])->assertNotFound();
        $this->assertSame(0, DB::table('order_messages')->where('order_id', 2)->where('body', 'intrusion')->count());
    }

    public function test_customer_cannot_touch_another_customers_design(): void
    {
        $mine  = $this->design(1);
        $other = $this->design(2);
        $this->actAs(5);
        $this->putJson("/api/customer/designs/{$mine}", ['design_name' => 'renamed'])->assertOk();
        $this->putJson("/api/customer/designs/{$other}", ['design_name' => 'hijack'])->assertNotFound();
        $this->deleteJson("/api/customer/designs/{$other}")->assertNotFound();
        $this->postJson("/api/customer/designs/{$other}/submit-showcase")->assertNotFound();
        $this->assertSame('sec-test', DB::table('designs')->where('design_id', $other)->value('design_name'));
    }

    public function test_customer_cannot_reach_another_customers_material_recommendations(): void
    {
        $this->actAs(5);
        $this->getJson('/api/customer/orders/2/ai-recommendation')->assertNotFound();
        $this->postJson('/api/customer/ai/recommend-materials', ['order_id' => 2])->assertNotFound();
        $this->postJson('/api/customer/orders/2/accept-materials')->assertNotFound();
        $this->postJson('/api/customer/orders/2/reject-materials')->assertNotFound();
        $mat = DB::table('materials')->value('material_id');
        $this->postJson('/api/customer/orders/2/select-materials', ['material_ids' => [$mat]])->assertNotFound();
    }

    public function test_recommendations_are_locked_once_materials_were_issued(): void
    {
        $mat = DB::table('materials')->first();
        DB::table('material_recommendations')->insert([
            'order_id' => 1, 'material_name' => $mat->material_name, 'material_id' => $mat->material_id,
            'category' => $mat->category, 'unit' => $mat->unit, 'display_order' => 0,
            'status' => 'accepted', 'customer_accepted' => 1, 'actual_qty_issued' => 3, 'issued_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actAs(5);
        $this->postJson('/api/customer/orders/1/select-materials', ['material_ids' => [$mat->material_id]])->assertStatus(409);
        $this->postJson('/api/customer/ai/recommend-materials', ['order_id' => 1])->assertStatus(409);
        $this->assertNotNull(DB::table('material_recommendations')->where('order_id', 1)->value('issued_at'));
    }

    public function test_recommendation_payload_never_carries_quantities(): void
    {
        $this->actAs(5);
        $body = json_encode($this->getJson('/api/customer/orders/1/ai-recommendation')->assertOk()->json());
        foreach (['estimated_range', 'total_estimated_range', 'yardage', 'quantity', 'cost', 'actual_qty_issued'] as $k) {
            $this->assertStringNotContainsString($k, $body);
        }
    }

    public function test_staff_is_blocked_from_manager_only_endpoints(): void
    {
        $this->actAs(4);
        $this->getJson('/api/admin/reports')->assertForbidden();
        $this->getJson('/api/admin/users')->assertForbidden();
        $this->getJson('/api/admin/activity-log')->assertForbidden();
        $this->postJson('/api/admin/suppliers', ['supplier_name' => 'x'])->assertForbidden();
        $this->patchJson('/api/admin/orders/1', ['status' => 'completed'])->assertForbidden();
        $this->postJson('/api/admin/orders/1/advance')->assertForbidden();
        $this->deleteJson('/api/admin/output-logs/1')->assertForbidden();
        $this->assertNotSame('completed', DB::table('orders')->where('order_id', 1)->value('status'));
    }

    public function test_only_inventory_staff_can_receive_a_purchase_order(): void
    {
        DB::table('users')->where('user_id', 4)->update(['job_function' => 'production']);
        $this->actAs(4);
        $this->patchJson('/api/admin/purchase-orders/999999/receive')->assertForbidden();

        DB::table('users')->where('user_id', 4)->update(['job_function' => 'inventory']);
        $this->actAs(4);
        $this->patchJson('/api/admin/purchase-orders/999999/receive')->assertNotFound();
    }

    public function test_admin_cannot_create_or_promote_customer_accounts(): void
    {
        $this->actAs(1);
        $body = ['name' => 'X', 'email' => 'new-cust@gmail.com', 'password' => 'Secret#123', 'password_confirmation' => 'Secret#123', 'role' => 'customer'];
        foreach (['/api/admin/users', '/api/admin/users/create'] as $url) {
            $this->postJson($url, $body)->assertStatus(422)->assertJsonValidationErrors('role');
        }
        $this->assertFalse(DB::table('users')->where('email', 'new-cust@gmail.com')->exists());

        // An existing customer account (user 5) cannot be turned into staff from User Management.
        $this->putJson('/api/admin/users/5', ['role' => 'staff'])->assertStatus(422);
        $this->assertSame(['customer'], DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', 5)->pluck('roles.name')->all());
    }

    public function test_activity_log_and_transactions_name_the_actor_and_time_on_every_row(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Writes a test payment; run on the local MySQL vfrb_test only.');
        }
        $this->actAs(1);
        $ghost = DB::table('users')->max('user_id') + 1000; // a deleted staff account
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $txn = DB::table('sales_transactions')->insertGetId([
            'order_id' => DB::table('orders')->whereNotIn('order_id', DB::table('sales_transactions')->pluck('order_id'))->value('order_id'),
            'processed_by' => $ghost, 'amount_total' => 100, 'amount_paid' => 50, 'balance_due' => 50, 'payment_method' => 'cash',
            'payment_terms' => 'down_payment', 'completion_status' => 'processing', 'date_processed' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], 'transaction_id');
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $rows = collect($this->getJson('/api/admin/transactions?per_page=100')->assertOk()->json('data'));
        $this->assertTrue($rows->contains('transaction_id', $txn), 'a payment by a deleted user must still be listed');
        foreach ($rows as $r) {
            $this->assertNotEmpty($r['processed_by_name']);
            $this->assertNotEmpty($r['processed_at']);
        }
        $this->getJson("/api/admin/transactions/{$txn}")->assertOk()->assertJson(['processed_by_name' => 'Unknown user'])->assertJsonStructure(['processed_at']);

        foreach ($this->getJson('/api/admin/activity-log?per_page=100')->assertOk()->json('data') as $r) {
            $this->assertNotEmpty($r['actor_name']);
            $this->assertNotEmpty($r['occurred_at']);
        }
    }

    public function test_manager_reaches_manager_endpoints(): void
    {
        $this->actAs(1);
        $this->getJson('/api/admin/users')->assertOk();
        $this->assertNotSame(403, $this->getJson('/api/admin/reports')->status());
    }

    public function test_roles_do_not_cross_portals(): void
    {
        $this->actAs(5);
        $this->getJson('/api/admin/orders')->assertForbidden();
        $this->actAs(4);
        $this->getJson('/api/customer/orders')->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/customer/orders/1')->assertUnauthorized();
        $this->getJson('/api/customer/messages/1')->assertUnauthorized();
        $this->getJson('/api/admin/orders')->assertUnauthorized();
        $this->getJson('/api/admin/users')->assertUnauthorized();
    }

    public function test_logout_revokes_the_token(): void
    {
        $token = User::findOrFail(5)->createToken('sec-test')->plainTextToken;
        $this->withToken($token)->getJson('/api/customer/orders/1')->assertOk();
        $this->withToken($token)->postJson('/api/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/customer/orders/1')->assertUnauthorized();
    }

    public function test_deactivating_staff_revokes_tokens_and_blocks_access(): void
    {
        $staffToken = User::findOrFail(4)->createToken('sec-test')->plainTextToken;
        $this->withToken($staffToken)->getJson('/api/admin/orders')->assertOk();

        $this->actAs(1);
        $this->patchJson('/api/admin/users/4/toggle')->assertOk()->assertJson(['is_active' => false]);
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', 4)->count());

        $this->app['auth']->forgetGuards();
        $this->actAs(4);
        $this->getJson('/api/admin/orders')->assertForbidden();
    }

    public function test_deactivated_staff_cannot_log_in(): void
    {
        DB::table('users')->where('user_id', 4)->update(['email_verified_at' => null, 'password' => bcrypt('Sec#Test1234')]);
        $email = DB::table('users')->where('user_id', 4)->value('email');
        $this->postJson('/api/login', ['email' => $email, 'password' => 'Sec#Test1234'])->assertForbidden();
        $this->postJson('/api/admin/login', ['email' => $email, 'password' => 'Sec#Test1234'])->assertForbidden();
    }

    public function test_activate_deactivate_is_limited_to_staff_and_manager_accounts(): void
    {
        $this->actAs(1);
        $this->patchJson('/api/admin/users/5/toggle')->assertStatus(422);
    }

    // Free-form chat was removed (AI scope: materials + B5 design edits): a chat payload is refused before any AI call.
    public function test_public_design_endpoint_has_no_free_chat_and_caps_the_brief(): void
    {
        $this->postJson('/api/ai/describe-design', ['mode' => 'chat', 'prompt' => 'hi', 'chat_context' => 'anything'])
            ->assertStatus(422)->assertJsonValidationErrors('description');
        $this->postJson('/api/ai/describe-design', ['description' => str_repeat('a', 501)])->assertStatus(422);
    }
}
