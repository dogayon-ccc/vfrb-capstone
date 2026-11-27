<?php

// AI quality tools (2026-11-02): acceptance metrics, the per-order AI context view, and the offline evaluation command.
namespace Tests\Feature;

use App\Models\MaterialRecommendation;
use App\Models\RecommendationGuidance;
use App\Models\User;
use App\Services\GeminiClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiQualityToolsTest extends TestCase
{
    use DatabaseTransactions;

    // Fixture (vfrb_test): user 1 = manager, 5 = customer; order 8 = completed royal-blue polo, order 9 = pending sky-blue polo.
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTestDatabase();
        config(['services.gemini.key_1' => 'test-key', 'services.gemini.key_2' => null, 'services.gemini.key_3' => null]);
        if (Schema::hasColumn('materials', 'ownership')) {
            DB::table('materials')->where('category', 'Fabric')->update(['ownership' => 'vfrb']);
        }
        DB::table('material_recommendations')->whereIn('order_id', [8, 9])->delete();
    }

    private function line(int $order, int $material, ?string $verdict, ?bool $customer = null, ?string $note = null, ?string $aiNote = 'ok'): void
    {
        DB::table('material_recommendations')->insert([
            'order_id' => $order, 'material_id' => $material, 'material_name' => DB::table('materials')->where('material_id', $material)->value('material_name'),
            'category' => 'Fabric', 'unit' => 'yards', 'ai_note' => $aiNote, 'customer_accepted' => (int) $customer,
            'status' => $customer === null ? 'pending' : ($customer ? 'accepted' : 'rejected'),
            'staff_feedback' => $verdict, 'staff_feedback_note' => $note, 'staff_feedback_by' => $verdict ? 1 : null, 'staff_feedback_at' => $verdict ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_metrics_count_staff_and_customer_acceptance_and_leave_customer_picks_out(): void
    {
        DB::table('material_recommendations')->where('created_at', '>=', now()->subMonths(6))->delete();
        $this->line(8, 2, 'reject', false);
        $this->line(8, 12, 'accept', true);
        $this->line(9, 12, 'accept', null);
        $this->line(9, 14, null, true, null, MaterialRecommendation::CUSTOMER_PICK_NOTE);
        Sanctum::actingAs(User::findOrFail(1), ['*']);

        $res = $this->getJson('/api/admin/ai/recommendation-metrics?months=3')->assertOk();

        $res->assertJsonPath('overall.lines', 3)
            ->assertJsonPath('overall.staff_accepted', 2)
            ->assertJsonPath('overall.staff_rejected', 1)
            ->assertJsonPath('overall.staff_acceptance_rate', 0.667)
            ->assertJsonPath('overall.customer_accepted', 1)
            ->assertJsonPath('overall.customer_rejected', 1)
            ->assertJsonPath('overall.customer_acceptance_rate', 0.5)
            ->assertJsonPath('by_garment.0.garment', 'Polo Shirt')
            ->assertJsonPath('by_month.0.month', now()->format('Y-m'));
        $this->getJson('/api/admin/ai/recommendation-metrics?months=99')->assertUnprocessable();
    }

    public function test_ai_context_shows_guidance_staff_reasons_and_catalog_without_quantities(): void
    {
        RecommendationGuidance::create(['scope_garment' => 'Polo Shirt', 'rule' => 'Prefer breathable knits for polos.', 'is_active' => true]);
        $this->line(8, 12, 'accept', null, 'Soft and keeps its shape after washing.');
        Sanctum::actingAs(User::findOrFail(1), ['*']);

        $res = $this->getJson('/api/admin/orders/9/ai-context')->assertOk();

        $this->assertContains('Prefer breathable knits for polos.', $res->json('data.guidance'));
        $this->assertSame(['Cotton Fabric'], $res->json('data.staff_approved'));
        $this->assertSame(['Cotton Fabric (approved): "Soft and keeps its shape after washing."'], $res->json('data.staff_reasons'));
        $this->assertSame(['material_id', 'material_code', 'material_name', 'category', 'unit', 'colour_match', 'in_stock'],
            array_keys($res->json('data.catalog.0')));
        $this->assertDoesNotMatchRegularExpression('/quantity_in_stock|unit_cost|price/', $res->getContent());
    }

    public function test_quality_tools_are_manager_side_only(): void
    {
        Sanctum::actingAs(User::findOrFail(5), ['*']);
        $this->getJson('/api/admin/ai/recommendation-metrics')->assertForbidden();
        $this->getJson('/api/admin/orders/9/ai-context')->assertForbidden();
    }

    public function test_eval_fallback_only_scores_without_calling_gemini(): void
    {
        $this->line(8, 12, 'accept');
        Http::fake();

        $this->assertSame(0, Artisan::call('vfrb:ai-eval', ['--fallback-only' => true, '--limit' => 1, '--json' => true]));
        $out = json_decode(Artisan::output(), true);

        Http::assertNothingSent();
        $this->assertSame('catalog_fallback', $out['mode']);
        $this->assertSame(1, $out['scored']);
        $this->assertSame('Polo Shirt', $out['by_garment'][0]['garment']);
    }

    public function test_eval_scores_ai_picks_and_never_shows_an_order_its_own_verdicts(): void
    {
        $this->line(8, 12, 'accept', null, 'OWN-ANSWER-NOTE keeps its shape.');
        $this->line(8, 2, 'reject');
        $prompts = [];
        $this->mock(GeminiClient::class, function ($m) use (&$prompts) {
            $m->shouldReceive('call')->andReturnUsing(function ($prompt) use (&$prompts) {
                $prompts[] = $prompt;
                return json_encode(['selections' => [['material_id' => 2, 'reason' => 'ok'], ['material_id' => 12, 'reason' => 'ok']], 'narration' => 'ok']);
            });
        });

        Artisan::call('vfrb:ai-eval', ['--limit' => 1, '--sleep' => 0, '--model' => 'model-under-test', '--json' => true]);
        $out = json_decode(Artisan::output(), true);

        $this->assertSame(['ai', 'model-under-test', 1], [$out['mode'], $out['model'], $out['scored']]);
        $this->assertEquals([2, 1, 1, 0.5, 1, 0.5], [$out['total']['picks'], $out['total']['accepted_picked'], $out['total']['rejected_picked'],
            $out['total']['precision'], $out['total']['recall'], $out['total']['rejected_rate']]);
        $this->assertCount(1, $prompts);
        $this->assertStringNotContainsString('OWN-ANSWER-NOTE', $prompts[0]);
        $this->assertStringContainsString('(avoid unless the design clearly needs them): none', $prompts[0]);
        $this->assertSame(2, DB::table('material_recommendations')->where('order_id', 8)->count()); // read-only
    }

    public function test_eval_counts_provider_failures_separately_from_scores(): void
    {
        $this->line(8, 12, 'accept');
        $this->mock(GeminiClient::class, fn ($m) => $m->shouldReceive('call')->andReturn(null));

        Artisan::call('vfrb:ai-eval', ['--limit' => 1, '--sleep' => 0, '--json' => true]);
        $out = json_decode(Artisan::output(), true);

        $this->assertSame([0, 1], [$out['scored'], $out['ai_failed']]);
        $this->assertNull($out['total']['precision']);
    }

    // ── Evaluation leakage guards (2026-11-19). NOT RUN in the authoring sandbox: run php artisan test --filter=AiQualityToolsTest ──

    private function capturePrompts(array &$prompts): void
    {
        $this->mock(GeminiClient::class, function ($m) use (&$prompts) {
            $m->shouldReceive('call')->andReturnUsing(function ($prompt) use (&$prompts) {
                $prompts[] = $prompt;
                return json_encode(['selections' => [['material_id' => 12, 'reason' => 'ok']], 'narration' => 'ok']);
            });
        });
    }

    public function test_eval_never_shows_an_order_feedback_recorded_after_it(): void
    {
        $this->line(8, 12, 'accept');
        DB::table('material_recommendations')->where('order_id', 8)->update(['staff_feedback_at' => now()->subDays(2)]);
        $this->line(9, 12, 'reject'); // newer verdict, on another order of the same garment
        $prompts = [];
        $this->capturePrompts($prompts);

        Artisan::call('vfrb:ai-eval', ['--limit' => 2, '--sleep' => 0, '--json' => true]);
        $out = json_decode(Artisan::output(), true);

        $this->assertSame([2, 0], [$out['scored'], $out['duplicates_collapsed']], 'orders 8 and 9 must be distinct designs for this test');
        $this->assertCount(2, $prompts);
        [$newer, $older] = $prompts; // newest first: order 9, then order 8
        $this->assertStringContainsString('(good candidates when they fit this design): Cotton Fabric', $newer); // earlier verdict may inform a later order
        $this->assertStringContainsString('(avoid unless the design clearly needs them): none', $older);        // a later verdict must not inform an earlier one
        $this->assertStringContainsString('(good candidates when they fit this design): none', $older);
    }

    public function test_eval_scores_near_duplicate_orders_once_and_never_trains_on_them(): void
    {
        $this->line(8, 12, 'accept');
        $this->line(9, 12, 'reject');
        $o8 = DB::table('orders')->where('order_id', 8)->first();
        DB::table('orders')->where('order_id', 9)->update([
            'user_id' => $o8->user_id, 'garment_type' => $o8->garment_type, 'studio_config' => $o8->studio_config, 'color' => $o8->color,
        ]);
        $prompts = [];
        $this->capturePrompts($prompts);

        Artisan::call('vfrb:ai-eval', ['--limit' => 2, '--sleep' => 0, '--json' => true]);
        $out = json_decode(Artisan::output(), true);

        $this->assertSame([1, 1], [$out['scored'], $out['duplicates_collapsed']]);
        $this->assertCount(1, $prompts);
        $this->assertStringContainsString('(avoid unless the design clearly needs them): none', $prompts[0]);
        $this->assertStringContainsString('(good candidates when they fit this design): none', $prompts[0]);
    }

    public function test_eval_withholds_metrics_without_both_verdict_classes_and_ignores_unattributed_verdicts(): void
    {
        $this->line(8, 12, 'accept');
        Http::fake();
        Artisan::call('vfrb:ai-eval', ['--fallback-only' => true, '--limit' => 5, '--json' => true]);
        $out = json_decode(Artisan::output(), true);
        $this->assertNull($out['total']['precision']);
        $this->assertNull($out['total']['recall']);
        $this->assertSame('no reject verdicts', $out['total']['withheld']);

        DB::table('material_recommendations')->where('order_id', 8)->update(['staff_feedback_by' => null]); // not attributable to staff
        Artisan::call('vfrb:ai-eval', ['--fallback-only' => true, '--limit' => 5, '--json' => true]);
        $out = json_decode(Artisan::output(), true);
        $this->assertSame([0, 'no rated orders'], [$out['scored'], $out['total']['withheld']]);
    }

    public function test_customer_reload_shows_the_same_technical_fit_disclosure_as_generation(): void
    {
        $fabric = (int) DB::table('materials')->where('category', 'Fabric')->orderBy('material_id')->value('material_id');
        $eval = ['checks' => [['attribute' => 'gsm', 'status' => 'unknown']], 'spec_fit' => 0.0, 'hard_fail' => false, 'complete' => false, 'verified' => false];
        DB::table('material_recommendations')->where('order_id', 9)->delete();
        DB::table('material_recommendations')->insert([
            'order_id' => 9, 'material_id' => $fabric, 'material_name' => 'X', 'category' => 'Fabric', 'unit' => 'yards', 'ai_note' => 'ok', 'status' => 'pending',
            'spec_snapshot' => json_encode(['at' => now()->toIso8601String(), 'eval' => $eval + ['secret' => 'min 140 max 220']]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs(User::findOrFail(5), ['*']);

        $res = $this->getJson('/api/customer/orders/9/ai-recommendation')->assertOk();

        $spec = collect($res->json('recommendation.materials_json'))->first()['spec_fit'];
        $this->assertFalse($spec['complete']);
        $this->assertSame('unknown', $spec['items'][0]['status']);
        $this->assertStringContainsString('not yet confirmed', $spec['items'][0]['text']);
        $this->assertStringNotContainsString('spec_snapshot', $res->getContent());
        $this->assertStringNotContainsString('min 140', $res->getContent());
    }
}
