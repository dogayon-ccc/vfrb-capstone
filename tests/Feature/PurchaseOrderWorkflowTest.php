<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\User;
use App\Services\ProductionStageService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Goods receipt, the colour hold on cutting, count reconciliation, and auto-RFQs that count stock on order. LOCAL database only.
class PurchaseOrderWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertNotSame('pgsql', DB::connection()->getDriverName(), 'Point DB_* at the local vfrb_test database.');
        Sanctum::actingAs(User::findOrFail(1), ['*']);
    }

    private function material(float $stock, float $threshold): Material
    {
        return Material::create([
            'material_code' => Material::nextCode('Thread'), 'material_name' => 'PO Test Thread '.uniqid(), 'category' => 'Thread',
            'unit' => 'cone', 'quantity_in_stock' => $stock, 'reorder_threshold' => $threshold, 'unit_cost' => 50,
        ]);
    }

    private function po(Material $m, float $qty, array $over = []): int
    {
        $supplierId = DB::table('suppliers')->value('supplier_id');

        return $this->postJson('/api/admin/purchase-orders', array_merge([
            'supplier_id' => $supplierId, 'items' => [['material_id' => $m->material_id, 'qty' => $qty, 'unit_cost' => 50]],
        ], $over))->assertCreated()->json('po_id');
    }

    public function test_a_po_is_received_once_even_if_submitted_twice(): void
    {
        $m = $this->material(5, 10);
        $id = $this->po($m, 20);

        $this->patchJson("/api/admin/purchase-orders/{$id}/receive")->assertOk();
        $this->patchJson("/api/admin/purchase-orders/{$id}/receive")->assertStatus(422);

        $this->assertEquals(25, (float) $m->fresh()->quantity_in_stock);
    }

    public function test_a_colour_mismatch_holds_cutting_until_a_manager_confirms(): void
    {
        $orderId = DB::table('orders')->insertGetId([
            'user_id' => 5, 'garment_type' => 'Polo Shirt', 'color' => 'Navy', 'quantity_ordered' => 100, 'order_type' => 'direct',
            'status' => 'cutting', 'created_at' => now(), 'updated_at' => now(),
        ], 'order_id');
        $id = $this->po($this->material(5, 10), 20, ['order_id' => $orderId, 'order_color_hex' => '#1B2A4A']);

        $this->patchJson("/api/admin/purchase-orders/{$id}/receive", ['received_color_hex' => '#FF0000'])->assertOk()->assertJson(['color_mismatch' => true]);
        $this->assertNotNull(ProductionStageService::colorHold($orderId));

        $this->patchJson("/api/admin/purchase-orders/{$id}/confirm-color", ['color_notes' => 'Client approved the shade'])->assertOk();
        $this->assertNull(ProductionStageService::colorHold($orderId));
    }

    public function test_reconciling_a_count_keeps_stock_received_after_the_count(): void
    {
        $m = $this->material(10, 5);
        $countId = $this->postJson('/api/admin/physical-counts', ['material_id' => $m->material_id, 'counted_qty' => 8])->assertCreated()->json('count_id');
        $this->patchJson('/api/admin/purchase-orders/'.$this->po($m, 20).'/receive')->assertOk();

        $this->patchJson("/api/admin/physical-counts/{$countId}/reconcile")->assertOk()->assertJson(['new_qty' => 28]);
        $this->patchJson("/api/admin/physical-counts/{$countId}/reconcile")->assertStatus(409);
        $this->assertEquals(28, (float) $m->fresh()->quantity_in_stock);
    }

    public function test_auto_rfq_skips_a_gap_already_covered_by_an_open_po(): void
    {
        $covered = $this->material(3, 10);
        $partly = $this->material(3, 10);
        $this->po($covered, 7);
        $this->po($partly, 4);

        $this->artisan('vfrb:daily-digest')->assertSuccessful();

        $this->assertSame(0, DB::table('rfq_requests')->where('material_id', $covered->material_id)->count());
        $this->assertEquals(3, (float) DB::table('rfq_requests')->where('material_id', $partly->material_id)->value('qty_needed'));
    }
}
