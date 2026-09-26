<?php

declare(strict_types=1);

namespace App\Application\Partnership;

use App\Application\Access\CreateBusinessAccessInvitation;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\Enums\StandardAccessProfile;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionProfile;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PartnerDirectory
{
    public function __construct(
        private readonly PartnershipActorContext $actor,
        private readonly PartnershipOccurrence $occurrence,
        private readonly CreateBusinessAccessInvitation $accessInvitations,
    ) {}

    /**
     * @return array{id:string, revision:int}|null
     */
    public function create(
        User $user,
        Business $business,
        string $displayName,
        ?string $legalName,
        ?string $email,
        ?string $notes,
    ): ?array {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::PARTNERS_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        $displayName = trim($displayName);

        if ($displayName === '' || mb_strlen($displayName) > 160) {
            throw new InvalidArgumentException(
                'Partner display name is required and must not exceed 160 characters.',
            );
        }

        $email = $email === null
            ? null
            : mb_strtolower(trim($email));

        if (
            $email !== null
            && filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new InvalidArgumentException(
                'Partner email must be valid.',
            );
        }

        $id = (string) Str::uuid7();

        DB::table('partners')->insert([
            'id' => $id,
            'business_id' => $business->getKey(),
            'display_name' => $displayName,
            'legal_name' => $this->nullableTrim($legalName),
            'email' => $email,
            'status' => 'prospective',
            'notes' => $this->nullableTrim($notes),
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->occurrence->record(
            $user,
            $business,
            'partnership.partner.created',
            'partner',
            $id,
            ['status' => 'prospective'],
        );

        return [
            'id' => $id,
            'revision' => 1,
        ];
    }

    /**
     * Create a Business access invitation using the existing F2/F3 invitation
     * foundation and link it to the domain Partner.
     *
     * The raw token is returned once and is never persisted.
     *
     * @return array{invitation_id:string, token:string}|null
     */
    public function invite(
        User $user,
        Business $business,
        string $partnerId,
        string $email,
        int $expiresInHours = 72,
    ): ?array {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::PARTNERS_MANAGE,
        );

        if (
            $membership === null
            || ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::ACCESS_ADMIN_MANAGE,
            )
        ) {
            return null;
        }

        if ($expiresInHours < 1 || $expiresInHours > 168) {
            throw new InvalidArgumentException(
                'Invitation expiry must be between 1 and 168 hours.',
            );
        }

        $partner = DB::table('partners')
            ->where('business_id', $business->getKey())
            ->where('id', $partnerId)
            ->first();

        if ($partner === null) {
            return null;
        }

        $email = mb_strtolower(trim($email));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException(
                'Invitation email must be valid.',
            );
        }

        $partnerProfile = PermissionProfile::query()
            ->where('business_id', $business->getKey())
            ->where(
                'name',
                StandardAccessProfile::Partner->value,
            )
            ->first();

        if ($partnerProfile === null) {
            throw new InvalidArgumentException(
                'Partner access profile is not provisioned for this Business.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $partnerId,
            $email,
            $expiresInHours,
            $membership,
            $partnerProfile,
        ): ?array {
            $invitation = $this->accessInvitations->execute(
                $user,
                $business,
                $email,
                (string) $partnerProfile->getKey(),
                $expiresInHours,
            );

            if ($invitation === null) {
                return null;
            }

            DB::table('partner_access_invitation_links')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'partner_id' => $partnerId,
                'business_access_invitation_id' => $invitation['id'],
                'linked_by_membership_id' => $membership->getKey(),
                'created_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'partnership.partner.invited',
                'partner',
                $partnerId,
                [
                    'invitation_id' => $invitation['id'],
                ],
            );

            return [
                'invitation_id' => $invitation['id'],
                'token' => $invitation['token'],
            ];
        });
    }

    public function linkMembership(
        User $user,
        Business $business,
        string $partnerId,
        string $membershipId,
    ): ?string {
        $actorMembership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::PARTNERS_MANAGE,
        );

        if ($actorMembership === null) {
            return null;
        }

        $partnerExists = DB::table('partners')
            ->where('business_id', $business->getKey())
            ->where('id', $partnerId)
            ->exists();

        $membershipExists = DB::table('memberships')
            ->where('business_id', $business->getKey())
            ->where('id', $membershipId)
            ->where('access_status', 'active')
            ->exists();

        if (! $partnerExists || ! $membershipExists) {
            return null;
        }

        $id = (string) Str::uuid7();

        DB::table('partner_membership_links')->insert([
            'id' => $id,
            'business_id' => $business->getKey(),
            'partner_id' => $partnerId,
            'membership_id' => $membershipId,
            'linked_by_membership_id' => $actorMembership->getKey(),
            'linked_at' => now(),
        ]);

        $this->occurrence->record(
            $user,
            $business,
            'partnership.partner.membership_linked',
            'partner',
            $partnerId,
            ['membership_id' => $membershipId],
        );

        return $id;
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
