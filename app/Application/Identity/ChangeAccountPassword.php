<?php

namespace App\Application\Identity;

use App\Domain\Identity\Enums\SecurityEventType;
use App\Domain\Identity\Policies\PasswordPolicy;
use App\Domain\Identity\ValueObjects\EmailAddress;
use App\Infrastructure\Persistence\Eloquent\Identity\SecurityEvent;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class ChangeAccountPassword
{
    public function __construct(
        private readonly Hasher $hasher,
    ) {}

    public function handle(
        string $email,
        string $password,
        string $actorLabel,
        string $reason,
        string $source = 'cli',
    ): User {
        $canonicalEmail = EmailAddress::from($email)->value();
        $actorLabel = $this->requiredEvidence($actorLabel, 'Actor label');
        $reason = $this->requiredEvidence($reason, 'Reason');
        $source = $this->requiredEvidence($source, 'Source');

        PasswordPolicy::assert($password);

        return DB::transaction(function () use (
            $canonicalEmail,
            $password,
            $actorLabel,
            $reason,
            $source,
        ): User {
            $user = User::query()
                ->where('email', $canonicalEmail)
                ->lockForUpdate()
                ->first();

            if ($user === null) {
                throw new InvalidArgumentException('Account not found.');
            }

            $user->password = $this->hashPassword($password);
            $user->password_changed_at = now();
            $user->save();

            SecurityEvent::query()->create([
                'event_type' => SecurityEventType::AccountPasswordChanged,
                'subject_user_id' => $user->id,
                'actor_label' => $actorLabel,
                'source' => $source,
                'reason' => $reason,
                'metadata' => [],
                'occurred_at' => now(),
            ]);

            return $user->refresh();
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
