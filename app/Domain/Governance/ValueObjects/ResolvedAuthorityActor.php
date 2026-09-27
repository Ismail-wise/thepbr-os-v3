<?php

declare(strict_types=1);

namespace App\Domain\Governance\ValueObjects;

use InvalidArgumentException;

final readonly class ResolvedAuthorityActor
{
    public function __construct(
        public string $membershipId,
        public string $capacity,
        public bool $canApprove,
        public bool $canVote,
        public bool $canSign,
        public string $source = 'base',
    ) {
        if (trim($membershipId) === '' || trim($capacity) === '') {
            throw new InvalidArgumentException(
                'Resolved authority actor requires Membership and capacity.',
            );
        }

        if (! in_array($source, ['base', 'delegation', 'emergency'], true)) {
            throw new InvalidArgumentException(
                'Resolved authority actor source is invalid.',
            );
        }

        if (! $canApprove && ! $canVote && ! $canSign) {
            throw new InvalidArgumentException(
                'Resolved authority actor must hold at least one decision capability.',
            );
        }
    }

    public function delegatedTo(
        string $membershipId,
    ): self {
        if ($membershipId === $this->membershipId) {
            throw new InvalidArgumentException(
                'Delegation cannot resolve to the same Membership.',
            );
        }

        return new self(
            $membershipId,
            'Delegated: '.$this->capacity,
            $this->canApprove,
            $this->canVote,
            $this->canSign,
            'delegation',
        );
    }
}
