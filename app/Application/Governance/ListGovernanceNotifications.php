<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Domain\Access\CapabilityCatalog;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\GovernanceNotification;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Database\Eloquent\Collection;

final class ListGovernanceNotifications
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly GovernanceNotificationVisibility $visibility,
    ) {}

    /** @return Collection<int, GovernanceNotification> */
    public function execute(
        User $user,
        Business $business,
        int $limit = 50,
    ): Collection {
        $membership = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
        );

        if ($membership === null) {
            return new Collection();
        }

        $limit = max(1, min($limit, 100));

        $candidates = GovernanceNotification::query()
            ->where('business_id', $business->getKey())
            ->where('recipient_membership_id', $membership->getKey())
            ->orderByDesc('created_at')
            ->limit(min(200, max($limit * 4, 50)))
            ->get();

        return $candidates
            ->filter(
                fn (GovernanceNotification $notification): bool =>
                    $this->visibility->allows(
                        $user,
                        $business,
                        $notification,
                    ),
            )
            ->take($limit)
            ->values();
    }
}
