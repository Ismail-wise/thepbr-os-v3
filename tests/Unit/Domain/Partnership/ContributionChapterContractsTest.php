<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Partnership;

use App\Domain\Partnership\AcceptedContributionRegisterContract;
use App\Domain\Partnership\ContributionSetupContract;
use App\Domain\Partnership\ContributionTimeSkillValuation;
use App\Domain\Partnership\ValueObjects\ContributionValue;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ContributionChapterContractsTest extends TestCase
{
    public function test_time_skill_valuation_uses_exact_formula_and_never_returns_negative_accepted_basis(): void
    {
        $valuation = new ContributionTimeSkillValuation;

        $result = $valuation->calculate(
            '160',
            new ContributionValue('25.00'),
            6,
            new ContributionValue('1000.00'),
        );

        self::assertSame(
            '24000.00',
            $result['fairWorkValue'],
        );
        self::assertSame(
            '1000.00',
            $result['cashCompensationReceived'],
        );
        self::assertSame(
            '23000.00',
            $result['sweatContributionValue'],
        );
        self::assertNull(
            $result['discrepancy'],
        );

        $discrepancy = $valuation->calculate(
            '10.50',
            new ContributionValue('20.00'),
            1,
            new ContributionValue('500.00'),
        );

        self::assertSame(
            '210.00',
            $discrepancy['fairWorkValue'],
        );
        self::assertSame(
            '0.00',
            $discrepancy['sweatContributionValue'],
        );
        self::assertSame(
            'cash_compensation_exceeds_fair_work_value',
            $discrepancy['discrepancy'],
        );
    }

    public function test_contribution_setup_contract_rejects_invalid_period_and_duplicate_approvers(): void
    {
        $contract = new ContributionSetupContract;

        $this->expectException(
            InvalidArgumentException::class,
        );

        $contract->normalize([
            'valuationDate' => '2026-10-06',
            'currency' => 'USD',
            'periodStart' => '2026-12-31',
            'periodEnd' => '2026-01-01',
            'valuationOwnerMembershipId' => 'owner',
            'approverMembershipIds' => [
                'approver',
            ],
        ]);
    }

    public function test_accepted_register_hash_is_deterministic_and_exact_integer_money_is_preserved(): void
    {
        $contract =
            new AcceptedContributionRegisterContract;

        $left = [
            'businessId' => 'business',
            'rows' => [
                [
                    'id' => 'a',
                    'acceptedValue' => '10.10',
                ],
            ],
            'version' => 'v1',
        ];

        $right = [
            'version' => 'v1',
            'rows' => [
                [
                    'acceptedValue' => '10.10',
                    'id' => 'a',
                ],
            ],
            'businessId' => 'business',
        ];

        self::assertSame(
            $contract->hash($left),
            $contract->hash($right),
        );

        self::assertSame(
            '30.30',
            $contract->decimal(
                $contract->addMinorUnits(
                    1010,
                    2020,
                ),
            ),
        );
    }
}
