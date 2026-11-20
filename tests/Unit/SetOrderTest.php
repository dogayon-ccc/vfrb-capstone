<?php

namespace Tests\Unit;

use App\Support\SetOrder;
use PHPUnit\Framework\TestCase;

class SetOrderTest extends TestCase
{
    private function matrix(int $top, int $bottom): array
    {
        return ['top' => ['M' => $top], 'bottom' => ['L' => $bottom]];
    }

    private function check(array $raw): array
    {
        [$m, $err] = SetOrder::normalise($raw);

        return [$m, $err ?? ($m ? SetOrder::check($m) : null)];
    }

    public function test_matching_matrices_of_100_or_more_are_valid_and_cover_every_size(): void
    {
        [$m, $err] = $this->check($this->matrix(105, 105));
        $this->assertNull($err);
        $this->assertSame(SetOrder::SIZES, array_keys($m['top']));
        $this->assertSame(105, array_sum($m['bottom']));
    }

    public function test_below_100_on_either_piece_is_refused(): void
    {
        $this->assertStringContainsString('at least 100', $this->check($this->matrix(99, 99))[1]);
        $this->assertStringContainsString('at least 100', $this->check($this->matrix(120, 99))[1]);
    }

    public function test_unequal_totals_report_the_exact_difference(): void
    {
        $this->assertStringContainsString('5 apart', $this->check($this->matrix(105, 100))[1]);
    }

    public function test_tampered_payloads_are_refused(): void
    {
        foreach ([
            ['top' => ['M' => 100]],
            ['top' => ['M' => 100], 'bottom' => ['M' => 100], 'extra' => []],
            ['top' => ['XXXL' => 100], 'bottom' => ['M' => 100]],
            ['top' => ['M' => 100.5], 'bottom' => ['M' => 100]],
            ['top' => ['M' => -1], 'bottom' => ['M' => 100]],
            ['top' => ['M' => '1e2'], 'bottom' => ['M' => 100]],
            ['top' => ['M' => true], 'bottom' => ['M' => 100]],
            'not json',
        ] as $bad) {
            $this->assertNotNull(SetOrder::normalise($bad)[1]);
        }
    }

    public function test_json_string_and_lowercase_sizes_are_accepted(): void
    {
        [$m, $err] = SetOrder::normalise('{"top":{"s":"100"},"bottom":{"3xl":100}}');
        $this->assertNull($err);
        $this->assertSame([100, 100], [$m['top']['S'], $m['bottom']['3XL']]);
    }

    public function test_quote_and_payment_block_unless_the_reference_is_orderable(): void
    {
        $this->assertNull(SetOrder::blockedMessage((object) [], null));
        $this->assertNotNull(SetOrder::blockedMessage((object) ['reference_validation_id' => 1], (object) ['status' => 'needs_review']));
        $this->assertNotNull(SetOrder::blockedMessage((object) ['reference_validation_id' => 1], null));
        $this->assertNull(SetOrder::blockedMessage((object) ['reference_validation_id' => 1], (object) ['status' => 'approved']));
    }
}
