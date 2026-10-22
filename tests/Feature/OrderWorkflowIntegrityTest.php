<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Stage, delivery, invoice and uniform-set guards. Runs on a LOCAL database only (rolled back per test):
//   DB_CONNECTION=mysql DB_DATABASE=vfrb_test php artisan test --filter OrderWorkflowIntegrityTest
// Users: 1 = manager, 4 = staff, 5 = customer.
class OrderWorkflowIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    private const STAGES = ['pattern', 'segregation', 'cutting', 'sewing', 'qc', 'pressing', 'packing'];

    protected function setUp(): void
    {
        parent::setUp();
        // Never write test rows to the hosted database.
        $this->assertNotSame('pgsql', DB::connection()->getDriverName(), 'Point DB_* at the local vfrb_test database.');
    }

    private function actAs(int $userId): void
    {
        Sanctum::actingAs(User::findOrFail($userId), ['*']);
    }

    private function order(string $status, array $over = []): int
    {
        return DB::table('orders')->insertGetId(array_merge([
            'user_id' => 5, 'garment_type' => 'Polo Shirt', 'color' => 'Navy', 'quantity_ordered' => 100,
            'order_type' => 'direct', 'status' => $status, 'qc_required' => 0, 'created_at' => now(), 'updated_at' => now(),
            'promised_date' => now()->addDays(14)->toDateString(), 'down_payment_received_at' => now(),
        ], $over), 'order_id');
    }

    private function complete(int $orderId, array $stages): void
    {
        foreach ($stages as $s) {
            DB::table('order_production_tracking')->insert([
                'order_id' => $orderId, 'stage' => $s, 'qty_target' => 100, 'qty_completed' => 100,
                'updated_by' => 4, 'created_at' => now(), 'updated_at' => now(),
            ]);
            // Per-size flow and the packed delivery cap both read output logs, not just the tracking row.
            DB::table('daily_output_logs')->insert([
                'order_id' => $orderId, 'stage' => $s, 'logged_by' => 4, 'qty_m' => 100,
                'log_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function delivery(int $orderId, ?string $address, string $method = 'vfrb_deliver'): int
    {
        return DB::table('delivery_tracking')->insertGetId([
            'order_id' => $orderId, 'delivery_method' => $method, 'delivery_status' => 'preparing',
            'delivery_address' => $address, 'updated_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ], 'tracking_id');
    }

    private function log(int $orderId, string $stage, int $qty, array $extra = [])
    {
        return $this->postJson('/api/admin/output-logs', array_merge([
            'order_id' => $orderId, 'stage' => $stage, 'log_date' => now()->toDateString(), 'qty_m' => $qty,
        ], $extra));
    }

    private function setConfig(string $setId, string $garment): string
    {
        return json_encode(['garment' => $garment, 'uniformSet' => ['id' => $setId, 'role' => 'top', 'part' => 1, 'of' => 2]]);
    }

    // (1) ─────────────────────────────────────────────────────────────────────

    public function test_resubmitting_a_finished_held_stage_logs_nothing_and_never_exceeds_target(): void
    {
        $this->actAs(1);
        $id = $this->order('pattern');
        $mat = DB::table('materials')->value('material_id');
        DB::table('material_recommendations')->insert([
            'order_id' => $id, 'material_id' => $mat, 'material_name' => 'Test fabric', 'customer_accepted' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Pattern hits 100/100 but is held for material actuals; the same submit again must not make it 200/100.
        $this->log($id, 'pattern', 100)->assertCreated()->assertJson(['materials_blocked' => true, 'advanced' => false]);
        $this->log($id, 'pattern', 100)->assertCreated()->assertJson(['already_complete' => true, 'log_id' => null]);

        $this->assertSame(100, (int) DB::table('order_production_tracking')->where(['order_id' => $id, 'stage' => 'pattern'])->value('qty_completed'));
        $this->assertSame(1, DB::table('daily_output_logs')->where(['order_id' => $id, 'stage' => 'pattern'])->count());

        // Retrying with the actuals releases the hold without logging pieces again.
        $this->log($id, 'pattern', 100, ['material_actuals' => [['material_id' => $mat, 'qty_used' => 0]]])
            ->assertCreated()->assertJson(['advanced' => true, 'new_status' => 'segregation']);
        $this->assertSame(1, DB::table('daily_output_logs')->where(['order_id' => $id, 'stage' => 'pattern'])->count());
    }

    public function test_a_batch_that_would_overshoot_the_target_is_rejected(): void
    {
        $this->actAs(1);
        $id = $this->order('cutting');
        $this->complete($id, ['pattern', 'segregation']);

        $this->log($id, 'cutting', 60)->assertCreated();
        $this->log($id, 'cutting', 60)->assertStatus(422)->assertJsonFragment(['message' => 'Cutting is at 60/100; only 40 more pieces can be logged.']);
        $this->assertSame(60, (int) DB::table('order_production_tracking')->where(['order_id' => $id, 'stage' => 'cutting'])->value('qty_completed'));
    }

    // (2) ─────────────────────────────────────────────────────────────────────

    public function test_manager_override_cannot_advance_past_an_unfinished_stage(): void
    {
        $this->actAs(1);
        $id = $this->order('qc');
        $this->complete($id, ['pattern', 'segregation', 'cutting', 'sewing']);

        $this->postJson("/api/admin/orders/{$id}/advance")->assertStatus(422)->assertJson(['incomplete_stages' => ['QC 0/100']]);
        $this->assertSame('qc', DB::table('orders')->where('order_id', $id)->value('status'));

        $this->complete($id, ['qc']);
        $this->postJson("/api/admin/orders/{$id}/advance")->assertOk()->assertJson(['new_stage' => 'pressing']);
    }

    public function test_logging_a_stage_does_not_advance_while_an_earlier_stage_is_open(): void
    {
        $this->actAs(1);
        $id = $this->order('pressing');
        $this->complete($id, ['pattern', 'segregation', 'cutting', 'sewing']); // QC left at 0/100

        $this->log($id, 'pressing', 100)->assertStatus(422)
            ->assertJsonFragment(['message' => 'Size M: only 0 more can be logged at Pressing (QC has finished 0; Pressing has 0).']);
        $this->assertSame('pressing', DB::table('orders')->where('order_id', $id)->value('status'));
    }

    public function test_an_order_with_unfinished_stages_cannot_be_marked_delivered(): void
    {
        $this->actAs(1);
        $id = $this->order('completed');
        $this->complete($id, ['pattern', 'segregation', 'cutting', 'sewing', 'packing']); // QC and Pressing 0/100
        $t = $this->delivery($id, '12 Rizal St, Muntinlupa');

        $this->patchJson("/api/admin/delivery/{$t}/delivered")->assertStatus(422)
            ->assertJsonFragment(['message' => "Order #{$id} is still in production (QC 0/100, Pressing 0/100). Finish every stage before marking it delivered."]);
        $this->assertSame('preparing', DB::table('delivery_tracking')->where('tracking_id', $t)->value('delivery_status'));
    }

    // (3) ─────────────────────────────────────────────────────────────────────

    public function test_delivered_needs_an_address_and_accepts_one_in_the_same_request(): void
    {
        $this->actAs(1);
        $id = $this->order('completed');
        $this->complete($id, self::STAGES);
        $t = $this->delivery($id, 'Address pending');

        $this->patchJson("/api/admin/delivery/{$t}/delivered")->assertStatus(422)
            ->assertJsonFragment(['message' => "Order #{$id} has no delivery address. Add the address before marking it delivered."]);

        $this->patchJson("/api/admin/delivery/{$t}/delivered", ['delivery_address' => '12 Rizal St, Muntinlupa'])->assertOk();
        $row = DB::table('delivery_tracking')->where('tracking_id', $t)->first();
        $this->assertSame(['delivered', '12 Rizal St, Muntinlupa'], [$row->delivery_status, $row->delivery_address]);
    }

    public function test_pickup_needs_no_address(): void
    {
        $this->actAs(1);
        $id = $this->order('completed');
        $this->complete($id, self::STAGES);
        $t = $this->delivery($id, null, 'pickup');

        $this->patchJson("/api/admin/delivery/{$t}/delivered")->assertOk();
    }

    // (4) ─────────────────────────────────────────────────────────────────────

    public function test_invoice_waits_for_delivery_and_down_payments_only_get_a_receipt(): void
    {
        $this->actAs(1);
        $id = $this->order('sewing', ['agreed_total' => 50000]);

        $this->postJson('/api/admin/transactions', ['order_id' => $id, 'amount_paid' => 20000, 'payment_method' => 'cash', 'payment_terms' => 'down_payment'])
            ->assertSuccessful();
        $this->assertSame(1, DB::table('payment_receipts')->where('order_id', $id)->count());
        $this->assertSame(0, DB::table('invoices')->where('order_id', $id)->count());

        $this->getJson("/api/admin/orders/{$id}/billing")->assertOk()->assertJson(['can_issue' => false, 'awaiting_delivery' => true]);
        $this->postJson("/api/admin/orders/{$id}/invoice")->assertStatus(422);
        $this->assertSame(0, DB::table('invoices')->where('order_id', $id)->count());

        $this->delivery($id, '12 Rizal St, Muntinlupa');
        $this->getJson("/api/admin/orders/{$id}/billing")->assertOk()->assertJson(['can_issue' => true, 'awaiting_delivery' => false]);
        $this->postJson("/api/admin/orders/{$id}/invoice")->assertCreated();
        $this->assertSame(1, DB::table('payment_receipts')->where('order_id', $id)->whereNotNull('invoice_id')->count());
    }

    // (5) ─────────────────────────────────────────────────────────────────────

    public function test_confirming_one_piece_of_a_uniform_set_confirms_both(): void
    {
        $this->actAs(1);
        $setId = 'set-' . uniqid();
        $top    = $this->order('pending', ['studio_config' => $this->setConfig($setId, 'Polo Shirt')]);
        $bottom = $this->order('pending', ['studio_config' => $this->setConfig($setId, 'Pants'), 'garment_type' => 'Pants']);
        $other  = $this->order('pending', ['studio_config' => $this->setConfig('set-other', 'Polo Shirt')]);

        $this->patchJson("/api/admin/orders/{$top}/confirm")->assertOk()->assertJson(['set_confirmed_ids' => [$bottom]]);

        $status = DB::table('orders')->whereIn('order_id', [$top, $bottom, $other])->pluck('status', 'order_id');
        $this->assertSame(['confirmed', 'confirmed', 'pending'], [$status[$top], $status[$bottom], $status[$other]]);
    }

    public function test_confirming_from_order_detail_also_confirms_the_set(): void
    {
        $this->actAs(1);
        $setId = 'set-' . uniqid();
        $top    = $this->order('pending', ['studio_config' => $this->setConfig($setId, 'Polo Shirt')]);
        $bottom = $this->order('pending', ['studio_config' => $this->setConfig($setId, 'Pants'), 'garment_type' => 'Pants']);

        $this->patchJson("/api/admin/orders/{$bottom}", ['status' => 'confirmed'])->assertOk();
        $this->assertSame('confirmed', DB::table('orders')->where('order_id', $top)->value('status'));
    }

    public function test_a_uniform_set_is_delivered_together_or_not_at_all(): void
    {
        $this->actAs(1);
        $setId = 'set-' . uniqid();
        $top    = $this->order('completed', ['studio_config' => $this->setConfig($setId, 'Polo Shirt')]);
        $bottom = $this->order('packing', ['studio_config' => $this->setConfig($setId, 'Pants'), 'garment_type' => 'Pants']);
        $this->complete($top, self::STAGES);
        $tTop = $this->delivery($top, '12 Rizal St, Muntinlupa');

        // The bottom has not reached delivery yet, so neither piece is marked.
        $this->patchJson("/api/admin/delivery/{$tTop}/delivered")->assertStatus(422);
        $this->assertSame('preparing', DB::table('delivery_tracking')->where('tracking_id', $tTop)->value('delivery_status'));

        $this->complete($bottom, self::STAGES);
        DB::table('orders')->where('order_id', $bottom)->update(['status' => 'completed']);
        $tBottom = $this->delivery($bottom, '12 Rizal St, Muntinlupa');

        $this->patchJson("/api/admin/delivery/{$tTop}/delivered")->assertOk()->assertJson(['set_delivered_ids' => [$bottom]]);
        $this->assertSame(['delivered', 'delivered'], DB::table('delivery_tracking')->whereIn('tracking_id', [$tTop, $tBottom])->orderBy('tracking_id')->pluck('delivery_status')->all());
    }
}
