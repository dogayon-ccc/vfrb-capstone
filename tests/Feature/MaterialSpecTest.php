<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\Order;
use App\Services\MaterialRecommender;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use App\Models\User;
use Tests\TestCase;

// Same MySQL fixture as AiRecommendationTest (vfrb_db.sql): user 1 manager, user 5 customer, order 9 = user 5's sky-blue polo.
// NOT RUN in the authoring sandbox (no PHP 8.4 / Composer / MySQL there). Run locally: php artisan test --filter=MaterialSpecTest
class MaterialSpecTest extends TestCase
{
    use DatabaseTransactions;

    private function actAs(int $id): void { Sanctum::actingAs(User::findOrFail($id), ['*']); }

    private function fabricId(): int { return (int) DB::table('materials')->where('category', 'Fabric')->orderBy('material_id')->value('material_id'); }

    /** Fabric ids the recommender really offers for order 9 (colour, ownership and eligibility already applied), or skip. */
    private function eligibleFabrics(int $n): array
    {
        DB::table('materials')->where('category', 'Fabric')->update(['ownership' => 'vfrb', 'ai_eligible' => 1, 'applies_to' => null]);
        $prep = app(MaterialRecommender::class)->prepare(Order::findOrFail(9));
        $ids = is_array($prep) ? array_keys(array_filter($prep['eligible'], fn ($m) => str_contains(mb_strtolower($m['category']), 'fabric'))) : [];
        if (count($ids) < $n) { $this->markTestSkipped("Fixture offers fewer than {$n} fabrics for order 9."); }
        return array_slice($ids, 0, $n);
    }

    private function requireSpec(string $attr, ?float $min, ?float $max, bool $required = true, string $kind = 'top'): void
    {
        DB::table('material_spec_requirements')->insert(['garment_kind' => $kind, 'attribute' => $attr, 'min_value' => $min, 'max_value' => $max,
            'is_required' => $required, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_existing_materials_stay_valid_and_every_spec_is_unknown(): void
    {
        $this->assertGreaterThan(0, DB::table('materials')->count());
        foreach (['gsm', 'usable_width_cm', 'stretch_pct', 'fiber_composition', 'spec_verified_at'] as $col) {
            $this->assertSame(0, DB::table('materials')->whereNotNull($col)->count(), "{$col} must default to unknown, never an invented value");
        }
        $this->assertSame(0, DB::table('material_spec_requirements')->count());
    }

    public function test_manager_records_specs_in_canonical_units_with_provenance_and_unverified(): void
    {
        $this->actAs(1);
        $id = $this->fabricId();
        $this->putJson("/api/admin/materials/{$id}", ['gsm' => 5, 'gsm_unit' => 'oz_yd2', 'usable_width' => 60, 'usable_width_unit' => 'in',
            'spec_source' => 'supplier_datasheet', 'spec_reference' => 'DS-77'])->assertOk();
        $row = DB::table('materials')->where('material_id', $id)->first();
        $this->assertEquals(169.5, $row->gsm);
        $this->assertEquals(152.4, $row->usable_width_cm);
        $this->assertNull($row->spec_verified_at);
        $this->assertSame('supplier_datasheet', json_decode($row->spec_provenance, true)['gsm']['source']);
    }

    public function test_values_without_a_source_and_out_of_range_values_are_rejected(): void
    {
        $this->actAs(1);
        $id = $this->fabricId();
        $this->putJson("/api/admin/materials/{$id}", ['gsm' => 150])->assertStatus(422)->assertJsonValidationErrors(['spec_source']);
        $this->putJson("/api/admin/materials/{$id}", ['gsm' => 9999, 'spec_source' => 'lab_test'])->assertStatus(422);
        $this->assertNull(DB::table('materials')->where('material_id', $id)->value('gsm'));
    }

    public function test_verification_needs_specs_and_any_later_edit_clears_it(): void
    {
        $this->actAs(1);
        $id = $this->fabricId();
        $this->putJson("/api/admin/materials/{$id}", ['spec_verified' => true])->assertStatus(422);
        $this->putJson("/api/admin/materials/{$id}", ['gsm' => 150, 'spec_source' => 'lab_test', 'spec_verified' => true])->assertOk();
        $this->assertNotNull(DB::table('materials')->where('material_id', $id)->value('spec_verified_at'));
        $this->putJson("/api/admin/materials/{$id}", ['gsm' => 160, 'spec_source' => 'lab_test'])->assertOk();
        $this->assertNull(DB::table('materials')->where('material_id', $id)->value('spec_verified_at'));
    }

    public function test_a_customer_cannot_edit_material_specs(): void
    {
        $this->actAs(5);
        $id = $this->fabricId();
        $this->putJson("/api/admin/materials/{$id}", ['gsm' => 150, 'spec_source' => 'other'])->assertForbidden();
        $this->assertNull(DB::table('materials')->where('material_id', $id)->value('gsm'));
    }

    public function test_verified_mismatch_removes_a_fabric_but_unknown_specs_stay_and_are_disclosed_without_numbers(): void
    {
        config(['services.gemini.key_1' => 'test-key']);
        [$bad, $unknown] = $ids = $this->eligibleFabrics(2);
        DB::table('materials')->where('material_id', $bad)->update(['gsm' => 90, 'spec_verified_at' => now()]);
        $this->requireSpec('gsm', 140, 220, true);

        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
            'selections' => [['material_id' => $bad, 'reason' => 'Looks right.'], ['material_id' => $unknown, 'reason' => 'Suits the design.']],
            'narration' => 'Fabric picks for this polo.'])]]]]]])]);
        $this->actAs(5);
        $res = $this->postJson('/api/customer/ai/recommend-materials', ['order_id' => 9])->assertOk();

        $got = collect($res->json('materials'))->pluck('material_id')->all();
        $this->assertNotContains($bad, $got);                                   // verified required mismatch: removed before ranking
        $this->assertDatabaseMissing('material_recommendations', ['order_id' => 9, 'material_id' => $bad]);
        $this->assertContains($unknown, $got);                                  // unknown GSM is not a reason to hide a material
        $spec = collect($res->json('materials'))->firstWhere('material_id', $unknown)['spec_fit'];
        $this->assertSame('unknown', $spec['items'][0]['status']);
        $this->assertFalse($spec['complete']);
        $this->assertDoesNotMatchRegularExpression('/\d/', json_encode($spec)); // no thresholds or values for customers
        $this->assertStringNotContainsString('spec_snapshot', json_encode($res->json()));
        $this->assertNotNull(DB::table('material_recommendations')->where(['order_id' => 9, 'material_id' => $unknown])->value('spec_snapshot'));
    }

    public function test_gemini_failure_still_falls_back_and_orders_by_spec_fit(): void
    {
        config(['services.gemini.key_1' => 'test-key']);
        [$first, $second] = $ids = $this->eligibleFabrics(2);
        DB::table('materials')->whereIn('material_id', $ids)->update(['quantity_in_stock' => 10]);
        DB::table('materials')->where('material_id', $second)->update(['gsm' => 160, 'spec_verified_at' => now()]);
        $this->requireSpec('gsm', 140, 220, false);
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->actAs(5);
        $res = $this->postJson('/api/customer/ai/recommend-materials', ['order_id' => 9])->assertOk();
        $this->assertSame('catalog_default', $res->json('source'));
        $this->assertContains($second, collect($res->json('materials'))->pluck('material_id')->all());
    }

    public function test_evaluation_context_leaves_the_orders_own_verdict_out(): void
    {
        $order = Order::findOrFail(9);
        [$id] = $this->eligibleFabrics(1);
        DB::table('material_recommendations')->insert(['order_id' => 9, 'material_id' => $id, 'material_name' => 'x', 'category' => 'Fabric', 'unit' => 'yards',
            'status' => 'pending', 'staff_feedback' => 'accept', 'staff_feedback_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $r = app(MaterialRecommender::class);
        $prep = $r->prepare($order);
        $this->assertIsArray($prep);
        $this->assertContains($id, $r->context($order, $prep['eligible'], $prep['profile'])['approved_material_ids']);
        $this->assertNotContains($id, $r->context($order, $prep['eligible'], $prep['profile'], 9)['approved_material_ids']);
    }

    public function test_manager_defines_requirements_atomically_and_bad_rows_change_nothing(): void
    {
        $this->actAs(1);
        $url = '/api/admin/material-spec-requirements/top';
        $this->putJson($url, ['requirements' => [['attribute' => 'gsm', 'min_value' => 140, 'max_value' => 220, 'is_required' => true], ['attribute' => 'construction', 'text_value' => 'knit']]])->assertOk();
        $this->assertSame(2, DB::table('material_spec_requirements')->where('garment_kind', 'top')->count());

        $this->putJson($url, ['requirements' => [['attribute' => 'gsm', 'min_value' => 1], ['attribute' => 'nonsense', 'min_value' => 1]]])->assertStatus(422);
        $this->assertSame(2, DB::table('material_spec_requirements')->where('garment_kind', 'top')->count()); // untouched
        $this->putJson($url, ['requirements' => [['attribute' => 'gsm', 'min_value' => 100], ['attribute' => 'gsm', 'max_value' => 200]]])->assertStatus(422); // duplicate

        $this->getJson('/api/admin/material-spec-requirements?garment_kind=top')->assertOk()->assertJsonCount(2, 'data');
        $this->putJson('/api/admin/material-spec-requirements/hat', ['requirements' => []])->assertNotFound();
        $this->putJson($url, ['requirements' => []])->assertOk();
        $this->assertSame(0, DB::table('material_spec_requirements')->where('garment_kind', 'top')->count());
    }

    public function test_a_customer_cannot_read_or_change_requirements(): void
    {
        $this->actAs(5);
        $this->getJson('/api/admin/material-spec-requirements')->assertForbidden();
        $this->putJson('/api/admin/material-spec-requirements/top', ['requirements' => []])->assertForbidden();
    }
}
