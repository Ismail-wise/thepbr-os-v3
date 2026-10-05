<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Capital;

use App\Domain\Capital\CapitalCalculationEngine;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CapitalCalculationEngineTest extends TestCase
{
    public function test_category_subtotals_working_capital_contingency_and_total_are_deterministic(): void
    {
        $result = (new CapitalCalculationEngine)->calculate([
            'preOpeningItems' => [
                [
                    'category' => 'registration_legal',
                    'amount' => '100.10',
                ],
                [
                    'category' => 'deposit',
                    'amount' => '200.00',
                ],
                [
                    'category' => 'registration_legal',
                    'amount' => '50.00',
                ],
            ],
            'initialAssetsInventoryItems' => [
                [
                    'category' => 'equipment',
                    'amount' => '500.00',
                ],
                [
                    'category' => 'technology',
                    'amount' => '250.00',
                ],
            ],
            'workingCapital' => [
                'method' => 'monthly_costs',
                'months' => 3,
                'items' => [
                    [
                        'category' => 'salary',
                        'amount' => '1000.00',
                    ],
                    [
                        'category' => 'rent',
                        'amount' => '500.00',
                    ],
                    [
                        'category' => 'software',
                        'amount' => '100.00',
                    ],
                ],
            ],
            'contingency' => [
                'method' => 'percentage',
                'percentage' => '10.00',
            ],
            'confirmedFunding' => '3000.00',
        ]);

        self::assertSame(
            CapitalCalculationEngine::CONTRACT_VERSION,
            $result['contractVersion'],
        );
        self::assertSame(
            '150.10',
            $result['preOpening']['categories']['registration_legal'][
                'subtotal'
            ],
        );
        self::assertSame('350.10', $result['preOpening']['subtotal']);
        self::assertSame(
            '750.00',
            $result['initialAssetsInventory']['subtotal'],
        );
        self::assertSame(
            '1600.00',
            $result['workingCapital']['monthlyBurn'],
        );
        self::assertSame(
            '4800.00',
            $result['workingCapital']['amount'],
        );
        self::assertSame(
            '5900.10',
            $result['contingency']['baseAmount'],
        );
        self::assertSame(
            '590.01',
            $result['contingency']['amount'],
        );
        self::assertSame(
            '6490.11',
            $result['totalCapitalRequirement']['amount'],
        );
        self::assertSame(
            '3490.11',
            $result['fundingPosition']['fundingGap'],
        );
        self::assertSame(
            '46.22',
            $result['fundingPosition']['fundedPercentage'],
        );
    }

    public function test_total_capital_requirement_follows_the_canonical_four_part_formula(): void
    {
        $result = (new CapitalCalculationEngine)->calculate([
            'preOpeningItems' => [
                ['category' => 'training', 'amount' => '1000.00'],
            ],
            'initialAssetsInventoryItems' => [
                ['category' => 'equipment', 'amount' => '2000.00'],
            ],
            'workingCapital' => [
                'method' => 'fixed_amount',
                'amount' => '3000.00',
            ],
            'contingency' => [
                'method' => 'fixed_amount',
                'amount' => '500.00',
            ],
            'confirmedFunding' => '2500.00',
        ]);

        self::assertSame(
            'pre_opening + initial_assets_inventory + working_capital + contingency_reserve',
            $result['totalCapitalRequirement']['formula'],
        );
        self::assertSame(
            '6500.00',
            $result['totalCapitalRequirement']['amount'],
        );
        self::assertSame(
            '4000.00',
            $result['fundingPosition']['fundingGap'],
        );
    }

    public function test_percentage_contingency_uses_deterministic_half_up_minor_unit_rounding(): void
    {
        $result = (new CapitalCalculationEngine)->calculate([
            'preOpeningItems' => [
                [
                    'category' => 'registration_legal',
                    'amount' => '0.01',
                ],
            ],
            'initialAssetsInventoryItems' => [],
            'workingCapital' => [
                'method' => 'fixed_amount',
                'amount' => '0.00',
            ],
            'contingency' => [
                'method' => 'percentage',
                'percentage' => '50.00',
            ],
            'confirmedFunding' => '0.00',
        ]);

        self::assertSame('0.01', $result['contingency']['amount']);
        self::assertSame(
            '0.02',
            $result['totalCapitalRequirement']['amount'],
        );
        self::assertSame(
            '0.02',
            $result['fundingPosition']['fundingGap'],
        );
        self::assertSame(
            '0.00',
            $result['fundingPosition']['fundedPercentage'],
        );
    }

    public function test_missing_and_explicit_zero_remain_distinct_and_do_not_create_false_outputs(): void
    {
        $missing = (new CapitalCalculationEngine)->calculate([
            'initialAssetsInventoryItems' => [],
            'workingCapital' => [
                'method' => 'fixed_amount',
                'amount' => '0.00',
            ],
            'contingency' => [
                'method' => 'fixed_amount',
                'amount' => '0.00',
            ],
            'confirmedFunding' => '0.00',
        ]);

        self::assertSame('missing', $missing['preOpening']['status']);
        self::assertNull($missing['preOpening']['subtotal']);
        self::assertSame(
            'unavailable',
            $missing['totalCapitalRequirement']['status'],
        );
        self::assertNull(
            $missing['totalCapitalRequirement']['amount'],
        );
        self::assertSame(
            'unavailable',
            $missing['fundingPosition']['status'],
        );
        self::assertNull(
            $missing['fundingPosition']['fundingGap'],
        );

        $zero = (new CapitalCalculationEngine)->calculate([
            'preOpeningItems' => [],
            'initialAssetsInventoryItems' => [],
            'workingCapital' => [
                'method' => 'monthly_burn',
                'months' => 0,
                'monthlyBurn' => '0.00',
            ],
            'contingency' => [
                'method' => 'fixed_amount',
                'amount' => '0.00',
            ],
            'confirmedFunding' => '0.00',
        ]);

        self::assertSame('calculable', $zero['preOpening']['status']);
        self::assertSame('0.00', $zero['preOpening']['subtotal']);
        self::assertSame(
            '0.00',
            $zero['totalCapitalRequirement']['amount'],
        );
        self::assertSame(
            '0.00',
            $zero['fundingPosition']['fundingGap'],
        );
        self::assertNull(
            $zero['fundingPosition']['fundedPercentage'],
        );
        self::assertSame(
            'zero_requirement_percentage_unavailable',
            $zero['fundingPosition']['reasonCode'],
        );
    }

    public function test_missing_item_amount_makes_section_incomplete_instead_of_silently_zero(): void
    {
        $result = (new CapitalCalculationEngine)->calculate([
            'preOpeningItems' => [
                [
                    'category' => 'registration_legal',
                    'amount' => null,
                ],
            ],
            'initialAssetsInventoryItems' => [],
            'workingCapital' => [
                'method' => 'fixed_amount',
                'amount' => '0.00',
            ],
            'contingency' => [
                'method' => 'fixed_amount',
                'amount' => '0.00',
            ],
            'confirmedFunding' => '100.00',
        ]);

        self::assertSame('incomplete', $result['preOpening']['status']);
        self::assertNull($result['preOpening']['subtotal']);
        self::assertSame(
            'item_amount_missing',
            $result['preOpening']['reasonCode'],
        );
        self::assertNull(
            $result['totalCapitalRequirement']['amount'],
        );
        self::assertNull(
            $result['fundingPosition']['fundedPercentage'],
        );
    }

    public function test_confirmed_funding_preserves_real_gap_surplus_and_unclamped_coverage(): void
    {
        $result = (new CapitalCalculationEngine)->calculate([
            'preOpeningItems' => [
                ['category' => 'deposit', 'amount' => '100.00'],
            ],
            'initialAssetsInventoryItems' => [],
            'workingCapital' => [
                'method' => 'fixed_amount',
                'amount' => '0.00',
            ],
            'contingency' => [
                'method' => 'fixed_amount',
                'amount' => '0.00',
            ],
            'confirmedFunding' => '250.00',
        ]);

        self::assertSame(
            '0.00',
            $result['fundingPosition']['fundingGap'],
        );
        self::assertSame(
            '150.00',
            $result['fundingPosition']['fundingSurplus'],
        );
        self::assertSame(
            '250.00',
            $result['fundingPosition']['fundedPercentage'],
        );
    }

    public function test_runway_stays_unavailable_without_explicit_operating_cash_allocation(): void
    {
        $result = (new CapitalCalculationEngine)->calculate([
            'preOpeningItems' => [],
            'initialAssetsInventoryItems' => [],
            'workingCapital' => [
                'method' => 'monthly_burn',
                'months' => 3,
                'monthlyBurn' => '1000.00',
            ],
            'contingency' => [
                'method' => 'fixed_amount',
                'amount' => '0.00',
            ],
            'confirmedFunding' => '3000.00',
        ]);

        self::assertSame('unavailable', $result['runway']['status']);
        self::assertNull($result['runway']['months']);
        self::assertSame(
            'available_operating_cash_not_explicit',
            $result['runway']['reasonCode'],
        );
    }

    public function test_negative_and_malformed_money_fail_safely(): void
    {
        foreach (['-1.00', '1.001', '1e3', '1,000.00'] as $invalid) {
            try {
                (new CapitalCalculationEngine)->calculate([
                    'preOpeningItems' => [
                        [
                            'category' => 'deposit',
                            'amount' => $invalid,
                        ],
                    ],
                ]);

                self::fail(
                    "Expected invalid money [{$invalid}] to fail.",
                );
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_output_never_creates_contribution_equity_ownership_or_lifecycle_truth(): void
    {
        $result = (new CapitalCalculationEngine)->calculate([
            'preOpeningItems' => [],
            'initialAssetsInventoryItems' => [],
            'workingCapital' => [
                'method' => 'fixed_amount',
                'amount' => '0.00',
            ],
            'contingency' => [
                'method' => 'fixed_amount',
                'amount' => '0.00',
            ],
            'confirmedFunding' => '0.00',
        ]);

        self::assertFalse(
            $result['semantics'][
                'capitalRequirementIsPartnerContribution'
            ],
        );
        self::assertFalse(
            $result['semantics']['fundingBecomesEquityAutomatically'],
        );
        self::assertFalse(
            $result['semantics']['createsOwnershipTruth'],
        );
        self::assertFalse(
            $result['semantics']['createsApprovedTruth'],
        );
        self::assertFalse(
            $result['semantics']['createsSignedTruth'],
        );
        self::assertFalse(
            $result['semantics']['createsEffectiveTruth'],
        );
        self::assertFalse(
            $result['semantics']['businessSuccessPrediction'],
        );
    }
}
