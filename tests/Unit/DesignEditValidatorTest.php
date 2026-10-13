<?php
// tests/Unit/DesignEditValidatorTest.php
namespace Tests\Unit;

use App\Services\DesignEditValidator;
use PHPUnit\Framework\TestCase;

class DesignEditValidatorTest extends TestCase
{
    private DesignEditValidator $v;

    protected function setUp(): void
    {
        $this->v = new DesignEditValidator();
    }

    // Facts as the Studio would send them for Pants: no collar/sleeve zones, no arm placements.
    private function pants(array $texts = [['id' => 'abc-1', 'text' => 'SMITH']]): array
    {
        return $this->v->template(['zones' => ['body', 'pocket'], 'placements' => ['left_chest', 'center_chest', 'back_center'], 'patterns' => ['solid', 'hstripes']], $texts);
    }

    public function test_template_rejects_unknown_zones_placements_and_ids(): void
    {
        $this->assertIsString($this->v->template(['zones' => ['body', 'hood'], 'placements' => [], 'patterns' => []], []));
        $this->assertIsString($this->v->template(['zones' => [], 'placements' => [], 'patterns' => []], []));
        $this->assertIsString($this->v->template(['zones' => ['body'], 'placements' => ['left_hip'], 'patterns' => []], []));
        $this->assertIsString($this->v->template(['zones' => ['body'], 'placements' => [], 'patterns' => ['<svg>']], []));
        $this->assertIsString($this->v->template(['zones' => ['body'], 'placements' => [], 'patterns' => []], [['id' => '../x', 'text' => 'a']]));
        $this->assertIsArray($this->pants());
    }

    public function test_valid_operations_pass_in_a_fixed_shape(): void
    {
        $out = $this->v->validate([
            ['op' => 'set_color', 'zone' => 'body', 'hex' => '#1b2a4a'],
            ['op' => 'set_pattern', 'zone' => 'pocket', 'pattern' => 'hstripes'],
            ['op' => 'add_text', 'text' => '  <b>RIVERA</b> 10 ', 'placement' => 'back_center'],
            ['op' => 'edit_text', 'target' => 'abc-1', 'text' => 'SMITH JR'],
            ['op' => 'move_text', 'target' => 'abc-1', 'placement' => 'left_chest'],
        ], $this->pants());

        $this->assertSame([], $out['rejected']);
        $this->assertSame(['op' => 'set_color', 'zone' => 'body', 'hex' => '#1B2A4A'], $out['operations'][0]);
        $this->assertSame('RIVERA 10', $out['operations'][2]['text']);
    }

    public function test_every_unknown_or_out_of_template_operation_is_rejected(): void
    {
        $bad = [
            ['op' => 'set_color', 'zone' => 'collar', 'hex' => '#FFFFFF'],          // pants have no collar
            ['op' => 'set_color', 'zone' => 'body', 'hex' => 'white'],
            ['op' => 'set_pattern', 'zone' => 'body', 'pattern' => 'camo'],          // not in this template's list
            ['op' => 'add_text', 'text' => 'X', 'placement' => 'left_arm'],         // no sleeves on pants
            ['op' => 'add_text', 'text' => '   ', 'placement' => 'left_chest'],
            ['op' => 'edit_text', 'target' => 'ghost', 'text' => 'Y'],
            ['op' => 'move_text', 'target' => 'abc-1', 'placement' => 'hood'],
            ['op' => 'resize_garment', 'zone' => 'body'],                            // geometry is never editable
            ['op' => 'set_color', 'zone' => 'body', 'hex' => '#000000', 'path' => 'M0 0'],
            'set_color body red',
        ];
        $out = $this->v->validate($bad, $this->pants());
        $this->assertSame([], $out['operations']);
        $this->assertCount(count($bad), $out['rejected']);
    }

    public function test_operation_count_is_capped(): void
    {
        $ops = array_fill(0, 30, ['op' => 'set_color', 'zone' => 'body', 'hex' => '#000000']);
        $this->assertCount(DesignEditValidator::MAX_OPS, $this->v->validate($ops, $this->pants())['operations']);
    }

    public function test_schema_offers_only_this_templates_values(): void
    {
        $items = $this->v->schema($this->pants([]))['properties']['operations']['items']['properties'];
        $this->assertSame(['body', 'pocket'], $items['zone']['enum']);
        $this->assertSame(['left_chest', 'center_chest', 'back_center'], $items['placement']['enum']);
        $this->assertNotContains('edit_text', $items['op']['enum']); // no text on the design yet
        $this->assertNotContains('move_text', $items['op']['enum']);
    }
}
