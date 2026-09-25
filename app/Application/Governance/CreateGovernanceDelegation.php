<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Governance\Enums\DelegationStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\GovernanceDelegation;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * F3 foundation record only. This record does not itself grant governance
 * authority until a later authority resolver explicitly evaluates it.
 */
final class CreateGovernanceDelegation
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly RecordGovernanceOccurrence $occurrence,
    ) {}

    public function execute(
        User $user,
        Business $business,
        string $delegatorMembershipId,
        string $delegateMembershipId,
        string $scope,
        ?string $decisionType = null,
        ?CarbonInterface $effectiveFrom = null,
        ?CarbonInterface $expiresAt = null,
    ): ?GovernanceDelegation {
        $creator = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
        );

        if ($creator === null) {
            return null;
        }

        $scope = trim($scope);
        $decisionType = $decisionType === null ? null : trim($decisionType);
        $effectiveFrom ??= now();

        if ($scope === '' || ($decisionType !== null && $decisionType === '')) {
            throw new InvalidArgumentException(
                'Delegation scope and decision type must be canonical nonblank values.',
            );
        }

        if ($delegatorMembershipId === $delegateMembershipId) {
            throw new InvalidArgumentException(
                'Governance authority cannot be delegated to the same Membership.',
            );
        }

        if ($expiresAt !== null && $expiresAt <= $effectiveFrom) {
            throw new InvalidArgumentException(
                'Delegation expiry must be after effective-from.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $delegatorMembershipId,
            $delegateMembershipId,
            $scope,
            $decisionType,
            $effectiveFrom,
            $expiresAt,
            $creator,
        ): ?GovernanceDelegation {
            $memberships = Membership::query()
                ->where('business_id', $business->getKey())
                ->whereIn('id', [$delegatorMembershipId, $delegateMembershipId])
                ->where('access_status', 'active')
                ->lockForUpdate()
                ->pluck('id')
                ->map(static fn ($id): string => (string) $id)
                ->all();

            if (count($memberships) !== 2) {
                return null;
            }

            $delegation = GovernanceDelegation::query()->create([
                'business_id' => $business->getKey(),
                'delegator_membership_id' => $delegatorMembershipId,
                'delegate_membership_id' => $delegateMembershipId,
                'decision_type' => $decisionType,
                'scope' => $scope,
                'status' => DelegationStatus::Active->value,
                'effective_from' => $effectiveFrom,
                'expires_at' => $expiresAt,
                'created_by_membership_id' => $creator->getKey(),
                'revoked_by_membership_id' => null,
                'revoked_at' => null,
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'governance.delegation.created',
                'governance_delegation',
                (string) $delegation->getKey(),
                [
                    'delegator_membership_id' => $delegatorMembershipId,
                    'delegate_membership_id' => $delegateMembershipId,
                    'decision_type' => $decisionType,
                ],
            );

            return $delegation;
        });
    }
}
