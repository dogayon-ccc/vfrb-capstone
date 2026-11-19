<?php

namespace Tests\Unit;

use App\Support\MaterialSpec;
use PHPUnit\Framework\TestCase;

class MaterialSpecTest extends TestCase
{
    public function test_nothing_supplied_stays_untouched_and_nothing_is_defaulted(): void
    {
        $n = MaterialSpec::normalize([]);
        $this->assertSame([], $n['values']);
        $this->assertSame([], $n['errors']);
    }

    public function test_units_convert_to_canonical_gsm_and_cm(): void
    {
        $n = MaterialSpec::normalize(['gsm' => 5, 'gsm_unit' => 'oz_yd2', 'usable_width' => 60, 'usable_width_unit' => 'in']);
        $this->assertSame(169.5, $n['values']['gsm']);
        $this->assertSame(152.4, $n['values']['usable_width_cm']);
        $this->assertSame([], $n['errors']);
    }

    public function test_out_of_range_values_are_rejected_not_clamped(): void
    {
        $n = MaterialSpec::normalize(['gsm' => 5000, 'stretch_pct' => -1, 'colourfastness_grade' => 6]);
        $this->assertArrayHasKey('gsm', $n['errors']);
        $this->assertArrayHasKey('stretch_pct', $n['errors']);
        $this->assertArrayHasKey('colourfastness_grade', $n['errors']);
    }

    public function test_explicit_null_clears_a_value_back_to_unknown(): void
    {
        $n = MaterialSpec::normalize(['gsm' => null, 'finish' => '']);
        $this->assertArrayHasKey('gsm', $n['values']);
        $this->assertNull($n['values']['gsm']);
        $this->assertNull($n['values']['finish']);
    }

    public function test_fibre_percentages_must_total_100_and_names_are_unique(): void
    {
        $ok = MaterialSpec::normalize(['fiber_composition' => [['fiber' => 'Polyester', 'pct' => 65], ['fiber' => 'cotton', 'pct' => 35]]]);
        $this->assertSame([], $ok['errors']);
        $this->assertSame('polyester', $ok['values']['fiber_composition'][0]['fiber']);
        $this->assertArrayHasKey('fiber_composition', MaterialSpec::normalize(['fiber_composition' => [['fiber' => 'cotton', 'pct' => 60]]])['errors']);
        $this->assertArrayHasKey('fiber_composition', MaterialSpec::normalize(['fiber_composition' => [['fiber' => 'cotton', 'pct' => 50], ['fiber' => 'Cotton', 'pct' => 50]]])['errors']);
    }

    public function test_stretch_direction_needs_a_stretch_value_and_a_known_direction(): void
    {
        $this->assertArrayHasKey('stretch_direction', MaterialSpec::normalize(['stretch_direction' => 'both'])['errors']);
        $this->assertArrayHasKey('stretch_direction', MaterialSpec::normalize(['stretch_pct' => 10, 'stretch_direction' => 'diagonal'])['errors']);
        $this->assertSame([], MaterialSpec::normalize(['stretch_pct' => 10, 'stretch_direction' => 'both'])['errors']);
    }

    public function test_provenance_records_source_and_never_verifies_by_default(): void
    {
        $p = MaterialSpec::provenance(['gsm' => 150.0, 'finish' => null], ['spec_source' => 'supplier_datasheet', 'spec_reference' => 'DS-1'], ['finish' => ['source' => 'other']], '2026-11-19T00:00:00+00:00');
        $this->assertSame('supplier_datasheet', $p['provenance']['gsm']['source']);
        $this->assertArrayNotHasKey('finish', $p['provenance']); // cleared value loses its provenance
        $this->assertFalse($p['verified']);
    }

    public function test_requirement_rows_are_validated_and_normalised(): void
    {
        $ok = MaterialSpec::requirement(['attribute' => 'GSM', 'min_value' => 140, 'max_value' => 220, 'is_required' => true]);
        $this->assertNull($ok['error']);
        $this->assertSame(['attribute' => 'gsm', 'min_value' => 140.0, 'max_value' => 220.0, 'text_value' => null, 'is_required' => true], $ok['row']);
        $this->assertNotNull(MaterialSpec::requirement(['attribute' => 'gsm'])['error']);                                   // no bounds
        $this->assertNotNull(MaterialSpec::requirement(['attribute' => 'gsm', 'min_value' => 300, 'max_value' => 100])['error']);
        $this->assertNotNull(MaterialSpec::requirement(['attribute' => 'gsm', 'min_value' => 5000])['error']);
        $this->assertNotNull(MaterialSpec::requirement(['attribute' => 'weight'])['error']);                                 // unknown attribute
        $this->assertNull(MaterialSpec::requirement(['attribute' => 'fiber:cotton', 'min_value' => 50])['error']);
        $this->assertNotNull(MaterialSpec::requirement(['attribute' => 'fiber:cotton', 'min_value' => 150])['error']);
        $this->assertNull(MaterialSpec::requirement(['attribute' => 'construction', 'text_value' => 'Knit'])['error']);
        $this->assertNotNull(MaterialSpec::requirement(['attribute' => 'construction', 'text_value' => 'felt'])['error']);
        $this->assertNotNull(MaterialSpec::requirement(['attribute' => 'construction'])['error']);
    }
}
