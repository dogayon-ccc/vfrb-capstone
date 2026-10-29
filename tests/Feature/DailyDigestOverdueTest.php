<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Services\ClientMaterialService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Deadline reminders, overdue flags and client-return reminders from vfrb:daily-digest. LOCAL database only (rolled back per test).
class DailyDigestOverdueTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertNotSame('pgsql', DB::connection()->getDriverName(), 'Point DB_* at the local vfrb_test database.');
    }

    private function order(string $status, string $promised): int
    {
        return DB::table('orders')->insertGetId([
            'user_id' => 5, 'garment_type' => 'Polo Shirt', 'color' => 'Navy', 'quantity_ordered' => 100, 'order_type' => 'direct',
            'status' => $status, 'promised_date' => $promised, 'target_delivery_date' => now()->addMonths(2)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ], 'order_id');
    }

    public function test_late_orders_and_overdue_invoices_are_flagged_once(): void
    {
        $late = $this->order('sewing', now()->subDays(2)->toDateString());
        $billed = $this->order('completed', now()->subDays(20)->toDateString());
        DB::table('invoices')->insert([
            'order_id' => $billed, 'invoice_number' => 'INV-T-'.uniqid(), 'status' => 'issued', 'billed_to_name' => 'Test School', 'total_amount' => 1000,
            'due_date' => now()->subDay()->toDateString(), 'issued_at' => now(), 'issued_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('vfrb:daily-digest')->assertSuccessful();
        $this->artisan('vfrb:daily-digest')->assertSuccessful();

        $this->assertSame(1, DB::table('notifications')->where('type', 'order_overdue')->where('order_id', $late)->where('user_id', 1)->count());
        $this->assertSame(1, DB::table('notifications')->where('type', 'invoice_overdue')->where('order_id', $billed)->where('user_id', 1)->count());
        $this->assertFalse(DB::table('notifications')->where('type', 'order_overdue')->where('order_id', $billed)->exists());
    }

    public function test_deadline_reminder_uses_the_agreed_date_and_hides_the_stage_from_the_customer(): void
    {
        $id = $this->order('sewing', now()->addDays(3)->toDateString());

        $this->artisan('vfrb:daily-digest')->assertSuccessful();

        $message = DB::table('notifications')->where('type', 'deadline_reminder')->where('order_id', $id)->where('user_id', 5)->value('message');
        $this->assertStringContainsString('In Production', $message);
        $this->assertStringNotContainsStringIgnoringCase('sewing', $message);
    }

    public function test_a_cancelled_order_holding_client_material_gets_a_return_reminder(): void
    {
        if (! ClientMaterialService::ready()) {
            $this->markTestSkipped('Run 2026_10_21_000001_add_client_material_ledger on vfrb_test first.');
        }
        $material = Material::create([
            'material_code' => Material::nextCode('Fabric'), 'material_name' => 'Client Fabric '.uniqid(), 'category' => 'Fabric',
            'unit' => 'yard', 'quantity_in_stock' => 0, 'reorder_threshold' => 0, 'unit_cost' => 0,
        ]);
        DB::table('materials')->where('material_id', $material->material_id)->update(['ownership' => 'client']);
        $id = $this->order('cancelled', now()->addDays(30)->toDateString());
        (new ClientMaterialService)->insert($id, $material->material_id, 'received', 40, null, 1);

        $this->artisan('vfrb:daily-digest')->assertSuccessful();

        $message = DB::table('notifications')->where('type', 'client_return')->value('message');
        $this->assertStringContainsString("#{$id}", $message);
        $this->assertStringContainsString('40 yard', $message);
    }
}
