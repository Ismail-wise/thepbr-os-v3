<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Partnership;

use App\Domain\Partnership\EquityScenarioSimulator;
use App\Domain\Partnership\OwnershipActionPlanContract;
use App\Domain\Partnership\OwnershipDecisionRecordContract;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OwnershipChapterContractsTest extends TestCase
{
    public function test_equity_scenario_simulator_uses_default_weight_shape_deterministically_and_remains_planning_only(): void
    {
        $result = (new EquityScenarioSimulator)->calculate(
            [
                'capital' => '40',
                'work' => '30',
                'expertise' => '15',
                'risk' => '15',
            ],
            [
                [
                    'name' => 'Partner A',
                    'capital' => '100',
                    'work' => '0',
                    'expertise' => '10',
                    'risk' => '5',
                ],
                [
                    'name' => 'Partner B',
                    'capital' => '0',
                    'work' => '100',
                    'expertise' => '10',
                    'risk' => '5',
                ],
            ],
        );

        self::assertSame('100.00', $result['weightTotal']);
        self::assertTrue($result['weightsTotalOneHundred']);
        self::assertSame('55.00', $result['results'][0]['scenarioPercent']);
        self::assertSame('45.00', $result['results'][1]['scenarioPercent']);
        self::assertTrue($result['semantics']['scenarioOnly']);
        self::assertFalse($result['semantics']['writesOfficialOwnership']);
        self::assertFalse($result['semantics']['writesContribution']);
        self::assertFalse($result['semantics']['writesGovernance']);
        self::assertFalse($result['semantics']['writesShareRegister']);
    }

    public function test_equity_scenario_simulator_warns_when_weights_do_not_total_one_hundred(): void
    {
        $result = (new EquityScenarioSimulator)->calculate(
            [
                'capital' => '40',
                'work' => '30',
                'expertise' => '15',
                'risk' => '10',
            ],
            [[
                'name' => 'Partner A',
                'capital' => '1',
                'work' => '1',
                'expertise' => '1',
                'risk' => '1',
            ]],
        );

        self::assertSame('95.00', $result['weightTotal']);
        self::assertFalse($result['weightsTotalOneHundred']);
        self::assertSame('100.00', $result['results'][0]['scenarioPercent']);
    }

    public function test_decision_record_contract_requires_owner_review_date_and_summary_and_normalizes_references(): void
    {
        $normalized = (new OwnershipDecisionRecordContract)->normalize([
            'decisionOwnerMembershipId' => 'member-1',
            'reviewDate' => '2027-10-07',
            'decisionSummary' => ' Keep the current agreed Ownership. ',
            'evidenceReferences' => [
                'Board note 1',
                'Board note 1',
                ' Share agreement ',
                '',
            ],
        ]);

        self::assertSame('member-1', $normalized['decisionOwnerMembershipId']);
        self::assertSame('2027-10-07', $normalized['reviewDate']);
        self::assertSame(
            'Keep the current agreed Ownership.',
            $normalized['decisionSummary'],
        );
        self::assertSame(
            ['Board note 1', 'Share agreement'],
            $normalized['evidenceReferences'],
        );
    }

    public function test_decision_record_contract_rejects_invalid_review_date(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new OwnershipDecisionRecordContract)->normalize([
            'decisionOwnerMembershipId' => 'member-1',
            'reviewDate' => '2026-02-31',
            'decisionSummary' => 'Summary',
            'evidenceReferences' => [],
        ]);
    }

    public function test_action_plan_contract_allows_only_known_suggestions_and_normalizes_dates(): void
    {
        $contract = new OwnershipActionPlanContract;

        self::assertSame(
            OwnershipActionPlanContract::SUGGESTION_REVIEW,
            $contract->assertSuggestionKey(
                OwnershipActionPlanContract::SUGGESTION_REVIEW,
            ),
        );
        self::assertSame(
            '2027-10-07',
            $contract->normalizeDueDate('2027-10-07'),
        );

        $this->expectException(InvalidArgumentException::class);
        $contract->assertSuggestionKey('issue_shares_now');
    }
}
