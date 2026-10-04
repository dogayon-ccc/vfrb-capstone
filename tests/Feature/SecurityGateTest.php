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
        return DB::table('designs')->insertGetId([
            'source_order_id' => $sourceOrderId,
            'design_name'     => 'sec-test',
            'garment_type'    => 'Polo Shirt',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
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

    public function test_public_chat_endpoint_rejects_oversized_context(): void
    {
        $this->postJson('/api/ai/describe-design', [
            'mode' => 'chat', 'prompt' => 'hi', 'chat_context' => str_repeat('a', 12001),
        ])->assertStatus(422);
    }
}
