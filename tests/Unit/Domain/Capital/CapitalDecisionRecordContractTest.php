<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Capital;

use App\Domain\Capital\CapitalDecisionRecordContract;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CapitalDecisionRecordContractTest extends TestCase
{
    public function test_it_normalizes_dates_summary_and_evidence(): void
    {
        $contract = new CapitalDecisionRecordContract;

        $result = $contract->normalize([
            'decisionOwnerMembershipId' => ' owner-id ',
            'effectiveDate' => '2027-01-15',
            'reviewDate' => '2027-04-15',
            'decisionSummary' => ' Approved Base Capital Plan. ',
            'evidenceReferences' => [
                ' Partner meeting note ',
                '',
                'Partner meeting note',
                'Bank confirmation',
            ],
        ]);

        self::assertSame('owner-id', $result['decisionOwnerMembershipId']);
        self::assertSame('2027-01-15', $result['effectiveDate']);
        self::assertSame('2027-04-15', $result['reviewDate']);
        self::assertSame(
            'Approved Base Capital Plan.',
            $result['decisionSummary'],
        );
        self::assertSame(
            ['Partner meeting note', 'Bank confirmation'],
            $result['evidenceReferences'],
        );
    }

    public function test_review_date_cannot_precede_effective_date(): void
    {
        $contract = new CapitalDecisionRecordContract;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Review Date must be on or after the Effective Date.',
        );

        $contract->normalize([
            'decisionOwnerMembershipId' => 'owner-id',
            'effectiveDate' => '2027-04-15',
            'reviewDate' => '2027-01-15',
            'decisionSummary' => 'Decision.',
            'evidenceReferences' => [],
        ]);
    }

    public function test_summary_and_calendar_dates_are_required(): void
    {
        $contract = new CapitalDecisionRecordContract;

        foreach ([
            [
                'input' => [
                    'decisionOwnerMembershipId' => 'owner-id',
                    'effectiveDate' => '2027-02-30',
                    'reviewDate' => '2027-04-15',
                    'decisionSummary' => 'Decision.',
                ],
                'message' => 'Effective Date must be a valid calendar date.',
            ],
            [
                'input' => [
                    'decisionOwnerMembershipId' => 'owner-id',
                    'effectiveDate' => '2027-01-15',
                    'reviewDate' => '2027-04-15',
                    'decisionSummary' => '   ',
                ],
                'message' => 'Decision Summary is required.',
            ],
        ] as $case) {
            try {
                $contract->normalize($case['input']);
                self::fail('Expected validation failure.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame($case['message'], $exception->getMessage());
            }
        }
    }
}
