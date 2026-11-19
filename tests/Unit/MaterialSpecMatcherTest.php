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
}
