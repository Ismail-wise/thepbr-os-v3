<?php

declare(strict_types=1);

namespace Tests\Unit\Formation;

use App\Application\Formation\BusinessModelEconomicsCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class BusinessModelEconomicsCalculatorTest extends TestCase
{
    public function test_calculates_unit_economics_and_break_even_from_reusable_business_model_inputs(): void
    {
        $result = $this->app
            ->make(BusinessModelEconomicsCalculator::class)
            ->calculate([
                'average_selling_price' => '100.00',
                'variable_cost_per_unit' => '40.00',
                'monthly_fixed_cost' => '3000.00',
                'expected_monthly_units' => '80.00',
            ]);

        self::assertSame('ready', $result['status']);
        self::assertSame('60.00', $result['contributionMarginPerUnit']);
        self::assertSame(60.0, $result['grossMarginPercent']);
        self::assertSame(50.0, $result['breakEvenUnits']);
        self::assertSame('5000.00', $result['breakEvenRevenue']);
        self::assertSame('8000.00', $result['expectedMonthlyRevenue']);
        self::assertSame('4800.00', $result['expectedMonthlyGrossProfit']);
        self::assertSame(
            '1800.00',
            $result['expectedMonthlyOperatingProfit'],
        );
    }

    #[DataProvider('notReadyCases')]
    public function test_break_even_fails_safe_when_inputs_are_not_economically_usable(
        array $profile,
        string $expectedStatus,
    ): void {
        $result = $this->app
            ->make(BusinessModelEconomicsCalculator::class)
            ->calculate($profile);

        self::assertSame($expectedStatus, $result['status']);
        self::assertNull($result['breakEvenUnits']);
        self::assertNull($result['breakEvenRevenue']);
    }

    /**
     * @return iterable<string,array{array<string,mixed>,string}>
     */
    public static function notReadyCases(): iterable
    {
        yield 'missing fixed cost' => [[
            'average_selling_price' => '100.00',
            'variable_cost_per_unit' => '40.00',
        ], 'incomplete'];

        yield 'zero selling price' => [[
            'average_selling_price' => '0.00',
            'variable_cost_per_unit' => '0.00',
            'monthly_fixed_cost' => '1000.00',
        ], 'invalid_price'];

        yield 'non-positive contribution margin' => [[
            'average_selling_price' => '50.00',
            'variable_cost_per_unit' => '60.00',
            'monthly_fixed_cost' => '1000.00',
        ], 'non_positive_margin'];
    }
}
