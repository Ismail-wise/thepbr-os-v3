<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Governance\Enums\DelegationStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\GovernanceDelegation;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class RevokeGovernanceDelegation
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly RecordGovernanceOccurrence $occurrence,
    ) {}

    public function execute(
        User $user,
        Business $business,
        string $delegationId,
    ): ?GovernanceDelegation {
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
            $delegationId,
            $membership,
        ): ?GovernanceDelegation {
            $delegation = GovernanceDelegation::query()
                ->where('business_id', $business->getKey())
                ->whereKey($delegationId)
                ->lockForUpdate()
                ->first();

            if ($delegation === null || $delegation->status !== DelegationStatus::Active) {
                return null;
            }

            if (! $this->actorContext->canAccessResource(
                $user,
                $business,
                CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
                GovernanceDelegation::class,
                (string) $delegation->getKey(),
            )) {
                return null;
            }

            $delegation->fill([
                'status' => DelegationStatus::Revoked->value,
                'revoked_by_membership_id' => $membership->getKey(),
                'revoked_at' => now(),
            ])->save();

            $this->occurrence->record(
                $user,
                $business,
                'governance.delegation.revoked',
                'governance_delegation',
                (string) $delegation->getKey(),
            );

            return $delegation->fresh();
        });
    }
}
