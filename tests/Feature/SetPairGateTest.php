<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\UploadDisk;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// No new order without a validated, approved top+bottom set; the pair list is the only source of compatibility.
class SetPairGateTest extends TestCase
{
    use DatabaseTransactions;

    private const BYTES = "\x89PNG\r\n\x1a\n0000";

    private function user(string $role): User
    {
        $u = User::create(['name' => 'Pair Test', 'email' => uniqid('pair').'@example.test', 'password' => bcrypt('secret-pass')]);
        $u->forceFill(['email_verified_at' => now()])->save();
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $u->assignRole($role);

        return $u;
    }

    private function pair(string $top = 'Scrub Top', string $bottom = 'Scrub Pants'): void
    {
        DB::table('approved_set_pairs')->insertOrIgnore(['top_garment' => $top, 'bottom_garment' => $bottom, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function validation(User $u, string $status, string $top = 'Scrub Top', string $bottom = 'Scrub Pants'): int
    {
        Storage::fake(UploadDisk::name());
        $path = 'design-refs/pair-'.uniqid().'.png';
        Storage::disk(UploadDisk::name())->put($path, self::BYTES);

        return DB::table('reference_validations')->insertGetId([
            'user_id' => $u->getKey(), 'path' => $path, 'sha256' => hash('sha256', self::BYTES), 'status' => $status, 'reason' => 'test',
            'top_garment' => $top, 'bottom_garment' => $bottom, 'prompt_version' => 'set-2', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function body(int $id): array
    {
        return ['reference_validation_id' => $id, 'size_matrix' => json_encode(['top' => ['M' => 100], 'bottom' => ['L' => 100]])];
    }

    public function test_the_old_single_piece_path_is_closed_for_every_customer_request(): void
    {
        $u = $this->user('customer');
        $legacy = ['garment_type' => 'Scrub Top', 'quantity_ordered' => 150, 'client_design_notes' => 'Navy scrub top, white piping', 'studio_config' => '{"garment":"Scrub Top"}'];
        $this->actingAs($u, 'sanctum')->postJson('/api/customer/orders', $legacy)->assertStatus(422);
        $this->postJson('/api/customer/orders', $legacy + ['verdict' => 'accepted', 'sizes' => '{"M":150}'])->assertStatus(422);
        $this->assertSame(0, DB::table('orders')->where('user_id', $u->getKey())->count());
    }

    public function test_an_accepted_verdict_for_an_unapproved_pair_cannot_become_an_order(): void
    {
        $u = $this->user('customer');
        $id = $this->validation($u, 'accepted', 'Polo Shirt', 'Shorts');
        $this->actingAs($u, 'sanctum')->postJson('/api/customer/orders', $this->body($id))->assertStatus(422);
        $this->assertSame(0, DB::table('orders')->where('user_id', $u->getKey())->count());
        $this->pair('Polo Shirt', 'Shorts');
        $this->postJson('/api/customer/orders', $this->body($id))->assertCreated();
        $this->assertSame(1, DB::table('orders')->where('user_id', $u->getKey())->count());
    }

    public function test_a_manager_cannot_approve_a_pair_vfrb_has_not_approved(): void
    {
        $u = $this->user('customer');
        $id = $this->validation($u, 'needs_review', 'Blazer', 'Skirt');
        $m = $this->user('manager');
        $decide = ['decision' => 'approve', 'top_garment' => 'Blazer', 'bottom_garment' => 'Skirt'];
        $this->actingAs($m, 'sanctum')->postJson("/api/admin/reference-validations/{$id}/decision", $decide)->assertStatus(422);
        $this->assertSame('needs_review', DB::table('reference_validations')->where('id', $id)->value('status'));
        $this->postJson('/api/admin/reference-validations/'.$id.'/decision', ['decision' => 'approve', 'top_garment' => 'Blazer', 'bottom_garment' => 'T-Shirt'])->assertStatus(422);
        $this->postJson('/api/admin/set-pairs', ['top_garment' => 'Blazer', 'bottom_garment' => 'Skirt'])->assertCreated();
        $this->postJson("/api/admin/reference-validations/{$id}/decision", $decide)->assertOk();
    }

    public function test_only_a_manager_edits_the_pair_list_and_names_must_be_on_the_allowlist(): void
    {
        $c = $this->user('customer');
        $this->actingAs($c, 'sanctum')->postJson('/api/admin/set-pairs', ['top_garment' => 'Blazer', 'bottom_garment' => 'Skirt'])->assertForbidden();
        $this->getJson('/api/admin/set-pairs')->assertForbidden();

        $m = $this->user('manager');
        $this->actingAs($m, 'sanctum')->postJson('/api/admin/set-pairs', ['top_garment' => 'Dress', 'bottom_garment' => 'Skirt'])->assertStatus(422);
        $this->postJson('/api/admin/set-pairs', ['top_garment' => 'Blazer', 'bottom_garment' => 'Blazer'])->assertStatus(422);
        $this->postJson('/api/admin/set-pairs', ['top_garment' => 'Blazer', 'bottom_garment' => 'Skirt'])->assertCreated();
        $this->getJson('/api/admin/set-pairs')->assertOk()->assertJsonFragment(['top_garment' => 'Blazer', 'bottom_garment' => 'Skirt']);
    }

    public function test_a_held_upload_can_be_resumed_after_staff_decide_and_only_by_its_owner(): void
    {
        $u = $this->user('customer');
        $id = $this->validation($u, 'needs_review');
        $this->pair();
        $this->actingAs($u, 'sanctum')->getJson('/api/customer/reference-validations')->assertOk()
            ->assertJsonPath('0.id', $id)->assertJsonPath('0.orderable', false)->assertJsonMissingPath('0.path');

        $m = $this->user('manager');
        $this->actingAs($m, 'sanctum')->postJson("/api/admin/reference-validations/{$id}/decision", ['decision' => 'approve', 'top_garment' => 'Scrub Top', 'bottom_garment' => 'Scrub Pants'])->assertOk();
        $this->actingAs($u, 'sanctum')->getJson('/api/customer/reference-validations')->assertJsonPath('0.orderable', true);
        $this->actingAs($this->user('customer'), 'sanctum')->getJson('/api/customer/reference-validations')->assertOk()->assertJsonCount(0);
    }

    public function test_one_set_order_is_one_parent_order_with_no_second_payable_row(): void
    {
        $u = $this->user('customer');
        $this->pair();
        $id = $this->validation($u, 'accepted');
        $res = $this->actingAs($u, 'sanctum')->postJson('/api/customer/orders', $this->body($id))->assertCreated();
        $orderId = $res->json('order.order_id');
        $this->assertSame(1, DB::table('orders')->where('user_id', $u->getKey())->count());
        $this->assertSame(0, DB::table('sales_transactions')->where('order_id', $orderId)->count());
        $this->assertSame(['bottom', 'top'], DB::table('measurements')->where('order_id', $orderId)->distinct()->orderBy('piece')->pluck('piece')->all());
    }

    public function test_upload_goes_to_staff_review_when_the_pair_is_not_approved_and_is_accepted_when_it_is(): void
    {
        config(['services.gemini.key_1' => 'test-key', 'services.gemini.key_2' => null, 'services.gemini.key_3' => null, 'services.gemini.fallback_model' => '']);
        $reply = ['is_uniform_reference' => 'yes', 'garments' => ['Scrub Top', 'Scrub Pants'], 'colors' => ['navy'], 'unknown' => []];
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($reply)]]]]]], 200)]);
        Storage::fake(UploadDisk::name());
        $u = $this->user('customer');
        $file = fn () => UploadedFile::fake()->createWithContent('set.png', self::BYTES);

        $this->actingAs($u, 'sanctum')->post('/api/customer/ai/validate-reference', ['reference' => $file()], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('verdict', 'needs_review')->assertJsonPath('reason', 'pair_not_approved');
        $this->pair();
        $this->post('/api/customer/ai/validate-reference', ['reference' => $file()], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('verdict', 'accepted')->assertJsonPath('reason', 'complete_set');
    }
}
