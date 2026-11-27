<?php

namespace Tests\Feature;

use App\Models\MaterialRecommendation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// vfrb:ml-readiness counts only labels vfrb:ai-eval would use. Fixture (vfrb_test): user 1 = manager, orders 8 and 9 = polo orders.
// NOT RUN in the authoring sandbox: php artisan test --filter=MlReadinessTest
class MlReadinessTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('vfrb_test', DB::connection()->getDatabaseName(), 'Tests run only against a database named vfrb_test.');
        DB::table('material_recommendations')->where(fn ($q) => $q->whereNotNull('staff_feedback')->orWhereIn('order_id', [8, 9]))->delete();
    }

    private function mat(int $n): int { return (int) DB::table('materials')->orderBy('material_id')->skip($n)->value('material_id'); }

    private function line(int $order, ?int $mat, ?string $verdict, ?int $by = 1, ?string $at = 'now', ?string $aiNote = 'ok'): void
    {
        DB::table('material_recommendations')->insert([
            'order_id' => $order, 'material_id' => $mat, 'material_name' => 'M' . $mat, 'category' => 'Fabric', 'unit' => 'yards', 'ai_note' => $aiNote,
            'status' => 'pending', 'staff_feedback' => $verdict, 'staff_feedback_by' => $by, 'staff_feedback_at' => $at === 'now' ? now() : $at,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function report(int $min = 200): array
    {
        Artisan::call('vfrb:ml-readiness', ['--json' => true, '--min-orders' => $min]);

        return json_decode(Artisan::output(), true);
    }

    private function sameDesign(): void
    {
        $o8 = DB::table('orders')->where('order_id', 8)->first();
        DB::table('orders')->where('order_id', 9)->update(['user_id' => $o8->user_id, 'garment_type' => $o8->garment_type, 'studio_config' => $o8->studio_config, 'color' => $o8->color]);
    }

    public function test_zero_data_is_never_ready_even_with_a_threshold_of_one(): void
    {
        $r = $this->report(1);

        $this->assertSame([0, 0, 0], [$r['feedback']['raw_observations'], $r['feedback']['genuine_verdicts'], $r['orders']['independent_groups']]);
        $this->assertFalse($r['ml_ready']);
        $this->assertStringContainsString('No genuine staff verdicts', $r['blockers'][0]);
        $this->assertFalse($r['labels']['both_classes']);
    }

    public function test_only_attributed_dated_accept_reject_staff_verdicts_count_as_labels(): void
    {
        $this->line(8, $this->mat(0), 'accept');                                            // genuine
        $this->line(8, $this->mat(1), 'maybe');                                             // invalid label
        $this->line(8, $this->mat(2), 'reject', null);                                      // unattributed
        $this->line(8, $this->mat(3), 'reject', 1, null);                                   // undated
        $this->line(8, $this->mat(4), 'accept', 1, 'now', MaterialRecommendation::CUSTOMER_PICK_NOTE); // the customer's own pick
        $this->line(8, null, 'reject');                                                     // linked to no material
        DB::statement('SET FOREIGN_KEY_CHECKS=0');                                          // staff_feedback_by is a foreign key; only legacy or imported rows could dangle
        try { $this->line(8, $this->mat(5), 'accept', 999999); } finally { DB::statement('SET FOREIGN_KEY_CHECKS=1'); } // attributed to no existing user

        $r = $this->report();

        $this->assertSame(7, $r['feedback']['raw_observations']);
        $this->assertSame(1, $r['feedback']['genuine_verdicts']);
        $this->assertSame(6, $r['feedback']['excluded_total']); // raw minus genuine; the reasons below can overlap
        $this->assertSame([1, 1, 1, 1, 1, 1], array_values($r['feedback']['excluded_reasons_may_overlap']));
        $this->assertSame([1, 0, 1], [$r['labels']['accept'], $r['labels']['reject'], $r['orders']['eligible']]);
    }

    public function test_near_duplicate_orders_count_once_and_one_sided_labels_block_readiness(): void
    {
        $this->sameDesign();
        $this->line(8, $this->mat(0), 'accept');
        $this->line(9, $this->mat(0), 'reject');

        $r = $this->report(1);

        $this->assertSame([2, 1, 1], [$r['orders']['eligible'], $r['orders']['duplicates_collapsed'], $r['orders']['independent_groups']]);
        $this->assertSame([0, 1], [$r['labels']['accept'], $r['labels']['reject']]); // newest of the group only
        $this->assertFalse($r['labels']['both_classes']);
        $this->assertFalse($r['ml_ready']);
        $this->assertStringContainsString('no accepts or no rejects', implode(' ', $r['blockers']));
    }

    public function test_distinct_designs_with_both_classes_clear_label_contrast_but_not_the_planning_threshold(): void
    {
        $this->line(8, $this->mat(0), 'accept');
        $this->line(9, $this->mat(0), 'reject');
        $o9 = DB::table('orders')->where('order_id', 9)->first();
        DB::table('orders')->where('order_id', 8)->update(['user_id' => $o9->user_id, 'color' => '#123456', 'studio_config' => null]);
        DB::table('orders')->where('order_id', 9)->update(['color' => '#abcdef', 'studio_config' => null]);

        $r = $this->report();

        $this->assertSame(2, $r['orders']['independent_groups']);
        $this->assertTrue($r['labels']['both_classes']);
        $this->assertFalse($r['ml_ready']);
        $this->assertStringContainsString('Only 2 independent', implode(' ', $r['blockers']));
        $this->assertStringNotContainsString('no accepts or no rejects', implode(' ', $r['blockers']));
        $this->assertStringContainsString('not evidence of accuracy', $r['threshold']['meaning']);
    }

    public function test_readiness_is_read_only(): void
    {
        $this->line(8, $this->mat(0), 'accept');
        $before = DB::table('material_recommendations')->count();
        $this->report();
        $this->assertSame($before, DB::table('material_recommendations')->count());
    }

    public function test_genuine_verdicts_are_split_by_the_engine_that_produced_the_line(): void
    {
        if (!Schema::hasColumn('material_recommendations', 'rec_source')) { $this->markTestSkipped('Run the provenance migration on vfrb_test first.'); }
        $this->line(8, $this->mat(0), 'accept');
        $this->line(8, $this->mat(1), 'reject');
        $this->line(8, $this->mat(2), 'accept');
        DB::table('material_recommendations')->where('order_id', 8)->where('material_id', $this->mat(0))->update(['rec_source' => 'ai']);
        DB::table('material_recommendations')->where('order_id', 8)->where('material_id', $this->mat(1))->update(['rec_source' => 'catalog_default']);

        $this->assertSame(['ai' => 1, 'catalog_default' => 1, 'unrecorded' => 1], $this->report()['feedback']['genuine_by_source']);
    }
}
