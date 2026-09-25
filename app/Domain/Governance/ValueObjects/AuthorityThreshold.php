<?php

declare(strict_types=1);

namespace App\Domain\Governance\ValueObjects;

use App\Domain\Governance\Enums\DecisionMethod;
use InvalidArgumentException;

final readonly class AuthorityThreshold
{
    public function __construct(
        public DecisionMethod $method,
        public int $requiredApprovals,
        public int $requiredVotes,
        public int $quorumCount,
    ) {
        if ($requiredApprovals < 0) {
            throw new InvalidArgumentException(
                'Required approvals cannot be negative.',
            );
        }

        if ($requiredVotes < 0) {
            throw new InvalidArgumentException(
                'Required votes cannot be negative.',
            );
        }

        if ($quorumCount < 1) {
            throw new InvalidArgumentException(
                'Quorum count must be at least one.',
            );
        }

        if (
            $method === DecisionMethod::Approval
            && ($requiredApprovals < 1 || $requiredVotes !== 0)
        ) {
            throw new InvalidArgumentException(
                'Approval decisions require approvals and no votes.',
            );
        }

        if (
            $method === DecisionMethod::Vote
            && ($requiredVotes < 1 || $requiredApprovals !== 0)
        ) {
            throw new InvalidArgumentException(
                'Vote decisions require votes and no approvals.',
            );
        }

        if (
            $method === DecisionMethod::ApprovalAndVote
            && ($requiredApprovals < 1 || $requiredVotes < 1)
        ) {
            throw new InvalidArgumentException(
                'Combined decisions require both approvals and votes.',
            );
        }
    }
}
