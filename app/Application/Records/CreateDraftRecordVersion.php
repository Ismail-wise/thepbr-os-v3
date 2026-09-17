<?php

declare(strict_types=1);

namespace App\Application\Records;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Records\Enums\FormalRecordState;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionStateTransition;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateDraftRecordVersion
{
    public function __construct(
        private readonly AuthorizeBusinessCapability $authorizeBusinessCapability,
    ) {}

    public function execute(
        User $user,
        Business $currentBusiness,
        Capability $capability,
        string $formalRecordFamilyId,
        string $contentHash,
        string $changeSummary,
        ?DateTimeInterface $effectiveFrom = null,
        ?DateTimeInterface $effectiveUntil = null,
        ?DateTimeInterface $reviewDueAt = null,
        ?string $predecessorVersionId = null,
    ): ?FormalRecordVersion {
        $baseDecision = $this->authorizeBusinessCapability->decide(
            $user,
            $currentBusiness,
            $currentBusiness,
            $capability,
        );

        if (! $baseDecision->allowed) {
            return null;
        }

        $this->assertVersionMetadata(
            $contentHash,
            $changeSummary,
            $effectiveFrom,
            $effectiveUntil,
        );

        return DB::transaction(function () use (
            $user,
            $currentBusiness,
            $capability,
            $formalRecordFamilyId,
            $contentHash,
            $changeSummary,
            $effectiveFrom,
            $effectiveUntil,
            $reviewDueAt,
            $predecessorVersionId,
        ): ?FormalRecordVersion {
            $family = FormalRecordFamily::query()
                ->where('business_id', $currentBusiness->getKey())
                ->whereKey($formalRecordFamilyId)
                ->lockForUpdate()
                ->first();

            if ($family === null) {
                return null;
            }

            $resourceDecision = $this->authorizeBusinessCapability->decide(
                $user,
                $currentBusiness,
                $currentBusiness,
                $capability,
                FormalRecordFamily::class,
                (string) $family->getKey(),
            );

            if (! $resourceDecision->allowed) {
                return null;
            }

            $latestNumber = (int) FormalRecordVersion::query()
                ->where('business_id', $currentBusiness->getKey())
                ->where('formal_record_family_id', $family->getKey())
                ->max('version_number');

            if ($latestNumber > 0 && $predecessorVersionId === null) {
                throw new InvalidArgumentException(
                    'A predecessor version is required after version 1.',
                );
            }

            if ($predecessorVersionId !== null) {
                $predecessor = FormalRecordVersion::query()
                    ->where('business_id', $currentBusiness->getKey())
                    ->where('formal_record_family_id', $family->getKey())
                    ->whereKey($predecessorVersionId)
                    ->first();

                if ($predecessor === null) {
                    return null;
                }
            }

            $version = FormalRecordVersion::query()->create([
                'business_id' => $currentBusiness->getKey(),
                'formal_record_family_id' => $family->getKey(),
                'version_number' => $latestNumber + 1,
                'predecessor_version_id' => $predecessorVersionId,
                'revision' => 1,
                'change_summary' => trim($changeSummary),
                'created_by_user_id' => $user->getKey(),
                'last_changed_by_user_id' => $user->getKey(),
                'effective_from' => $effectiveFrom,
                'effective_until' => $effectiveUntil,
                'review_due_at' => $reviewDueAt,
                'content_hash' => strtolower($contentHash),
                'frozen_at' => null,
            ]);

            RecordVersionStateTransition::query()->create([
                'business_id' => $currentBusiness->getKey(),
                'formal_record_version_id' => $version->getKey(),
                'sequence' => 1,
                'from_state' => null,
                'to_state' => FormalRecordState::Draft->value,
                'transitioned_by_user_id' => $user->getKey(),
                'occurred_at' => now(),
            ]);

            return $version->fresh();
        });
    }

    private function assertVersionMetadata(
        string $contentHash,
        string $changeSummary,
        ?DateTimeInterface $effectiveFrom,
        ?DateTimeInterface $effectiveUntil,
    ): void {
        if (preg_match('/\A[a-f0-9]{64}\z/i', $contentHash) !== 1) {
            throw new InvalidArgumentException(
                'Content hash must be an exact SHA-256 hexadecimal identity.',
            );
        }

        if (trim($changeSummary) === '') {
            throw new InvalidArgumentException('Change summary must not be empty.');
        }

        if ($effectiveUntil !== null && $effectiveFrom === null) {
            throw new InvalidArgumentException(
                'A planned effective-until requires effective-from.',
            );
        }

        if (
            $effectiveFrom !== null
            && $effectiveUntil !== null
            && $effectiveUntil <= $effectiveFrom
        ) {
            throw new InvalidArgumentException(
                'Effective-until must be later than effective-from.',
            );
        }
    }
}
