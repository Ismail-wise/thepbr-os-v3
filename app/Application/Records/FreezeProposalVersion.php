<?php

declare(strict_types=1);

namespace App\Application\Records;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Application\Events\RecordBusinessOccurrence;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Audit\ValueObjects\AuditActor;
use App\Domain\Audit\ValueObjects\SafeAuditMetadata;
use App\Domain\Events\ValueObjects\OccurrenceTarget;
use App\Domain\Events\ValueObjects\SafeBusinessEventPayload;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Domain\Records\ValueObjects\Revision;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\Proposal;
use App\Infrastructure\Persistence\Eloquent\Records\ProposalVersion;
use App\Infrastructure\Persistence\Eloquent\Records\ProposalVersionRecord;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class FreezeProposalVersion
{
    public function __construct(
        private readonly AuthorizeBusinessCapability $authorizeBusinessCapability,
        private readonly RecordBusinessOccurrence $recordBusinessOccurrence,
    ) {}

    /**
     * @param  array<string>  $formalRecordVersionIds
     */
    public function execute(
        User $user,
        Business $currentBusiness,
        Capability $capability,
        string $proposalId,
        int $expectedRevision,
        array $formalRecordVersionIds,
    ): ?ProposalVersion {
        $baseDecision = $this->authorizeBusinessCapability->decide(
            $user,
            $currentBusiness,
            $currentBusiness,
            $capability,
        );
        if (! $baseDecision->allowed) {
            return null;
        }
        $recordIds = array_values(
            array_map(
                static fn (mixed $id): string => trim((string) $id),
                $formalRecordVersionIds,
            ),
        );
        foreach ($recordIds as $recordId) {
            if ($recordId === '') {
                throw new InvalidArgumentException(
                    'Formal-record version IDs must not be empty.',
                );
            }
        }
        if (count($recordIds) !== count(array_unique($recordIds))) {
            throw new InvalidArgumentException(
                'A frozen proposal cannot contain duplicate formal-record version bindings.',
            );
        }
        sort($recordIds, SORT_STRING);

        return DB::transaction(function () use (
            $user,
            $currentBusiness,
            $capability,
            $proposalId,
            $expectedRevision,
            $recordIds,
        ): ?ProposalVersion {
            $proposal = Proposal::query()
                ->where('business_id', $currentBusiness->getKey())
                ->whereKey($proposalId)
                ->lockForUpdate()
                ->first();
            if ($proposal === null) {
                return null;
            }
            $proposalDecision = $this->authorizeBusinessCapability->decide(
                $user,
                $currentBusiness,
                $currentBusiness,
                $capability,
                Proposal::class,
                (string) $proposal->getKey(),
            );
            if (! $proposalDecision->allowed) {
                return null;
            }
            $revision = new Revision((int) $proposal->revision);
            if (! $revision->matches($expectedRevision)) {
                throw new StaleRevision(
                    $expectedRevision,
                    $revision->value,
                );
            }
            $bindings = [];
            foreach ($recordIds as $recordId) {
                $recordVersion = FormalRecordVersion::query()
                    ->where(
                        'business_id',
                        $currentBusiness->getKey(),
                    )
                    ->whereKey($recordId)
                    ->lockForUpdate()
                    ->first();
                if ($recordVersion === null) {
                    return null;
                }
                $recordDecision = $this->authorizeBusinessCapability->decide(
                    $user,
                    $currentBusiness,
                    $currentBusiness,
                    $capability,
                    FormalRecordVersion::class,
                    (string) $recordVersion->getKey(),
                );
                if (! $recordDecision->allowed) {
                    return null;
                }
                $bindings[] = [
                    'formal_record_version_id' => (string) $recordVersion->getKey(),
                    'captured_content_hash' => (string) $recordVersion->content_hash,
                ];
            }
            $snapshotHash = $this->snapshotHash(
                (string) $currentBusiness->getKey(),
                (string) $proposal->getKey(),
                (int) $proposal->revision,
                (string) $proposal->content_hash,
                $bindings,
            );
            $latestVersionNumber = (int) ProposalVersion::query()
                ->where('business_id', $currentBusiness->getKey())
                ->where('proposal_id', $proposal->getKey())
                ->max('version_number');
            $proposalVersion = ProposalVersion::query()->create([
                'business_id' => $currentBusiness->getKey(),
                'proposal_id' => $proposal->getKey(),
                'version_number' => $latestVersionNumber + 1,
                'proposal_revision' => $proposal->revision,
                'proposal_content_hash' => $proposal->content_hash,
                'snapshot_hash' => $snapshotHash,
                'frozen_by_user_id' => $user->getKey(),
                'frozen_at' => now(),
            ]);
            foreach ($bindings as $binding) {
                ProposalVersionRecord::query()->create([
                    'business_id' => $currentBusiness->getKey(),
                    'proposal_version_id' => $proposalVersion->getKey(),
                    'formal_record_version_id' => $binding['formal_record_version_id'],
                    'captured_content_hash' => $binding['captured_content_hash'],
                ]);
            }
            $occurredAt = now();
            $correlationId =
                $this->recordBusinessOccurrence->newCorrelationId();
            $actor = AuditActor::user((string) $user->getKey());

            $this->recordBusinessOccurrence->audit(
                $currentBusiness,
                $actor,
                'records.proposal_version.frozen',
                new OccurrenceTarget(
                    (string) $currentBusiness->getKey(),
                    'proposal',
                    (string) $proposal->getKey(),
                    (string) $proposalVersion->getKey(),
                ),
                SafeAuditMetadata::from([
                    'proposal_revision' => (int) $proposalVersion->proposal_revision,
                    'version_number' => (int) $proposalVersion->version_number,
                    'record_count' => count($bindings),
                ]),
                $occurredAt,
                $correlationId,
            );

            $this->recordBusinessOccurrence->businessEvent(
                $currentBusiness,
                'records.proposal_version.frozen',
                new OccurrenceTarget(
                    (string) $currentBusiness->getKey(),
                    'proposal',
                    (string) $proposal->getKey(),
                    (string) $proposalVersion->getKey(),
                ),
                new OccurrenceTarget(
                    (string) $currentBusiness->getKey(),
                    'proposal',
                    (string) $proposal->getKey(),
                ),
                SafeBusinessEventPayload::from([
                    'version_number' => (int) $proposalVersion->version_number,
                ]),
                $occurredAt,
                $actor,
                $correlationId,
            );

            return $proposalVersion->fresh();
        });
    }

    /**
     * @param array<array{
     *   formal_record_version_id: string,
     *   captured_content_hash: string
     * }> $bindings
     */
    private function snapshotHash(
        string $businessId,
        string $proposalId,
        int $proposalRevision,
        string $proposalContentHash,
        array $bindings,
    ): string {
        usort(
            $bindings,
            static fn (array $left, array $right): int => strcmp(
                $left['formal_record_version_id'],
                $right['formal_record_version_id'],
            ),
        );
        $lines = [
            'business_id='.$businessId,
            'proposal_id='.$proposalId,
            'proposal_revision='.$proposalRevision,
            'proposal_content_hash='.$proposalContentHash,
            'record_count='.count($bindings),
        ];
        foreach ($bindings as $binding) {
            $lines[] = sprintf(
                'record=%s|%s',
                $binding['formal_record_version_id'],
                $binding['captured_content_hash'],
            );
        }

        return hash('sha256', implode("\n", $lines));
    }
}
