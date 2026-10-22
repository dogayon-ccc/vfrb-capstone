<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
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
            'backOverlays' => [['type' => 'i-text', 'text' => 'BACK-MARK']],
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
        $this->post('/api/customer/orders', $this->payload(['status' => 'completed']), ['Accept' => 'application/json'])->assertStatus(422);
        $res = $this->post('/api/customer/orders', $this->payload(['user_id' => 6]), ['Accept' => 'application/json']);
        $res->assertStatus(201);
        $id = $res->json('order.order_id');

        // Client-supplied status is rejected; client-supplied owner is ignored.
        $row = DB::table('orders')->where('order_id', $id)->first();
        $this->assertSame(5, (int) $row->user_id);
        $this->assertSame('pending', $row->status);

        $detail = $this->getJson("/api/customer/orders/{$id}")->assertOk();
        $cfg = $detail->json('order.studio_config');
        $this->assertSame('Polo Shirt', $cfg['garment']);
        $this->assertSame('#1e3a5f', $cfg['colors']['body']);
        $this->assertSame('#c8a96e', $cfg['colors']['collar']);
        $this->assertSame('FRONT-MARK', $cfg['frontOverlays'][0]['text']);
        $this->assertSame('BACK-MARK', $cfg['backOverlays'][0]['text']);
        $this->assertArrayNotHasKey('previewPng', $cfg);
        $this->assertNotEmpty($detail->json('order.design_preview_url'));
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

    public function test_negative_or_fractional_size_quantities_are_rejected_and_nothing_is_written(): void
    {
        Storage::fake('public');
        $this->actAs(5);
        $before = DB::table('orders')->count();

        foreach ([['S' => 150, 'M' => -50], ['S' => 50.5, 'M' => 49.5], ['S' => 'abc', 'M' => 100]] as $sizes) {
            $this->post('/api/customer/orders', $this->payload(['sizes' => json_encode($sizes)]), ['Accept' => 'application/json'])->assertStatus(422);
        }
        $this->post('/api/customer/orders', $this->payload(['sizes' => 'not json']), ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame($before, DB::table('orders')->count());
    }

    public function test_unreadable_studio_design_is_rejected_instead_of_silently_dropped(): void
    {
        Storage::fake('public');
        $this->actAs(5);
        $before = DB::table('orders')->count();

        foreach (['{broken json', '"just a string"', '42'] as $bad) {
            $this->post('/api/customer/orders', $this->payload(['studio_config' => $bad]), ['Accept' => 'application/json'])->assertStatus(422);
        }
        $this->assertSame($before, DB::table('orders')->count());

        // The rejections released the debounce lock, so a corrected resubmit goes straight through.
        $this->post('/api/customer/orders', $this->payload(), ['Accept' => 'application/json'])->assertStatus(201);
    }

    public function test_upload_only_order_without_a_studio_design_still_submits(): void
    {
        Storage::fake('public');
        $this->actAs(5);

        $res = $this->post('/api/customer/orders', $this->payload(['studio_config' => '']), ['Accept' => 'application/json']);

        $res->assertStatus(201);
        $this->assertNull(DB::table('orders')->where('order_id', $res->json('order.order_id'))->value('studio_config'));
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
        $this->post('/api/customer/orders', $this->payload(['preferred_date' => '2020-01-01']), $h)->assertStatus(422);
        $this->post('/api/customer/orders', $this->payload(['design_ref_file' => UploadedFile::fake()->create('shell.php', 5, 'application/x-php')]), $h)->assertStatus(422);
        $this->post('/api/customer/orders', $this->payload(['design_preview_file' => UploadedFile::fake()->create('x.html', 5, 'text/html')]), $h)->assertStatus(422);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_preferred_date_is_optional_but_never_a_rush_date(): void
    {
        Storage::fake('public');
        $this->actAs(5);
        $h = ['Accept' => 'application/json'];

        $id = $this->post('/api/customer/orders', $this->payload(), $h)->assertStatus(201)->json('order.order_id');
        $this->assertNull(DB::table('orders')->where('order_id', $id)->value('target_delivery_date'));
        Cache::lock('order-submit:5')->forceRelease();

        foreach (['preferred_date', 'deadline'] as $field) {
            $this->post('/api/customer/orders', $this->payload([$field => now()->addDays(6)->toDateString()]), $h)
                ->assertStatus(422)->assertJsonValidationErrors($field);
        }
        $preferred = now()->addDays(7)->toDateString();
        $id = $this->post('/api/customer/orders', $this->payload(['preferred_date' => $preferred]), $h)->assertStatus(201)->json('order.order_id');
        $this->assertSame($preferred, substr((string) DB::table('orders')->where('order_id', $id)->value('target_delivery_date'), 0, 10));
    }

    public function test_customer_cannot_set_promised_date_total_status_or_down_payment(): void
    {
        Storage::fake('public');
        $this->actAs(5);
        $before = DB::table('orders')->count();
        foreach (['promised_date' => now()->addDays(30)->toDateString(), 'agreed_total' => 1, 'total' => 1, 'status' => 'confirmed', 'down_payment_amount' => 1] as $field => $value) {
            $this->post('/api/customer/orders', $this->payload([$field => $value]), ['Accept' => 'application/json'])
                ->assertStatus(422)->assertJsonValidationErrors($field);
        }
        $this->assertSame($before, DB::table('orders')->count());
    }

    public function test_vfrb_promises_a_date_at_least_seven_days_after_confirmation_and_the_customer_accepts(): void
    {
        Storage::fake('public');
        $this->actAs(5);
        $id = $this->post('/api/customer/orders', $this->payload(['preferred_date' => now()->addDays(10)->toDateString()]), ['Accept' => 'application/json'])
            ->assertStatus(201)->json('order.order_id');

        $this->actAs(1);
        $note = ['total' => 25000, 'proposal_note' => 'Customer supplies the fabric; threads and logo patch from VFRB.'];
        $this->postJson("/api/admin/orders/{$id}/propose", $note + ['promised_date' => now()->addDays(6)->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('promised_date');
        $this->patchJson("/api/admin/orders/{$id}", ['status' => 'confirmed'])->assertStatus(422);

        // No promised_date sent: VFRB keeps the customer's preferred date.
        $this->postJson("/api/admin/orders/{$id}/propose", $note)->assertOk()->assertJsonPath('order.proposal_status', 'pending');
        $row = DB::table('orders')->where('order_id', $id)->first();
        $this->assertSame(now()->addDays(10)->toDateString(), substr((string) $row->promised_date, 0, 10));
        $this->assertEquals(25000, (float) $row->agreed_total);

        // Four days later the date is under 7 days from confirmation, so accepting is refused.
        $this->travel(4)->days();
        $this->actAs(5);
        $this->postJson("/api/customer/orders/{$id}/accept")->assertStatus(422);
        $this->assertSame('pending', DB::table('orders')->where('order_id', $id)->value('status'));

        $this->actAs(1);
        $this->postJson("/api/admin/orders/{$id}/propose", $note + ['promised_date' => now()->addDays(7)->toDateString()])->assertOk();
        $this->actAs(5);
        $this->postJson("/api/customer/orders/{$id}/accept")->assertOk();
        $row = DB::table('orders')->where('order_id', $id)->first();
        $this->assertSame(['confirmed', 'accepted'], [$row->status, $row->proposal_status]);
    }

    public function test_customer_declines_a_proposal_with_a_reason(): void
    {
        Storage::fake('public');
        $this->actAs(5);
        $id = $this->post('/api/customer/orders', $this->payload(), ['Accept' => 'application/json'])->assertStatus(201)->json('order.order_id');
        $this->actAs(1);
        $this->postJson("/api/admin/orders/{$id}/propose", ['total' => 25000, 'proposal_note' => 'Earliest feasible date with current workers.'])->assertOk();

        $this->actAs(5);
        $this->postJson("/api/customer/orders/{$id}/decline", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/customer/orders/{$id}/decline", ['reason' => 'Too late for our event.'])->assertOk();
        $row = DB::table('orders')->where('order_id', $id)->first();
        $this->assertSame(['pending', 'declined'], [$row->status, $row->proposal_status]);
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
