<?php

namespace App\Application\Identity;

use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Identity\Enums\LanguageMode;
use App\Domain\Identity\Enums\SecurityEventType;
use App\Domain\Identity\Policies\PasswordPolicy;
use App\Domain\Identity\ValueObjects\EmailAddress;
use App\Domain\Identity\ValueObjects\TimezoneIdentifier;
use App\Infrastructure\Persistence\Eloquent\Identity\SecurityEvent;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Identity\UserProfile;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class ProvisionAccount
{
    public function __construct(
        private readonly Hasher $hasher,
    ) {}

    public function handle(
        string $email,
        string $displayName,
        string $password,
        LanguageMode $languageMode,
        string $timezone,
        string $actorLabel,
        string $reason,
        string $source = 'cli',
    ): User {
        $canonicalEmail = EmailAddress::from($email)->value();
        $canonicalTimezone = TimezoneIdentifier::from($timezone)->value();
        $actorLabel = $this->requiredEvidence($actorLabel, 'Actor label');
        $reason = $this->requiredEvidence($reason, 'Reason');
        $source = $this->requiredEvidence($source, 'Source');

        PasswordPolicy::assert($password);

        $passwordHash = $this->hashPassword($password);

        return DB::transaction(function () use (
            $canonicalEmail,
            $displayName,
            $passwordHash,
            $languageMode,
            $canonicalTimezone,
            $actorLabel,
            $reason,
            $source,
        ): User {
            if (User::query()->where('email', $canonicalEmail)->exists()) {
                throw new InvalidArgumentException('An account with this email already exists.');
            }

            $user = User::query()->create([
                'email' => $canonicalEmail,
                'password' => $passwordHash,
                'status' => AccountStatus::Provisioned,
                'password_changed_at' => now(),
            ]);

            UserProfile::query()->create([
                'user_id' => $user->id,
                'display_name' => $displayName,
                'language_mode' => $languageMode,
                'timezone' => $canonicalTimezone,
            ]);

            SecurityEvent::query()->create([
                'event_type' => SecurityEventType::AccountProvisioned,
                'subject_user_id' => $user->id,
                'actor_label' => $actorLabel,
                'source' => $source,
                'reason' => $reason,
                'metadata' => ['initial_status' => AccountStatus::Provisioned->value],
                'occurred_at' => now(),
            ]);

            return $user->refresh()->load('profile');
        });
    }

    private function hashPassword(string $password): string
    {
        $hash = $this->hasher->make($password);

        if (($this->hasher->info($hash)['algoName'] ?? null) !== 'argon2id') {
            throw new RuntimeException('Configured password hashing algorithm is not Argon2id.');
        }

        return $hash;
    }

    private function requiredEvidence(string $value, string $label): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException($label.' is required.');
        }

        return $value;
    }
}
