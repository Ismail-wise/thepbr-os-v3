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
use App\Domain\Records\Exceptions\StaleRevision;
use App\Domain\Records\ValueObjects\Revision;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionStateTransition;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class UpdateDraftRecordVersion
{
    public function __construct(
        private readonly AuthorizeBusinessCapability $authorizeBusinessCapability,
        private readonly RecordBusinessOccurrence $recordBusinessOccurrence,
    ) {}

    public function execute(
        User $user,
        Business $currentBusiness,
        Capability $capability,
        string $formalRecordVersionId,
        int $expectedRevision,
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
            $formalRecordVersionId,
            $expectedRevision,
            $contentHash,
            $changeSummary,
            $effectiveFrom,
            $effectiveUntil,
            $reviewDueAt,
        ): ?FormalRecordVersion {
            $version = FormalRecordVersion::query()
                ->where('business_id', $currentBusiness->getKey())
                ->whereKey($formalRecordVersionId)
                ->lockForUpdate()
                ->first();
            if ($version === null) {
                return null;
            }
            $resourceDecision = $this->authorizeBusinessCapability->decide(
                $user,
                $currentBusiness,
                $currentBusiness,
                $capability,
                FormalRecordVersion::class,
                (string) $version->getKey(),
            );
            if (! $resourceDecision->allowed) {
                return null;
            }
            if ($version->frozen_at !== null) {
                throw new InvalidWorkflowTransition(
                    'Frozen formal-record versions cannot be edited.',
                );
            }
            $latestState = $this->latestState($version);
            if ($latestState !== FormalRecordState::Draft) {
                throw new InvalidWorkflowTransition(
                    'Only Draft formal-record versions are editable.',
                );
            }
            $revision = new Revision((int) $version->revision);
            if (! $revision->matches($expectedRevision)) {
                throw new StaleRevision(
                    $expectedRevision,
                    $revision->value,
                );
            }
            $version->fill([
                'revision' => $revision->next()->value,
                'change_summary' => trim($changeSummary),
                'last_changed_by_user_id' => $user->getKey(),
                'effective_from' => $effectiveFrom,
                'effective_until' => $effectiveUntil,
                'review_due_at' => $reviewDueAt,
                'content_hash' => strtolower($contentHash),
            ]);
            $version->save();

            $this->recordBusinessOccurrence->audit(
                $currentBusiness,
                AuditActor::user((string) $user->getKey()),
                'records.formal_record_version.updated',
                new OccurrenceTarget(
                    (string) $currentBusiness->getKey(),
                    'formal_record_family',
                    (string) $version->formal_record_family_id,
                    (string) $version->getKey(),
                ),
                SafeAuditMetadata::from([
                    'version_number' => (int) $version->version_number,
                    'revision' => (int) $version->revision,
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
