<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Runs against the vfrb_db.sql clone (see SecurityGateTest): users 5/6 are customers.
class DesignLockTest extends TestCase
{
    use DatabaseTransactions;

    private function designOnOrder(int $userId, string $status, ?string $proposalStatus = null): int
    {
        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $userId, 'garment_type' => 'Polo Shirt', 'quantity_ordered' => 100, 'status' => $status,
            'proposal_status' => $proposalStatus, 'created_at' => now(), 'updated_at' => now(),
        ], 'order_id');
        $designId = DB::table('designs')->insertGetId([
            'source_order_id' => $orderId, 'design_name' => 'lock-test', 'garment_type' => 'Polo Shirt',
            'custom_builder_config' => json_encode(['garment' => 'polo']), 'created_at' => now(), 'updated_at' => now(),
        ], 'design_id');
        DB::table('orders')->where('order_id', $orderId)->update(['design_id' => $designId]);

        return $designId;
    }

    public function test_test_database_has_proposal_status_column(): void
    {
        $this->assertTrue(Schema::hasColumn('orders', 'proposal_status'));
    }

    public function test_design_on_confirmed_order_cannot_be_edited_or_deleted(): void
    {
        $design = $this->designOnOrder(5, 'confirmed');
        Sanctum::actingAs(User::findOrFail(5), ['*']);

        $this->putJson("/api/customer/designs/{$design}", ['design_name' => 'changed'])->assertStatus(422)->assertJson(['locked' => true]);
        $this->deleteJson("/api/customer/designs/{$design}")->assertStatus(422)->assertJson(['locked' => true]);
        $this->assertDatabaseHas('designs', ['design_id' => $design, 'design_name' => 'lock-test', 'is_active' => 1]);
    }

    public function test_design_with_accepted_proposal_is_locked_while_order_is_pending(): void
    {
        $design = $this->designOnOrder(5, 'pending', 'accepted');
        Sanctum::actingAs(User::findOrFail(5), ['*']);

        $this->putJson("/api/customer/designs/{$design}", ['design_name' => 'changed'])->assertStatus(422);
        $this->deleteJson("/api/customer/designs/{$design}")->assertStatus(422);
    }

    public function test_locked_design_can_be_duplicated_into_an_editable_copy(): void
    {
        $design = $this->designOnOrder(5, 'sewing');
        Sanctum::actingAs(User::findOrFail(5), ['*']);

        $copy = $this->postJson("/api/customer/designs/{$design}/copy")->assertCreated()
            ->assertJson(['label' => 'Copy of lock-test', 'config' => ['garment' => 'polo']])->json('id');

        $this->assertNotSame($design, $copy);
        $this->assertDatabaseHas('designs', ['design_id' => $copy, 'parent_design_id' => $design, 'is_active' => 1, 'showcase_status' => 'none']);
        $this->putJson("/api/customer/designs/{$copy}", ['design_name' => 'my edit'])->assertOk();
        $list = collect($this->getJson('/api/customer/designs')->assertOk()->json())->keyBy('id');
        $this->assertTrue($list[$copy]['is_copy']);
        $this->assertFalse($list[$copy]['locked']);
        $this->assertTrue($list[$design]['locked']);
        $this->assertDatabaseHas('designs', ['design_id' => $design, 'design_name' => 'lock-test']);
    }

    public function test_another_customer_cannot_duplicate_the_design(): void
    {
        $design = $this->designOnOrder(5, 'confirmed');
        Sanctum::actingAs(User::findOrFail(6), ['*']);
        $before = DB::table('designs')->count();

        $this->postJson("/api/customer/designs/{$design}/copy")->assertNotFound();
        $this->assertSame($before, DB::table('designs')->count());
    }
}
