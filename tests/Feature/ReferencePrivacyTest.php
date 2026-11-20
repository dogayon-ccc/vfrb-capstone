<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\UploadDisk;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Reference uploads stay private; the held queue gives staff what they need to decide and nothing the customer must not see.
class ReferencePrivacyTest extends TestCase
{
    use DatabaseTransactions;

    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89";

    private function user(string $role): User
    {
        $u = User::create(['name' => 'Priv '.$role, 'email' => uniqid('priv').'@example.test', 'password' => bcrypt('secret-pass')]);
        $u->forceFill(['email_verified_at' => now()])->save();
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $u->assignRole($role);

        return $u;
    }

    private function held(User $owner, string $bytes = self::PNG, string $ext = 'png'): int
    {
        Storage::fake(UploadDisk::name());
        $path = 'design-refs/priv-'.uniqid().'.'.$ext;
        Storage::disk(UploadDisk::name())->put($path, $bytes);

        return DB::table('reference_validations')->insertGetId([
            'user_id' => $owner->getKey(), 'path' => $path, 'sha256' => hash('sha256', $bytes), 'status' => 'needs_review', 'reason' => 'pair_not_approved',
            'top_garment' => 'Polo Shirt', 'bottom_garment' => 'Shorts', 'prompt_version' => 'set-2', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_owner_reads_their_reference_with_private_headers_and_a_sniffed_type(): void
    {
        $c = $this->user('customer');
        $id = $this->held($c);
        $r = $this->actingAs($c, 'sanctum')->get("/api/customer/reference-validations/{$id}/file");
        $r->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
    }

    public function test_another_customer_gets_404_not_the_file(): void
    {
        $id = $this->held($this->user('customer'));
        $this->actingAs($this->user('customer'), 'sanctum')->get("/api/customer/reference-validations/{$id}/file")->assertNotFound();
    }

    public function test_an_unexpected_file_type_is_never_served_even_with_an_image_extension(): void
    {
        $c = $this->user('customer');
        $id = $this->held($c, '<html><script>alert(1)</script></html>', 'png');
        $this->actingAs($c, 'sanctum')->get("/api/customer/reference-validations/{$id}/file")->assertNotFound();
    }

    public function test_manager_and_owner_can_read_it_but_station_staff_and_customers_cannot(): void
    {
        $id = $this->held($this->user('customer'));
        $url = "/api/admin/reference-validations/{$id}/file";
        $this->actingAs($this->user('manager'), 'sanctum')->get($url)->assertOk();
        $this->actingAs($this->user('owner'), 'sanctum')->get($url)->assertOk();
        $this->actingAs($this->user('customer'), 'sanctum')->get($url)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertUnauthorized();
    }

    public function test_the_held_queue_names_the_customer_and_flags_whether_the_pair_is_approved_without_leaking_paths(): void
    {
        $c = $this->user('customer');
        $id = $this->held($c);
        $m = $this->user('manager');
        $row = collect($this->actingAs($m, 'sanctum')->getJson('/api/admin/reference-validations')->assertOk()->json())->firstWhere('id', $id);
        $this->assertSame($c->name, $row['customer_name']);
        $this->assertFalse($row['pair_approved']);
        $this->assertArrayNotHasKey('path', $row);
        $this->assertFalse($row['is_pdf']);
    }

    public function test_a_reload_returns_the_stored_recommendation_source(): void
    {
        $c = $this->user('customer');
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('material_recommendations', 'rec_source'));
        $id = $this->held($c);
        DB::table('reference_validations')->where('id', $id)->update(['status' => 'accepted']);
        DB::table('approved_set_pairs')->insertOrIgnore(['top_garment' => 'Polo Shirt', 'bottom_garment' => 'Shorts', 'created_at' => now(), 'updated_at' => now()]);
        $made = $this->actingAs($c, 'sanctum')->postJson('/api/customer/orders', [
            'reference_validation_id' => $id, 'size_matrix' => json_encode(['top' => ['M' => 100], 'bottom' => ['L' => 100]]),
        ])->assertCreated();
        $oid = $made->json('order.order_id');
        DB::table('material_recommendations')->insert(['order_id' => $oid, 'material_name' => 'Test Twill', 'category' => 'Fabric', 'unit' => 'm', 'status' => 'pending', 'display_order' => 0, 'rec_source' => 'catalog_default', 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson("/api/customer/orders/{$oid}/ai-recommendation")->assertOk()->assertJsonPath('recommendation.source', 'catalog_default');
        $this->getJson("/api/customer/orders/{$oid}")->assertOk()->assertJsonPath('recommendation_source', 'catalog_default');
    }
}
