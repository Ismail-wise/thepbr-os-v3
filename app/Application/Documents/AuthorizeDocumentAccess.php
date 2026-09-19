<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Application\Access\ResolveMembershipCapabilities;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Documents\Enums\DocumentAccessRight;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Documents\Document;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentAccessGrant;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;

final class AuthorizeDocumentAccess
{
    public function __construct(
        private readonly ResolveMembershipCapabilities $membershipCapabilities,
        private readonly AuthorizeBusinessCapability $businessCapability,
    ) {}

    public function activeMembershipWithCapability(
        User $user,
        Business $currentBusiness,
        string $capability,
    ): ?Membership {
        $membership = $this->membershipCapabilities->activeMembership(
            $user,
            $currentBusiness,
        );

        if ($membership === null) {
            return null;
        }

        $decision = $this->businessCapability->decide(
            $user,
            $currentBusiness,
            $currentBusiness,
            new Capability($capability),
        );

        if (! $decision->allowed) {
            return null;
        }

        return $membership;
    }

    public function allows(
        User $user,
        Business $currentBusiness,
        Document $document,
        DocumentAccessRight $right,
        string $capability,
    ): ?Membership {
        $membership = $this->activeMembershipWithCapability(
            $user,
            $currentBusiness,
            $capability,
        );

        if ($membership === null) {
            return null;
        }

        if (
            (string) $document->business_id
            !== (string) $currentBusiness->getKey()
        ) {
            return null;
        }

        $grants = DocumentAccessGrant::query()
            ->where('business_id', $currentBusiness->getKey())
            ->where('membership_id', $membership->getKey())
            ->where('document_id', $document->getKey())
            ->where('right', $right->value);

        if ((clone $grants)->where('effect', 'deny')->exists()) {
            return null;
        }

        if (! (clone $grants)->where('effect', 'allow')->exists()) {
            return null;
        }

        return $membership;
    }
}
