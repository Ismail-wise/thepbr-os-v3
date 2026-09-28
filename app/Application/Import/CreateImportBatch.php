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
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Import\ImportBatch;
use InvalidArgumentException;

final class CreateImportBatch
{
    private const int MAX_SOURCE_BYTES = 2097152;

    /** @var array<string,array{identity:string,version:string}> */
    private const array PARSERS = [
        'csv' => [
            'identity' => 'pbr.csv',
            'version' => '1.0',
        ],
        'json' => [
            'identity' => 'pbr.json',
            'version' => '1.0',
        ],
    ];

    /** @var list<string> */
    private const array TARGETS = [
        'partner',
        'formal_record_amendment',
    ];

    public function __construct(
        private readonly ResolveMembershipCapabilities $memberships,
        private readonly AuthorizeBusinessCapability $authorize,
        private readonly RecordBusinessOccurrence $occurrence,
    ) {}

    public function execute(
        User $user,
        Business $business,
        string $sourceType,
        ?string $sourceSystem,
        string $sourceFilename,
        string $sourceContent,
        string $schemaVersion,
        string $intendedTarget,
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

        $sourceType = mb_strtolower(trim($sourceType));
        $sourceSystem = trim((string) $sourceSystem);
        $sourceFilename = trim($sourceFilename);
        $schemaVersion = trim($schemaVersion);
        $intendedTarget = trim($intendedTarget);

        if (! array_key_exists($sourceType, self::PARSERS)) {
            throw new InvalidArgumentException(
                'Import source type must be csv or json.',
            );
        }

        if (! in_array($intendedTarget, self::TARGETS, true)) {
            throw new InvalidArgumentException(
                'Import intended target is not supported.',
            );
        }

        if (
            $sourceFilename === ''
            || mb_strlen($sourceFilename) > 255
        ) {
            throw new InvalidArgumentException(
                'Import source filename is required.',
            );
        }

        if (
            $sourceSystem === ''
            || mb_strlen($sourceSystem) > 120
        ) {
            throw new InvalidArgumentException(
                'Import source system is required.',
            );
        }

        if (
            $schemaVersion === ''
            || mb_strlen($schemaVersion) > 80
        ) {
            throw new InvalidArgumentException(
                'Import schema version is required.',
            );
        }

        $size = strlen($sourceContent);

        if ($size < 1 || $size > self::MAX_SOURCE_BYTES) {
            throw new InvalidArgumentException(
                'Import source file size is not allowed.',
            );
        }

        $parser = self::PARSERS[$sourceType];
        $fingerprint = hash('sha256', $sourceContent);

        $batch = ImportBatch::query()->create([
            'business_id' => $business->getKey(),
            'created_by_membership_id' => $membership->getKey(),
            'source_type' => $sourceType,
            'source_system' => $sourceSystem,
            'source_filename' => $sourceFilename,
            'source_fingerprint' => $fingerprint,
            'parser_identity' => $parser['identity'],
            'parser_version' => $parser['version'],
            'schema_version' => $schemaVersion,
            'intended_target' => $intendedTarget,
            'source_payload' => $sourceContent,
            'status' => ImportBatchStatus::Staged,
            'staged_at' => now(),
        ]);

        $this->recordOccurrence(
            $user,
            $business,
            'import.batch.staged',
            (string) $batch->getKey(),
            [
                'source_type' => $sourceType,
                'source_system' => $sourceSystem,
                'source_fingerprint' => $fingerprint,
                'parser_identity' => $parser['identity'],
                'parser_version' => $parser['version'],
                'schema_version' => $schemaVersion,
                'intended_target' => $intendedTarget,
            ],
        );

        return $batch->fresh();
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
