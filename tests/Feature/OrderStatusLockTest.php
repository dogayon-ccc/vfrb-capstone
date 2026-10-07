<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Fixture orders (vfrb_db.sql): 9 = pending, 1 = pattern, 8 = completed. User 1 = manager.
class OrderStatusLockTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::findOrFail(1), ['*']);
    }

    private function statusOf(int $id): string
    {
        return DB::table('orders')->where('order_id', $id)->value('status');
    }

    private function seedCancelled(): int
    {
        return DB::table('orders')->insertGetId([
            'user_id' => 5, 'garment_type' => 'T-Shirt', 'quantity_ordered' => 100, 'status' => 'cancelled',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_pending_order_can_be_confirmed_and_the_customer_is_notified(): void
    {
        $this->patchJson('/api/admin/orders/9', ['status' => 'confirmed', 'agreed_total' => 15000])->assertOk();

        $this->assertSame('confirmed', $this->statusOf(9));
        $this->assertSame(1, DB::table('notifications')->where('user_id', 5)->where('order_id', 9)->where('message', 'like', '%Confirmed%')->count());
    }

    public function test_pending_order_can_be_cancelled_with_a_reason(): void
    {
        $this->patchJson('/api/admin/orders/9', ['status' => 'cancelled', 'notes' => 'Out of capacity'])->assertOk();
        $this->assertSame('cancelled', $this->statusOf(9));
    }

    public function test_confirmed_order_can_only_be_cancelled_not_reverted_or_skipped_ahead(): void
    {
        DB::table('orders')->where('order_id', 9)->update(['status' => 'confirmed']);

        $this->patchJson('/api/admin/orders/9', ['status' => 'pending'])->assertStatus(422);
        $this->patchJson('/api/admin/orders/9', ['status' => 'completed'])->assertStatus(422);
        $this->patchJson('/api/admin/orders/9', ['status' => 'sewing'])->assertStatus(422);
        $this->assertSame('confirmed', $this->statusOf(9));

        $this->patchJson('/api/admin/orders/9', ['status' => 'cancelled', 'notes' => 'Customer request'])->assertOk();
        $this->assertSame('cancelled', $this->statusOf(9));
    }

    public function test_pending_order_cannot_skip_to_a_production_stage_or_completed(): void
    {
        foreach (['pattern', 'cutting', 'qc', 'packing', 'completed'] as $to) {
            $this->patchJson('/api/admin/orders/9', ['status' => $to])->assertStatus(422);
        }
        $this->assertSame('pending', $this->statusOf(9));
    }

    public function test_production_orders_are_only_moved_by_the_production_flow(): void
    {
        $this->assertSame('pattern', $this->statusOf(1));

        $this->patchJson('/api/admin/orders/1', ['status' => 'completed'])->assertStatus(422);
        $this->patchJson('/api/admin/orders/1', ['status' => 'cancelled'])->assertStatus(422);
        $this->patchJson('/api/admin/orders/1', ['status' => 'pending'])->assertStatus(422);
        $this->assertSame('pattern', $this->statusOf(1));

        $this->postJson('/api/admin/orders/1/advance')->assertOk();
        $this->assertSame('segregation', $this->statusOf(1));
    }

    public function test_completed_and_cancelled_orders_are_fully_read_only(): void
    {
        $cancelled = $this->seedCancelled();

        foreach ([8, $cancelled] as $id) {
            $before = DB::table('orders')->where('order_id', $id)->first();

            $this->patchJson("/api/admin/orders/{$id}", ['status' => 'pending'])->assertStatus(409);
            $this->patchJson("/api/admin/orders/{$id}", ['notes' => 'edited after close'])->assertStatus(409);
            $this->patchJson("/api/admin/orders/{$id}", ['agreed_total' => 1])->assertStatus(409);

            $this->assertEquals($before, DB::table('orders')->where('order_id', $id)->first());
        }
    }

    public function test_resending_the_current_status_is_not_a_transition_and_sends_no_duplicate_notice(): void
    {
        $this->patchJson('/api/admin/orders/9', ['status' => 'pending', 'notes' => 'still reviewing'])->assertOk();

        $this->assertSame('pending', $this->statusOf(9));
        $this->assertSame('still reviewing', DB::table('orders')->where('order_id', 9)->value('notes'));
        $this->assertSame(0, DB::table('notifications')->where('order_id', 9)->where('message', 'like', '%status has been updated%')->count());
    }

    public function test_unknown_status_value_is_rejected_by_validation(): void
    {
        $this->patchJson('/api/admin/orders/9', ['status' => 'teleported'])->assertStatus(422);
    }

    public function test_staff_cannot_change_status_at_all(): void
    {
        Sanctum::actingAs(User::findOrFail(4), ['*']);

        $this->patchJson('/api/admin/orders/9', ['status' => 'confirmed'])->assertForbidden();
        $this->assertSame('pending', $this->statusOf(9));
    }

    public function test_customer_has_no_route_to_edit_or_cancel_a_submitted_order(): void
    {
        Sanctum::actingAs(User::findOrFail(5), ['*']);

        $this->patchJson('/api/customer/orders/9', ['status' => 'cancelled', 'quantity_ordered' => 1])->assertStatus(405);
        $this->putJson('/api/customer/orders/9', ['quantity_ordered' => 1])->assertStatus(405);
        $this->deleteJson('/api/customer/orders/9')->assertStatus(405);
        $this->patchJson('/api/admin/orders/9', ['status' => 'cancelled'])->assertForbidden();
        $this->assertSame('pending', $this->statusOf(9));
    }
}
