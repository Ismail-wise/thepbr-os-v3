<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Rewards;

use App\Domain\Rewards\Services\DistributionCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DistributionCalculatorTest extends TestCase
{
    public function test_waterfall_never_produces_negative_distributable_profit(): void
    {
        $calculator = new DistributionCalculator;

        self::assertSame(
            0,
            $calculator->waterfall(100, 70, 40, 10, 0, 0)[
                'distributable_profit_minor_units'
            ],
        );
    }

    public function test_waterfall_preserves_finance_deductions_and_approved_adjustment(): void
    {
        $result = (new DistributionCalculator)->waterfall(
            100000,
            10000,
            5000,
            15000,
            20000,
            2500,
        );

        self::assertSame(52500, $result['distributable_profit_minor_units']);
    }

    public function test_allocation_is_deterministic_and_exhausts_pool(): void
    {
        $calculator = new DistributionCalculator;

        $allocation = $calculator->allocate(101, [
            'partner-b' => '1.00000000',
            'partner-a' => '1.00000000',
        ]);

        self::assertSame(101, array_sum($allocation));
        self::assertSame(51, $allocation['partner-a']);
        self::assertSame(50, $allocation['partner-b']);
    }

    public function test_small_pool_preserves_zero_allocation_lines_without_losing_money(): void
    {
        $allocation = (new DistributionCalculator)->allocate(1, [
            'partner-b' => '1.00000000',
            'partner-a' => '1.00000000',
        ]);

        self::assertSame(1, array_sum($allocation));
        self::assertSame(1, $allocation['partner-a']);
        self::assertSame(0, $allocation['partner-b']);
    }

    public function test_zero_entitlement_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new DistributionCalculator)->allocate(100, [
            'partner-a' => '0',
        ]);
    }
}
