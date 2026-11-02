<?php

namespace Tests\Feature;

use App\Console\Commands\VfrbDailyDigest;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// HTTP cron endpoint (serverless stand-in for schedule:run). LOCAL database only (rolled back per test). Users: 1 = manager, 5 = customer.
class AutomationCronTest extends TestCase
{
    use DatabaseTransactions;

    private const SECRET = 'test-cron-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertNotSame('pgsql', DB::connection()->getDriverName(), 'Point DB_* at the local vfrb_test database.');
        config(['services.cron.secret' => self::SECRET]);
    }

    private function cron(string $secret = self::SECRET)
    {
        return $this->withToken($secret)->getJson('/api/cron/run');
    }

    private function order(string $status, string $promised): int
    {
        return DB::table('orders')->insertGetId([
            'user_id' => 5, 'garment_type' => 'Polo Shirt', 'color' => 'Navy', 'quantity_ordered' => 100, 'order_type' => 'direct',
            'status' => $status, 'promised_date' => $promised, 'target_delivery_date' => $promised,
            'down_payment_received_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], 'order_id');
    }

    private function automationRows(): array
    {
        return [
            DB::table('notifications')->count(),
            DB::table('rfq_requests')->count(),
            DB::table('kpi_daily_snapshots')->count(),
        ];
    }

    public function test_missing_wrong_or_unset_secret_is_401_and_runs_nothing(): void
    {
        $before = $this->automationRows();

        $this->getJson('/api/cron/run')->assertUnauthorized();
        $this->cron('wrong-secret')->assertUnauthorized();
        $this->withHeader('X-Cron-Secret', self::SECRET)->postJson('/api/cron/run')->assertUnauthorized();
        config(['services.cron.secret' => null]);
        $this->cron('')->assertUnauthorized();

        $this->assertSame($before, $this->automationRows());
    }

    public function test_only_jobs_whose_time_has_passed_run(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 06:30'));
        $this->cron()->assertOk()->assertExactJson(['message' => 'Cron run complete.', 'ran' => [], 'skipped' => []]);

        $this->travelTo(Carbon::parse('2026-11-10 08:00'));
        $this->assertSame(['vfrb:daily-digest'], array_keys($this->cron()->assertOk()->json('ran')));

        $this->travelTo(Carbon::parse('2026-11-10 23:58'));
        $this->assertSame(['vfrb:daily-digest', 'vfrb:kpi-snapshot'], array_keys($this->cron()->assertOk()->json('ran')));
        $this->assertSame(1, DB::table('kpi_daily_snapshots')->whereDate('snapshot_date', '2026-11-10')->count());
    }

    public function test_running_twice_adds_no_notifications_rfqs_or_snapshots(): void
    {
        // Monday late evening: every digest section and the KPI snapshot are due.
        $this->travelTo(Carbon::parse('2026-11-09 23:58'));
        DB::table('materials')->insert([
            'material_code' => uniqid('TST-'), 'material_name' => 'Cron Test Thread', 'category' => 'Thread', 'unit' => 'cone',
            'quantity_in_stock' => 1, 'reorder_threshold' => 10, 'unit_cost' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $near = $this->order('sewing', now()->addDays(3)->toDateString());
        $late = $this->order('for_delivery', now()->subDays(2)->toDateString());

        $this->cron()->assertOk();
        $after = $this->automationRows();
        $this->cron()->assertOk();

        $this->assertSame($after, $this->automationRows());
        foreach (['daily_digest', 'weekly_count'] as $type) {
            $this->assertSame(1, DB::table('notifications')->where('type', $type)->where('user_id', 1)->whereDate('date_sent', today())->count(), $type);
        }
        $this->assertSame(1, DB::table('notifications')->where(['type' => 'deadline_reminder', 'order_id' => $near, 'user_id' => 1])->count());
        $this->assertSame(1, DB::table('notifications')->where(['type' => 'order_overdue', 'order_id' => $late, 'user_id' => 1])->count());
        $this->assertStringContainsString('still at For Delivery.', DB::table('notifications')->where(['type' => 'order_overdue', 'order_id' => $late])->value('message'));
        $this->assertStringContainsString('still in Sewing.', DB::table('notifications')->where(['type' => 'deadline_reminder', 'order_id' => $near, 'user_id' => 1])->value('message'));
    }

    public function test_an_overlapping_run_is_refused(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 08:00'));
        $lock = Cache::lock('vfrb:cron-run', 300);
        $lock->get();
        $before = $this->automationRows();

        $this->cron()->assertStatus(409);

        $this->assertSame($before, $this->automationRows());
        $lock->release();
    }

    // A digest already running elsewhere: the holder of VfrbDailyDigest::LOCK.
    private function holdDigestLock(): \Illuminate\Contracts\Cache\Lock
    {
        $lock = Cache::lock(VfrbDailyDigest::LOCK, VfrbDailyDigest::LOCK_SECONDS);
        $this->assertTrue($lock->get());

        return $lock;
    }

    public function test_run_now_while_a_digest_is_running_is_skipped_and_writes_nothing(): void
    {
        Sanctum::actingAs(User::findOrFail(1), ['*']);
        $lock = $this->holdDigestLock();
        $before = $this->automationRows();

        $this->postJson('/api/admin/automation/run-digest')->assertOk()
            ->assertJson(['message' => 'Automation is already running. Try again in a minute.', 'skipped' => true, 'summary' => null]);

        $this->assertSame($before, $this->automationRows());
        $lock->release();
        $this->postJson('/api/admin/automation/run-digest')->assertOk()->assertJson(['message' => 'Automation run complete.', 'skipped' => false]);
    }

    public function test_run_now_releases_the_lock_so_the_next_click_and_cron_still_run(): void
    {
        Sanctum::actingAs(User::findOrFail(1), ['*']);
        $this->travelTo(Carbon::parse('2026-11-10 08:00'));

        $this->postJson('/api/admin/automation/run-digest')->assertOk()->assertJson(['skipped' => false]);
        $this->postJson('/api/admin/automation/run-digest')->assertOk()->assertJson(['skipped' => false]);
        $this->cron()->assertOk()->assertJson(['skipped' => []]);
        $this->assertTrue(Cache::lock(VfrbDailyDigest::LOCK, 1)->get());
    }

    public function test_run_now_reports_what_it_sent_and_zero_on_a_repeat(): void
    {
        Sanctum::actingAs(User::findOrFail(1), ['*']);
        $this->travelTo(Carbon::parse('2026-11-10 08:00'));
        DB::table('materials')->insert([
            'material_code' => uniqid('TST-'), 'material_name' => 'Run Now Thread', 'category' => 'Thread', 'unit' => 'cone',
            'quantity_in_stock' => 1, 'reorder_threshold' => 10, 'unit_cost' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $first = $this->postJson('/api/admin/automation/run-digest')->assertOk()->json('summary');
        $repeat = $this->postJson('/api/admin/automation/run-digest')->assertOk()->json('summary');

        $this->assertSame(VfrbDailyDigest::SUMMARY_KEYS, array_keys($first));
        $this->assertGreaterThanOrEqual(1, $first['low_stock_digest']);
        $this->assertGreaterThanOrEqual(1, $first['rfqs_auto_created']);
        $this->assertSame(array_fill_keys(VfrbDailyDigest::SUMMARY_KEYS, 0), $repeat);
    }

    public function test_cron_skips_a_digest_that_run_now_is_running_but_still_runs_the_other_jobs(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 23:58'));
        $lock = $this->holdDigestLock();

        $response = $this->cron()->assertOk()->assertJson(['skipped' => ['vfrb:daily-digest']]);

        $this->assertSame(['vfrb:kpi-snapshot'], array_keys($response->json('ran')));
        $this->assertSame(1, DB::table('kpi_daily_snapshots')->whereDate('snapshot_date', '2026-11-10')->count());
        $lock->release();
    }

    public function test_the_cli_command_exits_busy_while_another_run_holds_the_lock(): void
    {
        $lock = $this->holdDigestLock();

        $this->artisan('vfrb:daily-digest')->assertExitCode(VfrbDailyDigest::BUSY);

        $lock->release();
        $this->artisan('vfrb:daily-digest')->assertSuccessful();
    }

    public function test_kpi_snapshot_command_writes_one_row_per_day(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 23:55'));

        $this->artisan('vfrb:kpi-snapshot')->assertSuccessful();
        $this->artisan('vfrb:kpi-snapshot')->assertSuccessful();

        $this->assertSame(1, DB::table('kpi_daily_snapshots')->whereDate('snapshot_date', '2026-11-10')->count());
    }
}
