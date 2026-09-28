<?php

declare(strict_types=1);

namespace App\Application\Import;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Application\Access\ResolveMembershipCapabilities;
use App\Application\Events\RecordBusinessOccurrence;
use App\Application\Partnership\PartnerDirectory;
use App\Application\Records\CreateAmendedDraftVersion;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Audit\ValueObjects\AuditActor;
use App\Domain\Audit\ValueObjects\SafeAuditMetadata;
use App\Domain\Events\ValueObjects\OccurrenceTarget;
use App\Domain\Events\ValueObjects\SafeBusinessEventPayload;
use App\Domain\Import\Enums\ImportBatchStatus;
use App\Domain\Import\Enums\ImportedRecordStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Import\ImportBatch;
use App\Infrastructure\Persistence\Eloquent\Import\ImportedRecord;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class ConfirmImportRecords
{
    public function __construct(
        private readonly ResolveMembershipCapabilities $memberships,
        private readonly AuthorizeBusinessCapability $authorize,
        private readonly PartnerDirectory $partners,
        private readonly CreateAmendedDraftVersion $amendments,
        private readonly RecordBusinessOccurrence $occurrence,
    ) {}

    /**
     * @param  list<string>  $recordIds
     * @return array{
     *   batch:ImportBatch,
     *   results:list<array{
     *     record_id:string,
     *     success:bool,
     *     status:string,
     *     code:string
     *   }>
     * }|null
     */
    public function execute(
        User $user,
        Business $business,
        string $batchId,
        array $recordIds,
    ): ?array {
        $membership = $this->memberships->activeMembership(
            $user,
            $business,
        );

        if (
            $membership === null
            || ! $this->authorize->decide(
                $user,
                $business,
                $business,
                new Capability(CapabilityCatalog::IMPORT_MANAGE),
            )->allowed
        ) {
            return null;
        }

        $recordIds = array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $id): string => trim((string) $id),
                $recordIds,
            ),
            static fn (string $id): bool => $id !== '',
        )));

        if ($recordIds === []) {
            throw new InvalidArgumentException(
                'At least one Imported Record must be selected for confirmation.',
            );
        }

        foreach ($recordIds as $recordId) {
            if (! Str::isUuid($recordId)) {
                return null;
            }
        }

        $batch = DB::transaction(function () use (
            $business,
            $membership,
            $batchId,
            $recordIds,
        ): ?ImportBatch {
            $batch = ImportBatch::query()
                ->where('business_id', $business->getKey())
                ->where('created_by_membership_id', $membership->getKey())
                ->whereKey($batchId)
                ->lockForUpdate()
                ->first();

            if ($batch === null) {
                return null;
            }

            if ($batch->status !== ImportBatchStatus::ReviewReady) {
                throw new InvalidArgumentException(
                    'Only a review-ready Import Batch may be confirmed.',
                );
            }

            $selectedCount = ImportedRecord::query()
                ->where('business_id', $business->getKey())
                ->where('import_batch_id', $batch->getKey())
                ->whereIn('id', $recordIds)
                ->count();

            if ($selectedCount !== count($recordIds)) {
                return null;
            }

            $batch->status = ImportBatchStatus::Confirming;
            $batch->save();

            return $batch->fresh();
        });

        if ($batch === null) {
            return null;
        }

        $results = [];

        foreach ($recordIds as $recordId) {
            $results[] = $this->confirmOne(
                $user,
                $business,
                $batch,
                $recordId,
            );
        }

        $batch = DB::transaction(function () use (
            $business,
            $batch,
        ): ImportBatch {
            $locked = ImportBatch::query()
                ->where('business_id', $business->getKey())
                ->whereKey($batch->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== ImportBatchStatus::Confirming) {
                throw new RuntimeException(
                    'Import Batch confirmation state changed unexpectedly.',
                );
            }

            $statusCounts = ImportedRecord::query()
                ->where('business_id', $business->getKey())
                ->where('import_batch_id', $locked->getKey())
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status');

            $remainingValid = (int) ($statusCounts[
                ImportedRecordStatus::Valid->value
            ] ?? 0);

            $errorCount = 0;

            foreach ([
                ImportedRecordStatus::Invalid,
                ImportedRecordStatus::Conflict,
                ImportedRecordStatus::ConfirmationFailed,
            ] as $status) {
                $errorCount += (int) ($statusCounts[$status->value] ?? 0);
            }

            if ($remainingValid > 0) {
                $locked->status = ImportBatchStatus::ReviewReady;
            } elseif ($errorCount > 0) {
                $locked->status = ImportBatchStatus::CompletedWithErrors;
                $locked->completed_at = now();
            } else {
                $locked->status = ImportBatchStatus::Completed;
                $locked->completed_at = now();
            }

            $locked->save();

            return $locked->fresh();
        });

        $successCount = count(array_filter(
            $results,
            static fn (array $result): bool => $result['success'],
        ));

        $this->recordOccurrence(
            $user,
            $business,
            'import.batch.confirmed',
            (string) $batch->getKey(),
            [
                'selected_count' => count($recordIds),
                'success_count' => $successCount,
                'failure_count' => count($recordIds) - $successCount,
                'batch_status' => $batch->status->value,
            ],
        );

        return [
            'batch' => $batch,
            'results' => $results,
        ];
    }

    /**
     * @return array{
     *   record_id:string,
     *   success:bool,
     *   status:string,
     *   code:string
     * }
     */
    private function confirmOne(
        User $user,
        Business $business,
        ImportBatch $batch,
        string $recordId,
    ): array {
        try {
            return DB::transaction(function () use (
                $user,
                $business,
                $batch,
                $recordId,
            ): array {
                $record = ImportedRecord::query()
                    ->where('business_id', $business->getKey())
                    ->where('import_batch_id', $batch->getKey())
                    ->whereKey($recordId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($record->status !== ImportedRecordStatus::Valid) {
                    return [
                        'record_id' => $recordId,
                        'success' => false,
                        'status' => $record->status->value,
                        'code' => 'record_not_confirmable',
                    ];
                }

                DB::selectOne(
                    'SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))',
                    [(string) $record->idempotency_key],
                );

                $duplicate = ImportedRecord::query()
                    ->where('business_id', $business->getKey())
                    ->where(
                        'idempotency_key',
                        $record->idempotency_key,
                    )
                    ->where('status', ImportedRecordStatus::Confirmed->value)
                    ->where('id', '!=', $record->getKey())
                    ->exists();

                if ($duplicate) {
                    $record->status = ImportedRecordStatus::Conflict;
                    $record->confirmation_error_code =
                        'duplicate_import_identity';
                    $record->confirmation_error_message =
                        'This import identity was already confirmed.';
                    $record->save();

                    return [
                        'record_id' => $recordId,
                        'success' => false,
                        'status' => ImportedRecordStatus::Conflict->value,
                        'code' => 'duplicate_import_identity',
                    ];
                }

                $payload = $record->normalized_payload;

                if (! is_array($payload)) {
                    throw new RuntimeException(
                        'Validated Imported Record payload is unavailable.',
                    );
                }

                [$resourceType, $resourceId] = match (
                    (string) $record->intended_target
                ) {
                    'partner' => $this->confirmPartner(
                        $user,
                        $business,
                        $payload,
                    ),
                    'formal_record_amendment' => $this->confirmFormalRecordAmendment(
                        $user,
                        $business,
                        $payload,
                    ),
                    default => throw new RuntimeException(
                        'Imported Record target is not supported.',
                    ),
                };

                $record->status = ImportedRecordStatus::Confirmed;
                $record->canonical_resource_type = $resourceType;
                $record->canonical_resource_id = $resourceId;
                $record->confirmation_error_code = null;
                $record->confirmation_error_message = null;
                $record->confirmed_at = now();
                $record->save();

                return [
                    'record_id' => $recordId,
                    'success' => true,
                    'status' => ImportedRecordStatus::Confirmed->value,
                    'code' => 'confirmed',
                ];
            });
        } catch (DomainException|InvalidArgumentException|RuntimeException) {
            DB::transaction(function () use (
                $business,
                $batch,
                $recordId,
            ): void {
                $record = ImportedRecord::query()
                    ->where('business_id', $business->getKey())
                    ->where('import_batch_id', $batch->getKey())
                    ->whereKey($recordId)
                    ->lockForUpdate()
                    ->first();

                if (
                    $record === null
                    || $record->status !== ImportedRecordStatus::Valid
                ) {
                    return;
                }

                $record->status = ImportedRecordStatus::ConfirmationFailed;
                $record->confirmation_error_code = 'target_domain_rejected';
                $record->confirmation_error_message =
                    'Target domain use case rejected this Imported Record.';
                $record->save();
            });

            return [
                'record_id' => $recordId,
                'success' => false,
                'status' => ImportedRecordStatus::ConfirmationFailed->value,
                'code' => 'target_domain_rejected',
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{string,string}
     */
    private function confirmPartner(
        User $user,
        Business $business,
        array $payload,
    ): array {
        $created = $this->partners->create(
            $user,
            $business,
            (string) $payload['display_name'],
            $payload['legal_name'] === null
                ? null
                : (string) $payload['legal_name'],
            $payload['email'] === null
                ? null
                : (string) $payload['email'],
            $payload['notes'] === null
                ? null
                : (string) $payload['notes'],
        );

        if ($created === null) {
            throw new RuntimeException(
                'Partner target domain authorization denied.',
            );
        }

        return ['partner', $created['id']];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{string,string}
     */
    private function confirmFormalRecordAmendment(
        User $user,
        Business $business,
        array $payload,
    ): array {
        $version = $this->amendments->execute(
            $user,
            $business,
            new Capability(CapabilityCatalog::RECORDS_MANAGE),
            (string) $payload['source_version_id'],
            (string) $payload['content_hash'],
            (string) $payload['change_summary'],
        );

        if ($version === null) {
            throw new RuntimeException(
                'Formal Record amendment target domain authorization denied.',
            );
        }

        return [
            'formal_record_version',
            (string) $version->getKey(),
        ];
    }

    /**
     * @param  array<string,bool|float|int|string|null>  $metadata
     */
    private function recordOccurrence(
        User $user,
        Business $business,
        string $action,
        string $batchId,
        array $metadata,
    ): void {
        $at = now();
        $correlationId = $this->occurrence->newCorrelationId();
        $actor = AuditActor::user((string) $user->getKey());
        $target = new OccurrenceTarget(
            (string) $business->getKey(),
            'import_batch',
            $batchId,
        );

        $this->occurrence->audit(
            $business,
            $actor,
            $action,
            $target,
            SafeAuditMetadata::from($metadata),
            $at,
            $correlationId,
        );

        $this->occurrence->businessEvent(
            $business,
            $action,
            $target,
            $target,
            SafeBusinessEventPayload::from($metadata),
            $at,
            $actor,
            $correlationId,
        );
    }
}
