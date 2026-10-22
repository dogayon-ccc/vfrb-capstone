<?php

namespace Tests\Feature;

use App\Models\Material;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Digest auto-RFQs. LOCAL database only (rolled back per test).
class DailyDigestRfqTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertNotSame('pgsql', DB::connection()->getDriverName(), 'Point DB_* at the local vfrb_test database.');
    }

    private function material(string $name, float $stock, float $threshold): Material
    {
        return Material::create([
            'material_code' => Material::nextCode('Thread'), 'material_name' => $name, 'category' => 'Thread',
            'unit' => 'cone', 'quantity_in_stock' => $stock, 'reorder_threshold' => $threshold, 'unit_cost' => 50,
        ]);
    }

    public function test_auto_rfq_asks_for_the_threshold_gap_and_skips_materials_without_one(): void
    {
        $low = $this->material('Test Thread Low', 3, 10);
        $atThreshold = $this->material('Test Thread At Threshold', 10, 10);

        $this->artisan('vfrb:daily-digest')->assertSuccessful();

        $rfq = DB::table('rfq_requests')->where('material_id', $low->material_id)->first();
        $this->assertSame([7.0, 'open', 1], [(float) $rfq->qty_needed, $rfq->status, (int) $rfq->auto_generated]);
        $this->assertSame(0, DB::table('rfq_requests')->where('material_id', $atThreshold->material_id)->count());
    }

    public function test_material_with_an_open_rfq_gets_no_second_one(): void
    {
        $low = $this->material('Test Thread Low', 3, 10);

        $this->artisan('vfrb:daily-digest')->assertSuccessful();
        $this->artisan('vfrb:daily-digest')->assertSuccessful();

        $this->assertSame(1, DB::table('rfq_requests')->where('material_id', $low->material_id)->count());
    }
}
