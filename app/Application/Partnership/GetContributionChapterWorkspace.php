<?php

declare(strict_types=1);

namespace App\Application\Partnership;

use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;

final class GetContributionChapterWorkspace
{
    public function __construct(
        private readonly GetContributionSetupReadModel $setup,
        private readonly GetContributionRegisterReadModel $register,
        private readonly GetAcceptedContributionRegister $accepted,
        private readonly GetContributionDecisionRecordReadModel $decision,
        private readonly GetContributionActionPlanReadModel $actions,
    ) {}

    /**
     * @return array<string,mixed>|null
     */
    public function execute(
        User $user,
        Business $business,
        int $partnerCount,
    ): ?array {
        $setup = $this->setup->execute(
            $user,
            $business,
        );
        $register = $this->register->execute(
            $user,
            $business,
        );
        $accepted = $this->accepted->execute(
            $user,
            $business,
        );

        if (
            $setup === null
            || $register === null
            || $accepted === null
        ) {
            return null;
        }

        $decision = $this->decision->execute(
            $user,
            $business,
        );
        $actions = $this->actions->execute(
            $user,
            $business,
        );

        $counts =
            $register['counts'] ?? [];

        $hasContribution =
            (int) ($counts['total'] ?? 0)
            > 0;

        $hasValued =
            (int) ($counts['reviewed'] ?? 0)
            + (int) ($counts['approved'] ?? 0)
            + (int) ($counts['delivered'] ?? 0)
            + (int) ($counts['accepted'] ?? 0)
            + (int) ($counts['rejected'] ?? 0)
            + (int) ($counts['cancelled'] ?? 0)
            + (int) ($counts['defaulted'] ?? 0)
            > 0;

        $rows =
            $register['rows'] ?? [];

        $hasEvidence =
            collect($rows)->contains(
                static fn (
                    array $row,
                ): bool => (int) (
                    $row['evidenceCount']
                    ?? 0
                ) > 0,
            );

        $hasApproved =
            (int) ($counts['approved'] ?? 0)
            + (int) ($counts['delivered'] ?? 0)
            + (int) ($counts['accepted'] ?? 0)
            > 0;

        $hasDelivery =
            (int) ($counts['delivered'] ?? 0)
            + (int) ($counts['accepted'] ?? 0)
            > 0;

        $hasAccepted =
            (int) (
                $accepted['acceptedCount']
                ?? 0
            ) > 0;

        $hasCurrentDecision =
            ($decision['recorded'] ?? false)
            === true;

        $states = [
            'setup' => ($setup['configured'] ?? false)
                === true,
            'partners' => $partnerCount > 0,
            'contributions' => $hasContribution,
            'valuation' => $hasValued,
            'evidence_conditions' => $hasEvidence,
            'approval' => $hasApproved,
            'delivery' => $hasDelivery,
            'acceptance' => $hasAccepted,
            'matrix_register' => ($accepted['available'] ?? false)
                === true
                && $hasAccepted,
            'decision_record' => $hasCurrentDecision,
            'action_plan' => $hasCurrentDecision,
        ];

        $order = array_keys($states);
        $firstMissing = collect($order)
            ->first(
                static fn (
                    string $key,
                ): bool => $states[$key] === false,
            );

        $steps = array_map(
            static function (
                string $key,
            ) use (
                $states,
                $firstMissing,
            ): array {
                return [
                    'key' => $key,
                    'state' => $states[$key]
                        ? 'recorded'
                        : (
                            $firstMissing === $key
                            ? 'current'
                            : 'available'
                        ),
                ];
            },
            $order,
        );

        return [
            'contractVersion' => 'partner-contributions-chapter-v1',
            'setup' => $setup,
            'register' => $register,
            'acceptedRegister' => $accepted,
            'decisionRecord' => $decision,
            'actionPlan' => $actions,
            'progress' => [
                'steps' => $steps,
                'nextStep' => $firstMissing,
                'chapterComplete' => ($setup['configured']
                        ?? false) === true
                    && (
                        $accepted[
                            'decisionReady'
                        ] ?? false
                    ) === true
                    && $hasCurrentDecision,
            ],
            'routes' => [
                'documentVault' => '/records/documents',
                'governance' => '/governance',
                'ownership' => '/partnership?section=ownership',
            ],
            'boundaries' => [
                'capitalIsContribution' => false,
                'fundingIsContribution' => false,
                'committedIsReceived' => false,
                'receivedIsAccepted' => false,
                'approvalIsAcceptance' => false,
                'contributionIsEquity' => false,
                'contributionIsShares' => false,
                'contributionIsOwnership' => false,
                'signatureIsEffectivity' => false,
                'actionCompletionChangesAcceptedValue' => false,
            ],
        ];
    }
}
