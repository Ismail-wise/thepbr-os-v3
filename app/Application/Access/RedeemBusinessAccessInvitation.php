<?php

declare(strict_types=1);

namespace App\Application\Access;

use App\Application\Identity\ChangeAccountPassword;
use App\Application\Identity\ChangeAccountStatus;
use App\Application\Identity\ProvisionAccount;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Identity\Enums\LanguageMode;
use App\Domain\Identity\ValueObjects\EmailAddress;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Infrastructure\Persistence\Eloquent\Access\BusinessAccessInvitation;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RedeemBusinessAccessInvitation
{
    public function __construct(
        private readonly ProvisionAccount $provisionAccount,
        private readonly ChangeAccountPassword $changePassword,
        private readonly ChangeAccountStatus $changeStatus,
        private readonly AccessOccurrence $occurrence,
    ) {}

    /**
     * @return array{user:User,business:Business,membership:Membership}
     */
    public function execute(
        string $token,
        string $email,
        ?User $authenticatedUser,
        string $displayName,
        string $password,
        LanguageMode $languageMode,
        string $timezone,
    ): array {
        $token = Str::upper(trim($token));

        if (
            strlen($token) !== 48
            || preg_match('/\\A[A-Z0-9]{48}\\z/', $token) !== 1
        ) {
            throw new InvalidArgumentException(
                'Invitation is invalid or unavailable.',
            );
        }

        $canonicalEmail = EmailAddress::from($email)->value();
        $fingerprint = hash('sha256', $token);

        return DB::transaction(function () use (
            $fingerprint,
            $canonicalEmail,
            $authenticatedUser,
            $displayName,
            $password,
            $languageMode,
            $timezone,
        ): array {
            $invitation = BusinessAccessInvitation::query()
                ->where('token_fingerprint', $fingerprint)
                ->lockForUpdate()
                ->first();

            if (
                $invitation === null
                || $invitation->status !== 'pending'
                || ! $invitation->expires_at->isFuture()
                || ! hash_equals(
                    (string) $invitation->invited_email,
                    $canonicalEmail,
                )
            ) {
                throw new InvalidArgumentException(
                    'Invitation is invalid or unavailable.',
                );
            }

            $business = Business::query()
                ->whereKey($invitation->business_id)
                ->lockForUpdate()
                ->first();

            if ($business === null) {
                throw new InvalidArgumentException(
                    'Invitation is invalid or unavailable.',
                );
            }

            $user = User::query()
                ->where('email', $canonicalEmail)
                ->lockForUpdate()
                ->first();

            $actorLabel = 'access-invitation:'.$invitation->token_last4;
            $reason = 'Authorized business access invitation redemption';

            if ($user === null) {
                $displayName = trim($displayName);

                if ($displayName === '') {
                    throw new InvalidArgumentException(
                        'Display name is required for a new account.',
                    );
                }

                $user = $this->provisionAccount->handle(
                    $canonicalEmail,
                    $displayName,
                    $password,
                    $languageMode,
                    $timezone,
                    $actorLabel,
                    $reason,
                    'web_invitation',
                );

                $user = $this->changeStatus->handle(
                    $canonicalEmail,
                    AccountStatus::Active,
                    $actorLabel,
                    $reason,
                    'web_invitation',
                );
            } elseif ($user->status === AccountStatus::Provisioned) {
                $this->changePassword->handle(
                    $canonicalEmail,
                    $password,
                    $actorLabel,
                    $reason,
                    'web_invitation',
                );

                $user = $this->changeStatus->handle(
                    $canonicalEmail,
                    AccountStatus::Active,
                    $actorLabel,
                    $reason,
                    'web_invitation',
                );
            } elseif ($user->status === AccountStatus::Active) {
                if (
                    $authenticatedUser === null
                    || (string) $authenticatedUser->getKey()
                        !== (string) $user->getKey()
                ) {
                    throw new InvalidArgumentException(
                        'Sign in with the invited account before redeeming this invitation.',
                    );
                }
            } else {
                throw new InvalidArgumentException(
                    'This account cannot be activated by an access invitation.',
                );
            }

            $membership = Membership::query()
                ->where('user_id', $user->getKey())
                ->where('business_id', $business->getKey())
                ->lockForUpdate()
                ->first();

            if ($membership === null) {
                $membership = Membership::query()->create([
                    'user_id' => $user->getKey(),
                    'business_id' => $business->getKey(),
                    'access_status' => MembershipAccessStatus::Active,
                ]);
            }

            DB::table('membership_permission_profiles')->insertOrIgnore([
                'business_id' => $business->getKey(),
                'membership_id' => $membership->getKey(),
                'permission_profile_id' => $invitation->permission_profile_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $partnerLink = DB::table('partner_access_invitation_links')
                ->where(
                    'business_access_invitation_id',
                    $invitation->getKey(),
                )
                ->where('business_id', $business->getKey())
                ->first();

            $partnerLinked = false;

            if ($partnerLink !== null) {
                $existingPartnerLink = DB::table('partner_membership_links')
                    ->where('business_id', $business->getKey())
                    ->where('partner_id', $partnerLink->partner_id)
                    ->lockForUpdate()
                    ->first();

                $existingMembershipLink = DB::table('partner_membership_links')
                    ->where('business_id', $business->getKey())
                    ->where('membership_id', $membership->getKey())
                    ->lockForUpdate()
                    ->first();

                if (
                    $existingPartnerLink !== null
                    && (string) $existingPartnerLink->membership_id
                        !== (string) $membership->getKey()
                ) {
                    throw new InvalidArgumentException(
                        'This Partner is already linked to another Membership.',
                    );
                }

                if (
                    $existingMembershipLink !== null
                    && (string) $existingMembershipLink->partner_id
                        !== (string) $partnerLink->partner_id
                ) {
                    throw new InvalidArgumentException(
                        'This Membership is already linked to another Partner.',
                    );
                }

                if (
                    $existingPartnerLink === null
                    && $existingMembershipLink === null
                ) {
                    DB::table('partner_membership_links')->insert([
                        'id' => (string) Str::uuid7(),
                        'business_id' => $business->getKey(),
                        'partner_id' => $partnerLink->partner_id,
                        'membership_id' => $membership->getKey(),
                        'linked_by_membership_id' => $partnerLink->linked_by_membership_id,
                        'linked_at' => now(),
                    ]);
                }

                $partnerLinked = true;
            }

            $redeemedAt = now();

            $invitation->status = 'redeemed';
            $invitation->redeemed_by_user_id = $user->getKey();
            $invitation->redeemed_membership_id = $membership->getKey();
            $invitation->redeemed_at = $redeemedAt;
            $invitation->save();

            $this->occurrence->record(
                $user,
                $business,
                'access.invitation.redeemed',
                'business_access_invitation',
                (string) $invitation->getKey(),
                [
                    'membership_id' => (string) $membership->getKey(),
                    'permission_profile_id' => (string) $invitation->permission_profile_id,
                    'partner_linked' => $partnerLinked,
                ],
            );

            return [
                'user' => $user->refresh(),
                'business' => $business,
                'membership' => $membership->refresh(),
            ];
        });
    }
}
