<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\Enums\PbrAccessCodeStatus;
use App\Infrastructure\Persistence\Eloquent\Identity\PbrAccessCode;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RevokePbrAccessCode
{
    public function handle(
        string $codeId,
        string $actorLabel,
        string $reason,
    ): bool {
        $actorLabel = $this->requiredEvidence(
            $actorLabel,
            'Administrative actor label',
            160,
        );
        $reason = $this->requiredEvidence($reason, 'Reason');

        return DB::transaction(function () use (
            $codeId,
            $actorLabel,
            $reason,
        ): bool {
            $code = PbrAccessCode::query()
                ->whereKey($codeId)
                ->lockForUpdate()
                ->first();

            if (
                $code === null
                || $code->status !== PbrAccessCodeStatus::Pending
            ) {
                return false;
            }

            $code->status = PbrAccessCodeStatus::Revoked;
            $code->revoked_at = now();
            $code->revoked_by_label = $actorLabel;
            $code->revocation_reason = $reason;
            $code->save();

            return true;
        });
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
}
