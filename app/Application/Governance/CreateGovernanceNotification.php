<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Domain\Governance\Enums\NotificationStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\GovernanceNotification;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use InvalidArgumentException;

final class CreateGovernanceNotification
{
    public function execute(
        Business $business,
        string $recipientMembershipId,
        string $kind,
        string $subjectType,
        string $subjectId,
    ): ?GovernanceNotification {
        $kind = trim($kind);
        $subjectType = trim($subjectType);

        if (
            $kind === ''
            || $subjectType === ''
            || preg_match('/\A[a-z][a-z0-9_.]{0,95}\z/', $kind) !== 1
            || preg_match('/\A[a-z][a-z0-9_.]{0,95}\z/', $subjectType) !== 1
        ) {
            throw new InvalidArgumentException(
                'Governance notification identifiers must use canonical safe identifiers.',
            );
        }

        $recipient = Membership::query()
            ->where('business_id', $business->getKey())
            ->whereKey($recipientMembershipId)
            ->where('access_status', 'active')
            ->first();

        if ($recipient === null) {
            return null;
        }

        return GovernanceNotification::query()->create([
            'business_id' => $business->getKey(),
            'recipient_membership_id' => $recipient->getKey(),
            'kind' => $kind,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'status' => NotificationStatus::Unread->value,
            'read_at' => null,
        ]);
    }
}
