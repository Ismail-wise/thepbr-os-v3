<?php

namespace App\Application\Identity;

use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Identity\Enums\SecurityEventType;
use App\Domain\Identity\ValueObjects\EmailAddress;
use App\Infrastructure\Persistence\Eloquent\Identity\SecurityEvent;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ChangeAccountStatus
{
    public function handle(
        string $email,
        AccountStatus $targetStatus,
        string $actorLabel,
        string $reason,
        string $source = 'cli',
    ): User {
        $canonicalEmail = EmailAddress::from($email)->value();
        $actorLabel = $this->requiredEvidence($actorLabel, 'Actor label');
        $reason = $this->requiredEvidence($reason, 'Reason');
        $source = $this->requiredEvidence($source, 'Source');

        return DB::transaction(function () use (
            $canonicalEmail,
            $targetStatus,
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

            $currentStatus = $user->status;

            if (! $currentStatus->canTransitionTo($targetStatus)) {
                throw new InvalidArgumentException(sprintf(
                    'Account status transition from %s to %s is not allowed.',
                    $currentStatus->value,
                    $targetStatus->value,
                ));
            }

            $user->status = $targetStatus;
            $user->save();

            SecurityEvent::query()->create([
                'event_type' => SecurityEventType::AccountStatusChanged,
                'subject_user_id' => $user->id,
                'actor_label' => $actorLabel,
                'source' => $source,
                'reason' => $reason,
                'metadata' => [
                    'from_status' => $currentStatus->value,
                    'to_status' => $targetStatus->value,
                ],
                'occurred_at' => now(),
            ]);

            return $user->refresh();
        });
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
