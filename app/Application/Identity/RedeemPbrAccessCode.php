<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Identity\Enums\LanguageMode;
use App\Domain\Identity\Enums\PbrAccessCodeStatus;
use App\Domain\Identity\ValueObjects\EmailAddress;
use App\Infrastructure\Persistence\Eloquent\Identity\AccountEntitlement;
use App\Infrastructure\Persistence\Eloquent\Identity\PbrAccessCode;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RedeemPbrAccessCode
{
    public function __construct(
        private readonly ProvisionAccount $provisionAccount,
        private readonly ChangeAccountPassword $changePassword,
        private readonly ChangeAccountStatus $changeStatus,
    ) {}

    public function handle(
        string $token,
        string $email,
        ?User $authenticatedUser,
        string $displayName,
        string $password,
        LanguageMode $languageMode,
        string $timezone,
    ): User {
        $token = Str::upper(trim($token));

        if (
            strlen($token) !== 48
            || preg_match('/\A[A-Z0-9]{48}\z/', $token) !== 1
        ) {
            throw new InvalidArgumentException(
                'Access code is invalid or unavailable.',
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
        ): User {
            $code = PbrAccessCode::query()
                ->where('token_fingerprint', $fingerprint)
                ->lockForUpdate()
                ->first();

            if (
                $code === null
                || $code->status !== PbrAccessCodeStatus::Pending
                || (
                    $code->expires_at !== null
                    && ! $code->expires_at->isFuture()
                )
                || (
                    $code->bound_email !== null
                    && ! hash_equals(
                        (string) $code->bound_email,
                        $canonicalEmail,
                    )
                )
            ) {
                throw new InvalidArgumentException(
                    'Access code is invalid or unavailable.',
                );
            }

            $user = User::query()
                ->where('email', $canonicalEmail)
                ->lockForUpdate()
                ->first();

            if (
                $user !== null
                && AccountEntitlement::query()
                    ->where('user_id', $user->getKey())
                    ->where(
                        'entitlement_key',
                        HasBusinessCreationEntitlement::ENTITLEMENT,
                    )
                    ->exists()
            ) {
                throw new InvalidArgumentException(
                    'This account already has PBR business creation access.',
                );
            }

            $actorLabel = 'pbr-access-code:'.$code->token_last4;
            $reason = 'Authorized PBR access code redemption';

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
                    'web_access_code',
                );

                $user = $this->changeStatus->handle(
                    $canonicalEmail,
                    AccountStatus::Active,
                    $actorLabel,
                    $reason,
                    'web_access_code',
                );
            } elseif ($user->status === AccountStatus::Provisioned) {
                $this->changePassword->handle(
                    $canonicalEmail,
                    $password,
                    $actorLabel,
                    $reason,
                    'web_access_code',
                );

                $user = $this->changeStatus->handle(
                    $canonicalEmail,
                    AccountStatus::Active,
                    $actorLabel,
                    $reason,
                    'web_access_code',
                );
            } elseif ($user->status === AccountStatus::Active) {
                if (
                    $authenticatedUser === null
                    || (string) $authenticatedUser->getKey()
                        !== (string) $user->getKey()
                ) {
                    throw new InvalidArgumentException(
                        'Sign in with this account before redeeming the access code.',
                    );
                }
            } else {
                throw new InvalidArgumentException(
                    'This account cannot be activated by an access code.',
                );
            }

            AccountEntitlement::query()->create([
                'user_id' => $user->getKey(),
                'entitlement_key' => HasBusinessCreationEntitlement::ENTITLEMENT,
                'pbr_access_code_id' => $code->getKey(),
                'granted_at' => now(),
            ]);

            $code->status = PbrAccessCodeStatus::Redeemed;
            $code->redeemed_by_user_id = $user->getKey();
            $code->redeemed_at = now();
            $code->save();

            return $user->refresh()->load('profile');
        });
    }
}
