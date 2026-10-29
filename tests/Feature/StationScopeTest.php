<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Staff = "Administrator (Station)". LOCAL database only (rolled back per test); needs 2026_10_25_000001 applied to vfrb_test.
class StationScopeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertNotSame('pgsql', DB::connection()->getDriverName(), 'Point DB_* at the local vfrb_test database.');
    }

    private function makeUser(string $role, ?string $stage = null): User
    {
        $user = User::create(['name' => ucfirst($role).' Test', 'email' => uniqid($role).'@example.test', 'password' => bcrypt('secret-pass')]);
        $user->forceFill(['email_verified_at' => now(), 'assigned_stage' => $stage])->save();
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($role);

        return $user;
    }

    private function order(string $status = 'pattern'): int
    {
        return DB::table('orders')->insertGetId([
            'user_id' => 5, 'garment_type' => 'Polo Shirt', 'color' => 'Navy', 'quantity_ordered' => 100,
            'order_type' => 'direct', 'status' => $status, 'qc_required' => 0, 'agreed_total' => 25000,
            'promised_date' => now()->addDays(14)->toDateString(), 'down_payment_received_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], 'order_id');
    }

    private function log(int $orderId, string $stage)
    {
        return $this->postJson('/api/admin/output-logs', ['order_id' => $orderId, 'stage' => $stage, 'log_date' => now()->toDateString(), 'qty_m' => 5]);
    }

    public function test_station_logs_its_own_stage_and_the_row_records_who_and_which_stage(): void
    {
        $station = $this->makeUser('staff', 'pattern');
        $this->actingAs($station, 'sanctum');
        $logId = $this->log($this->order(), 'pattern')->assertCreated()->json('log_id');

        $row = DB::table('daily_output_logs')->where('log_id', $logId)->first();
        $this->assertSame([$station->user_id, 'pattern'], [(int) $row->logged_by, $row->stage]);
    }

    public function test_station_cannot_write_another_stage_qc_or_defects(): void
    {
        $this->actingAs($this->makeUser('staff', 'pattern'), 'sanctum');
        $order = $this->order();
        $this->log($order, 'cutting')->assertForbidden();
        $this->postJson("/api/admin/orders/{$order}/qc", ['items_checked' => 10, 'items_passed' => 10, 'items_failed' => 0])->assertForbidden();
        $this->postJson("/api/admin/orders/{$order}/defects", ['size' => 'M', 'qty' => 1, 'reason' => 'x'])->assertForbidden();

        $sewer = $this->makeUser('staff', 'sewing');
        DB::table('daily_output_logs')->insert(['order_id' => $order, 'stage' => 'sewing', 'logged_by' => $sewer->user_id, 'qty_m' => 5, 'log_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($sewer, 'sanctum');
        $this->postJson("/api/admin/orders/{$order}/defects", ['size' => 'M', 'qty' => 1, 'reason' => 'x'])->assertCreated();
    }

    public function test_station_order_picker_hides_orders_past_its_stage(): void
    {
        $orders = array_map(fn ($s) => $this->order($s), ['pattern', 'cutting', 'sewing']);
        $this->actingAs($this->makeUser('staff', 'cutting'), 'sanctum');
        $ids = collect($this->getJson('/api/admin/orders?status=pattern,segregation,cutting,sewing,qc,pressing,packing&per_page=100')->assertOk()->json('data'))->pluck('order_id');

        $this->assertTrue($ids->contains($orders[0]) && $ids->contains($orders[1]));
        $this->assertFalse($ids->contains($orders[2]));
    }

    public function test_station_without_an_assigned_stage_cannot_write(): void
    {
        $this->actingAs($this->makeUser('staff'), 'sanctum');
        $this->log($this->order(), 'pattern')->assertForbidden();
    }

    public function test_station_is_kept_out_of_money_stock_delivery_users_and_proposals(): void
    {
        $this->actingAs($this->makeUser('staff', 'pattern'), 'sanctum');
        $order = $this->order('pending');
        $this->postJson('/api/admin/transactions', ['order_id' => $order, 'amount_paid' => 100])->assertForbidden();
        $this->getJson('/api/admin/transactions')->assertForbidden();
        $this->postJson("/api/admin/orders/{$order}/down-payment", ['down_payment_amount' => 100])->assertForbidden();
        $this->postJson("/api/admin/orders/{$order}/propose", [])->assertForbidden();
        $this->postJson('/api/admin/inventory/stock-in', [])->assertForbidden();
        $this->getJson('/api/admin/delivery')->assertForbidden();
        $this->getJson('/api/admin/users')->assertForbidden();
        $this->getJson('/api/admin/audit-logs')->assertForbidden();
        $this->getJson('/api/admin/reports')->assertForbidden();
        $this->patchJson('/api/admin/settings/company', [])->assertForbidden();
    }

    public function test_station_reads_dashboard_and_orders_without_money_fields(): void
    {
        $this->actingAs($this->makeUser('staff', 'pattern'), 'sanctum');
        $order = $this->order();
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonMissingPath('revenue')->assertJsonMissingPath('monthly_revenue')
            ->assertJsonPath('orders.total', fn ($v) => is_int($v));
        $body = json_encode($this->getJson("/api/admin/orders/{$order}")->assertOk()->json());
        $this->assertStringNotContainsString('agreed_total', $body);
        $this->assertStringNotContainsString('unit_cost', json_encode($this->getJson('/api/admin/inventory')->assertOk()->json()));
    }

    public function test_manager_logs_any_stage_and_owner_cannot_log(): void
    {
        $this->actingAs($this->makeUser('manager'), 'sanctum');
        $this->log($this->order(), 'pattern')->assertCreated();
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonStructure(['revenue']);

        $this->actingAs($this->makeUser('owner'), 'sanctum');
        $this->log($this->order(), 'pattern')->assertForbidden();
    }
}
