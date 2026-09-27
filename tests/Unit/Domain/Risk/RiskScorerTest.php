<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Risk;

use App\Domain\Risk\Services\RiskScorer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RiskScorerTest extends TestCase
{
    public function test_score_uses_configurable_exact_version_thresholds(): void
    {
        $scorer = new RiskScorer;

        self::assertSame(
            ['score' => 4, 'level' => 'low'],
            $scorer->score(2, 2, 4, 9, 15),
        );
        self::assertSame(
            ['score' => 6, 'level' => 'medium'],
            $scorer->score(2, 3, 4, 9, 15),
        );
        self::assertSame(
            ['score' => 12, 'level' => 'high'],
            $scorer->score(3, 4, 4, 9, 15),
        );
        self::assertSame(
            ['score' => 20, 'level' => 'critical'],
            $scorer->score(4, 5, 4, 9, 15),
        );

        self::assertSame(
            ['score' => 12, 'level' => 'medium'],
            $scorer->score(3, 4, 5, 12, 20),
            'Risk levels must follow the exact Risk Register version thresholds.',
        );
    }

    public function test_invalid_score_or_threshold_design_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new RiskScorer)->score(6, 1, 4, 9, 15);
    }

    public function test_thresholds_must_be_strictly_ordered_and_leave_critical_band(): void
    {
        $scorer = new RiskScorer;

        foreach ([
            [4, 4, 15],
            [4, 9, 9],
            [4, 9, 25],
        ] as [$low, $medium, $high]) {
            try {
                $scorer->score(1, 1, $low, $medium, $high);
                self::fail('Invalid thresholds must fail closed.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
