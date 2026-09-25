<?php

declare(strict_types=1);

namespace App\Application\Access;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Access\Enums\StandardAccessProfile;
use App\Domain\Access\StandardAccessProfileMatrix;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Infrastructure\Persistence\Eloquent\Access\AccessPolicy;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionProfile;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Action;
use App\Infrastructure\Persistence\Eloquent\Governance\AmendmentRequest;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Governance\Review;
use App\Infrastructure\Persistence\Eloquent\Governance\SignatureRequest;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\Proposal;
use App\Infrastructure\Persistence\Eloquent\Records\ProposalVersion;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ProvisionStandardAccessProfiles
{
    /**
     * Provision Master-Spec system-access templates for one Business.
     *
     * This never creates governance authority, ownership rights or document
     * rights. Workspace Owner assignment is system access only.
     *
     * @return array<string, string> profile name => profile id
     */
    public function execute(
        Business $business,
        ?Membership $workspaceOwner = null,
    ): array {
        if (
            $workspaceOwner !== null
            && (
                (string) $workspaceOwner->business_id
                    !== (string) $business->getKey()
                || $workspaceOwner->access_status
                    !== MembershipAccessStatus::Active
            )
        ) {
            throw new InvalidArgumentException(
                'Workspace Owner profile may only be assigned to an active Membership in the same Business.',
            );
        }

        return DB::transaction(function () use (
            $business,
            $workspaceOwner,
        ): array {
            /** @var array<string, Permission> $permissions */
            $permissions = [];

            foreach (CapabilityCatalog::all() as $capability) {
                $permissions[$capability] = Permission::query()
                    ->firstOrCreate(['key' => $capability]);
            }

            $profileIds = [];

            foreach (StandardAccessProfile::cases() as $standardProfile) {
                $profile = PermissionProfile::query()->firstOrCreate([
                    'business_id' => $business->getKey(),
                    'name' => $standardProfile->value,
                ]);

                $profileIds[$standardProfile->value] =
                    (string) $profile->getKey();

                $capabilities =
                    StandardAccessProfileMatrix::capabilities($standardProfile);

                foreach ($capabilities as $capability) {
                    $permission = $permissions[$capability];

                    DB::table('permission_profile_permissions')
                        ->insertOrIgnore([
                            'business_id' => $business->getKey(),
                            'permission_profile_id' => $profile->getKey(),
                            'permission_id' => $permission->getKey(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                    foreach (
                        $this->resourceTypesFor($capability)
                        as $resourceType
                    ) {
                        AccessPolicy::query()->firstOrCreate([
                            'business_id' => $business->getKey(),
                            'membership_id' => null,
                            'permission_profile_id' => $profile->getKey(),
                            'permission_id' => $permission->getKey(),
                            'resource_type' => $resourceType,
                            'effect' => PermissionEffect::Allow->value,
                        ]);
                    }
                }
            }

            if ($workspaceOwner !== null) {
                $workspaceOwnerProfileId =
                    $profileIds[StandardAccessProfile::WorkspaceOwner->value];

                DB::table('membership_permission_profiles')
                    ->insertOrIgnore([
                        'business_id' => $business->getKey(),
                        'membership_id' => $workspaceOwner->getKey(),
                        'permission_profile_id' => $workspaceOwnerProfileId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            return $profileIds;
        });
    }

    /**
     * Resource visibility remains separate from the base capability grant.
     *
     * Document access is intentionally absent here because Document Permission
     * is an independent rights domain.
     *
     * @return list<class-string>
     */
    private function resourceTypesFor(string $capability): array
    {
        return match ($capability) {
            CapabilityCatalog::PERMISSION_PROFILES_VIEW,
            CapabilityCatalog::ACCESS_ADMIN_VIEW,
            CapabilityCatalog::ACCESS_ADMIN_MANAGE => [
                PermissionProfile::class,
            ],

            CapabilityCatalog::RECORDS_VIEW,
            CapabilityCatalog::RECORDS_MANAGE => [
                FormalRecordFamily::class,
                FormalRecordVersion::class,
                Proposal::class,
                ProposalVersion::class,
            ],

            CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE => [
                Decision::class,
                SignatureRequest::class,
                Action::class,
                Review::class,
                AmendmentRequest::class,
                FormalRecordVersion::class,
                ProposalVersion::class,
            ],

            CapabilityCatalog::GOVERNANCE_SIGNATURE_ACT => [
                SignatureRequest::class,
            ],

            CapabilityCatalog::GOVERNANCE_ACTION_MANAGE => [
                Action::class,
                Decision::class,
                FormalRecordVersion::class,
            ],

            default => [],
        };
    }
}
