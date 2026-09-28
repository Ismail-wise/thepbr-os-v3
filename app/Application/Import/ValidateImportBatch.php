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
use App\Domain\Records\Enums\FormalRecordState;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Import\ImportBatch;
use App\Infrastructure\Persistence\Eloquent\Import\ImportedRecord;
use App\Infrastructure\Persistence\Eloquent\Import\ImportValidationResult;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionStateTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ValidateImportBatch
{
    public function __construct(
        private readonly ResolveMembershipCapabilities $memberships,
        private readonly AuthorizeBusinessCapability $authorize,
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

            if ($batch->status !== ImportBatchStatus::Parsed) {
                throw new InvalidArgumentException(
                    'Only a parsed Import Batch may be validated.',
                );
            }

            $records = ImportedRecord::query()
                ->where('business_id', $business->getKey())
                ->where('import_batch_id', $batch->getKey())
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $counts = [
                'valid' => 0,
                'invalid' => 0,
                'conflict' => 0,
            ];

            foreach ($records as $record) {
                if ($record->status !== ImportedRecordStatus::Observed) {
                    throw new InvalidArgumentException(
                        'Import Batch contains a record outside the observed validation state.',
                    );
                }

                $result = $this->validateRecord(
                    $user,
                    $business,
                    $record,
                );

                foreach ($result['issues'] as $issue) {
                    ImportValidationResult::query()->create([
                        'business_id' => $business->getKey(),
                        'imported_record_id' => $record->getKey(),
                        'severity' => $issue['severity'],
                        'code' => $issue['code'],
                        'field' => $issue['field'],
                        'message' => $issue['message'],
                    ]);
                }

                $record->normalized_payload = $result['normalized'];
                $record->status = $result['status'];
                $record->save();

                $counts[$result['status']->value]++;
            }

            $batch->status = ImportBatchStatus::ReviewReady;
            $batch->validated_at = now();
            $batch->review_ready_at = now();
            $batch->save();

            $this->recordOccurrence(
                $user,
                $business,
                'import.batch.validated',
                (string) $batch->getKey(),
                [
                    'record_count' => $records->count(),
                    'valid_count' => $counts['valid'],
                    'invalid_count' => $counts['invalid'],
                    'conflict_count' => $counts['conflict'],
                ],
            );

            return $batch->fresh();
        });
    }

    /**
     * @return array{
     *   normalized:array<string,mixed>,
     *   status:ImportedRecordStatus,
     *   issues:list<array{
     *     severity:string,
     *     code:string,
     *     field:?string,
     *     message:string
     *   }>
     * }
     */
    private function validateRecord(
        User $user,
        Business $business,
        ImportedRecord $record,
    ): array {
        $payload = $record->observed_payload;

        if (! is_array($payload)) {
            return $this->invalid(
                [],
                'payload_invalid',
                null,
                'Imported record payload is not an object.',
            );
        }

        if ($this->hasDuplicateImportIdentity($business, $record)) {
            return $this->conflict(
                $payload,
                'duplicate_import_identity',
                'source_record_key',
                'This import identity is duplicated or was already confirmed.',
            );
        }

        return match ((string) $record->intended_target) {
            'partner' => $this->validatePartner(
                $business,
                $payload,
            ),
            'formal_record_amendment' => $this->validateFormalRecordAmendment(
                $user,
                $business,
                $payload,
            ),
            default => $this->invalid(
                $payload,
                'target_unsupported',
                null,
                'Imported record target is not supported.',
            ),
        };
    }

    private function hasDuplicateImportIdentity(
        Business $business,
        ImportedRecord $record,
    ): bool {
        $sameBatchCount = ImportedRecord::query()
            ->where('business_id', $business->getKey())
            ->where('import_batch_id', $record->import_batch_id)
            ->where('idempotency_key', $record->idempotency_key)
            ->count();

        if ($sameBatchCount > 1) {
            return true;
        }

        return ImportedRecord::query()
            ->where('business_id', $business->getKey())
            ->where('idempotency_key', $record->idempotency_key)
            ->where('status', ImportedRecordStatus::Confirmed->value)
            ->where('id', '!=', $record->getKey())
            ->exists();
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{
     *   normalized:array<string,mixed>,
     *   status:ImportedRecordStatus,
     *   issues:list<array{
     *     severity:string,
     *     code:string,
     *     field:?string,
     *     message:string
     *   }>
     * }
     */
    private function validatePartner(
        Business $business,
        array $payload,
    ): array {
        $allowed = [
            'source_record_key',
            'display_name',
            'legal_name',
            'email',
            'notes',
        ];

        $unsupported = array_values(
            array_diff(array_keys($payload), $allowed),
        );

        if ($unsupported !== []) {
            return $this->invalid(
                $payload,
                'unsupported_field',
                $unsupported[0],
                'Imported Partner contains a field that cannot be promoted through this import target.',
            );
        }

        $displayName = $this->stringValue(
            $payload['display_name'] ?? null,
        );

        if (
            $displayName === null
            || $displayName === ''
            || mb_strlen($displayName) > 160
        ) {
            return $this->invalid(
                $payload,
                'display_name_invalid',
                'display_name',
                'Partner display_name is required and must not exceed 160 characters.',
            );
        }

        $legalName = $this->nullableStringValue(
            $payload['legal_name'] ?? null,
        );
        $email = $this->nullableStringValue(
            $payload['email'] ?? null,
        );
        $notes = $this->nullableStringValue(
            $payload['notes'] ?? null,
        );

        if ($legalName !== null && mb_strlen($legalName) > 160) {
            return $this->invalid(
                $payload,
                'legal_name_invalid',
                'legal_name',
                'Partner legal_name must not exceed 160 characters.',
            );
        }

        if ($email !== null) {
            $email = mb_strtolower($email);

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return $this->invalid(
                    $payload,
                    'email_invalid',
                    'email',
                    'Partner email is not valid.',
                );
            }

            if (DB::table('partners')
                ->where('business_id', $business->getKey())
                ->whereRaw('lower(email) = ?', [$email])
                ->exists()) {
                return $this->conflict(
                    [
                        'display_name' => $displayName,
                        'legal_name' => $legalName,
                        'email' => $email,
                        'notes' => $notes,
                    ],
                    'partner_email_collision',
                    'email',
                    'A Partner with this email already exists in this Business and must be reconciled instead of overwritten.',
                );
            }
        }

        return [
            'normalized' => [
                'display_name' => $displayName,
                'legal_name' => $legalName,
                'email' => $email,
                'notes' => $notes,
            ],
            'status' => ImportedRecordStatus::Valid,
            'issues' => [],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{
     *   normalized:array<string,mixed>,
     *   status:ImportedRecordStatus,
     *   issues:list<array{
     *     severity:string,
     *     code:string,
     *     field:?string,
     *     message:string
     *   }>
     * }
     */
    private function validateFormalRecordAmendment(
        User $user,
        Business $business,
        array $payload,
    ): array {
        $allowed = [
            'source_record_key',
            'source_version_id',
            'content_hash',
            'change_summary',
        ];

        $unsupported = array_values(
            array_diff(array_keys($payload), $allowed),
        );

        if ($unsupported !== []) {
            return $this->invalid(
                $payload,
                'unsupported_field',
                $unsupported[0],
                'Formal Record import may create only an amendment Draft and cannot import effectivity, approval, signature or authority fields.',
            );
        }

        $sourceVersionId = $this->stringValue(
            $payload['source_version_id'] ?? null,
        );
        $contentHash = mb_strtolower(
            (string) ($this->stringValue(
                $payload['content_hash'] ?? null,
            ) ?? ''),
        );
        $changeSummary = $this->stringValue(
            $payload['change_summary'] ?? null,
        );

        if (
            $sourceVersionId === null
            || ! Str::isUuid($sourceVersionId)
        ) {
            return $this->invalid(
                $payload,
                'source_version_invalid',
                'source_version_id',
                'Formal Record source_version_id must identify an accessible record version in this Business.',
            );
        }

        if (preg_match('/\A[a-f0-9]{64}\z/', $contentHash) !== 1) {
            return $this->invalid(
                $payload,
                'content_hash_invalid',
                'content_hash',
                'Formal Record content_hash must be an exact SHA-256 hexadecimal identity.',
            );
        }

        if (
            $changeSummary === null
            || $changeSummary === ''
            || mb_strlen($changeSummary) > 500
        ) {
            return $this->invalid(
                $payload,
                'change_summary_invalid',
                'change_summary',
                'Formal Record change_summary is required and must not exceed 500 characters.',
            );
        }

        $source = FormalRecordVersion::query()
            ->where('business_id', $business->getKey())
            ->whereKey($sourceVersionId)
            ->first();

        if (
            $source === null
            || $source->frozen_at === null
            || ! $this->authorize->decide(
                $user,
                $business,
                $business,
                new Capability(CapabilityCatalog::RECORDS_MANAGE),
                FormalRecordVersion::class,
                $sourceVersionId,
            )->allowed
        ) {
            return $this->invalid(
                $payload,
                'source_version_unavailable',
                'source_version_id',
                'Formal Record source version is not available for amendment in this Business.',
            );
        }

        $latestState = RecordVersionStateTransition::query()
            ->where('formal_record_version_id', $sourceVersionId)
            ->orderByDesc('sequence')
            ->first(['to_state'])
            ?->to_state;

        if (! in_array(
            $latestState,
            [
                FormalRecordState::Effective,
                FormalRecordState::ChangesRequested,
            ],
            true,
        )) {
            return $this->conflict(
                [
                    'source_version_id' => $sourceVersionId,
                    'content_hash' => $contentHash,
                    'change_summary' => $changeSummary,
                ],
                'source_version_state_collision',
                'source_version_id',
                'Formal Record source state no longer permits an amendment Draft.',
            );
        }

        return [
            'normalized' => [
                'source_version_id' => $sourceVersionId,
                'content_hash' => $contentHash,
                'change_summary' => $changeSummary,
            ],
            'status' => ImportedRecordStatus::Valid,
            'issues' => [[
                'severity' => 'info',
                'code' => 'effective_truth_preserved_by_amendment',
                'field' => 'source_version_id',
                'message' => 'Confirmation creates a new Draft amendment and never overwrites the frozen source version.',
            ]],
        ];
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        return trim((string) $value);
    }

    private function nullableStringValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->stringValue($value);
    }

    /**
     * @param  array<string,mixed>  $normalized
     * @return array{
     *   normalized:array<string,mixed>,
     *   status:ImportedRecordStatus,
     *   issues:list<array{
     *     severity:string,
     *     code:string,
     *     field:?string,
     *     message:string
     *   }>
     * }
     */
    private function invalid(
        array $normalized,
        string $code,
        ?string $field,
        string $message,
    ): array {
        return [
            'normalized' => $normalized,
            'status' => ImportedRecordStatus::Invalid,
            'issues' => [[
                'severity' => 'error',
                'code' => $code,
                'field' => $field,
                'message' => $message,
            ]],
        ];
    }

    /**
     * @param  array<string,mixed>  $normalized
     * @return array{
     *   normalized:array<string,mixed>,
     *   status:ImportedRecordStatus,
     *   issues:list<array{
     *     severity:string,
     *     code:string,
     *     field:?string,
     *     message:string
     *   }>
     * }
     */
    private function conflict(
        array $normalized,
        string $code,
        ?string $field,
        string $message,
    ): array {
        return [
            'normalized' => $normalized,
            'status' => ImportedRecordStatus::Conflict,
            'issues' => [[
                'severity' => 'error',
                'code' => $code,
                'field' => $field,
                'message' => $message,
            ]],
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
