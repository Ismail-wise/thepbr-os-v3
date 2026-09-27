<?php

declare(strict_types=1);

namespace App\Application\Conflict;

use App\Application\Governance\GovernanceActorContext;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\Enums\PermissionEffect;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\RecordAccessRule;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Conflict\ConflictCase;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Support\Str;

final class ConflictRecordVisibility
{
    public const string CASE_RESOURCE = 'conflict_case';

    public function __construct(
        private readonly GovernanceActorContext $actorContext,
    ) {}

    public function canView(
        User $user,
        Business $business,
        string $caseId,
    ): bool {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::CONFLICT_VIEW,
        ) === null) {
            return false;
        }

        return $this->actorContext->canAccessResource(
            $user,
            $business,
            CapabilityCatalog::CONFLICT_VIEW,
            ConflictCase::class,
            $caseId,
        );
    }

    public function canManage(
        User $user,
        Business $business,
        string $caseId,
    ): bool {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::CONFLICT_MANAGE,
        ) === null) {
            return false;
        }

        return $this->actorContext->canAccessResource(
            $user,
            $business,
            CapabilityCatalog::CONFLICT_MANAGE,
            ConflictCase::class,
            $caseId,
        );
    }

    /**
     * Participant status does not grant access. This creates only explicit
     * record-level System access; it creates no Governance, Ownership or
     * Document authority.
     *
     * @param  list<string>  $membershipIds
     */
    public function grantRestrictedAccess(
        Business $business,
        string $caseId,
        array $membershipIds,
        bool $manage = true,
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

        $capabilities = [
            CapabilityCatalog::CONFLICT_VIEW,
            CapabilityCatalog::RECORDS_ACTIVITY_VIEW,
        ];

        if ($manage) {
            $capabilities[] = CapabilityCatalog::CONFLICT_MANAGE;
        }

        foreach ($capabilities as $capability) {
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
                        'resource_type' => ConflictCase::class,
                        'resource_id' => $caseId,
                    ],
                    ['effect' => PermissionEffect::Allow->value],
                );
            }
        }
    }

    /**
     * Revocation is an explicit scoped deny. History remains preserved.
     *
     * @param  list<string>  $membershipIds
     */
    public function denyRestrictedAccess(
        Business $business,
        string $caseId,
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
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        foreach ([
            CapabilityCatalog::CONFLICT_VIEW,
            CapabilityCatalog::CONFLICT_MANAGE,
            CapabilityCatalog::RECORDS_ACTIVITY_VIEW,
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
                        'resource_type' => ConflictCase::class,
                        'resource_id' => $caseId,
                    ],
                    ['effect' => PermissionEffect::Deny->value],
                );
            }
        }
    }
}
