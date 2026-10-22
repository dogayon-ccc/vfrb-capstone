<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Stock warnings on confirm. LOCAL database only (rolled back per test). Users: 1 = manager, 5 = customer.
class MaterialShortageTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertNotSame('pgsql', DB::connection()->getDriverName(), 'Point DB_* at the local vfrb_test database.');
    }

    private function actAs(int $userId): void
    {
        Sanctum::actingAs(User::findOrFail($userId), ['*']);
    }

    private function order(array $over = []): int
    {
        return DB::table('orders')->insertGetId(array_merge([
            'user_id' => 5, 'garment_type' => 'Polo Shirt', 'color' => 'Navy', 'quantity_ordered' => 100,
            'order_type' => 'direct', 'status' => 'pending', 'qc_required' => 0, 'created_at' => now(), 'updated_at' => now(),
            'promised_date' => now()->addDays(14)->toDateString(),
        ], $over), 'order_id');
    }

    private function recommend(int $orderId, string $name, float $stock, float $threshold, string $status = 'pending'): Material
    {
        $m = Material::create([
            'material_code' => Material::nextCode('Fabric'), 'material_name' => $name, 'category' => 'Fabric',
            'unit' => 'yard', 'quantity_in_stock' => $stock, 'reorder_threshold' => $threshold, 'unit_cost' => 100,
        ]);
        DB::table('material_recommendations')->insert([
            'order_id' => $orderId, 'material_id' => $m->material_id, 'material_name' => $name, 'category' => 'Fabric',
            'unit' => 'yard', 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $m;
    }

    public function test_manager_confirm_lists_only_short_recommended_materials(): void
    {
        $id = $this->order();
        $out = $this->recommend($id, 'Test Pique Out', 0, 10);
        $low = $this->recommend($id, 'Test Pique Low', 5, 10);
        $this->recommend($id, 'Test Pique Plenty', 50, 10);
        $this->recommend($id, 'Test Pique Rejected', 0, 10, 'rejected');

        $this->actAs(1);
        $warnings = $this->patchJson("/api/admin/orders/{$id}", ['status' => 'confirmed'])->assertOk()->json('material_warnings');

        $this->assertEqualsCanonicalizing([
            ['material_id' => $out->material_id, 'material_name' => 'Test Pique Out', 'category' => 'Fabric', 'reason' => 'out_of_stock'],
            ['material_id' => $low->material_id, 'material_name' => 'Test Pique Low', 'category' => 'Fabric', 'reason' => 'low_stock'],
        ], $warnings);
    }

    public function test_quick_confirm_returns_warnings_too(): void
    {
        $id = $this->order();
        $this->recommend($id, 'Test Pique Out', 0, 10);

        $this->actAs(1);
        $this->patchJson("/api/admin/orders/{$id}/confirm")->assertOk()
            ->assertJsonPath('material_warnings.0.material_name', 'Test Pique Out');
    }

    public function test_customer_accept_hides_warnings_and_tells_managers(): void
    {
        $id = $this->order(['proposal_status' => 'pending', 'agreed_total' => 50000]);
        $this->recommend($id, 'Test Pique Out', 0, 10);

        $this->actAs(5);
        $res = $this->postJson("/api/customer/orders/{$id}/accept")->assertOk();

        $this->assertArrayNotHasKey('material_warnings', $res->json());
        $this->assertStringNotContainsString('Test Pique Out', $res->getContent());
        $this->assertTrue(DB::table('notifications')->where('order_id', $id)->where('user_id', 1)
            ->where('message', 'like', '%Test Pique Out (out of stock)%')->exists());
        $this->assertFalse(DB::table('notifications')->where('order_id', $id)->where('user_id', 5)
            ->where('message', 'like', '%Test Pique%')->exists());
    }
}
