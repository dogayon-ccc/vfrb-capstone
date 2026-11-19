<?php

namespace Tests\Unit;

use App\Support\AiReviewStats;
use PHPUnit\Framework\TestCase;

class AiReviewStatsTest extends TestCase
{
    public function test_ratings_count_normalise_and_never_treat_blank_as_a_rating(): void
    {
        $r = AiReviewStats::ratings([
            ['garment' => 'Polo', 'rating' => 'Appropriate'], ['garment' => 'Polo', 'rating' => 'acceptable with changes'],
            ['garment' => 'Pants', 'rating' => 'i'], ['garment' => 'Pants', 'rating' => ''], ['garment' => 'Pants', 'rating' => 'maybe'],
        ]);
        $this->assertSame(['appropriate' => 1, 'acceptable' => 1, 'inappropriate' => 1], $r['counts']);
        $this->assertSame(3, $r['rated']);
        $this->assertSame(1, $r['unrated']);
        $this->assertSame(1, $r['invalid']);
        $this->assertSame(33.3, $r['percent']['appropriate']);
    }

    public function test_no_rated_rows_gives_null_percentages_not_zero_accuracy(): void
    {
        $r = AiReviewStats::ratings([['garment' => 'x', 'rating' => '']]);
        $this->assertSame(0, $r['rated']);
        $this->assertNull($r['percent']['appropriate']);
    }

    public function test_run_stats_count_fallback_and_allowed_list_rejections_and_exclude_skipped_orders(): void
    {
        $s = AiReviewStats::run([
            ['source' => 'ai', 'dropped' => ['unknown' => 0, 'duplicate' => 0, 'sanitized' => 1, 'forbidden_field' => 0]],
            ['source' => 'catalog_default', 'ai_empty' => true, 'dropped' => ['unknown' => 0, 'duplicate' => 0, 'sanitized' => 0, 'forbidden_field' => 0]],
            ['source' => 'ai', 'dropped' => ['unknown' => 2, 'duplicate' => 1, 'sanitized' => 0, 'forbidden_field' => 0]],
            ['skipped' => 'No approved materials are configured for this garment yet.'],
        ]);
        $this->assertSame(4, $s['orders_total']);
        $this->assertSame(3, $s['orders_run']);
        $this->assertSame(1, $s['catalog_fallback']);
        $this->assertSame(33.3, $s['catalog_fallback_pct']);
        $this->assertSame(1, $s['gemini_no_usable_reply']);
        $this->assertSame(2, $s['rejected_not_in_allowed_list']);
        $this->assertSame(2, $s['orders_with_rejected_items']);
    }

    public function test_comparison_with_past_orders_only_counts_orders_that_have_records(): void
    {
        $p = AiReviewStats::againstPast([
            ['order_id' => 1, 'material_code' => 'mat-fab-001', 'vfrb_used' => 'MAT-FAB-001; MAT-THR-002'],
            ['order_id' => 1, 'material_code' => 'MAT-FAB-009', 'vfrb_used' => 'MAT-FAB-001;MAT-THR-002'],
            ['order_id' => 2, 'material_code' => 'MAT-FAB-005', 'vfrb_used' => ''],
        ]);
        $this->assertSame(1, $p['orders_compared']);
        $this->assertSame(1, $p['suggested_that_vfrb_used']);
        $this->assertSame(2, $p['suggested_total']);
        $this->assertSame(1, $p['vfrb_materials_covered']);
        $this->assertSame(2, $p['vfrb_materials_total']);
    }
}
