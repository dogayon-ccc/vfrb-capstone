<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\UploadDisk;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Complete-set rule at the order boundary: the server trusts only its own reference_validations row.
class SetOrderTest extends TestCase
{
    use DatabaseTransactions;

    private const BYTES = "\x89PNG\r\n\x1a\n0000";

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('approved_set_pairs')->insertOrIgnore(['top_garment' => 'Scrub Top', 'bottom_garment' => 'Scrub Pants', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function user(string $role): User
    {
        $u = User::create(['name' => 'Set Test', 'email' => uniqid('set').'@example.test', 'password' => bcrypt('secret-pass')]);
        $u->forceFill(['email_verified_at' => now()])->save();
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $u->assignRole($role);

        return $u;
    }

    private function validation(User $u, string $status, array $over = []): int
    {
        Storage::fake(UploadDisk::name());
        $path = 'design-refs/test-'.uniqid().'.png';
        Storage::disk(UploadDisk::name())->put($path, self::BYTES);

        return DB::table('reference_validations')->insertGetId($over + [
            'user_id' => $u->getKey(), 'path' => $path, 'sha256' => hash('sha256', self::BYTES), 'status' => $status, 'reason' => 'test',
            'top_garment' => 'Scrub Top', 'bottom_garment' => 'Scrub Pants', 'prompt_version' => 'set-2', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function body(int $validationId, int $top = 100, int $bottom = 100): array
    {
        return ['reference_validation_id' => $validationId, 'size_matrix' => json_encode(['top' => ['M' => $top], 'bottom' => ['L' => $bottom]])];
    }

    public function test_accepted_reference_creates_one_order_with_two_matrices(): void
    {
        $u = $this->user('customer');
        $id = $this->validation($u, 'accepted');
        $res = $this->actingAs($u, 'sanctum')->postJson('/api/customer/orders', $this->body($id))->assertCreated();
        $orderId = $res->json('order.order_id');
        $this->assertSame(100, (int) $res->json('order.quantity_ordered'));
        $this->assertSame(2, DB::table('measurements')->where('order_id', $orderId)->count());
        $this->assertSame(['top', 'bottom'], DB::table('measurements')->where('order_id', $orderId)->orderBy('measurement_id')->pluck('piece')->all());
        $this->assertSame($orderId, (int) DB::table('reference_validations')->where('id', $id)->value('order_id'));
        $this->getJson('/api/customer/orders/'.$orderId)->assertOk()->assertJsonPath('order.size_matrix.top.M', 100);
    }

    public function test_held_rejected_and_forged_references_cannot_become_orders(): void
    {
        $u = $this->user('customer');
        foreach (['needs_review', 'rejected'] as $status) {
            $this->actingAs($u, 'sanctum')->postJson('/api/customer/orders', $this->body($this->validation($u, $status)))->assertStatus(422);
        }
        $this->actingAs($u, 'sanctum')->postJson('/api/customer/orders', $this->body($this->validation($u, 'needs_review')) + ['verdict' => 'accepted', 'garments' => ['Scrub Top', 'Pants']])->assertStatus(422);
        $this->assertSame(0, DB::table('orders')->where('user_id', $u->getKey())->count());
    }

    public function test_another_customers_validation_and_a_replaced_file_are_refused(): void
    {
        $owner = $this->user('customer');
        $other = $this->user('customer');
        $id = $this->validation($owner, 'accepted');
        $this->actingAs($other, 'sanctum')->postJson('/api/customer/orders', $this->body($id))->assertStatus(422);

        $path = DB::table('reference_validations')->where('id', $id)->value('path');
        Storage::disk(UploadDisk::name())->put($path, self::BYTES.'tampered');
        $this->actingAs($owner, 'sanctum')->postJson('/api/customer/orders', $this->body($id))->assertStatus(422);
    }

    public function test_matrix_rules_and_single_use_are_enforced_by_the_server(): void
    {
        $u = $this->user('customer');
        $id = $this->validation($u, 'accepted');
        $this->actingAs($u, 'sanctum');
        $this->postJson('/api/customer/orders', $this->body($id, 99, 99))->assertStatus(422);
        $this->postJson('/api/customer/orders', $this->body($id, 105, 100))->assertStatus(422);
        $this->postJson('/api/customer/orders', ['reference_validation_id' => $id])->assertStatus(422);
        $this->postJson('/api/customer/orders', $this->body($id))->assertCreated();
        $this->postJson('/api/customer/orders', $this->body($id))->assertStatus(422);
    }

    public function test_only_a_manager_can_release_a_held_reference(): void
    {
        $u = $this->user('customer');
        $id = $this->validation($u, 'needs_review');
        $this->actingAs($u, 'sanctum')->postJson("/api/admin/reference-validations/{$id}/decision", ['decision' => 'approve'])->assertForbidden();

        $m = $this->user('manager');
        $this->actingAs($m, 'sanctum')->postJson("/api/admin/reference-validations/{$id}/decision", ['decision' => 'approve'])->assertStatus(422);
        $this->postJson("/api/admin/reference-validations/{$id}/decision", ['decision' => 'approve', 'top_garment' => 'Scrub Top', 'bottom_garment' => 'Scrub Pants'])->assertOk();
        $this->actingAs($u, 'sanctum')->postJson('/api/customer/orders', $this->body($id))->assertCreated();
    }
}
