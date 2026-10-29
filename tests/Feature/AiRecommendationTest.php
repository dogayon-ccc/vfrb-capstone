<?php

namespace Tests\Feature;

use App\Models\MaterialRecommendation;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Same fixture as SecurityGateTest (vfrb_db.sql in MySQL). Users: 1 manager, 5/6 customers.
// Order 9 = user 5, pending; order 1 = user 5, in production. Gemini is always faked.
class AiRecommendationTest extends TestCase
{
    use DatabaseTransactions;

    private const FORBIDDEN_KEYS = ['estimated_range', 'total_estimated_range', 'actual_qty_issued', 'linked_by', 'linked_at', 'issued_at', 'yardage', 'quantity', 'cost'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.key_1' => 'test-key', 'services.gemini.key_2' => null, 'services.gemini.key_3' => null]);

        // These tests use fabric VFRB buys for direct orders; the reclassification is master data and rolls back.
        if (Schema::hasColumn('materials', 'ownership')) {
            DB::table('materials')->where('category', 'Fabric')->update(['ownership' => 'vfrb']);
        }
    }

    private function actAs(int $userId): void
    {
        Sanctum::actingAs(User::findOrFail($userId), ['*']);
    }

    private function gemini(array $payload): PromiseInterface
    {
        return Http::response(['candidates' => [['content' => ['parts' => [['text' => is_string($payload) ? $payload : json_encode($payload)]]]]]]);
    }

    private function recommend(int $orderId = 9)
    {
        return $this->postJson('/api/customer/ai/recommend-materials', ['order_id' => $orderId]);
    }

    private function recRows(int $orderId = 9)
    {
        return DB::table('material_recommendations')->where('order_id', $orderId)->orderBy('display_order')->get();
    }

    public function test_success_stores_catalog_rows_and_returns_a_quantity_free_shape(): void
    {
        Http::fake(['*' => $this->gemini([
            'selections' => [
                ['material_id' => 2, 'reason' => 'Durable navy fabric for a polo.'],
                ['material_id' => 14, 'reason' => 'Neutral thread.'],
            ],
            'narration' => 'Fabric and thread suit this polo design.',
        ])]);
        $this->actAs(5);

        $res = $this->recommend()->assertOk();

        // Order 9 is a sky-blue polo: the navy fabric is the nearest colour (labelled "near"), white thread is a different colour.
        $this->assertSame('Fabric and thread suit this polo design.', $res->json('recommendation'));
        $this->assertSame('ai', $res->json('source'));
        $this->assertSame([2, 14], collect($res->json('materials'))->pluck('material_id')->all());
        $this->assertSame(['near', null], collect($res->json('materials'))->pluck('colour_match')->all());
        $this->assertSame('MAT-FAB-002', $res->json('materials.0.material_code'));
        $this->assertIsBool($res->json('materials.0.in_stock'));
        $this->assertSame(['pending', 'pending'], collect($res->json('materials'))->pluck('status')->all());

        $body = json_encode($res->json());
        foreach (self::FORBIDDEN_KEYS as $k) {
            $this->assertStringNotContainsString($k, $body);
        }

        $rows = $this->recRows();
        $this->assertSame(['Cotton-Poly Blend Fabric (Navy)', 'Polyester Thread'], $rows->pluck('material_name')->all());
        $this->assertSame([null], $rows->pluck('estimated_range')->unique()->values()->all());
        $this->assertSame('ready', DB::table('orders')->where('order_id', 9)->value('ai_recommendation_status'));
    }

    public function test_request_is_grounded_in_the_catalog_and_design_and_keeps_the_key_server_side(): void
    {
        Http::fake(['*' => $this->gemini(['selections' => [['material_id' => 2, 'reason' => 'ok']], 'narration' => 'ok'])]);
        $this->actAs(5);

        $res = $this->recommend()->assertOk();

        Http::assertSent(function (Request $r) {
            $cfg = $r->data()['generationConfig'];
            $prompt = $r->data()['contents'][0]['parts'][0]['text'];
            $ids = $cfg['responseJsonSchema']['properties']['selections']['items']['properties']['material_id']['enum'];

            return $r->hasHeader('x-goog-api-key', 'test-key')
                && ! str_contains($r->url(), 'test-key')
                && $cfg['responseMimeType'] === 'application/json'
                && in_array(2, $ids, true) && ! in_array(9999, $ids, true)
                && str_contains($prompt, 'polo_shirt')
                && str_contains($prompt, '| Cotton-Poly Blend Fabric (Navy) | Fabric |')
                && str_contains($prompt, 'colour: close to the body colour')
                && str_contains($prompt, 'stock: in stock')
                && ! in_array(4, $ids, true)
                && str_contains($prompt, 'Never state or imply a quantity');
        });
        $this->assertStringNotContainsString('test-key', json_encode($res->json()));
    }

    public function test_hallucinated_and_duplicate_ids_are_dropped_before_storage(): void
    {
        Http::fake(['*' => $this->gemini([
            'selections' => [
                ['material_id' => 9999, 'reason' => 'invented'],
                ['material_id' => 2, 'reason' => 'real'],
                ['material_id' => 2, 'reason' => 'duplicate'],
            ],
            'narration' => 'x',
        ])]);
        $this->actAs(5);

        $this->recommend()->assertOk();

        $this->assertSame([2], $this->recRows()->pluck('material_id')->map(fn ($i) => (int) $i)->all());
    }

    public function test_quantity_and_price_language_never_reaches_the_customer_or_the_db(): void
    {
        Http::fake(['*' => $this->gemini([
            'selections' => [['material_id' => 2, 'reason' => 'You need 3.5 yards per piece at PHP 200']],
            'narration' => 'Order 500 pieces, total of 1750 yards.',
        ])]);
        $this->actAs(5);

        $res = $this->recommend()->assertOk();

        $this->assertDoesNotMatchRegularExpression('/yards|PHP|pieces|\d/i', $res->json('recommendation'));
        $this->assertDoesNotMatchRegularExpression('/yards|PHP|\d/i', (string) $this->recRows()->first()->ai_note);
    }

    public function test_first_key_rejected_second_key_succeeds(): void
    {
        config(['services.gemini.key_2' => 'second-key']);
        Http::fake(['*' => Http::sequence()
            ->push(['error' => ['message' => 'API key not valid']], 403)
            ->push(['candidates' => [['content' => ['parts' => [['text' => json_encode(['selections' => [['material_id' => 2, 'reason' => 'ok']], 'narration' => 'ok'])]]]]]])]);
        $this->actAs(5);

        $this->recommend()->assertOk();
        Http::assertSent(fn (Request $r) => $r->hasHeader('x-goog-api-key', 'second-key'));
    }

    // Provider trouble never breaks the order: the deterministic catalog fallback answers, says so (source), and leaks nothing.
    private function assertCatalogFallback($res): void
    {
        $res->assertOk();
        $this->assertSame('catalog_default', $res->json('source'));
        $this->assertStringNotContainsString('SECRET-UPSTREAM-DETAIL', $res->getContent());
        $this->assertStringNotContainsString('test-key', $res->getContent());
        $this->assertNotEmpty($this->recRows());
        $this->assertSame('ready', DB::table('orders')->where('order_id', 9)->value('ai_recommendation_status'));
    }

    public function test_upstream_500_uses_the_catalog_fallback_and_hides_provider_detail(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'SECRET-UPSTREAM-DETAIL key=abc']], 500)]);
        $this->actAs(5);
        $this->assertCatalogFallback($this->recommend());
        Http::assertSentCount(2); // a 500 is not retried on the same model; the fallback model is tried once
    }

    public function test_missing_gemini_key_uses_the_fallback_without_any_request(): void
    {
        config(['services.gemini.key_1' => null]);
        Http::fake();
        $this->actAs(5);
        $this->assertCatalogFallback($this->recommend());
        Http::assertNothingSent();
    }

    public function test_unparseable_model_output_uses_the_fallback(): void
    {
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Sure! Here are some fabrics...']]]]]])]);
        $this->actAs(5);
        $this->assertCatalogFallback($this->recommend());
    }

    public function test_rate_limit_on_the_only_key_uses_the_fallback(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'quota']], 429)]);
        $this->actAs(5);
        $this->assertCatalogFallback($this->recommend());
    }

    public function test_timeout_is_retried_once_then_falls_back(): void
    {
        config(['services.gemini.retries' => 1]);
        Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28: timed out')]);
        $this->actAs(5);
        $this->assertCatalogFallback($this->recommend());
    }

    public function test_overloaded_503_is_retried_and_the_second_answer_is_used(): void
    {
        config(['services.gemini.retries' => 1]);
        $ok = $this->gemini(['selections' => [['material_id' => 2, 'reason' => 'ok']], 'narration' => 'ok']);
        Http::fake(['*' => Http::sequence()->push('busy', 503)->push($ok->wait()->getBody()->getContents(), 200)]);
        $this->actAs(5);

        $res = $this->recommend()->assertOk();
        $this->assertSame('ai', $res->json('source'));
        $this->assertSame([2], $this->recRows()->pluck('material_id')->map(fn ($i) => (int) $i)->all());
        Http::assertSentCount(2);
    }

    public function test_the_same_design_is_served_from_cache_without_a_second_provider_call(): void
    {
        Http::fake(['*' => $this->gemini(['selections' => [['material_id' => 2, 'reason' => 'ok']], 'narration' => 'ok'])]);
        $this->actAs(5);

        $this->recommend()->assertOk();
        $res = $this->recommend()->assertOk();
        $this->assertSame('cache', $res->json('source'));
        Http::assertSentCount(1);
    }

    public function test_no_approved_material_for_the_design_is_a_clear_422_not_an_invented_match(): void
    {
        DB::table('materials')->update(['ai_eligible' => false]);
        Http::fake();
        $this->actAs(5);

        $this->recommend()->assertStatus(422)->assertJsonPath('message', 'No approved materials are configured for this garment yet.');
        Http::assertNothingSent();
        $this->assertSame(0, $this->recRows()->count());
    }

    public function test_invalid_input_is_rejected(): void
    {
        Http::fake();
        $this->actAs(5);
        $this->postJson('/api/customer/ai/recommend-materials', [])->assertStatus(422);
        $this->postJson('/api/customer/ai/recommend-materials', ['order_id' => 'abc'])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_two_tone_striped_trousers_get_contrast_fabric_closures_and_stock_facts(): void
    {
        // White body, navy waistband trim and navy stripes on a rebuilt garment family the catalog never names.
        DB::table('orders')->where('order_id', 9)->update(['garment_type' => 'Pantsuit Trousers', 'studio_config' => json_encode([
            'garment' => 'Pantsuit Trousers', 'colors' => ['body' => '#FAFAFA', 'collar' => '#1B2A4A'],
            'patterns' => ['body' => 'pinstripe'], 'patternParams' => ['body' => ['c2' => '#1B2A4A', 'scale' => 1]],
        ])]);
        DB::table('materials')->where('material_id', 8)->update(['quantity_in_stock' => 0]); // the YKK zipper is out of stock
        config(['services.gemini.key_1' => null]); // deterministic path
        Http::fake();
        $this->actAs(5);

        $res = $this->recommend()->assertOk();
        $mats = collect($res->json('materials'))->keyBy('material_id');
        $this->assertSame('body', $mats[1]['colour_match']);       // white fabric for the body
        $this->assertSame('collar', $mats[2]['colour_match']);     // navy fabric for the contrast trim
        $this->assertTrue($mats->contains(fn ($m) => str_contains(strtolower($m['material_name']), 'zipper')));
        $this->assertTrue($mats->contains(fn ($m) => str_contains(strtolower($m['material_name']), 'button')));
        $this->assertFalse($mats->has(8));                         // the in-stock zipper is chosen over the out-of-stock one
        $this->assertSame('#FAFAFA', json_decode(DB::table('orders')->where('order_id', 9)->value('studio_config'), true)['colors']['body']);
    }

    public function test_another_customers_order_is_404_and_never_reaches_gemini(): void
    {
        Http::fake();
        $this->actAs(6);

        $this->recommend(9)->assertNotFound();
        Http::assertNothingSent();
        $this->assertSame(0, $this->recRows()->count());
    }

    public function test_unauthenticated_request_is_401(): void
    {
        Http::fake();
        $this->postJson('/api/customer/ai/recommend-materials', ['order_id' => 9])->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_orders_in_production_are_locked_for_ai_and_manual_selection(): void
    {
        Http::fake();
        $this->actAs(5);

        $this->recommend(1)->assertStatus(409);
        $this->postJson('/api/customer/orders/1/select-materials', ['material_ids' => [2]])->assertStatus(409);
        $this->postJson('/api/customer/orders/1/accept-materials')->assertStatus(409);
        $this->postJson('/api/customer/orders/1/reject-materials')->assertStatus(409);
        Http::assertNothingSent();
        $this->assertSame(0, $this->recRows(1)->count());
    }

    public function test_staff_linked_rows_are_never_overwritten_by_a_new_recommendation(): void
    {
        DB::table('material_recommendations')->insert([
            'order_id' => 9, 'material_name' => 'Cotton Fabric', 'material_id' => 12, 'category' => 'Fabric', 'unit' => 'yards',
            'status' => 'accepted', 'linked_by' => 1, 'linked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::fake();
        $this->actAs(5);

        $this->recommend()->assertStatus(409);
        Http::assertNothingSent();
        $this->assertSame(1, $this->recRows()->count());
    }

    public function test_customer_order_detail_never_exposes_quantity_or_staff_columns(): void
    {
        // The estimate columns exist only on databases that have not run the column-removal migration; seed them where present.
        $row = ['order_id' => 9, 'material_name' => 'Cotton Fabric', 'material_id' => 12, 'category' => 'Fabric', 'unit' => 'yards',
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now()];
        foreach (['estimated_range' => '150-200 yards', 'total_estimated_range' => '9999 yards', 'actual_qty_issued' => 321.5] as $col => $v) {
            if (Schema::hasColumn('material_recommendations', $col)) {
                $row[$col] = $v;
            }
        }
        DB::table('material_recommendations')->insert($row);
        $this->actAs(5);

        $res = $this->getJson('/api/customer/orders/9')->assertOk();

        $this->assertCount(1, $res->json('recommendations'));
        $this->assertEqualsCanonicalizing(
            array_diff(MaterialRecommendation::CUSTOMER_COLUMNS, ['created_at', 'updated_at']),
            array_diff(array_keys($res->json('recommendations.0')), ['created_at', 'updated_at'])
        );
        $body = $res->getContent();
        foreach (['estimated_range', 'actual_qty_issued', 'linked_by', '9999', '321.5'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }

    public function test_customer_can_still_pick_their_own_materials(): void
    {
        $this->actAs(5);

        $this->postJson('/api/customer/orders/9/select-materials', ['material_ids' => [2, 4], 'notes' => 'our preference'])->assertOk();

        $rows = $this->recRows();
        $this->assertSame([2, 4], $rows->pluck('material_id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame(['accepted', 'accepted'], $rows->pluck('status')->all());
        $this->assertSame('accepted', DB::table('orders')->where('order_id', 9)->value('ai_recommendation_status'));
    }

    public function test_customer_cannot_read_or_accept_another_customers_recommendation(): void
    {
        $this->actAs(6);

        $this->getJson('/api/customer/orders/9/ai-recommendation')->assertNotFound();
        $this->postJson('/api/customer/orders/9/accept-materials')->assertNotFound();
        $this->postJson('/api/customer/orders/9/select-materials', ['material_ids' => [2]])->assertNotFound();
    }

    public function test_describe_design_returns_only_validated_values(): void
    {
        Http::fake(['*' => $this->gemini([
            'garmentType' => 'Top', 'category' => 'PE/Sports', 'collarType' => 'V-Neck', 'sleeveType' => 'Laser Sleeve',
            'colors' => ['body' => '#112233', 'accent' => 'not-a-color'], 'pattern' => 'solid',
            'textContent' => '<b>RN</b> 12', 'textBold' => true, 'description_summary' => 'Navy scrub top.',
        ])]);

        $this->actAs(5);
        $res = $this->postJson('/api/ai/describe-design', ['description' => 'navy scrub top with RN 12'])->assertOk();

        $cfg = $res->json('config');
        $this->assertArrayNotHasKey('category', $cfg);
        $this->assertArrayNotHasKey('sleeveType', $cfg);
        $this->assertSame(['body' => '#112233'], $cfg['colors']);
        $this->assertSame('V-Neck', $cfg['collarType']);
        $this->assertSame('RN 12', $cfg['textContent']);
        $this->assertTrue($cfg['textBold']);
        $this->assertSame('Navy scrub top.', $res->json('description'));
    }

    public function test_describe_design_errors_keep_the_error_key_the_studio_reads(): void
    {
        $this->actAs(5);
        Http::fake(['*' => Http::sequence()
            ->push('down', 500)
            ->push('down', 500)
            ->push(['candidates' => [['content' => ['parts' => [['text' => json_encode(['category' => 'PE/Sports'])]]]]]])]);

        $this->postJson('/api/ai/describe-design', ['description' => 'navy scrub top'])
            ->assertStatus(503)->assertJsonPath('error', 'AI unavailable');

        $this->postJson('/api/ai/describe-design', ['description' => 'navy scrub top'])
            ->assertStatus(502)->assertJsonStructure(['error']);

        $this->postJson('/api/ai/describe-design', [])->assertStatus(422);
    }

    public function test_analytics_summary_is_503_not_a_fake_success_when_gemini_is_down(): void
    {
        Http::fake(['*' => Http::response('down', 500)]);
        $this->actAs(1);

        $res = $this->getJson('/api/admin/ai/analytics-summary')->assertStatus(503);
        $this->assertNull($res->json('insight'));
        $this->assertArrayHasKey('thisMonth', $res->json('metrics'));
    }

    public function test_analytics_summary_success_and_manager_only(): void
    {
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Orders are steady.']]]]]])]);

        $this->actAs(4);
        $this->getJson('/api/admin/ai/analytics-summary')->assertForbidden();

        $this->actAs(1);
        $this->getJson('/api/admin/ai/analytics-summary')->assertOk()->assertJsonPath('insight', 'Orders are steady.');
    }

    public function test_analytics_last_month_is_scoped_to_the_right_year(): void
    {
        $prev = now()->subMonthNoOverflow();
        $stray = $prev->copy()->subYear();
        $before = DB::table('orders')->whereMonth('created_at', $prev->month)->whereYear('created_at', $prev->year)->count();
        DB::table('orders')->insert([
            'user_id' => 5, 'garment_type' => 'T-Shirt', 'quantity_ordered' => 100, 'status' => 'pending',
            'created_at' => $stray, 'updated_at' => $stray,
        ]);
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]])]);
        $this->actAs(1);

        $this->assertSame($before, $this->getJson('/api/admin/ai/analytics-summary')->assertOk()->json('metrics.lastMonth'));
    }
}
