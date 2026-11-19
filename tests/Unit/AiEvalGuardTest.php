<?php

namespace Tests\Unit;

use App\Support\AiEvalGuard;
use PHPUnit\Framework\TestCase;

// Pure helpers only (no DB). The query scoping is covered in Feature\AiQualityToolsTest.
class AiEvalGuardTest extends TestCase
{
    public function test_collapse_keeps_the_newest_of_each_duplicate_group_and_counts_the_rest(): void
    {
        [$kept, $n] = AiEvalGuard::collapse([30, 20, 10, 5], [30 => 'a', 20 => 'b', 10 => 'a', 5 => 'b']);
        $this->assertSame([30, 20], $kept);
        $this->assertSame(2, $n);
    }

    public function test_orders_without_a_key_are_never_merged(): void
    {
        [$kept, $n] = AiEvalGuard::collapse([3, 2, 1], []);
        $this->assertSame([3, 2, 1], $kept);
        $this->assertSame(0, $n);
    }

    public function test_metrics_need_rated_orders_and_both_verdict_classes(): void
    {
        $t = ['orders' => 0, 'accepted_total' => 0, 'rejected_total' => 0];
        $this->assertSame('no rated orders', AiEvalGuard::withheldReason($t));
        $this->assertSame('no accept verdicts', AiEvalGuard::withheldReason(['orders' => 2, 'accepted_total' => 0, 'rejected_total' => 3] + $t));
        $this->assertSame('no reject verdicts', AiEvalGuard::withheldReason(['orders' => 2, 'accepted_total' => 3, 'rejected_total' => 0] + $t));
        $this->assertNull(AiEvalGuard::withheldReason(['orders' => 2, 'accepted_total' => 3, 'rejected_total' => 1] + $t));
    }
}
