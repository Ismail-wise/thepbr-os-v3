<?php

declare(strict_types=1);

namespace App\Application\Partnership;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Application\Access\ResolveMembershipCapabilities;
use App\Domain\Access\ValueObjects\Capability;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;

final class PartnershipActorContext
{
    public function __construct(
        private readonly AuthorizeBusinessCapability $authorize,
        private readonly ResolveMembershipCapabilities $capabilities,
    ) {}

    public function membership(
        User $user,
        Business $business,
        string $capability,
    ): ?Membership {
        $membership = $this->capabilities->activeMembership(
            $user,
            $business,
        );

        if ($membership === null) {
            return null;
        }

        $decision = $this->authorize->decide(
            $user,
            $business,
            $business,
            new Capability($capability),
        );

        return $decision->allowed ? $membership : null;
    }

    public function allows(
        User $user,
        Business $business,
        string $capability,
    ): bool {
        return $this->membership(
            $user,
            $business,
            $capability,
        ) !== null;
    }
}
