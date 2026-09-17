<?php

namespace App\Application\Access;

use App\Domain\Access\AccessDecision;
use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final class ResolveMembershipCapabilities
{
    public function activeMembership(
        User $user,
        Business $business,
    ): ?Membership {
        return Membership::query()
            ->where('user_id', $user->getKey())
            ->where('business_id', $business->getKey())
            ->where(
                'access_status',
                MembershipAccessStatus::Active->value,
            )
            ->first();
    }

    public function decide(
        Membership $membership,
        Capability $capability,
    ): AccessDecision {
        if ($membership->access_status !== MembershipAccessStatus::Active) {
            return AccessDecision::deny('membership_not_active');
        }

        $permission = Permission::query()
            ->where('key', $capability->value())
            ->first();

        if ($permission === null) {
            return AccessDecision::deny('permission_unknown');
        }

        $directGrant = PermissionGrant::query()
            ->where('business_id', $membership->business_id)
            ->where('membership_id', $membership->getKey())
            ->where('permission_id', $permission->getKey())
            ->first();

        if ($directGrant?->effect === PermissionEffect::Deny) {
            return AccessDecision::deny('direct_permission_denied');
        }

        $profileAllows = DB::table(
            'membership_permission_profiles as membership_profiles',
        )
            ->join(
                'permission_profile_permissions as profile_permissions',
                function (JoinClause $join): void {
                    $join
                        ->on(
                            'profile_permissions.permission_profile_id',
                            '=',
                            'membership_profiles.permission_profile_id',
                        )
                        ->on(
                            'profile_permissions.business_id',
                            '=',
                            'membership_profiles.business_id',
                        );
                },
            )
            ->where(
                'membership_profiles.business_id',
                $membership->business_id,
            )
            ->where(
                'membership_profiles.membership_id',
                $membership->getKey(),
            )
            ->where(
                'profile_permissions.permission_id',
                $permission->getKey(),
            )
            ->exists();

        if (
            $directGrant?->effect === PermissionEffect::Allow
            || $profileAllows
        ) {
            return AccessDecision::allow('capability_granted');
        }

        return AccessDecision::deny('capability_default_deny');
    }
}
