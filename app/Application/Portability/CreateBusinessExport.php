<?php

declare(strict_types=1);

namespace App\Application\Portability;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Application\Access\ResolveMembershipCapabilities;
use App\Application\Documents\ListAuthorizedDocuments;
use App\Application\Events\RecordBusinessOccurrence;
use App\Application\Reporting\AuthorizeBusinessPackDownload;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Audit\ValueObjects\AuditActor;
use App\Domain\Audit\ValueObjects\SafeAuditMetadata;
use App\Domain\Events\ValueObjects\OccurrenceTarget;
use App\Domain\Events\ValueObjects\SafeBusinessEventPayload;
use App\Domain\Portability\Enums\BusinessExportStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentVersion;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Portability\BusinessPortabilityExport;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CreateBusinessExport
{
    /** @var list<string> */
    private const array SUPPORTED_CATEGORIES = [
        'business',
        'formal_records',
        'documents',
        'ownership',
        'partners',
    ];

    public function __construct(
        private readonly ResolveMembershipCapabilities $memberships,
        private readonly AuthorizeBusinessCapability $authorize,
        private readonly ListAuthorizedDocuments $documents,
        private readonly AuthorizeBusinessPackDownload $sourceAuthorization,
        private readonly RecordBusinessOccurrence $occurrence,
    ) {}

    /**
     * @param  list<string>  $requestedCategories
     */
    public function execute(
        User $user,
        Business $business,
        array $requestedCategories,
    ): ?BusinessPortabilityExport {
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
                new Capability(CapabilityCatalog::PORTABILITY_MANAGE),
            )->allowed
        ) {
            return null;
        }

        $categories = $this->normalizeCategories($requestedCategories);
        $manifestGeneratedAt = now();

        return DB::transaction(function () use (
            $user,
            $business,
            $membership,
            $categories,
            $manifestGeneratedAt,
        ): BusinessPortabilityExport {
            $export = BusinessPortabilityExport::query()->create([
                'business_id' => $business->getKey(),
                'requested_by_membership_id' => $membership->getKey(),
                'requested_categories' => $categories,
                'excluded_categories' => [],
                'frozen_manifest' => null,
                'manifest_hash' => null,
                'status' => BusinessExportStatus::Requested,
                'requested_at' => $manifestGeneratedAt,
            ]);

            $this->transition(
                $business,
                (string) $export->getKey(),
                null,
                BusinessExportStatus::Requested,
                (string) $membership->getKey(),
            );

            [$sources, $included, $excluded] = $this->buildSources(
                $user,
                $business,
                $categories,
            );

            $manifest = [
                'schema_version' => 'pbr-business-portability-v1',
                'generated_at' => $manifestGeneratedAt->toAtomString(),
                'business_id' => (string) $business->getKey(),
                'requester' => [
                    'user_id' => (string) $user->getKey(),
                    'membership_id' => (string) $membership->getKey(),
                ],
                'included_categories' => $included,
                'excluded_categories' => $excluded,
                'sources' => $sources,
            ];

            $manifestHash = $this->hash($manifest);

            $export->excluded_categories = $excluded;
            $export->frozen_manifest = $manifest;
            $export->manifest_hash = $manifestHash;
            $export->status = BusinessExportStatus::ManifestFrozen;
            $export->save();

            $this->transition(
                $business,
                (string) $export->getKey(),
                BusinessExportStatus::Requested,
                BusinessExportStatus::ManifestFrozen,
                (string) $membership->getKey(),
            );

            $this->recordOccurrence(
                $user,
                $business,
                'portability.export.manifest_frozen',
                (string) $export->getKey(),
                [
                    'manifest_hash' => $manifestHash,
                    'included_category_count' => count($included),
                    'source_count' => count($sources),
                ],
            );

            return $export->fresh();
        });
    }

    /**
     * @param  list<string>  $categories
     * @return array{
     *   0:list<array<string,mixed>>,
     *   1:list<string>,
     *   2:list<array{category:string,reason:string}>
     * }
     */
    private function buildSources(
        User $user,
        Business $business,
        array $categories,
    ): array {
        $sources = [];
        $included = [];
        $excluded = [];

        foreach (self::SUPPORTED_CATEGORIES as $category) {
            if (! in_array($category, $categories, true)) {
                $excluded[] = [
                    'category' => $category,
                    'reason' => 'not_requested',
                ];

                continue;
            }

            $categorySources = match ($category) {
                'business' => [$this->businessSource($business)],
                'formal_records' => $this->formalRecordSources(
                    $user,
                    $business,
                ),
                'documents' => $this->documentSources(
                    $user,
                    $business,
                ),
                'ownership' => $this->ownershipSources(
                    $user,
                    $business,
                ),
                'partners' => $this->partnerSources(
                    $user,
                    $business,
                ),
                default => [],
            };

            if ($categorySources === []) {
                $excluded[] = [
                    'category' => $category,
                    'reason' => 'no_authorized_source_included',
                ];

                continue;
            }

            $included[] = $category;
            array_push($sources, ...$categorySources);
        }

        usort(
            $sources,
            static fn (array $left, array $right): int => [
                $left['category'],
                $left['source_kind'],
                $left['source_id'],
            ] <=> [
                $right['category'],
                $right['source_kind'],
                $right['source_id'],
            ],
        );

        return [$sources, $included, $excluded];
    }

    /** @return array<string,mixed> */
    private function businessSource(Business $business): array
    {
        $snapshot = [
            'id' => (string) $business->getKey(),
            'name' => (string) $business->name,
            'origin_type' => $business->origin_type->value,
            'business_stage' => $business->business_stage->value,
            'setup_phase' => $business->setup_phase?->value,
            'workspace_status' => $business->workspace_status->value,
            'base_currency' => (string) $business->base_currency,
        ];

        return [
            'category' => 'business',
            'source_kind' => 'business_snapshot',
            'source_id' => (string) $business->getKey(),
            'record_id' => (string) $business->getKey(),
            'version_id' => null,
            'document_id' => null,
            'source_hash' => $this->hash($snapshot),
            'snapshot' => $snapshot,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function formalRecordSources(
        User $user,
        Business $business,
    ): array {
        $families = FormalRecordFamily::query()
            ->where('business_id', $business->getKey())
            ->orderBy('record_type')
            ->orderBy('id')
            ->get();

        $sources = [];

        foreach ($families as $family) {
            $versions = FormalRecordVersion::query()
                ->where('business_id', $business->getKey())
                ->where(
                    'formal_record_family_id',
                    $family->getKey(),
                )
                ->orderBy('version_number')
                ->get();

            foreach ($versions as $version) {
                $candidate = [
                    'source_kind' => 'formal_record_version',
                    'source_id' => (string) $version->getKey(),
                ];

                $authorizedHash = $this->sourceAuthorization
                    ->authorizedSourceHash(
                        $user,
                        $business,
                        $candidate,
                    );

                if (
                    $authorizedHash === null
                    || ! hash_equals(
                        (string) $version->content_hash,
                        $authorizedHash,
                    )
                ) {
                    continue;
                }

                $states = DB::table('record_version_state_transitions')
                    ->where(
                        'formal_record_version_id',
                        $version->getKey(),
                    )
                    ->orderBy('sequence')
                    ->get([
                        'sequence',
                        'from_state',
                        'to_state',
                        'occurred_at',
                    ])
                    ->map(static fn (object $row): array => [
                        'sequence' => (int) $row->sequence,
                        'from_state' => $row->from_state,
                        'to_state' => (string) $row->to_state,
                        'occurred_at' => (string) $row->occurred_at,
                    ])
                    ->all();

                $sources[] = [
                    'category' => 'formal_records',
                    'source_kind' => 'formal_record_version',
                    'source_id' => (string) $version->getKey(),
                    'record_id' => (string) $family->getKey(),
                    'version_id' => (string) $version->getKey(),
                    'document_id' => null,
                    'source_hash' => (string) $version->content_hash,
                    'snapshot' => [
                        'record_type' => (string) $family->record_type,
                        'subject_type' => (string) $family->subject_type,
                        'subject_id' => (string) $family->subject_id,
                        'version_number' => (int) $version->version_number,
                        'revision' => (int) $version->revision,
                        'predecessor_version_id' => $version->predecessor_version_id,
                        'change_summary' => (string) $version->change_summary,
                        'effective_from' => $version->effective_from?->toAtomString(),
                        'effective_until' => $version->effective_until?->toAtomString(),
                        'review_due_at' => $version->review_due_at?->toAtomString(),
                        'frozen_at' => $version->frozen_at?->toAtomString(),
                        'state_history' => $states,
                    ],
                ];
            }
        }

        return $sources;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function documentSources(
        User $user,
        Business $business,
    ): array {
        $documents = $this->documents->execute($user, $business);

        if ($documents === null) {
            return [];
        }

        $sources = [];

        foreach ($documents as $document) {
            $versions = DocumentVersion::query()
                ->where('business_id', $business->getKey())
                ->where('document_id', $document->getKey())
                ->orderBy('version_number')
                ->get();

            foreach ($versions as $version) {
                $candidate = [
                    'source_kind' => 'document_version',
                    'source_id' => (string) $version->getKey(),
                    'container_id' => (string) $document->getKey(),
                ];

                $authorizedHash = $this->sourceAuthorization
                    ->authorizedSourceHash(
                        $user,
                        $business,
                        $candidate,
                    );

                if (
                    $authorizedHash === null
                    || ! hash_equals(
                        (string) $version->content_sha256,
                        $authorizedHash,
                    )
                ) {
                    continue;
                }

                $sources[] = [
                    'category' => 'documents',
                    'source_kind' => 'document_version',
                    'source_id' => (string) $version->getKey(),
                    'record_id' => null,
                    'document_id' => (string) $document->getKey(),
                    'version_id' => (string) $version->getKey(),
                    'source_hash' => (string) $version->content_sha256,
                    'snapshot' => [
                        'title' => (string) $document->title,
                        'category' => $document->category->value,
                        'version_number' => (int) $version->version_number,
                        'original_filename' => (string) $version->original_filename,
                        'mime_type' => (string) $version->mime_type,
                        'size_bytes' => (int) $version->size_bytes,
                        'effective_from' => $version->effective_from?->toAtomString(),
                        'supersedes_document_version_id' => $version->supersedes_document_version_id,
                    ],
                ];
            }
        }

        return $sources;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function ownershipSources(
        User $user,
        Business $business,
    ): array {
        if (! $this->authorize->decide(
            $user,
            $business,
            $business,
            new Capability(CapabilityCatalog::OWNERSHIP_VIEW),
        )->allowed) {
            return [];
        }

        $versions = DB::table('ownership_register_versions')
            ->where('business_id', $business->getKey())
            ->orderBy('version_number')
            ->orderBy('id')
            ->get();

        $sources = [];

        foreach ($versions as $version) {
            $candidate = [
                'source_kind' => 'ownership_register_version',
                'source_id' => (string) $version->id,
            ];

            $hash = $this->sourceAuthorization
                ->authorizedSourceHash(
                    $user,
                    $business,
                    $candidate,
                );

            if ($hash === null) {
                continue;
            }

            $shareClasses = DB::table('ownership_register_share_classes')
                ->where('business_id', $business->getKey())
                ->where(
                    'ownership_register_version_id',
                    $version->id,
                )
                ->orderBy('id')
                ->get()
                ->map(static fn (object $row): array => [
                    'id' => (string) $row->id,
                    'name' => (string) $row->name,
                    'voting_right_per_share' => (string) $row->voting_right_per_share,
                    'profit_right_per_share' => (string) $row->profit_right_per_share,
                    'transfer_allowed' => (bool) $row->transfer_allowed,
                    'restrictions' => $row->restrictions,
                    'special_rights' => $row->special_rights,
                ])
                ->all();

            $positions = DB::table('ownership_register_positions')
                ->where('business_id', $business->getKey())
                ->where(
                    'ownership_register_version_id',
                    $version->id,
                )
                ->orderBy('id')
                ->get()
                ->map(static fn (object $row): array => [
                    'id' => (string) $row->id,
                    'partner_id' => (string) $row->partner_id,
                    'share_class_id' => (string) $row->share_class_id,
                    'accepted_contribution_minor_units' => (int) $row->accepted_contribution_minor_units,
                    'shares_issued' => (string) $row->shares_issued,
                    'shares_vested' => (string) $row->shares_vested,
                    'voting_rights' => (string) $row->voting_rights,
                    'profit_rights' => (string) $row->profit_rights,
                    'issue_date' => $row->issue_date,
                    'vesting_start_date' => $row->vesting_start_date,
                    'vesting_period_months' => $row->vesting_period_months,
                    'vesting_cliff_months' => $row->vesting_cliff_months,
                    'vesting_conditions' => $row->vesting_conditions,
                    'early_exit_treatment' => $row->early_exit_treatment,
                ])
                ->all();

            $sources[] = [
                'category' => 'ownership',
                'source_kind' => 'ownership_register_version',
                'source_id' => (string) $version->id,
                'record_id' => (string) $version->ownership_register_id,
                'version_id' => (string) $version->id,
                'document_id' => null,
                'source_hash' => $hash,
                'snapshot' => [
                    'version_number' => (int) $version->version_number,
                    'status' => (string) $version->status,
                    'currency' => (string) $version->currency,
                    'issued_shares' => (string) $version->issued_shares,
                    'effective_from' => $version->effective_from,
                    'effective_until' => $version->effective_until,
                    'share_classes' => $shareClasses,
                    'positions' => $positions,
                ],
            ];
        }

        return $sources;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function partnerSources(
        User $user,
        Business $business,
    ): array {
        if (! $this->authorize->decide(
            $user,
            $business,
            $business,
            new Capability(CapabilityCatalog::PARTNERS_VIEW),
        )->allowed) {
            return [];
        }

        return DB::table('partners')
            ->where('business_id', $business->getKey())
            ->orderBy('id')
            ->get([
                'id',
                'display_name',
                'legal_name',
                'email',
                'status',
                'notes',
                'revision',
                'created_at',
                'updated_at',
            ])
            ->map(function (object $row): array {
                $snapshot = [
                    'display_name' => (string) $row->display_name,
                    'legal_name' => $row->legal_name,
                    'email' => $row->email,
                    'status' => (string) $row->status,
                    'notes' => $row->notes,
                    'revision' => (int) $row->revision,
                    'created_at' => (string) $row->created_at,
                    'updated_at' => (string) $row->updated_at,
                ];

                return [
                    'category' => 'partners',
                    'source_kind' => 'partner_snapshot',
                    'source_id' => (string) $row->id,
                    'record_id' => (string) $row->id,
                    'version_id' => null,
                    'document_id' => null,
                    'source_hash' => $this->hash($snapshot),
                    'snapshot' => $snapshot,
                ];
            })
            ->all();
    }

    /**
     * @param  list<string>  $categories
     * @return list<string>
     */
    private function normalizeCategories(array $categories): array
    {
        $normalized = [];

        foreach ($categories as $category) {
            if (! is_string($category)) {
                throw new InvalidArgumentException(
                    'Portability categories must be strings.',
                );
            }

            $category = trim($category);

            if (
                $category === ''
                || $category === '*'
                || $category === 'all'
                || ! in_array(
                    $category,
                    self::SUPPORTED_CATEGORIES,
                    true,
                )
            ) {
                throw new InvalidArgumentException(
                    'Portability Export requires explicit supported categories.',
                );
            }

            if (! in_array($category, $normalized, true)) {
                $normalized[] = $category;
            }
        }

        if ($normalized === []) {
            throw new InvalidArgumentException(
                'At least one Portability Export category is required.',
            );
        }

        sort($normalized);

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hash(array $payload): string
    {
        return hash(
            'sha256',
            json_encode(
                $this->canonicalize($payload),
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION,
            ),
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->canonicalize($item),
                $value,
            );
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function transition(
        Business $business,
        string $exportId,
        ?BusinessExportStatus $from,
        BusinessExportStatus $to,
        ?string $membershipId,
    ): void {
        DB::table('business_portability_export_transitions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'business_portability_export_id' => $exportId,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_membership_id' => $membershipId,
            'occurred_at' => now(),
        ]);
    }

    /**
     * @param  array<string,bool|float|int|string|null>  $metadata
     */
    private function recordOccurrence(
        User $user,
        Business $business,
        string $action,
        string $exportId,
        array $metadata,
    ): void {
        $at = now();
        $correlationId = $this->occurrence->newCorrelationId();
        $actor = AuditActor::user((string) $user->getKey());
        $target = new OccurrenceTarget(
            (string) $business->getKey(),
            'business_portability_export',
            $exportId,
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
