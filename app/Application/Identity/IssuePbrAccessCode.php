<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\Enums\PbrAccessCodeStatus;
use App\Domain\Identity\ValueObjects\EmailAddress;
use App\Infrastructure\Persistence\Eloquent\Identity\PbrAccessCode;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class IssuePbrAccessCode
{
    /**
     * @return array{
     *   id:string,
     *   token:string,
     *   last4:string,
     *   bound_email:?string,
     *   expires_at:?string
     * }
     */
    public function handle(
        ?string $boundEmail,
        ?int $expiresInHours,
        ?string $clientReference,
        ?string $batchReference,
        ?string $notes,
        string $actorLabel,
        string $reason,
    ): array {
        if ($expiresInHours !== null && $expiresInHours < 1) {
            throw new InvalidArgumentException(
                'Access code expiry must be at least one hour.',
            );
        }

        $actorLabel = $this->requiredEvidence(
            $actorLabel,
            'Administrative actor label',
            160,
        );
        $reason = $this->requiredEvidence(
            $reason,
            'Reason',
        );

        $canonicalEmail = $this->canonicalEmail($boundEmail);
        $clientReference = $this->optionalText(
            $clientReference,
            160,
            'Client reference',
        );
        $batchReference = $this->optionalText(
            $batchReference,
            160,
            'Batch reference',
        );
        $notes = $this->optionalText($notes, null, 'Notes');

        $token = Str::upper(Str::random(48));
        $expiresAt = $expiresInHours === null
            ? null
            : now()->addHours($expiresInHours);

        $code = PbrAccessCode::query()->create([
            'token_fingerprint' => hash('sha256', $token),
            'token_last4' => substr($token, -4),
            'status' => PbrAccessCodeStatus::Pending,
            'bound_email' => $canonicalEmail,
            'client_reference' => $clientReference,
            'batch_reference' => $batchReference,
            'notes' => $notes,
            'expires_at' => $expiresAt,
            'created_by_label' => $actorLabel,
            'creation_reason' => $reason,
        ]);

        return [
            'id' => (string) $code->getKey(),
            'token' => $token,
            'last4' => (string) $code->token_last4,
            'bound_email' => $canonicalEmail,
            'expires_at' => $expiresAt?->toIso8601String(),
        ];
    }

    private function canonicalEmail(?string $email): ?string
    {
        $email = trim((string) $email);

        if ($email === '') {
            return null;
        }

        return EmailAddress::from($email)->value();
    }

    private function requiredEvidence(
        string $value,
        string $label,
        ?int $maxLength = null,
    ): string {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException($label.' is required.');
        }

        if ($maxLength !== null && mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException(
                $label.' must not exceed '.$maxLength.' characters.',
            );
        }

        return $value;
    }

    private function optionalText(
        ?string $value,
        ?int $maxLength,
        string $label,
    ): ?string {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if ($maxLength !== null && mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException(
                $label.' must not exceed '.$maxLength.' characters.',
            );
        }

        return $value;
    }
}
