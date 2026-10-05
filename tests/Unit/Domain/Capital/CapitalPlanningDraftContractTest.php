<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Capital;

use App\Domain\Capital\CapitalPlanningDraftContract;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CapitalPlanningDraftContractTest extends TestCase
{
    public function test_contract_preserves_missing_zero_non_zero_and_detailed_line_order(): void
    {
        $result = (new CapitalPlanningDraftContract)->normalize([
            'openingDate' => '2026-12-01',
            'preOpeningItems' => null,
            'initialAssetsInventoryItems' => [
                [
                    'category' => 'equipment',
                    'label' => 'Coffee machine',
                    'amount' => '0',
                ],
                [
                    'category' => 'opening_stock',
                    'label' => 'Opening inventory',
                    'amount' => '1250.50',
                ],
            ],
            'workingCapital' => null,
            'contingency' => null,
            'confirmedFunding' => '0',
        ]);

        self::assertNull($result['preOpeningItems']);
        self::assertSame(
            [
                [
                    'category' => 'equipment',
                    'label' => 'Coffee machine',
                    'amount' => '0.00',
                ],
                [
                    'category' => 'opening_stock',
                    'label' => 'Opening inventory',
                    'amount' => '1250.50',
                ],
            ],
            $result['initialAssetsInventoryItems'],
        );
        self::assertNull($result['workingCapital']);
        self::assertSame('0.00', $result['confirmedFunding']);
    }

    public function test_working_capital_keeps_only_method_specific_inputs(): void
    {
        $contract = new CapitalPlanningDraftContract;

        self::assertSame(
            [
                'method' => 'monthly_burn',
                'months' => 3,
                'monthlyBurn' => '6200.00',
            ],
            $contract->normalize([
                'workingCapital' => [
                    'method' => 'monthly_burn',
                    'months' => 3,
                    'monthlyBurn' => '6200.00',
                ],
            ])['workingCapital'],
        );

        self::assertSame(
            [
                'method' => 'monthly_costs',
                'months' => 2,
                'items' => [
                    [
                        'category' => 'salary',
                        'label' => 'Team salary',
                        'amount' => '3000.00',
                    ],
                    [
                        'category' => 'software',
                        'label' => 'Software stack',
                        'amount' => null,
                    ],
                ],
            ],
            $contract->normalize([
                'workingCapital' => [
                    'method' => 'monthly_costs',
                    'months' => 2,
                    'items' => [
                        [
                            'category' => 'salary',
                            'label' => 'Team salary',
                            'amount' => '3000',
                        ],
                        [
                            'category' => 'software',
                            'label' => 'Software stack',
                            'amount' => null,
                        ],
                    ],
                ],
            ])['workingCapital'],
        );

        self::assertSame(
            [
                'method' => 'fixed_amount',
                'amount' => '15000.00',
            ],
            $contract->normalize([
                'workingCapital' => [
                    'method' => 'fixed_amount',
                    'amount' => '15000',
                ],
            ])['workingCapital'],
        );

        self::assertSame(
            [
                'method' => 'canonical_operating_profile',
                'months' => 4,
            ],
            $contract->normalize([
                'workingCapital' => [
                    'method' => 'canonical_operating_profile',
                    'months' => 4,
                ],
            ])['workingCapital'],
        );
    }

    public function test_contingency_methods_preserve_missing_and_explicit_zero(): void
    {
        $contract = new CapitalPlanningDraftContract;

        self::assertSame(
            [
                'method' => 'percentage',
                'percentage' => null,
            ],
            $contract->normalize([
                'contingency' => [
                    'method' => 'percentage',
                    'percentage' => null,
                ],
            ])['contingency'],
        );

        self::assertSame(
            [
                'method' => 'percentage',
                'percentage' => '0.00',
            ],
            $contract->normalize([
                'contingency' => [
                    'method' => 'percentage',
                    'percentage' => '0',
                ],
            ])['contingency'],
        );

        self::assertSame(
            [
                'method' => 'fixed_amount',
                'amount' => '0.00',
            ],
            $contract->normalize([
                'contingency' => [
                    'method' => 'fixed_amount',
                    'amount' => '0',
                ],
            ])['contingency'],
        );
    }

    public function test_negative_money_and_non_applicable_method_fields_fail_safely(): void
    {
        $contract = new CapitalPlanningDraftContract;

        try {
            $contract->normalize([
                'confirmedFunding' => '-1.00',
            ]);

            self::fail('Expected negative money to be rejected.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);

        $contract->normalize([
            'workingCapital' => [
                'method' => 'canonical_operating_profile',
                'months' => 3,
                'monthlyBurn' => '999.00',
            ],
        ]);
    }
}
