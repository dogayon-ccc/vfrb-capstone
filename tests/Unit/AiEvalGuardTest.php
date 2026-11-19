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

    public function test_readiness_is_blocked_with_zero_genuine_groups_whatever_the_threshold(): void
    {
        $f = ['groups' => 0, 'accept' => 0, 'reject' => 0, 'fabrics' => 3, 'gsm_fabrics' => 3, 'gsm_verified_fabrics' => 3];
        $b = AiEvalGuard::readinessBlockers($f, 1);
        $this->assertNotEmpty($b);
        $this->assertStringContainsString('No genuine staff verdicts', $b[0]);
        $this->assertCount(2, $b); // zero groups never also claims "no label contrast"
    }

    public function test_readiness_flags_class_imbalance_threshold_and_missing_gsm_separately(): void
    {
        $one = AiEvalGuard::readinessBlockers(['groups' => 300, 'accept' => 40, 'reject' => 0, 'fabrics' => 2, 'gsm_fabrics' => 2, 'gsm_verified_fabrics' => 2], 200);
        $this->assertCount(1, $one);
        $this->assertStringContainsString('no accepts or no rejects', $one[0]);
        $this->assertStringContainsString('Only 5 independent', AiEvalGuard::readinessBlockers(['groups' => 5, 'accept' => 3, 'reject' => 2, 'fabrics' => 2, 'gsm_fabrics' => 2, 'gsm_verified_fabrics' => 2], 200)[0]);
        $this->assertStringContainsString('GSM', AiEvalGuard::readinessBlockers(['groups' => 300, 'accept' => 3, 'reject' => 2, 'fabrics' => 2, 'gsm_fabrics' => 0, 'gsm_verified_fabrics' => 0], 200)[0]);
        $this->assertStringContainsString('staff-verified', AiEvalGuard::readinessBlockers(['groups' => 300, 'accept' => 3, 'reject' => 2, 'fabrics' => 2, 'gsm_fabrics' => 2, 'gsm_verified_fabrics' => 0], 200)[0]);
        $this->assertSame([], AiEvalGuard::readinessBlockers(['groups' => 300, 'accept' => 30, 'reject' => 20, 'fabrics' => 2, 'gsm_fabrics' => 1, 'gsm_verified_fabrics' => 1], 200));
    }

    public function test_a_changed_verdict_is_one_label_so_one_material_cannot_fake_label_contrast(): void
    {
        $row = fn ($o, $m, $v) => ['order_id' => $o, 'material_id' => $m, 'staff_feedback' => $v];
        $l = AiEvalGuard::latestLabels([$row(8, 2, 'reject'), $row(8, 2, 'accept'), $row(8, 3, 'accept'), $row(9, 2, 'reject')]);
        $this->assertSame([2, 1, 1], [$l['accept'], $l['reject'], $l['conflicts']]); // 8/2 counts once, as its latest verdict
        $this->assertSame([8 => 2, 9 => 1], $l['by_order']);
        $this->assertSame(['accept' => 0, 'reject' => 0, 'conflicts' => 0, 'by_order' => []], AiEvalGuard::latestLabels([]));
    }
}
