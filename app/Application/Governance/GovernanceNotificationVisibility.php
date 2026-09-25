<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Domain\Access\CapabilityCatalog;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Action;
use App\Infrastructure\Persistence\Eloquent\Governance\GovernanceNotification;
use App\Infrastructure\Persistence\Eloquent\Governance\Review;
use App\Infrastructure\Persistence\Eloquent\Governance\SignatureRequest;
use App\Infrastructure\Persistence\Eloquent\Identity\User;

final class GovernanceNotificationVisibility
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
    ) {}

    public function allows(
        User $user,
        Business $business,
        GovernanceNotification $notification,
    ): bool {
        if (
            (string) $notification->business_id
            !== (string) $business->getKey()
        ) {
            return false;
        }

        $resourceType = match ($notification->subject_type) {
            'governance_action' => Action::class,
            'governance_review' => Review::class,
            'signature_request' => SignatureRequest::class,
            default => null,
        };

        if ($resourceType === null) {
            return false;
        }

        return $this->actorContext->canAccessResource(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
            $resourceType,
            (string) $notification->subject_id,
        );
    }
}
