<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Stock-out / wastage guard. LOCAL database only (rolled back per test); the material is created inside the transaction.
//   DB_CONNECTION=mysql DB_DATABASE=vfrb_test php artisan test --filter InventoryStockOutTest
class InventoryStockOutTest extends TestCase
{
    use DatabaseTransactions;

    private int $materialId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertNotSame('pgsql', DB::connection()->getDriverName(), 'Point DB_* at the local vfrb_test database.');
        Sanctum::actingAs(User::findOrFail(1), ['*']);

        $this->materialId = DB::table('materials')->insertGetId([
            'material_code' => uniqid('TST-'), 'material_name' => 'Test Thread', 'category' => 'thread', 'unit' => 'cone',
            'quantity_in_stock' => 5, 'reorder_threshold' => 1, 'unit_cost' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ], 'material_id');
    }

    private function stock(): float
    {
        return (float) DB::table('materials')->where('material_id', $this->materialId)->value('quantity_in_stock');
    }

    private function logCount(): int
    {
        return DB::table('inventory_logs')->where('material_id', $this->materialId)->count();
    }

    public function test_stock_out_larger_than_the_stock_is_refused_and_writes_nothing(): void
    {
        $this->postJson("/api/admin/materials/{$this->materialId}/stock-out", ['quantity' => 6, 'reason' => 'demo'])
            ->assertStatus(422)
            ->assertJson(['message' => 'Only 5 cone in stock; cannot remove 6.', 'quantity_in_stock' => 5]);

        $this->assertSame(5.0, $this->stock());
        $this->assertSame(0, $this->logCount());
    }

    public function test_wastage_larger_than_the_stock_is_refused(): void
    {
        $this->postJson("/api/admin/materials/{$this->materialId}/adjust", ['type' => 'wastage', 'quantity' => 5.5])
            ->assertStatus(422);

        $this->assertSame(5.0, $this->stock());
        $this->assertSame(0, $this->logCount());
    }

    public function test_stock_out_within_the_stock_is_recorded_once_with_a_negative_change(): void
    {
        $this->postJson('/api/admin/inventory/stock-out', ['material_id' => $this->materialId, 'quantity' => 5, 'reason' => 'demo'])
            ->assertOk()
            ->assertJson(['quantity_in_stock' => 0, 'low_stock' => true]);

        $this->assertSame(0.0, $this->stock());
        $this->assertSame(1, $this->logCount());
        $this->assertEquals(-5, DB::table('inventory_logs')->where('material_id', $this->materialId)->value('change_qty'));
    }
}
