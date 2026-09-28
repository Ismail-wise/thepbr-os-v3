<?php

declare(strict_types=1);

namespace App\Application\Import;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Application\Access\ResolveMembershipCapabilities;
use App\Application\Events\RecordBusinessOccurrence;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Audit\ValueObjects\AuditActor;
use App\Domain\Audit\ValueObjects\SafeAuditMetadata;
use App\Domain\Events\ValueObjects\OccurrenceTarget;
use App\Domain\Events\ValueObjects\SafeBusinessEventPayload;
use App\Domain\Import\Enums\ImportBatchStatus;
use App\Domain\Import\Enums\ImportedRecordStatus;
use App\Infrastructure\Import\CsvImportParser;
use App\Infrastructure\Import\ImportParser;
use App\Infrastructure\Import\JsonImportParser;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Import\ImportBatch;
use App\Infrastructure\Persistence\Eloquent\Import\ImportedRecord;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ParseImportBatch
{
    public function __construct(
        private readonly ResolveMembershipCapabilities $memberships,
        private readonly AuthorizeBusinessCapability $authorize,
        private readonly CsvImportParser $csv,
        private readonly JsonImportParser $json,
        private readonly RecordBusinessOccurrence $occurrence,
    ) {}

    public function execute(
        User $user,
        Business $business,
        string $batchId,
    ): ?ImportBatch {
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

        return DB::transaction(function () use (
            $user,
            $business,
            $batchId,
            $membership,
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

            if ($batch->status !== ImportBatchStatus::Staged) {
                throw new InvalidArgumentException(
                    'Only a staged Import Batch may be parsed.',
                );
            }

            $parser = $this->parserFor((string) $batch->source_type);

            if (
                $parser->identity() !== (string) $batch->parser_identity
                || $parser->version() !== (string) $batch->parser_version
            ) {
                throw new InvalidArgumentException(
                    'Import parser identity no longer matches the frozen batch provenance.',
                );
            }

            $rows = $parser->parse((string) $batch->source_payload);

            foreach ($rows as $row) {
                $sourceRecordKey = $this->sourceRecordKey($row);

                ImportedRecord::query()->create([
                    'business_id' => $business->getKey(),
                    'import_batch_id' => $batch->getKey(),
                    'source_record_key' => $sourceRecordKey,
                    'intended_target' => $batch->intended_target,
                    'idempotency_key' => $this->idempotencyKey(
                        $business,
                        $batch,
                        $sourceRecordKey,
                    ),
                    'observed_payload' => $row,
                    'normalized_payload' => null,
                    'status' => ImportedRecordStatus::Observed,
                ]);
            }

            $batch->status = ImportBatchStatus::Parsed;
            $batch->parsed_at = now();
            $batch->save();

            $this->recordOccurrence(
                $user,
                $business,
                'import.batch.parsed',
                (string) $batch->getKey(),
                [
                    'record_count' => count($rows),
                    'parser_identity' => (string) $batch->parser_identity,
                    'parser_version' => (string) $batch->parser_version,
                ],
            );

            return $batch->fresh();
        });
    }

    private function parserFor(string $sourceType): ImportParser
    {
        return match ($sourceType) {
            'csv' => $this->csv,
            'json' => $this->json,
            default => throw new InvalidArgumentException(
                'Import source type has no registered parser.',
            ),
        };
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function sourceRecordKey(array $row): string
    {
        $value = $row['source_record_key'] ?? null;

        if (! is_scalar($value)) {
            throw new InvalidArgumentException(
                'Every imported record requires a source_record_key.',
            );
        }

        $key = trim((string) $value);

        if ($key === '' || mb_strlen($key) > 200) {
            throw new InvalidArgumentException(
                'Every imported record requires a source_record_key within 200 characters.',
            );
        }

        return $key;
    }

    private function idempotencyKey(
        Business $business,
        ImportBatch $batch,
        string $sourceRecordKey,
    ): string {
        $identity = [
            'business_id' => (string) $business->getKey(),
            'source_system' => (string) $batch->source_system,
            'source_type' => (string) $batch->source_type,
            'source_fingerprint' => (string) $batch->source_fingerprint,
            'source_record_key' => $sourceRecordKey,
            'intended_target' => (string) $batch->intended_target,
        ];

        return hash(
            'sha256',
            json_encode(
                $identity,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE,
            ),
        );
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
