<?php

declare(strict_types=1);

namespace App\Application\Access;

use App\Application\Exit\RecordExitOccurrence;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\Enums\StandardAccessProfile;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class TransitionMembershipAccess
{
    public function __construct(
        private readonly ResolveMembershipCapabilities $capabilities,
        private readonly AuthorizeBusinessCapability $authorize,
        private readonly RecordExitOccurrence $occurrence,
    ) {}

    public function execute(
        User $user,
        Business $business,
        string $membershipId,
        MembershipAccessStatus $expected,
        MembershipAccessStatus $target,
        string $reasonCode,
        ?string $sourceType = null,
        ?string $sourceId = null,
    ): bool {
        $actor = $this->capabilities->activeMembership($user, $business);

        if ($actor === null) {
            return false;
        }

        foreach ([
            CapabilityCatalog::EXIT_MANAGE,
            CapabilityCatalog::ACCESS_ADMIN_MANAGE,
        ] as $requiredCapability) {
            if (! $this->authorize->decide(
                $user,
                $business,
                $business,
                new Capability($requiredCapability),
            )->allowed) {
                return false;
            }
        }

        $this->assertTransitionAllowed($expected, $target);

        $reason = trim($reasonCode);

        if ($reason === '' || mb_strlen($reason) > 80) {
            throw new InvalidArgumentException(
                'Membership access transition reason is invalid.',
            );
        }

        if ($sourceId !== null && ! Str::isUuid($sourceId)) {
            throw new InvalidArgumentException(
                'Membership access transition source ID is invalid.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $membershipId,
            $expected,
            $target,
            $reason,
            $sourceType,
            $sourceId,
            $actor,
        ): bool {
            $membership = Membership::query()
                ->where('business_id', $business->getKey())
                ->whereKey($membershipId)
                ->lockForUpdate()
                ->first();

            if ($membership === null) {
                return false;
            }

            if ($membership->access_status !== $expected) {
                throw new InvalidArgumentException(
                    'Membership access changed before this transition was applied.',
                );
            }

            if (
                $expected === MembershipAccessStatus::Active
                && $target !== MembershipAccessStatus::Active
            ) {
                $this->assertWorkspaceOwnerRecoveryPath(
                    $business,
                    $membership,
                );
            }

            $updated = Membership::query()
                ->where('business_id', $business->getKey())
                ->whereKey($membershipId)
                ->where('access_status', $expected->value)
                ->update([
                    'access_status' => $target->value,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                return false;
            }

            DB::table('membership_access_transitions')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'membership_id' => $membershipId,
                'from_status' => $expected->value,
                'to_status' => $target->value,
                'reason_code' => $reason,
                'source_type' => $this->nullableText($sourceType),
                'source_id' => $sourceId,
                'actor_membership_id' => $actor->getKey(),
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'membership.access.transitioned',
                'membership',
                $membershipId,
                [
                    'from_status' => $expected->value,
                    'to_status' => $target->value,
                    'reason_code' => $reason,
                    'source_type' => $this->nullableText($sourceType),
                    'source_id' => $sourceId,
                ],
            );

            return true;
        });
    }

    private function assertWorkspaceOwnerRecoveryPath(
        Business $business,
        Membership $membership,
    ): void {
        $workspaceOwnerProfileId = DB::table('permission_profiles')
            ->where('business_id', $business->getKey())
            ->where('name', StandardAccessProfile::WorkspaceOwner->value)
            ->value('id');

        if ($workspaceOwnerProfileId === null) {
            return;
        }

        $isWorkspaceOwner = DB::table('membership_permission_profiles')
            ->where('business_id', $business->getKey())
            ->where('membership_id', $membership->getKey())
            ->where('permission_profile_id', $workspaceOwnerProfileId)
            ->exists();

        if (! $isWorkspaceOwner) {
            return;
        }

        $otherActiveWorkspaceOwners = DB::table(
            'membership_permission_profiles as profile_memberships',
        )
            ->join(
                'memberships as memberships',
                function ($join): void {
                    $join
                        ->on(
                            'memberships.id',
                            '=',
                            'profile_memberships.membership_id',
                        )
                        ->on(
                            'memberships.business_id',
                            '=',
                            'profile_memberships.business_id',
                        );
                },
            )
            ->where(
                'profile_memberships.business_id',
                $business->getKey(),
            )
            ->where(
                'profile_memberships.permission_profile_id',
                $workspaceOwnerProfileId,
            )
            ->where('memberships.access_status', MembershipAccessStatus::Active->value)
            ->where('memberships.id', '<>', $membership->getKey())
            ->exists();

        if (! $otherActiveWorkspaceOwners) {
            throw new InvalidArgumentException(
                'The last active Workspace Owner cannot be suspended or revoked without a recovery owner.',
            );
        }
    }

    private function assertTransitionAllowed(
        MembershipAccessStatus $from,
        MembershipAccessStatus $to,
    ): void {
        if ($from === $to) {
            throw new InvalidArgumentException(
                'Membership access status must actually change.',
            );
        }

        $allowed = match ($from) {
            MembershipAccessStatus::Active => [
                MembershipAccessStatus::Suspended,
                MembershipAccessStatus::Revoked,
            ],
            MembershipAccessStatus::Suspended => [
                MembershipAccessStatus::Active,
                MembershipAccessStatus::Revoked,
            ],
            MembershipAccessStatus::Revoked => [],
        };

        if (! in_array($to, $allowed, true)) {
            throw new InvalidArgumentException(
                "Membership access transition {$from->value} -> {$to->value} is not allowed.",
            );
        }
    }

    private function nullableText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
