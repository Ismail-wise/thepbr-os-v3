<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Governance\Enums\EmergencyAuthorityStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\EmergencyAuthorityGrant;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * F3 foundation record only. It is deliberately not consulted by the current
 * Formation Authority resolver, so creation cannot silently grant authority.
 */
final class CreateEmergencyAuthorityGrant
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly RecordGovernanceOccurrence $occurrence,
    ) {}

    public function execute(
        User $user,
        Business $business,
        string $granteeMembershipId,
        string $scope,
        string $reason,
        CarbonInterface $expiresAt,
        ?string $decisionType = null,
        ?CarbonInterface $effectiveFrom = null,
    ): ?EmergencyAuthorityGrant {
        $creator = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
        );

        if ($creator === null) {
            return null;
        }

        $scope = trim($scope);
        $reason = trim($reason);
        $decisionType = $decisionType === null ? null : trim($decisionType);
        $effectiveFrom ??= now();

        if (
            $scope === ''
            || $reason === ''
            || ($decisionType !== null && $decisionType === '')
        ) {
            throw new InvalidArgumentException(
                'Emergency authority scope, reason and decision type must be canonical.',
            );
        }

        if ($expiresAt <= $effectiveFrom) {
            throw new InvalidArgumentException(
                'Emergency authority expiry must be after effective-from.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $granteeMembershipId,
            $scope,
            $reason,
            $expiresAt,
            $decisionType,
            $effectiveFrom,
            $creator,
        ): ?EmergencyAuthorityGrant {
            $grantee = Membership::query()
                ->where('business_id', $business->getKey())
                ->whereKey($granteeMembershipId)
                ->where('access_status', 'active')
                ->lockForUpdate()
                ->first();

            if ($grantee === null) {
                return null;
            }

            $grant = EmergencyAuthorityGrant::query()->create([
                'business_id' => $business->getKey(),
                'grantee_membership_id' => $grantee->getKey(),
                'decision_type' => $decisionType,
                'scope' => $scope,
                'reason' => $reason,
                'status' => EmergencyAuthorityStatus::Active->value,
                'effective_from' => $effectiveFrom,
                'expires_at' => $expiresAt,
                'created_by_membership_id' => $creator->getKey(),
                'revoked_by_membership_id' => null,
                'revoked_at' => null,
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'governance.emergency_authority.created',
                'emergency_authority_grant',
                (string) $grant->getKey(),
                [
                    'grantee_membership_id' => (string) $grantee->getKey(),
                    'decision_type' => $decisionType,
                ],
            );

            return $grant;
        });
    }
}
