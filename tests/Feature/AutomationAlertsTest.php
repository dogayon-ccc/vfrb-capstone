<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Event-driven alerts: low stock, QC result, stage advance. LOCAL database only (rolled back per test). Users: 1 = manager, 5 = customer.
class AutomationAlertsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertNotSame('pgsql', DB::connection()->getDriverName(), 'Point DB_* at the local vfrb_test database.');
        Sanctum::actingAs(User::findOrFail(1), ['*']);
    }

    private function material(string $name): int
    {
        return DB::table('materials')->insertGetId([
            'material_code' => uniqid('TST-'), 'material_name' => $name, 'category' => 'Thread', 'unit' => 'cone',
            'quantity_in_stock' => 5, 'reorder_threshold' => 2, 'unit_cost' => 10, 'created_at' => now(), 'updated_at' => now(),
        ], 'material_id');
    }

    private function order(string $status, array $over = []): int
    {
        return DB::table('orders')->insertGetId(array_merge([
            'user_id' => 5, 'garment_type' => 'Polo Shirt', 'color' => 'Navy', 'quantity_ordered' => 100, 'order_type' => 'direct',
            'status' => $status, 'qc_required' => 0, 'agreed_total' => 50000, 'promised_date' => now()->addDays(14)->toDateString(),
            'down_payment_received_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $over), 'order_id');
    }

    private function lowStockAlerts(string $name): int
    {
        return DB::table('notifications')->where('type', 'low_stock')->where('user_id', 1)->where('message', 'like', "{$name} stock (%")->count();
    }

    public function test_low_stock_alert_fires_once_per_material_per_day(): void
    {
        $name = 'Alert Thread '.uniqid();
        $id = $this->material($name);
        $red = $this->material("{$name} Red");

        $this->postJson('/api/admin/inventory/stock-out', ['material_id' => $red, 'quantity' => 4])->assertOk()->assertJson(['low_stock' => true]);
        $this->postJson('/api/admin/inventory/stock-out', ['material_id' => $red, 'quantity' => 1])->assertOk();
        $this->assertSame(1, $this->lowStockAlerts("{$name} Red"));

        // "<name> Red" was alerted first; "<name>" must still get its own alert.
        $this->postJson('/api/admin/inventory/stock-out', ['material_id' => $id, 'quantity' => 4])->assertOk();
        $this->assertSame(1, $this->lowStockAlerts($name));
    }

    public function test_qc_result_is_sent_to_managers_for_pass_and_fail(): void
    {
        $checks = ['stitching_ok' => true, 'color_ok' => true, 'size_ok' => true, 'label_ok' => true, 'finish_ok' => true];
        $pass = $this->order('qc');
        $fail = $this->order('qc');

        $this->postJson("/api/admin/orders/{$pass}/qc", $checks + ['items_checked' => 10, 'items_passed' => 10, 'items_failed' => 0])->assertCreated();
        $this->postJson("/api/admin/orders/{$fail}/qc", $checks + ['items_checked' => 10, 'items_passed' => 5, 'items_failed' => 5])->assertCreated();

        $msg = fn (int $id) => DB::table('notifications')->where(['type' => 'order_update', 'order_id' => $id, 'user_id' => 1])->value('message');
        $this->assertStringStartsWith("QC passed on order #{$pass}", $msg($pass));
        $this->assertStringStartsWith("QC not passed on order #{$fail}", $msg($fail));
        $this->assertFalse(DB::table('notifications')->whereIn('order_id', [$pass, $fail])->where('user_id', 5)->exists());
    }

    public function test_auto_start_tells_managers_the_stage_and_the_customer_only_that_production_began(): void
    {
        $id = $this->order('confirmed', ['down_payment_received_at' => null]);

        $this->postJson("/api/admin/orders/{$id}/down-payment", ['down_payment_amount' => 40000])->assertOk();

        $this->assertSame('pattern', DB::table('orders')->where('order_id', $id)->value('status'));
        $this->assertSame("Order #{$id} advanced: Confirmed → Pattern.",
            DB::table('notifications')->where(['type' => 'production', 'order_id' => $id, 'user_id' => 1])->value('message'));
        $customer = DB::table('notifications')->where(['order_id' => $id, 'user_id' => 5])->pluck('message')->implode(' | ');
        $this->assertStringContainsString("VFRB has started producing order #{$id}.", $customer);
        $this->assertStringNotContainsString('Pattern', $customer);
    }
}
