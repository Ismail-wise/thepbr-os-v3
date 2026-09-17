<?php

declare(strict_types=1);

namespace App\Application\Access;

use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionProfile;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Support\Str;

final class FindAuthorizedPermissionProfile
{
    public function __construct(
        private readonly AuthorizeBusinessCapability $authorizeBusinessCapability,
    ) {}

    public function execute(
        User $user,
        Business $currentBusiness,
        Capability $capability,
        string $permissionProfileId,
    ): ?PermissionProfile {
        if (! Str::isUuid($permissionProfileId)) {
            return null;
        }

        $hasActiveMembership = Membership::query()
            ->where('user_id', $user->getKey())
            ->where('business_id', $currentBusiness->getKey())
            ->where(
                'access_status',
                MembershipAccessStatus::Active->value,
            )
            ->exists();

        if (! $hasActiveMembership) {
            return null;
        }

        $profile = PermissionProfile::query()
            ->where(
                'business_id',
                $currentBusiness->getKey(),
            )
            ->whereKey($permissionProfileId)
            ->first();

        if ($profile === null) {
            return null;
        }

        if (
            (string) $profile->business_id
            !== (string) $currentBusiness->getKey()
        ) {
            return null;
        }

        $decision = $this->authorizeBusinessCapability->decide(
            $user,
            $currentBusiness,
            $currentBusiness,
            $capability,
            PermissionProfile::class,
            (string) $profile->getKey(),
        );

        return $decision->allowed
            ? $profile
            : null;
    }
}
