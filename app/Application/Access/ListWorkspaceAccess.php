<?php

declare(strict_types=1);

namespace App\Application\Access;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionProfile;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class ListWorkspaceAccess
{
    public function __construct(
        private readonly AuthorizeBusinessCapability $authorizeBusinessCapability,
        private readonly FindAuthorizedPermissionProfile $findAuthorizedPermissionProfile,
        private readonly ResolveMembershipCapabilities $resolveMembershipCapabilities,
    ) {}

    /**
     * @return array{
     *     business: array{id: string, name: string},
     *     membership: array{id: string, accessStatus: string},
     *     profiles: list<array{
     *         id: string,
     *         name: string,
     *         capabilities: list<string>,
     *         assigned: bool
     *     }>,
     *     directGrants: list<array{capability: string, effect: string}>,
     *     canManageInvitations: bool,
     *     invitations: list<array{
     *         id: string,
     *         invitedEmail: string,
     *         profileName: string,
     *         status: string,
     *         tokenLast4: string,
     *         expiresAt: string,
     *         createdAt: string
     *     }>
     * }|null
     */
    public function execute(
        User $user,
        Business $currentBusiness,
    ): ?array {
        $membership = $this->resolveMembershipCapabilities->activeMembership(
            $user,
            $currentBusiness,
        );

        if ($membership === null) {
            return null;
        }

        $viewCapability = new Capability('permission_profiles.view');

        $decision = $this->authorizeBusinessCapability->decide(
            $user,
            $currentBusiness,
            $currentBusiness,
            $viewCapability,
        );

        if (! $decision->allowed) {
            return null;
        }

        $businessId = (string) $currentBusiness->getKey();
        $membershipId = (string) $membership->getKey();

        $canManageInvitations = $this->authorizeBusinessCapability->decide(
            $user,
            $currentBusiness,
            $currentBusiness,
            new Capability(CapabilityCatalog::ACCESS_ADMIN_MANAGE),
        )->allowed;

        $profiles = PermissionProfile::query()
            ->where('business_id', $businessId)
            ->orderBy('name')
            ->get(['id', 'business_id', 'name'])
            ->map(function (
                PermissionProfile $profile,
            ) use (
                $user,
                $currentBusiness,
                $viewCapability,
                $businessId,
                $membershipId,
            ): ?array {
                $authorizedProfile =
                    $this->findAuthorizedPermissionProfile->execute(
                        $user,
                        $currentBusiness,
                        $viewCapability,
                        (string) $profile->getKey(),
                    );

                if ($authorizedProfile === null) {
                    return null;
                }

                $capabilities = DB::table(
                    'permission_profile_permissions as profile_permissions',
                )
                    ->join(
                        'permissions',
                        'permissions.id',
                        '=',
                        'profile_permissions.permission_id',
                    )
                    ->where(
                        'profile_permissions.business_id',
                        $businessId,
                    )
                    ->where(
                        'profile_permissions.permission_profile_id',
                        $profile->getKey(),
                    )
                    ->orderBy('permissions.key')
                    ->pluck('permissions.key')
                    ->map(
                        static fn (mixed $key): string => (string) $key,
                    )
                    ->values()
                    ->all();

                $assigned = DB::table('membership_permission_profiles')
                    ->where('business_id', $businessId)
                    ->where('membership_id', $membershipId)
                    ->where(
                        'permission_profile_id',
                        $profile->getKey(),
                    )
                    ->exists();

                return [
                    'id' => (string) $profile->getKey(),
                    'name' => (string) $profile->name,
                    'capabilities' => $capabilities,
                    'assigned' => $assigned,
                ];
            })
            ->filter(
                static fn (?array $profile): bool => $profile !== null,
            )
            ->values()
            ->all();

        $directGrants = DB::table('permission_grants as grants')
            ->join(
                'permissions',
                'permissions.id',
                '=',
                'grants.permission_id',
            )
            ->where('grants.business_id', $businessId)
            ->where('grants.membership_id', $membershipId)
            ->orderBy('permissions.key')
            ->get([
                'permissions.key as capability',
                'grants.effect',
            ])
            ->map(
                static fn (object $grant): array => [
                    'capability' => (string) $grant->capability,
                    'effect' => (string) $grant->effect,
                ],
            )
            ->values()
            ->all();

        $invitations = $canManageInvitations
            ? DB::table('business_access_invitations as invitations')
                ->join(
                    'permission_profiles as profiles',
                    function ($join): void {
                        $join
                            ->on(
                                'profiles.id',
                                '=',
                                'invitations.permission_profile_id',
                            )
                            ->on(
                                'profiles.business_id',
                                '=',
                                'invitations.business_id',
                            );
                    },
                )
                ->where('invitations.business_id', $businessId)
                ->orderByDesc('invitations.created_at')
                ->limit(100)
                ->get([
                    'invitations.id',
                    'invitations.invited_email',
                    'invitations.status',
                    'invitations.token_last4',
                    'invitations.expires_at',
                    'invitations.created_at',
                    'profiles.name as profile_name',
                ])
                ->map(
                    static fn (object $invitation): array => [
                        'id' => (string) $invitation->id,
                        'invitedEmail' => (string) $invitation->invited_email,
                        'profileName' => (string) $invitation->profile_name,
                        'status' => (string) $invitation->status,
                        'tokenLast4' => (string) $invitation->token_last4,
                        'expiresAt' => (string) $invitation->expires_at,
                        'createdAt' => (string) $invitation->created_at,
                    ],
                )
                ->values()
                ->all()
            : [];

        return [
            'business' => [
                'id' => $businessId,
                'name' => (string) $currentBusiness->name,
            ],
            'membership' => [
                'id' => $membershipId,
                'accessStatus' => $membership->access_status->value,
            ],
            'profiles' => $profiles,
            'directGrants' => $directGrants,
            'canManageInvitations' => $canManageInvitations,
            'invitations' => $invitations,
        ];
    }
}
