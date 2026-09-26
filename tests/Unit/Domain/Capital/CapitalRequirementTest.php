<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Capital;

use App\Domain\Capital\ValueObjects\CapitalRequirement;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CapitalRequirementTest extends TestCase
{
    public function test_total_and_gap_follow_the_pbr_four_part_invariant(): void
    {
        $capital = new CapitalRequirement(
            '1000.00',
            '2500.00',
            '3000.00',
            '500.00',
            '4000.00',
        );

        self::assertSame('7000.00', $capital->totalRequirement);
        self::assertSame('3000.00', $capital->fundingGap);
    }

    public function test_funding_gap_never_becomes_negative(): void
    {
        $capital = new CapitalRequirement(
            '100.00',
            '100.00',
            '100.00',
            '100.00',
            '900.00',
        );

        self::assertSame('400.00', $capital->totalRequirement);
        self::assertSame('0.00', $capital->fundingGap);
    }

    public function test_amount_precision_is_bounded_to_two_decimals(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CapitalRequirement(
            '1.001',
            '0',
            '0',
            '0',
            '0',
        );
    }
}
