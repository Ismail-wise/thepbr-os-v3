<?php

declare(strict_types=1);

namespace App\Application\Access;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Infrastructure\Persistence\Eloquent\Access\BusinessAccessInvitation;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class RevokeBusinessAccessInvitation
{
    public function __construct(
        private readonly ResolveMembershipCapabilities $memberships,
        private readonly AuthorizeBusinessCapability $authorization,
        private readonly AccessOccurrence $occurrence,
    ) {}

    public function execute(
        User $user,
        Business $business,
        string $invitationId,
    ): ?bool {
        $membership = $this->memberships->activeMembership(
            $user,
            $business,
        );

        if ($membership === null) {
            return null;
        }

        $decision = $this->authorization->decide(
            $user,
            $business,
            $business,
            new Capability(CapabilityCatalog::ACCESS_ADMIN_MANAGE),
        );

        if (! $decision->allowed) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $invitationId,
        ): ?bool {
            $invitation = BusinessAccessInvitation::query()
                ->where('business_id', $business->getKey())
                ->whereKey($invitationId)
                ->lockForUpdate()
                ->first();

            if ($invitation === null) {
                return null;
            }

            if ($invitation->status !== 'pending') {
                return false;
            }

            $invitation->status = 'revoked';
            $invitation->revoked_at = now();
            $invitation->save();

            $this->occurrence->record(
                $user,
                $business,
                'access.invitation.revoked',
                'business_access_invitation',
                (string) $invitation->getKey(),
            );

            return true;
        });
    }
}
