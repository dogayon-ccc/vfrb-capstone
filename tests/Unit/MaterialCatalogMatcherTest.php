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
        $this->assertSame(['unknown' => 3, 'duplicate' => 1, 'sanitized' => 0, 'forbidden_field' => 0], $r['dropped']);
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

    public function test_quantity_and_price_fields_are_rejected(): void
    {
        $e = $this->m->eligible($this->catalog(), 'Polo Shirt');
        $r = $this->m->normalize(['selections' => [
            ['material_id' => 1, 'reason' => 'a', 'quantity' => 3],
            ['material_id' => 2, 'reason' => 'b', 'unit_price' => 9],
            ['material_id' => 5, 'reason' => 'c'],
        ], 'narration' => 'n'], $e);
        $this->assertSame([5], array_column($r['selections'], 'material_id'));
        $this->assertSame(2, $r['dropped']['forbidden_field']);

        $r = $this->m->normalize(['selections' => [['material_id' => 1, 'reason' => 'a']], 'narration' => 'n', 'yardage' => 2], $e);
        $this->assertSame([], $r['selections']);
    }

    public function test_fallback_is_deterministic_and_quantity_free(): void
    {
        $e  = $this->m->eligible($this->catalog(), 'Polo Shirt');
        $fb = $this->m->fallback($e);
        $this->assertSame([1, 2], array_column($fb['selections'], 'material_id'));
        $this->assertFalse($this->m->hasForbiddenTerms($fb['narration'] . ' ' . implode(' ', array_column($fb['selections'], 'reason'))));
        $this->assertSame([], $this->m->fallback([])['selections']);
    }

    public function test_design_hash_ignores_key_order(): void
    {
        $this->assertSame($this->m->designHash(['a' => 1, 'b' => ['y' => 2, 'x' => 1]]), $this->m->designHash(['b' => ['x' => 1, 'y' => 2], 'a' => 1]));
        $this->assertNotSame($this->m->designHash(['fit' => 'slim']), $this->m->designHash(['fit' => 'regular']));
    }

    // Names mirror the live catalog, where colour is part of the material name.
    private function colourCatalog(): array
    {
        $rows = [
            55 => ['Airstretch Fabric - Maroon', 'Fabric'], 56 => ['Airstretch Fabric - Black', 'Fabric'],
            63 => ['TC Poplin 65/35 - Navy Blue', 'Fabric'], 65 => ['TC Twill 65/35 - Black', 'Fabric'],
            68 => ['Polyester Interlock / Dry-fit - Custom colors', 'Fabric'],
            69 => ['Polyester Thread - Black (Industrial)', 'Thread'], 70 => ['Polyester Thread - Maroon (Industrial)', 'Thread'],
            72 => ['Polyester Thread - Navy Blue (Industrial)', 'Thread'], 73 => ['Polyester Thread - Matching color (per order)', 'Thread'],
            74 => ['Elastic Band - 2-inch wide', 'Elastic'], 78 => ['Flat Button - 12mm (White)', 'Accessories'],
        ];
        return array_map(fn ($id, $r) => ['material_id' => $id, 'material_name' => $r[0], 'category' => $r[1], 'unit' => 'pcs'], array_keys($rows), $rows);
    }

    public function test_gray_garment_never_gets_maroon_or_black_fabric(): void
    {
        // Live order #22: gray scrub top with navy trim. The navy poplin is offered for the navy trim only (colour_match 'collar').
        $e = $this->m->eligible($this->colourCatalog(), 'Scrub Top', ['body' => '#808080', 'collar' => '#1B2A4A']);
        $this->assertSame([63, 68, 72, 73, 74, 78], array_keys($e));
        $this->assertSame('collar', $e[63]['colour_match']);
        $this->assertSame('any', $e[68]['colour_match']);
    }

    public function test_black_garment_gets_black_fabric_and_thread_only(): void
    {
        // Live order #23: #282828 joggers with green trim.
        $ids = array_keys($this->m->eligible($this->colourCatalog(), 'Pants', ['body' => '#282828', 'collar' => '#00A86B']));
        $this->assertSame([56, 65, 68, 69, 73, 74, 78], $ids);
    }

    public function test_colour_name_on_the_order_is_used_when_there_is_no_studio_design(): void
    {
        $ids = array_keys($this->m->eligible($this->colourCatalog(), 'Polo Shirt', ['all' => 'Navy']));
        $this->assertSame([63, 68, 72, 73, 74, 78], $ids);
    }

    public function test_unknown_colour_filters_nothing(): void
    {
        $this->assertCount(11, $this->m->eligible($this->colourCatalog(), 'Pants', ['all' => 'any']));
        $this->assertCount(11, $this->m->eligible($this->colourCatalog(), 'Pants'));
    }

    public function test_fallback_follows_the_colour_filter(): void
    {
        $eligible = $this->m->eligible($this->colourCatalog(), 'Scrub Top', ['body' => 'Gray']);
        $picked   = array_column($this->m->fallback($eligible)['selections'], 'material_id');
        $this->assertNotContains(55, $picked);
        $this->assertSame([68, 73], $picked);
    }

    public function test_colour_families(): void
    {
        foreach (['#282828' => 'black', '#808080' => 'gray', '#FFFFFF' => 'white', '#1E3A5F' => 'navy', '#800000' => 'maroon',
                  '#00A86B' => 'green', 'Old Rose' => 'pink', 'Dark Navy' => 'navy', 'Camel' => 'brown', 'Custom colors' => 'any',
                  'Care Label (wash instructions)' => null, 'Standard' => null] as $in => $want) {
            $this->assertSame($want, $this->m->colourFamily($in), $in);
        }
    }

    // ── Phase 2 (2026-10-16): any valid colour, contrast zones, stock, garment profiles ──────────────────────────────

    public function test_colour_formats_and_new_families(): void
    {
        foreach (['#abc' => 'blue', '#FFF' => 'white', '#1b2a4aff' => 'navy', 'rgb(255, 128, 0)' => 'orange', 'rgba(14,124,134,0.5)' => 'teal',
                  '#0E7C86' => 'teal', '#8B4513' => 'brown', '#F57C00' => 'orange', 'Coral Pink' => 'orange', 'Turquoise' => 'teal',
                  'Off-White' => 'white', 'Midnight Blue' => 'navy', 'not a colour' => null, '#12345' => null] as $in => $want) {
            $this->assertSame($want, $this->m->colourFamily($in), $in);
        }
    }

    public function test_near_colour_fabric_is_offered_and_labelled_not_hidden(): void
    {
        // A sky-blue design: no blue fabric exists, the navy one is offered as "near"; maroon and black fabric stay out.
        $e = $this->m->eligible($this->colourCatalog(), 'Polo Shirt', ['body' => '#87CEEB']);
        $this->assertSame('near', $e[63]['colour_match']);
        $this->assertArrayNotHasKey(55, $e);
        $this->assertArrayNotHasKey(65, $e);
    }

    public function test_two_tone_and_stripe_colours_open_contrast_fabric_and_thread(): void
    {
        // Black body, maroon collar, navy stripes: maroon fabric for the collar, navy thread for the stripe colour.
        $e = $this->m->eligible($this->colourCatalog(), 'Polo Shirt', ['body' => '#111111', 'collar' => '#800000', 'body_pattern' => '#1B2A4A']);
        $this->assertSame('collar', $e[55]['colour_match']);
        $this->assertSame('body_pattern', $e[72]['colour_match']);
        $this->assertSame('body', $e[56]['colour_match']);
        $fb = array_column($this->m->fallback($e)['selections'], 'material_id');
        $this->assertSame([56, 55, 69], $fb); // main body fabric, contrast fabric, matching thread
    }

    public function test_stock_flag_and_fallback_prefers_in_stock(): void
    {
        $rows = [
            ['material_id' => 1, 'material_name' => 'Cotton Fabric', 'category' => 'Fabric', 'quantity_in_stock' => 0],
            ['material_id' => 2, 'material_name' => 'Twill Fabric', 'category' => 'Fabric', 'quantity_in_stock' => 40],
            ['material_id' => 3, 'material_name' => 'Polyester Thread', 'category' => 'Thread'],
        ];
        $e = $this->m->eligible($rows, 'Pants');
        $this->assertSame([false, true, null], array_column($e, 'in_stock'));
        $this->assertSame([2, 3], array_column($this->m->fallback($e)['selections'], 'material_id'));
        // Only out-of-stock fabric: still suggested (staff confirm), never invented.
        $this->assertSame([1, 3], array_column($this->m->fallback($this->m->eligible([$rows[0], $rows[2]], 'Pants'))['selections'], 'material_id'));
    }

    public function test_fallback_adds_the_closures_the_garment_needs_and_skips_lining_as_main_fabric(): void
    {
        $rows = [
            ['material_id' => 1, 'material_name' => 'Satin Lining', 'category' => 'Fabric'],
            ['material_id' => 2, 'material_name' => 'Twill Fabric', 'category' => 'Fabric'],
            ['material_id' => 3, 'material_name' => 'Polyester Thread', 'category' => 'Thread'],
            ['material_id' => 4, 'material_name' => 'YKK Zipper (7 inch)', 'category' => 'Accessories'],
            ['material_id' => 5, 'material_name' => 'Plastic Buttons (Medium)', 'category' => 'Accessories'],
            ['material_id' => 6, 'material_name' => 'Elastic Band (1 inch)', 'category' => 'Elastic'],
        ];
        $e = $this->m->eligible($rows, 'Pantsuit Trousers');
        $p = $this->m->garmentProfile('Pantsuit Trousers');
        $fb = $this->m->fallback($e, [], $p['needs']);
        $this->assertSame([2, 3, 4, 5], array_column($fb['selections'], 'material_id'));
        $this->assertFalse($this->m->hasForbiddenTerms(implode(' ', array_column($fb['selections'], 'reason'))));
        $jog = $this->m->fallback($e, [], $this->m->garmentProfile('Custom Bottom', ['custom' => ['style' => 'jogger']])['needs']);
        $this->assertSame([2, 3, 6], array_column($jog['selections'], 'material_id'));
    }

    public function test_garment_profiles_come_from_words_and_settings_not_a_fixed_list(): void
    {
        $p = fn ($g, $s = []) => $this->m->garmentProfile($g, $s);
        $this->assertSame(['bottom', ['zipper', 'button']], [$p('Pantsuit Trousers')['kind'], $p('Pantsuit Trousers')['needs']]);
        $this->assertSame(['bottom', ['elastic']], [$p("Women's Jogger Scrub Pants")['kind'], $p("Women's Jogger Scrub Pants")['needs']]);
        $this->assertSame(['skirt', ['zipper']], [$p('Midi Pencil Skirt')['kind'], $p('Midi Pencil Skirt')['needs']]);
        $this->assertSame([], $p('T-Shirt')['needs']);
        $this->assertSame(['button', 'interfacing'], $p('Barong Shirt')['needs']);
        $this->assertSame(['button', 'interfacing', 'lining'], $p('Short-Sleeve Notch Jacket')['needs']);
        $this->assertSame(['button', 'interfacing'], $p('Custom Top', ['custom' => ['neckline' => 'mandarin']])['needs']);
        $this->assertSame([], $p('Custom Top', ['custom' => ['neckline' => 'v']])['needs']);
        $this->assertSame('top', $p('Some New Garment')['kind']);
        $this->assertContains('Pants', $p("Men's Slacks")['aliases']);
    }

    public function test_applies_to_matches_the_garment_kind_alias(): void
    {
        $rows = [['material_id' => 1, 'material_name' => 'Trouser Hook', 'category' => 'Accessories', 'applies_to' => ['Pants']]];
        $this->assertSame([], $this->m->eligible($rows, 'Pantsuit Trousers'));
        $this->assertSame([1], array_keys($this->m->eligible($rows, 'Pantsuit Trousers', [], $this->m->garmentProfile('Pantsuit Trousers')['aliases'])));
        $this->assertSame([], $this->m->eligible($rows, 'Polo Shirt', [], $this->m->garmentProfile('Polo Shirt')['aliases']));
    }
}
