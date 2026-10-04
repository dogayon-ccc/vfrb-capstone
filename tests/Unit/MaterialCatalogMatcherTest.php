<?php
// tests/Unit/MaterialCatalogMatcherTest.php
namespace Tests\Unit;

use App\Services\MaterialCatalogMatcher;
use PHPUnit\Framework\TestCase;

class MaterialCatalogMatcherTest extends TestCase
{
    private MaterialCatalogMatcher $m;

    protected function setUp(): void
    {
        $this->m = new MaterialCatalogMatcher();
    }

    private function catalog(): array
    {
        return [
            ['material_id' => 1, 'material_name' => 'Cotton Fabric',            'category' => 'Fabric',      'unit' => 'yards'],
            ['material_id' => 2, 'material_name' => 'Polyester Thread',         'category' => 'Thread',      'unit' => 'kg', 'ai_eligible' => 1],
            ['material_id' => 3, 'material_name' => 'Satin Lining',             'category' => 'Fabric',      'unit' => 'yards', 'ai_eligible' => 0],
            ['material_id' => 4, 'material_name' => 'Elastic Band (1 inch)',    'category' => 'Elastic',     'unit' => 'meters', 'applies_to' => ['Pants', 'Shorts']],
            ['material_id' => 5, 'material_name' => 'Buttons (Set)',            'category' => 'Accessories', 'unit' => 'sets', 'applies_to' => '["Polo Shirt"]'],
        ];
    }

    public function test_eligible_skips_flagged_off_and_other_garments(): void
    {
        $this->assertSame([1, 2, 5], array_keys($this->m->eligible($this->catalog(), 'polo-shirt')));
        $this->assertSame([1, 2, 4], array_keys($this->m->eligible($this->catalog(), 'PANTS')));
    }

    public function test_unknown_garment_only_gets_unrestricted_materials(): void
    {
        $this->assertSame([1, 2], array_keys($this->m->eligible($this->catalog(), null)));
    }

    public function test_schema_limits_material_id_to_eligible_ids(): void
    {
        $e = $this->m->eligible($this->catalog(), 'Pants');
        $s = $this->m->schema($e);
        $this->assertSame([1, 2, 4], $s['properties']['selections']['items']['properties']['material_id']['enum']);
        $this->assertSame(['selections', 'narration'], $s['required']);
    }

    public function test_normalize_drops_unknown_duplicate_and_ineligible_ids(): void
    {
        $e = $this->m->eligible($this->catalog(), 'Pants');
        $r = $this->m->normalize([
            'selections' => [
                ['material_id' => 1,   'reason' => 'Main body fabric.'],
                ['material_id' => 1,   'reason' => 'Again.'],
                ['material_id' => 3,   'reason' => 'Flagged off, must not pass.'],
                ['material_id' => 999, 'reason' => 'Hallucinated.'],
                ['material_id' => '4', 'reason' => 'Waistband.'],
                'junk',
            ],
            'narration' => 'Cotton fabric and elastic suit these pants.',
        ], $e);
        $this->assertSame([1, 4], array_column($r['selections'], 'material_id'));
        $this->assertSame(['unknown' => 3, 'duplicate' => 1, 'sanitized' => 0], $r['dropped']);
    }

    public function test_quantity_and_price_language_is_replaced(): void
    {
        $e = $this->m->eligible($this->catalog(), 'Pants');
        $r = $this->m->normalize([
            'selections' => [
                ['material_id' => 1, 'reason' => 'You will need about 120 yards of this.'],
                ['material_id' => 2, 'reason' => 'Cheapest option at ₱450.'],
            ],
            'narration' => 'Total of 200 pcs of material.',
        ], $e);
        $this->assertSame('Suitable fabric for this design.', $r['selections'][0]['reason']);
        $this->assertSame('Suitable thread for this design.', $r['selections'][1]['reason']);
        $this->assertSame('Recommended materials for this design: Cotton Fabric and Polyester Thread.', $r['narration']);
        $this->assertSame(3, $r['dropped']['sanitized']);
    }

    public function test_catalog_names_with_digits_are_not_penalised(): void
    {
        $e = $this->m->eligible($this->catalog(), 'Pants');
        $r = $this->m->normalize([
            'selections' => [['material_id' => 4, 'reason' => 'Elastic Band (1 inch) keeps the waistband snug.']],
            'narration'  => 'Elastic Band (1 inch) for the waist.',
        ], $e);
        $this->assertSame('Elastic Band (1 inch) keeps the waistband snug.', $r['selections'][0]['reason']);
        $this->assertSame(0, $r['dropped']['sanitized']);
    }

    public function test_selection_count_is_capped(): void
    {
        $rows = [];
        for ($i = 1; $i <= 12; $i++) { $rows[] = ['material_id' => $i, 'material_name' => "M{$i}", 'category' => 'Trims', 'unit' => 'pcs']; }
        $e = $this->m->eligible($rows, 'Any');
        $sel = array_map(fn ($i) => ['material_id' => $i, 'reason' => 'Fits.'], range(1, 12));
        $r = $this->m->normalize(['selections' => $sel, 'narration' => 'ok'], $e);
        $this->assertCount(8, $r['selections']);
    }

    public function test_empty_or_malformed_reply_yields_nothing(): void
    {
        $e = $this->m->eligible($this->catalog(), 'Pants');
        $this->assertSame([], $this->m->normalize(null, $e)['selections']);
        $this->assertSame('', $this->m->normalize(['selections' => 'nope'], $e)['narration']);
    }
}
