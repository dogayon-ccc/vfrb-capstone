<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\BusinessDay;
use App\Support\Overdue;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Automation gaps Q1 (pending-order reminders and flags, Reports 7-day alert), Q3 (dashboard Overdue count), Q4 (low stock at Cutting).
// LOCAL database only (rolled back per test). Users: 1 = manager, 4 = staff, 5 = customer.
class AutomationGapsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertNotSame('pgsql', DB::connection()->getDriverName(), 'Point DB_* at the local vfrb_test database.');
        Sanctum::actingAs(User::findOrFail(1), ['*']);
    }

    private function order(string $status, array $over = []): int
    {
        return DB::table('orders')->insertGetId(array_merge([
            'user_id' => 5, 'garment_type' => 'Polo Shirt', 'color' => 'Navy', 'quantity_ordered' => 100, 'order_type' => 'direct',
            'status' => $status, 'qc_required' => 0, 'agreed_total' => 50000, 'promised_date' => BusinessDay::now()->addDays(14)->toDateString(),
            'target_delivery_date' => BusinessDay::now()->addDays(14)->toDateString(), 'down_payment_received_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $over), 'order_id');
    }

    private function notes(string $type, int $orderId, int $userId)
    {
        return DB::table('notifications')->where(['type' => $type, 'order_id' => $orderId, 'user_id' => $userId]);
    }

    // ── Q1 ───────────────────────────────────────────────────────────────

    public function test_pending_order_past_the_preferred_date_gets_the_review_flag_not_the_agreed_date_flag(): void
    {
        $id = $this->order('pending', ['promised_date' => null, 'target_delivery_date' => BusinessDay::now()->subDays(2)->toDateString()]);

        $this->artisan('vfrb:daily-digest')->assertSuccessful();
        $this->artisan('vfrb:daily-digest')->assertSuccessful();

        $this->assertSame(1, $this->notes('order_review_overdue', $id, 1)->count());
        $this->assertSame("Order #{$id} (Polo Shirt) has not been reviewed yet and the customer's preferred date has passed.",
            $this->notes('order_review_overdue', $id, 1)->value('message'));
        $this->assertFalse($this->notes('order_overdue', $id, 1)->exists());
    }

    public function test_customer_gets_the_7_day_reminder_only_once_an_agreed_date_exists(): void
    {
        $id = $this->order('pending', ['promised_date' => null, 'target_delivery_date' => BusinessDay::now()->addDays(3)->toDateString()]);

        $this->artisan('vfrb:daily-digest')->assertSuccessful();
        $this->assertSame(1, $this->notes('deadline_reminder', $id, 1)->count());
        $this->assertFalse($this->notes('deadline_reminder', $id, 5)->exists());

        DB::table('orders')->where('order_id', $id)->update(['status' => 'confirmed', 'promised_date' => BusinessDay::now()->addDays(5)->toDateString()]);
        $this->artisan('vfrb:daily-digest')->assertSuccessful();
        $this->artisan('vfrb:daily-digest')->assertSuccessful();

        $this->assertSame(1, $this->notes('deadline_reminder', $id, 5)->count());
        $this->assertStringContainsString('is due in 5 days', $this->notes('deadline_reminder', $id, 5)->value('message'));
        $this->assertSame(1, $this->notes('deadline_reminder', $id, 1)->count());
    }

    public function test_reports_7_day_alert_uses_the_agreed_date_and_the_digest_window(): void
    {
        $agreedSoon = $this->order('sewing', ['promised_date' => BusinessDay::now()->addDays(3)->toDateString(), 'target_delivery_date' => BusinessDay::now()->addDays(30)->toDateString()]);
        $packed = $this->order('for_delivery', ['promised_date' => BusinessDay::now()->addDays(2)->toDateString()]);
        $agreedLater = $this->order('sewing', ['promised_date' => BusinessDay::now()->addDays(30)->toDateString(), 'target_delivery_date' => BusinessDay::now()->addDays(3)->toDateString()]);
        $pastDue = $this->order('sewing', ['promised_date' => BusinessDay::now()->subDays(2)->toDateString()]);

        $alert = collect($this->getJson('/api/admin/reports')->assertOk()->json('prescriptive_alerts'))->firstWhere('type', 'deadline');
        $ids = collect($alert['affected_orders'])->map(fn ($v) => (int) $v);

        $this->assertTrue($ids->contains($agreedSoon) && $ids->contains($packed));
        $this->assertFalse($ids->contains($agreedLater) || $ids->contains($pastDue));
        $this->assertSame($ids->count().' order(s) are due within 7 days and not delivered yet.', $alert['message']);
    }

    // ── Q3 ───────────────────────────────────────────────────────────────

    public function test_dashboard_counts_overdue_orders_and_invoices_until_resolved(): void
    {
        [$orders, $invoices] = [Overdue::orders()->count(), Overdue::invoices()->count()];
        $late = $this->order('sewing', ['promised_date' => BusinessDay::now()->subDay()->toDateString()]);
        $billed = $this->order('completed');
        DB::table('invoices')->insert([
            'order_id' => $billed, 'invoice_number' => 'INV-T-'.uniqid(), 'status' => 'issued', 'billed_to_name' => 'Test School', 'total_amount' => 1000,
            'due_date' => BusinessDay::now()->subDay()->toDateString(), 'issued_at' => now(), 'issued_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->getJson('/api/admin/dashboard')->assertOk()->assertJson(['overdue' => ['orders' => $orders + 1, 'invoices' => $invoices + 1]]);

        DB::table('orders')->where('order_id', $late)->update(['status' => 'completed']);
        DB::table('invoices')->where('order_id', $billed)->update(['status' => 'paid']);
        cache()->forget('dashboard_stats');
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJson(['overdue' => ['orders' => $orders, 'invoices' => $invoices]]);
    }

    public function test_station_staff_see_the_overdue_order_count_but_not_invoices(): void
    {
        $station = User::create(['name' => 'Station Test', 'email' => uniqid('station').'@example.test', 'password' => bcrypt('secret-pass')]);
        $station->forceFill(['email_verified_at' => now(), 'assigned_stage' => 'sewing'])->save();
        Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);
        $station->assignRole('staff');
        Sanctum::actingAs($station, ['*']);

        $overdue = $this->getJson('/api/admin/dashboard')->assertOk()->json('overdue');

        $this->assertSame(['orders'], array_keys($overdue));
    }

    // ── Q4 ───────────────────────────────────────────────────────────────

    public function test_cutting_that_drops_a_material_below_its_reorder_level_alerts_managers_once(): void
    {
        $name = 'Cutting Twill '.uniqid();
        $material = DB::table('materials')->insertGetId([
            'material_code' => uniqid('TST-'), 'material_name' => $name, 'category' => 'Fabric', 'unit' => 'yard',
            'quantity_in_stock' => 10, 'reorder_threshold' => 5, 'unit_cost' => 10, 'created_at' => now(), 'updated_at' => now(),
        ], 'material_id');
        $alerts = fn () => DB::table('notifications')->where(['type' => 'low_stock', 'user_id' => 1])->where('message', 'like', "{$name} stock (%")->count();

        foreach ([8, 1] as $used) {
            $id = $this->order('cutting');
            foreach (['pattern', 'segregation'] as $s) {
                DB::table('order_production_tracking')->insert(['order_id' => $id, 'stage' => $s, 'qty_target' => 100, 'qty_completed' => 100, 'updated_by' => 4, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('daily_output_logs')->insert(['order_id' => $id, 'stage' => $s, 'logged_by' => 4, 'qty_m' => 100, 'log_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('material_recommendations')->insert([
                'order_id' => $id, 'material_id' => $material, 'material_name' => $name, 'category' => 'Fabric', 'unit' => 'yard',
                'status' => 'accepted', 'customer_accepted' => 1, 'actual_qty_issued' => $used, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $this->postJson('/api/admin/output-logs', ['order_id' => $id, 'stage' => 'cutting', 'log_date' => now()->toDateString(), 'qty_m' => 100])
                ->assertCreated()->assertJson(['advanced' => true]);
        }

        $this->assertSame(1.0, (float) DB::table('materials')->where('material_id', $material)->value('quantity_in_stock'));
        $this->assertSame(1, $alerts()); // first cut 10 → 2 alerts; second cut 2 → 1 is within the 24-hour dedupe
        $this->assertStringContainsString('(2 yard)', DB::table('notifications')->where(['type' => 'low_stock', 'user_id' => 1])->where('message', 'like', "{$name} stock (%")->value('message'));
    }
}
