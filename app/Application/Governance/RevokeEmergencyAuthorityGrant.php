<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Governance\Enums\EmergencyAuthorityStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\EmergencyAuthorityGrant;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class RevokeEmergencyAuthorityGrant
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly RecordGovernanceOccurrence $occurrence,
    ) {}

    public function execute(
        User $user,
        Business $business,
        string $grantId,
    ): ?EmergencyAuthorityGrant {
        $membership = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $grantId,
            $membership,
        ): ?EmergencyAuthorityGrant {
            $grant = EmergencyAuthorityGrant::query()
                ->where('business_id', $business->getKey())
                ->whereKey($grantId)
                ->lockForUpdate()
                ->first();

            if ($grant === null || $grant->status !== EmergencyAuthorityStatus::Active) {
                return null;
            }

            if (! $this->actorContext->canAccessResource(
                $user,
                $business,
                CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
                EmergencyAuthorityGrant::class,
                (string) $grant->getKey(),
            )) {
                return null;
            }

            $grant->fill([
                'status' => EmergencyAuthorityStatus::Revoked->value,
                'revoked_by_membership_id' => $membership->getKey(),
                'revoked_at' => now(),
            ])->save();

            $this->occurrence->record(
                $user,
                $business,
                'governance.emergency_authority.revoked',
                'emergency_authority_grant',
                (string) $grant->getKey(),
            );

            return $grant->fresh();
        });
    }
}
