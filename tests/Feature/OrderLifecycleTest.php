<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Same setup as SecurityGateTest: MySQL loaded from vfrb_db.sql, users 5/6 = customers, rollback per test.
//   php artisan test --filter OrderLifecycleTest
class OrderLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    private function actAs(int $userId): void
    {
        Sanctum::actingAs(User::findOrFail($userId), ['*']);
    }

    private function studioConfig(): array
    {
        return [
            'name' => 'Lifecycle test', 'category' => 'School Uniform', 'garment' => 'Polo Shirt',
            'garmentType' => 'Polo Shirt', 'fit' => 'male', 'sleeve' => 'Short',
            'colors' => ['body' => '#1e3a5f', 'collar' => '#c8a96e', 'sleeve' => '#1e3a5f', 'pocket' => '#c8a96e', 'tipping' => null],
            'patterns' => ['body' => 'solid', 'collar' => 'solid', 'sleeve' => 'solid', 'pocket' => 'solid'],
            'frontOverlays' => [['type' => 'i-text', 'text' => 'FRONT-MARK']],
            'backOverlays'  => [['type' => 'i-text', 'text' => 'BACK-MARK']],
            'previewPng' => 'data:image/png;base64,AAAA',
        ];
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'garment_type' => 'Polo Shirt',
            'color' => 'Navy',
            'quantity_ordered' => 100,
            'order_type' => 'direct',
            'sizing_type' => 'standard',
            'sizes' => json_encode(['S' => 40, 'M' => 60]),
            'client_design_notes' => 'School Uniform uniform. Polo Shirt style. Primary color: Navy.',
            'studio_config' => json_encode($this->studioConfig()),
            'design_preview_file' => UploadedFile::fake()->image('design.png', 40, 40),
        ], $over);
    }

    public function test_studio_design_survives_submit_and_comes_back_intact_to_the_owner(): void
    {
        Storage::fake('public');
        $this->actAs(5);
        $res  = $this->post('/api/customer/orders', $this->payload(['user_id' => 6, 'status' => 'completed']), ['Accept' => 'application/json']);
        $res->assertStatus(201);
        $id = $res->json('order.order_id');

        // Client-supplied owner/status are ignored.
        $row = DB::table('orders')->where('order_id', $id)->first();
        $this->assertSame(5, (int) $row->user_id);
        $this->assertSame('pending', $row->status);

        $detail = $this->getJson("/api/customer/orders/{$id}")->assertOk();
        $cfg = $detail->json('studio_config');
        $this->assertSame('Polo Shirt', $cfg['garment']);
        $this->assertSame('#1e3a5f', $cfg['colors']['body']);
        $this->assertSame('#c8a96e', $cfg['colors']['collar']);
        $this->assertSame('FRONT-MARK', $cfg['frontOverlays'][0]['text']);
        $this->assertSame('BACK-MARK', $cfg['backOverlays'][0]['text']);
        $this->assertArrayNotHasKey('previewPng', $cfg);
        $this->assertNotEmpty($detail->json('design_preview_url'));
    }

    public function test_size_breakdown_is_stored_per_size_under_the_authenticated_user(): void
    {
        Storage::fake('public');
        $this->actAs(5);
        $id = $this->post('/api/customer/orders', $this->payload(), ['Accept' => 'application/json'])->assertStatus(201)->json('order.order_id');
        $rows = DB::table('measurements')->where('order_id', $id)->get();
        $this->assertSame(['M' => 60, 'S' => 40], $rows->pluck('qty', 'size_label')->map(fn ($q) => (int) $q)->sortKeys()->all());
        $this->assertSame([5], $rows->pluck('user_id')->map(fn ($u) => (int) $u)->unique()->values()->all());
    }

    public function test_another_customer_cannot_read_the_submitted_order(): void
    {
        Storage::fake('public');
        $this->actAs(5);
        $id = $this->post('/api/customer/orders', $this->payload(), ['Accept' => 'application/json'])->assertStatus(201)->json('order.order_id');
        $this->actAs(6);
        $this->getJson("/api/customer/orders/{$id}")->assertNotFound();
        $ids = collect($this->getJson('/api/customer/orders?per_page=100')->json('data'))->pluck('order_id')->all();
        $this->assertNotContains($id, $ids);
    }

    public function test_size_mismatch_is_rejected_and_nothing_is_written(): void
    {
        Storage::fake('public');
        $before = DB::table('orders')->count();
        $this->actAs(5);
        $this->post('/api/customer/orders', $this->payload(['sizes' => json_encode(['S' => 10])]), ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame($before, DB::table('orders')->count());
    }

    public function test_double_submit_inside_the_debounce_window_creates_one_order(): void
    {
        Storage::fake('public');
        $before = DB::table('orders')->count();
        $this->actAs(5);
        $this->post('/api/customer/orders', $this->payload(), ['Accept' => 'application/json'])->assertStatus(201);
        $this->post('/api/customer/orders', $this->payload(), ['Accept' => 'application/json'])->assertStatus(409);
        $this->assertSame($before + 1, DB::table('orders')->count());
    }

    public function test_bulk_minimum_past_deadline_and_executable_upload_are_rejected(): void
    {
        Storage::fake('public');
        $this->actAs(5);
        $h = ['Accept' => 'application/json'];
        $this->post('/api/customer/orders', $this->payload(['quantity_ordered' => 99, 'sizes' => json_encode(['S' => 99])]), $h)->assertStatus(422);
        $this->post('/api/customer/orders', $this->payload(['deadline' => '2020-01-01']), $h)->assertStatus(422);
        $this->post('/api/customer/orders', $this->payload(['design_ref_file' => UploadedFile::fake()->create('shell.php', 5, 'application/x-php')]), $h)->assertStatus(422);
        $this->post('/api/customer/orders', $this->payload(['design_preview_file' => UploadedFile::fake()->create('x.html', 5, 'text/html')]), $h)->assertStatus(422);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_draft_is_per_user_and_survives_until_deleted(): void
    {
        $this->actAs(5);
        $this->postJson('/api/customer/drafts', ['studio_config' => $this->studioConfig(), 'label' => 'Polo'])->assertSuccessful();
        $this->getJson('/api/customer/drafts/latest')->assertOk()->assertJsonPath('draft.studio_config.colors.body', '#1e3a5f');
        $this->actAs(6);
        $this->getJson('/api/customer/drafts/latest')->assertOk()->assertJsonPath('draft', null);
        $this->actAs(5);
        $this->deleteJson('/api/customer/drafts/latest')->assertSuccessful();
        $this->getJson('/api/customer/drafts/latest')->assertOk()->assertJsonPath('draft', null);
    }
}
