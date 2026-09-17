<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Records;

use App\Domain\Records\Enums\RequirementOutcome;
use App\Domain\Records\ValueObjects\RequirementResult;
use PHPUnit\Framework\TestCase;

final class RequirementResultTest extends TestCase
{
    public function test_met_warning_and_blocked_remain_distinct(): void
    {
        $met = RequirementResult::met('identity');
        $warning = RequirementResult::warning('review-date');
        $blocked = RequirementResult::blocked('missing-required-fact');

        $this->assertSame(RequirementOutcome::Met, $met->outcome);
        $this->assertSame(RequirementOutcome::Warning, $warning->outcome);
        $this->assertSame(RequirementOutcome::Blocked, $blocked->outcome);

        $this->assertFalse($met->blocksTransition());
        $this->assertFalse($warning->blocksTransition());
        $this->assertTrue($blocked->blocksTransition());
    }
}
