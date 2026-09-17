<?php

namespace App\Application\Access;

use App\Domain\Access\AccessDecision;
use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Access\ValueObjects\Capability;
use App\Infrastructure\Persistence\Eloquent\Access\AccessPolicy;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\RecordAccessRule;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AuthorizeBusinessCapability
{
    public function __construct(
        private readonly ResolveMembershipCapabilities $capabilities,
    ) {}

    public function decide(
        User $user,
        Business $currentBusiness,
        Business $resourceBusiness,
        Capability $capability,
        ?string $resourceType = null,
        ?string $resourceId = null,
    ): AccessDecision {
        if (
            (string) $currentBusiness->getKey()
            !== (string) $resourceBusiness->getKey()
        ) {
            return AccessDecision::deny('resource_business_mismatch');
        }

        $membership = $this->capabilities->activeMembership(
            $user,
            $currentBusiness,
        );

        if ($membership === null) {
            return AccessDecision::deny('active_membership_required');
        }

        $capabilityDecision = $this->capabilities->decide(
            $membership,
            $capability,
        );

        if (! $capabilityDecision->allowed) {
            return $capabilityDecision;
        }

        if ($resourceType === null && $resourceId === null) {
            return $capabilityDecision;
        }

        if (
            $resourceType === null
            || $resourceType === ''
            || $resourceType !== trim($resourceType)
            || strlen($resourceType) > 160
        ) {
            return AccessDecision::deny('invalid_resource_type');
        }

        if (
            $resourceId !== null
            && ! Str::isUuid($resourceId)
        ) {
            return AccessDecision::deny('invalid_resource_id');
        }

        $permission = Permission::query()
            ->where('key', $capability->value())
            ->first();

        if ($permission === null) {
            return AccessDecision::deny('permission_unknown');
        }

        $profileIds = DB::table('membership_permission_profiles')
            ->where('business_id', $currentBusiness->getKey())
            ->where('membership_id', $membership->getKey())
            ->pluck('permission_profile_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $policyQuery = AccessPolicy::query()
            ->where('business_id', $currentBusiness->getKey())
            ->where('permission_id', $permission->getKey())
            ->where('resource_type', $resourceType)
            ->where(
                function (Builder $query) use (
                    $membership,
                    $profileIds,
                ): void {
                    $query->where(
                        'membership_id',
                        $membership->getKey(),
                    );

                    if ($profileIds !== []) {
                        $query->orWhereIn(
                            'permission_profile_id',
                            $profileIds,
                        );
                    }
                },
            );

        if (
            (clone $policyQuery)
                ->where('effect', PermissionEffect::Deny->value)
                ->exists()
        ) {
            return AccessDecision::deny('access_policy_denied');
        }

        $policyAllows = (clone $policyQuery)
            ->where('effect', PermissionEffect::Allow->value)
            ->exists();

        $recordAllows = false;

        if ($resourceId !== null) {
            $recordQuery = RecordAccessRule::query()
                ->where('business_id', $currentBusiness->getKey())
                ->where('permission_id', $permission->getKey())
                ->where('resource_type', $resourceType)
                ->where('resource_id', $resourceId)
                ->where(
                    function (Builder $query) use (
                        $membership,
                        $profileIds,
                    ): void {
                        $query->where(
                            'membership_id',
                            $membership->getKey(),
                        );

                        if ($profileIds !== []) {
                            $query->orWhereIn(
                                'permission_profile_id',
                                $profileIds,
                            );
                        }
                    },
                );

            if (
                (clone $recordQuery)
                    ->where('effect', PermissionEffect::Deny->value)
                    ->exists()
            ) {
                return AccessDecision::deny('record_access_denied');
            }

            $recordAllows = (clone $recordQuery)
                ->where('effect', PermissionEffect::Allow->value)
                ->exists();
        }

        if ($policyAllows || $recordAllows) {
            return AccessDecision::allow('resource_access_granted');
        }

        return AccessDecision::deny('resource_visibility_default_deny');
    }
}
