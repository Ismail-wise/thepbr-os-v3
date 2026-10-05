<?php

declare(strict_types=1);

namespace Tests\Unit\Formation;

use App\Application\Formation\BusinessValuationCalculator;
use Tests\TestCase;

final class BusinessValuationCalculatorTest extends TestCase
{
    public function test_calculates_reproducible_multi_method_indicative_range(): void
    {
        $result = $this->app
            ->make(BusinessValuationCalculator::class)
            ->calculate(
                [
                    'ebitda' => '100000.00',
                    'owner_earnings' => '120000.00',
                    'free_cash_flow' => '80000.00',
                    'cash' => '20000.00',
                    'debt' => '10000.00',
                    'total_assets' => '300000.00',
                    'total_liabilities' => '100000.00',
                ],
                [
                    'ebitda_multiple' => '4',
                    'sde_multiple' => '3',
                    'growth_rate_percent' => '10',
                    'discount_rate_percent' => '15',
                    'terminal_growth_rate_percent' => '3',
                ],
            );

        self::assertSame('ready', $result['status']);
        self::assertSame(
            BusinessValuationCalculator::FORMULA_VERSION,
            $result['formulaVersion'],
        );
        self::assertSame('410000.00', $result['methods']['ebitda_multiple']['value']);
        self::assertSame('370000.00', $result['methods']['owner_earnings_sde']['value']);
        self::assertSame('200000.00', $result['methods']['asset_based']['value']);
        self::assertSame(
            '910573.30',
            $result['methods']['discounted_cash_flow']['value'],
        );
        self::assertSame(
            [
                'low' => '200000.00',
                'base' => '390000.00',
                'high' => '910573.30',
                'basis' => 'available_method_spread',
            ],
            $result['range'],
        );
        self::assertSame(
            ['level' => 'high', 'usableMethodCount' => 4],
            $result['confidence'],
        );
        self::assertTrue($result['semantics']['indicativeEstimate']);
        self::assertFalse($result['semantics']['ownershipTruth']);
        self::assertFalse($result['semantics']['contributionValuation']);
    }

    public function test_uses_only_methods_with_required_inputs_and_explains_low_confidence(): void
    {
        $result = $this->app
            ->make(BusinessValuationCalculator::class)
            ->calculate(
                [
                    'total_assets' => '500000.00',
                    'total_liabilities' => '125000.00',
                ],
                [],
            );

        self::assertSame('ready', $result['status']);
        self::assertSame(
            ['asset_based'],
            array_keys($result['methods']),
        );
        self::assertSame('375000.00', $result['range']['base']);
        self::assertSame('low', $result['confidence']['level']);
        self::assertSame(1, $result['confidence']['usableMethodCount']);
        self::assertContains(
            'Only one valuation method is usable; the range is method-constrained.',
            $result['warnings'],
        );
    }

    public function test_invalid_dcf_assumptions_skip_dcf_without_corrupting_other_methods(): void
    {
        $result = $this->app
            ->make(BusinessValuationCalculator::class)
            ->calculate(
                [
                    'free_cash_flow' => '50000.00',
                    'cash' => '10000.00',
                    'debt' => '5000.00',
                    'total_assets' => '100000.00',
                    'total_liabilities' => '25000.00',
                ],
                [
                    'growth_rate_percent' => '5',
                    'discount_rate_percent' => '3',
                    'terminal_growth_rate_percent' => '3',
                ],
            );

        self::assertSame('ready', $result['status']);
        self::assertArrayNotHasKey('discounted_cash_flow', $result['methods']);
        self::assertSame('75000.00', $result['methods']['asset_based']['value']);
        self::assertContains(
            'DCF skipped because discount rate must be greater than terminal growth.',
            $result['warnings'],
        );
    }

    public function test_reports_insufficient_instead_of_inventing_a_value(): void
    {
        $result = $this->app
            ->make(BusinessValuationCalculator::class)
            ->calculate([], []);

        self::assertSame('insufficient', $result['status']);
        self::assertSame([], $result['methods']);
        self::assertNull($result['range']);
        self::assertSame(0, $result['confidence']['usableMethodCount']);
    }
}
