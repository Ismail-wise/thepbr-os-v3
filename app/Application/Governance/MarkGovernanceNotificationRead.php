<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Governance\Enums\NotificationStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\GovernanceNotification;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class MarkGovernanceNotificationRead
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly GovernanceNotificationVisibility $visibility,
    ) {}

    public function execute(
        User $user,
        Business $business,
        string $notificationId,
    ): ?GovernanceNotification {
        $membership = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
        );

        if ($membership === null) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $notificationId,
            $membership,
        ): ?GovernanceNotification {
            $notification = GovernanceNotification::query()
                ->where('business_id', $business->getKey())
                ->where('recipient_membership_id', $membership->getKey())
                ->whereKey($notificationId)
                ->lockForUpdate()
                ->first();

            if (
                $notification === null
                || ! $this->visibility->allows(
                    $user,
                    $business,
                    $notification,
                )
            ) {
                return null;
            }

            if ($notification->status === NotificationStatus::Read) {
                return $notification;
            }

            $notification->fill([
                'status' => NotificationStatus::Read->value,
                'read_at' => now(),
            ])->save();

            return $notification->fresh();
        });
    }
}
