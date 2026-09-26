<?php

declare(strict_types=1);

namespace App\Application\Access;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Identity\ValueObjects\EmailAddress;
use App\Infrastructure\Persistence\Eloquent\Access\BusinessAccessInvitation;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CreateBusinessAccessInvitation
{
    public function __construct(
        private readonly ResolveMembershipCapabilities $memberships,
        private readonly FindAuthorizedPermissionProfile $profiles,
        private readonly AccessOccurrence $occurrence,
    ) {}

    /**
     * @return array{id:string,token:string,last4:string,expires_at:string}|null
     */
    public function execute(
        User $user,
        Business $business,
        string $email,
        string $permissionProfileId,
        int $expiresInHours = 72,
    ): ?array {
        $membership = $this->memberships->activeMembership(
            $user,
            $business,
        );

        if ($membership === null) {
            return null;
        }

        if ($expiresInHours < 1 || $expiresInHours > 168) {
            throw new InvalidArgumentException(
                'Invitation expiry must be between 1 and 168 hours.',
            );
        }

        $canonicalEmail = EmailAddress::from($email)->value();

        $profile = $this->profiles->execute(
            $user,
            $business,
            new Capability(CapabilityCatalog::ACCESS_ADMIN_MANAGE),
            $permissionProfileId,
        );

        if ($profile === null) {
            return null;
        }

        $token = Str::upper(Str::random(48));
        $expiresAt = now()->addHours($expiresInHours);

        return DB::transaction(function () use (
            $user,
            $business,
            $membership,
            $canonicalEmail,
            $profile,
            $expiresInHours,
            $expiresAt,
            $token,
        ): array {
            $invitation = BusinessAccessInvitation::query()->create([
                'business_id' => $business->getKey(),
                'invited_email' => $canonicalEmail,
                'token_fingerprint' => hash('sha256', $token),
                'token_last4' => substr($token, -4),
                'permission_profile_id' => $profile->getKey(),
                'invited_by_membership_id' => $membership->getKey(),
                'status' => 'pending',
                'expires_at' => $expiresAt,
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'access.invitation.created',
                'business_access_invitation',
                (string) $invitation->getKey(),
                [
                    'permission_profile_id' => (string) $profile->getKey(),
                    'expires_in_hours' => $expiresInHours,
                ],
            );

            return [
                'id' => (string) $invitation->getKey(),
                'token' => $token,
                'last4' => (string) $invitation->token_last4,
                'expires_at' => $expiresAt->toIso8601String(),
            ];
        });
    }
}
