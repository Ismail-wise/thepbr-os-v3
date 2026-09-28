<?php

declare(strict_types=1);

namespace App\Application\Import;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Application\Access\ResolveMembershipCapabilities;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Import\ImportBatch;
use App\Infrastructure\Persistence\Eloquent\Import\ImportedRecord;
use App\Infrastructure\Persistence\Eloquent\Import\ImportValidationResult;

final class GetImportWorkspace
{
    public function __construct(
        private readonly ResolveMembershipCapabilities $memberships,
        private readonly AuthorizeBusinessCapability $authorize,
    ) {}

    /**
     * @return array<string,mixed>|null
     */
    public function execute(
        User $user,
        Business $business,
        ?string $selectedBatchId = null,
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
                new Capability(CapabilityCatalog::IMPORT_VIEW),
            )->allowed
        ) {
            return null;
        }

        $canManage = $this->authorize->decide(
            $user,
            $business,
            $business,
            new Capability(CapabilityCatalog::IMPORT_MANAGE),
        )->allowed;

        $batches = ImportBatch::query()
            ->where('business_id', $business->getKey())
            ->where('created_by_membership_id', $membership->getKey())
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $selected = null;

        if ($selectedBatchId !== null && $selectedBatchId !== '') {
            $selectedBatch = $batches->firstWhere('id', $selectedBatchId);

            if ($selectedBatch !== null) {
                $records = ImportedRecord::query()
                    ->where('business_id', $business->getKey())
                    ->where('import_batch_id', $selectedBatch->getKey())
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->get();

                $recordIds = $records
                    ->pluck('id')
                    ->map(static fn (mixed $id): string => (string) $id)
                    ->all();

                $issues = $recordIds === []
                    ? collect()
                    : ImportValidationResult::query()
                        ->where('business_id', $business->getKey())
                        ->whereIn('imported_record_id', $recordIds)
                        ->orderBy('created_at')
                        ->orderBy('id')
                        ->get()
                        ->groupBy('imported_record_id');

                $selected = [
                    'id' => (string) $selectedBatch->getKey(),
                    'source_type' => (string) $selectedBatch->source_type,
                    'source_system' => (string) $selectedBatch->source_system,
                    'source_filename' => (string) $selectedBatch->source_filename,
                    'source_fingerprint' => (string) $selectedBatch->source_fingerprint,
                    'parser_identity' => (string) $selectedBatch->parser_identity,
                    'parser_version' => (string) $selectedBatch->parser_version,
                    'schema_version' => (string) $selectedBatch->schema_version,
                    'intended_target' => (string) $selectedBatch->intended_target,
                    'status' => $selectedBatch->status->value,
                    'created_at' => $selectedBatch->created_at?->format(DATE_ATOM),
                    'records' => $records
                        ->map(function (ImportedRecord $record) use ($issues): array {
                            $recordIssues = $issues->get(
                                (string) $record->getKey(),
                                collect(),
                            );

                            return [
                                'id' => (string) $record->getKey(),
                                'source_record_key' => (string) $record->source_record_key,
                                'intended_target' => (string) $record->intended_target,
                                'status' => $record->status->value,
                                'observed_payload' => $record->observed_payload,
                                'normalized_payload' => $record->normalized_payload,
                                'confirmation_error_code' => $record->confirmation_error_code,
                                'confirmation_error_message' => $record->confirmation_error_message,
                                'canonical_resource_type' => $record->canonical_resource_type,
                                'issues' => $recordIssues
                                    ->map(static fn (
                                        ImportValidationResult $issue,
                                    ): array => [
                                        'severity' => (string) $issue->severity,
                                        'code' => (string) $issue->code,
                                        'field' => $issue->field,
                                        'message' => (string) $issue->message,
                                    ])
                                    ->values()
                                    ->all(),
                            ];
                        })
                        ->all(),
                ];
            }
        }

        return [
            'business' => [
                'id' => (string) $business->getKey(),
                'name' => (string) $business->name,
            ],
            'permissions' => [
                'view' => true,
                'manage' => $canManage,
            ],
            'supported_source_types' => [
                'csv',
                'json',
            ],
            'supported_targets' => [
                'partner',
                'formal_record_amendment',
            ],
            'batches' => $batches
                ->map(static fn (ImportBatch $batch): array => [
                    'id' => (string) $batch->getKey(),
                    'source_type' => (string) $batch->source_type,
                    'source_system' => (string) $batch->source_system,
                    'source_filename' => (string) $batch->source_filename,
                    'source_fingerprint' => (string) $batch->source_fingerprint,
                    'parser_identity' => (string) $batch->parser_identity,
                    'parser_version' => (string) $batch->parser_version,
                    'schema_version' => (string) $batch->schema_version,
                    'intended_target' => (string) $batch->intended_target,
                    'status' => $batch->status->value,
                    'created_at' => $batch->created_at?->format(DATE_ATOM),
                    'completed_at' => $batch->completed_at?->format(DATE_ATOM),
                ])
                ->all(),
            'selected_batch' => $selected,
        ];
    }
}
