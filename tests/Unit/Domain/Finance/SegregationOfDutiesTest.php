<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Finance;

use App\Domain\Finance\Services\SegregationOfDuties;
use PHPUnit\Framework\TestCase;

final class SegregationOfDutiesTest extends TestCase
{
    public function test_distinct_requester_payer_and_governance_actor_is_separated(): void
    {
        $result = (new SegregationOfDuties)->evaluate(
            'requester',
            'payer',
            ['approver'],
            true,
            false,
        );

        self::assertTrue($result['separated']);
        self::assertFalse($result['requires_compensating_review']);
    }

    public function test_strict_three_way_rejects_overlap_without_compensating_review(): void
    {
        $result = (new SegregationOfDuties)->evaluate(
            'same',
            'same',
            ['approver'],
            true,
            true,
        );

        self::assertFalse($result['separated']);
        self::assertFalse($result['requires_compensating_review']);
    }

    public function test_policy_may_require_compensating_review_when_overlap_is_explicitly_allowed(): void
    {
        $result = (new SegregationOfDuties)->evaluate(
            'requester',
            'payer',
            ['payer'],
            false,
            true,
        );

        self::assertFalse($result['separated']);
        self::assertTrue($result['requires_compensating_review']);
    }
}
