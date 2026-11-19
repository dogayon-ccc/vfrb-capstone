<?php

namespace Tests\Unit;

use App\Services\MaterialCatalogMatcher;
use App\Services\MaterialSpecMatcher;
use PHPUnit\Framework\TestCase;

class MaterialSpecMatcherTest extends TestCase
{
    private const REQS = [
        ['attribute' => 'gsm', 'min_value' => 140, 'max_value' => 220, 'text_value' => null, 'is_required' => true],
        ['attribute' => 'stretch_pct', 'min_value' => 10, 'max_value' => null, 'text_value' => null, 'is_required' => false],
        ['attribute' => 'construction', 'min_value' => null, 'max_value' => null, 'text_value' => 'knit', 'is_required' => false],
    ];

    private function evaluateWith(array $m): array
    {
        return (new MaterialSpecMatcher())->evaluate($m, self::REQS);
    }

    private function statuses(array $eval): array
    {
        return array_column($eval['checks'], 'status', 'attribute');
    }

    public function test_material_with_no_specs_is_unknown_everywhere_never_a_match_and_never_removed(): void
    {
        $e = $this->evaluateWith(['material_id' => 1]);
        $this->assertSame(['gsm' => 'unknown', 'stretch_pct' => 'unknown', 'construction' => 'unknown'], $this->statuses($e));
        $this->assertFalse($e['hard_fail']);
        $this->assertFalse($e['complete']);
        $this->assertSame(0.0, $e['spec_fit']);
    }

    public function test_recorded_but_unverified_values_are_unconfirmed(): void
    {
        $e = $this->evaluateWith(['gsm' => 160, 'stretch_pct' => 15, 'construction' => 'knit']);
        $this->assertSame(['gsm' => 'match_unconfirmed', 'stretch_pct' => 'match_unconfirmed', 'construction' => 'match_unconfirmed'], $this->statuses($e));
        $this->assertSame(0.5, $e['spec_fit']);
        $this->assertFalse($e['verified']);
    }

    public function test_verified_values_match_and_complete(): void
    {
        $e = $this->evaluateWith(['gsm' => 160, 'stretch_pct' => 15, 'construction' => 'KNIT', 'spec_verified_at' => '2026-11-19 00:00:00']);
        $this->assertSame(['gsm' => 'match', 'stretch_pct' => 'match', 'construction' => 'match'], $this->statuses($e));
        $this->assertSame(1.0, $e['spec_fit']);
        $this->assertTrue($e['complete']);
    }

    public function test_only_a_verified_mismatch_on_a_required_spec_removes_the_material(): void
    {
        $this->assertTrue($this->evaluateWith(['gsm' => 90, 'spec_verified_at' => '2026-11-19'])['hard_fail']);
        $this->assertFalse($this->evaluateWith(['gsm' => 90])['hard_fail']);                       // unverified mismatch stays, flagged
        $this->assertFalse($this->evaluateWith(['stretch_pct' => 2, 'spec_verified_at' => '2026-11-19'])['hard_fail']); // optional requirement
    }

    public function test_fibre_requirement_reads_the_composition_and_unknown_when_absent(): void
    {
        $r = [['attribute' => 'fiber:cotton', 'min_value' => 50, 'max_value' => null, 'text_value' => null, 'is_required' => true]];
        $m = new MaterialSpecMatcher();
        $this->assertSame('unknown', $m->evaluate(['fiber_composition' => null], $r)['checks'][0]['status']);
        $this->assertSame('match', $m->evaluate(['fiber_composition' => [['fiber' => 'Cotton', 'pct' => 60], ['fiber' => 'polyester', 'pct' => 40]], 'spec_verified_at' => 'x'], $r)['checks'][0]['status']);
        $this->assertSame('mismatch', $m->evaluate(['fiber_composition' => [['fiber' => 'polyester', 'pct' => 100]], 'spec_verified_at' => 'x'], $r)['checks'][0]['status']);
    }

    public function test_no_requirements_means_no_score_and_no_claim(): void
    {
        $e = (new MaterialSpecMatcher())->evaluate(['gsm' => 150], []);
        $this->assertNull($e['spec_fit']);
        $this->assertFalse($e['complete']);
        $this->assertSame('none defined', (new MaterialSpecMatcher())->promptLine($e));
    }

    public function test_customer_view_and_prompt_line_carry_no_numbers_or_thresholds(): void
    {
        $m = new MaterialSpecMatcher();
        $e = $m->evaluate(['gsm' => 160, 'stretch_pct' => 15, 'spec_verified_at' => 'x'], self::REQS);
        $this->assertDoesNotMatchRegularExpression('/\d/', json_encode($m->customerView($e)));
        $this->assertDoesNotMatchRegularExpression('/\d/', $m->promptLine($e));
        $this->assertStringNotContainsString('160', json_encode($m->customerView($e)));
    }

    public function test_fallback_prefers_better_spec_fit_after_stock_and_preference_but_before_colour(): void
    {
        $row = fn ($id, $fit, $colour = 'body') => ['material_id' => $id, 'material_code' => "C{$id}", 'material_name' => "Fabric {$id}", 'category' => 'Fabric',
            'colour_match' => $colour, 'in_stock' => true, 'spec' => $fit === null ? null : ['spec_fit' => $fit]];
        $eligible = [1 => $row(1, 0.0, 'body'), 2 => $row(2, 1.0, 'near'), 3 => $row(3, null, 'body')];
        $picks = (new MaterialCatalogMatcher())->fallback($eligible);
        $this->assertSame(2, $picks['selections'][0]['material_id']);
    }

    public function test_fallback_without_spec_data_keeps_the_previous_order(): void
    {
        $row = fn ($id) => ['material_id' => $id, 'material_code' => "C{$id}", 'material_name' => "Fabric {$id}", 'category' => 'Fabric', 'colour_match' => 'body', 'in_stock' => true];
        $picks = (new MaterialCatalogMatcher())->fallback([5 => $row(5), 3 => $row(3)]);
        $this->assertSame(3, $picks['selections'][0]['material_id']); // lowest material_id, as before
    }

    private function fabric(int $id, ?array $spec, string $colour = 'body', ?bool $stock = true, ?string $name = null): array
    {
        return ['material_id' => $id, 'material_code' => "C{$id}", 'material_name' => $name ?? "Fabric {$id}", 'category' => 'Fabric',
            'colour_match' => $colour, 'in_stock' => $stock, 'spec' => $spec];
    }

    public function test_risk_counts_only_required_attributes_whose_unverified_value_fails(): void
    {
        $this->assertSame(1, $this->evaluateWith(['gsm' => 90])['risk']);                                  // recorded, unverified, fails a required spec
        $this->assertSame(0, $this->evaluateWith(['gsm' => 160])['risk']);                                 // passes
        $this->assertSame(0, $this->evaluateWith(['material_id' => 1])['risk']);                           // no data is not evidence against
        $this->assertSame(0, $this->evaluateWith(['stretch_pct' => 2])['risk']);                           // optional requirement
        $this->assertSame(0, $this->evaluateWith(['gsm' => 90, 'spec_verified_at' => '2026-11-19'])['risk']); // verified mismatch is a hard fail, not a risk
    }

    public function test_a_recorded_failing_value_ranks_below_a_material_with_no_data_even_when_in_stock(): void
    {
        $m = new MaterialSpecMatcher();
        $risky   = $this->fabric(1, $m->evaluate(['gsm' => 90], self::REQS));
        $unknown = $this->fabric(2, $m->evaluate(['material_id' => 2], self::REQS), 'body', false);   // no data, and out of stock
        $this->assertSame($risky['spec']['spec_fit'], $unknown['spec']['spec_fit']);                  // same coverage score, so only risk tells them apart
        $picks = (new MaterialCatalogMatcher())->fallback([1 => $risky, 2 => $unknown]);
        $this->assertSame(2, $picks['selections'][0]['material_id']);
    }

    public function test_ai_pick_with_a_recorded_failing_value_is_swapped_for_a_clean_equal_fit_fabric(): void
    {
        $m = new MaterialSpecMatcher();
        $eligible = [1 => $this->fabric(1, $m->evaluate(['gsm' => 90], self::REQS)), 2 => $this->fabric(2, $m->evaluate(['gsm' => 160], self::REQS))];
        $out = (new MaterialCatalogMatcher())->enforceSpecPolicy([['material_id' => 1, 'reason' => 'x']], $eligible);
        $this->assertSame([2, 1], [$out['selections'][0]['material_id'], $out['swapped']]);
        $this->assertStringNotContainsString('x', $out['selections'][0]['reason']);
    }

    public function test_the_swap_never_trades_colour_stock_rejections_or_kind_and_never_touches_clean_picks(): void
    {
        $m = new MaterialSpecMatcher();
        $bad  = $m->evaluate(['gsm' => 90], self::REQS);
        $good = $m->evaluate(['gsm' => 160], self::REQS);
        $matcher = new MaterialCatalogMatcher();
        $pick = [['material_id' => 1, 'reason' => 'x']];

        // Worse colour fit, out of stock while the original is in stock, staff-rejected, and a lining for a main fabric: all stay as chosen.
        foreach ([
            [1 => $this->fabric(1, $bad), 2 => $this->fabric(2, $good, 'near')],
            [1 => $this->fabric(1, $bad), 2 => $this->fabric(2, $good, 'body', false)],
            [1 => $this->fabric(1, $bad), 2 => $this->fabric(2, $good, 'body', true, 'Cotton Lining')],
        ] as $eligible) {
            $this->assertSame([1, 0], [$matcher->enforceSpecPolicy($pick, $eligible)['selections'][0]['material_id'], $matcher->enforceSpecPolicy($pick, $eligible)['swapped']]);
        }
        $rejected = [1 => $this->fabric(1, $bad), 2 => $this->fabric(2, $good)];
        $this->assertSame(1, $matcher->enforceSpecPolicy($pick, $rejected, [], [2])['selections'][0]['material_id']);

        // Already-clean picks, picks without spec data, and a swap target already chosen are left alone.
        $clean = [1 => $this->fabric(1, $good), 2 => $this->fabric(2, $good)];
        $this->assertSame(0, $matcher->enforceSpecPolicy($pick, $clean)['swapped']);
        $none = [1 => $this->fabric(1, null), 2 => $this->fabric(2, $good)];
        $this->assertSame(0, $matcher->enforceSpecPolicy($pick, $none)['swapped']);
        $both = [1 => $this->fabric(1, $bad), 2 => $this->fabric(2, $good)];
        $two  = $matcher->enforceSpecPolicy([['material_id' => 1, 'reason' => 'x'], ['material_id' => 2, 'reason' => 'y']], $both);
        $this->assertSame([[1, 2], 0], [array_column($two['selections'], 'material_id'), $two['swapped']]);
    }
}
