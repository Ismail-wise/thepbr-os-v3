<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Capital;

use App\Domain\Capital\CapitalComparisonDraftContract;
use App\Domain\Capital\CapitalPlanningDraftContract;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CapitalComparisonDraftContractTest extends TestCase
{
    public function test_initialization_clones_canonical_input_into_exact_lean_base_growth_order(): void
    {
        $contract = $this->contract();
        $canonical = $this->planningInput('500.00');

        $result = $contract->initializeFromCanonical($canonical);

        self::assertSame(
            ['lean', 'base', 'growth'],
            array_keys($result['scenarios']),
        );
        self::assertSame(
            '500.00',
            $result['scenarios']['lean']['workingCapital']['amount'],
        );
        self::assertSame(
            $result['scenarios']['lean'],
            $result['scenarios']['base'],
        );
        self::assertSame(
            $result['scenarios']['base'],
            $result['scenarios']['growth'],
        );
        self::assertNull($result['preferredPlan']);
    }

    public function test_partial_scenarios_and_preferred_plan_are_normalized_without_inventing_missing_values(): void
    {
        $result = $this->contract()->normalize([
            'scenarios' => [
                'lean' => $this->planningInput('0'),
                'base' => null,
            ],
            'preferredPlan' => 'lean',
        ]);

        self::assertSame('0.00', $result['scenarios']['lean']['workingCapital']['amount']);
        self::assertNull($result['scenarios']['base']);
        self::assertNull($result['scenarios']['growth']);
        self::assertSame('lean', $result['preferredPlan']);
    }

    public function test_unknown_scenario_or_preferred_key_fails_closed(): void
    {
        $contract = $this->contract();

        try {
            $contract->normalize([
                'scenarios' => [
                    'custom' => $this->planningInput('100.00'),
                ],
            ]);

            self::fail('Expected custom scenario key to be rejected.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);

        $contract->normalize([
            'scenarios' => [],
            'preferredPlan' => 'custom',
        ]);
    }

    public function test_scenario_input_reuses_capital_planning_contract_rules(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->contract()->normalize([
            'scenarios' => [
                'growth' => [
                    'workingCapital' => [
                        'method' => 'fixed_amount',
                        'amount' => '-1.00',
                    ],
                ],
            ],
        ]);
    }

    private function contract(): CapitalComparisonDraftContract
    {
        return new CapitalComparisonDraftContract(
            new CapitalPlanningDraftContract,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function planningInput(string $workingCapital): array
    {
        return [
            'openingDate' => null,
            'preOpeningItems' => [],
            'initialAssetsInventoryItems' => [],
            'workingCapital' => [
                'method' => 'fixed_amount',
                'amount' => $workingCapital,
            ],
            'contingency' => [
                'method' => 'fixed_amount',
                'amount' => '0.00',
            ],
            'confirmedFunding' => '0.00',
        ];
    }
}
