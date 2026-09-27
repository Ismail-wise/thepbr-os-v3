<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

final class SegregationOfDuties
{
    /**
     * @param  list<string>  $affirmativeGovernanceActors
     * @return array{separated:bool,requires_compensating_review:bool}
     */
    public function evaluate(
        string $requesterMembershipId,
        string $payerMembershipId,
        array $affirmativeGovernanceActors,
        bool $strictThreeWay,
        bool $compensatingReviewAllowed,
    ): array {
        $actors = array_values(array_unique(array_filter(
            $affirmativeGovernanceActors,
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        )));

        $requesterIsApprover = in_array($requesterMembershipId, $actors, true);
        $payerIsApprover = in_array($payerMembershipId, $actors, true);
        $requesterIsPayer = $requesterMembershipId === $payerMembershipId;

        $separated = ! $requesterIsPayer
            && ! $requesterIsApprover
            && ! $payerIsApprover;

        if ($separated) {
            return [
                'separated' => true,
                'requires_compensating_review' => false,
            ];
        }

        return [
            'separated' => false,
            'requires_compensating_review' => ! $strictThreeWay
                && $compensatingReviewAllowed,
        ];
    }
}
