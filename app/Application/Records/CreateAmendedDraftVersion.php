<?php

declare(strict_types=1);

namespace App\Application\Records;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Application\Events\RecordBusinessOccurrence;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Audit\ValueObjects\AuditActor;
use App\Domain\Audit\ValueObjects\SafeAuditMetadata;
use App\Domain\Events\ValueObjects\OccurrenceTarget;
use App\Domain\Records\Enums\FormalRecordState;
use App\Domain\Records\Exceptions\InvalidWorkflowTransition;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionStateTransition;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateAmendedDraftVersion
{
    public function __construct(
        private readonly AuthorizeBusinessCapability $authorizeBusinessCapability,
        private readonly RecordBusinessOccurrence $recordBusinessOccurrence,
    ) {}

    public function execute(
        User $user,
        Business $currentBusiness,
        Capability $capability,
        string $sourceVersionId,
        string $contentHash,
        string $changeSummary,
        ?DateTimeInterface $effectiveFrom = null,
        ?DateTimeInterface $effectiveUntil = null,
        ?DateTimeInterface $reviewDueAt = null,
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
            $sourceVersionId,
            $contentHash,
            $changeSummary,
            $effectiveFrom,
            $effectiveUntil,
            $reviewDueAt,
        ): ?FormalRecordVersion {
            $source = FormalRecordVersion::query()
                ->where('business_id', $currentBusiness->getKey())
                ->whereKey($sourceVersionId)
                ->lockForUpdate()
                ->first();
            if ($source === null) {
                return null;
            }
            $sourceDecision = $this->authorizeBusinessCapability->decide(
                $user,
                $currentBusiness,
                $currentBusiness,
                $capability,
                FormalRecordVersion::class,
                (string) $source->getKey(),
            );
            if (! $sourceDecision->allowed) {
                return null;
            }
            if ($source->frozen_at === null) {
                throw new InvalidWorkflowTransition(
                    'An amendment predecessor must be a frozen version.',
                );
            }
            $latestState = $this->latestState($source);
            if (! in_array(
                $latestState,
                [
                    FormalRecordState::Effective,
                    FormalRecordState::ChangesRequested,
                ],
                true,
            )) {
                throw new InvalidWorkflowTransition(
                    'Amendment substrate accepts Effective or Changes Requested predecessors.',
                );
            }
            $family = FormalRecordFamily::query()
                ->where('business_id', $currentBusiness->getKey())
                ->whereKey($source->formal_record_family_id)
                ->lockForUpdate()
                ->first();
            if ($family === null) {
                return null;
            }
            $latestNumber = (int) FormalRecordVersion::query()
                ->where('business_id', $currentBusiness->getKey())
                ->where('formal_record_family_id', $family->getKey())
                ->max('version_number');
            $version = FormalRecordVersion::query()->create([
                'business_id' => $currentBusiness->getKey(),
                'formal_record_family_id' => $family->getKey(),
                'version_number' => $latestNumber + 1,
                'predecessor_version_id' => $source->getKey(),
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
            $this->recordBusinessOccurrence->audit(
                $currentBusiness,
                AuditActor::user((string) $user->getKey()),
                'records.formal_record_version.amended',
                new OccurrenceTarget(
                    (string) $currentBusiness->getKey(),
                    'formal_record_family',
                    (string) $family->getKey(),
                    (string) $version->getKey(),
                ),
                SafeAuditMetadata::from([
                    'version_number' => (int) $version->version_number,
                    'source_version_number' => (int) $source->version_number,
                ]),
                now(),
            );

            return $version->fresh();
        });
    }

    private function latestState(
        FormalRecordVersion $version,
    ): ?FormalRecordState {
        $latest = RecordVersionStateTransition::query()
            ->where(
                'formal_record_version_id',
                $version->getKey(),
            )
            ->orderByDesc('sequence')
            ->first(['to_state']);

        return $latest?->to_state;
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
