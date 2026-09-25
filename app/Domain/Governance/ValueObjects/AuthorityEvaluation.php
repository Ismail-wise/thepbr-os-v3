<?php

declare(strict_types=1);

namespace App\Domain\Governance\ValueObjects;

use App\Domain\Governance\Enums\DecisionMethod;
use InvalidArgumentException;

final readonly class AuthorityEvaluation
{
    public function __construct(
        public AuthorityThreshold $threshold,
        public int $eligibleCount,
        public int $approvalCount,
        public int $supportingVoteCount,
        public int $quorumPresentCount,
    ) {
        foreach (
            [
                'eligibleCount' => $eligibleCount,
                'approvalCount' => $approvalCount,
                'supportingVoteCount' => $supportingVoteCount,
                'quorumPresentCount' => $quorumPresentCount,
            ] as $label => $value
        ) {
            if ($value < 0) {
                throw new InvalidArgumentException(
                    sprintf('%s cannot be negative.', $label),
                );
            }
        }

        if ($quorumPresentCount > $eligibleCount) {
            throw new InvalidArgumentException(
                'Quorum-present count cannot exceed eligible count.',
            );
        }
    }

    public function quorumMet(): bool
    {
        return $this->quorumPresentCount >= $this->threshold->quorumCount;
    }

    public function requirementsMet(): bool
    {
        if (! $this->quorumMet()) {
            return false;
        }

        return match ($this->threshold->method) {
            DecisionMethod::Approval =>
                $this->approvalCount
                    >= $this->threshold->requiredApprovals,

            DecisionMethod::Vote =>
                $this->supportingVoteCount
                    >= $this->threshold->requiredVotes,

            DecisionMethod::ApprovalAndVote =>
                $this->approvalCount
                    >= $this->threshold->requiredApprovals
                && $this->supportingVoteCount
                    >= $this->threshold->requiredVotes,
        };
    }
}
