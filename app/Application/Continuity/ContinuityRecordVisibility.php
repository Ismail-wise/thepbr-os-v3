<?php

declare(strict_types=1);

namespace App\Application\Continuity;

use App\Application\Governance\GovernanceActorContext;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\Enums\PermissionEffect;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\RecordAccessRule;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Support\Str;

final class ContinuityRecordVisibility
{
    public const string EMERGENCY_ACCESS_RESOURCE = 'continuity_emergency_access_record';

    public function __construct(
        private readonly GovernanceActorContext $actorContext,
    ) {}

    public function canView(
        User $user,
        Business $business,
        string $resourceType,
        string $resourceId,
        bool $restricted = true,
    ): bool {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::CONTINUITY_VIEW,
        ) === null) {
            return false;
        }

        if (! $restricted) {
            return true;
        }

        return $this->actorContext->canAccessResource(
            $user,
            $business,
            CapabilityCatalog::CONTINUITY_VIEW,
            $resourceType,
            $resourceId,
        );
    }

    /** @param list<string> $membershipIds */
    public function grantRestrictedAccess(
        Business $business,
        string $resourceType,
        string $resourceId,
        array $membershipIds,
    ): void {
        $ids = array_values(array_unique(array_filter(
            $membershipIds,
            static fn (string $id): bool => Str::isUuid($id),
        )));

        if ($ids === []) {
            return;
        }

        $validIds = Membership::query()
            ->where('business_id', $business->getKey())
            ->where('access_status', 'active')
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        foreach ([
            CapabilityCatalog::CONTINUITY_VIEW,
            CapabilityCatalog::CONTINUITY_MANAGE,
        ] as $capability) {
            $permission = Permission::query()
                ->where('key', $capability)
                ->first();

            if ($permission === null) {
                continue;
            }

            foreach ($validIds as $membershipId) {
                RecordAccessRule::query()->updateOrCreate(
                    [
                        'business_id' => $business->getKey(),
                        'membership_id' => $membershipId,
                        'permission_profile_id' => null,
                        'permission_id' => $permission->getKey(),
                        'resource_type' => $resourceType,
                        'resource_id' => $resourceId,
                    ],
                    ['effect' => PermissionEffect::Allow->value],
                );
            }
        }
    }
}
