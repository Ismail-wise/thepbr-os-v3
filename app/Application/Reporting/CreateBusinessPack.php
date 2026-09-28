<?php

declare(strict_types=1);

namespace App\Application\Reporting;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Application\Access\ResolveMembershipCapabilities;
use App\Application\Documents\ListAuthorizedDocuments;
use App\Application\Events\RecordBusinessOccurrence;
use App\Application\Partnership\OwnershipWorkflow;
use App\Application\Records\ResolveCurrentEffectiveRecordVersion;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Audit\ValueObjects\AuditActor;
use App\Domain\Audit\ValueObjects\SafeAuditMetadata;
use App\Domain\Events\ValueObjects\OccurrenceTarget;
use App\Domain\Events\ValueObjects\SafeBusinessEventPayload;
use App\Domain\Identity\Enums\LanguageMode;
use App\Domain\Reporting\Enums\BusinessPackStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentVersion;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Reporting\BusinessPackExport;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CreateBusinessPack
{
    /** @var array<string,list<string>> */
    private const array RECORD_TYPES_BY_SCOPE = [
        'capital' => ['capital_plan'],
        'contributions' => ['partner_contribution'],
        'governance' => [
            'governance_charter',
            'formation_authority_policy',
        ],
        'operations' => ['operations_register'],
        'finance' => ['finance_policy', 'finance_payment'],
        'rewards' => ['reward_policy', 'distribution_run'],
        'risk' => ['risk_register'],
        'continuity' => ['continuity_plan'],
        'conflict' => [
            'conflict_resolution_policy',
            'conflict_settlement',
        ],
        'partner_changes' => ['partner_change'],
        'exit' => ['exit_case'],
        'closure' => ['closure_case'],
    ];

    /** @var list<string> */
    private const array DIRECT_SCOPES = [
        'ownership',
        'documents',
    ];

    public function __construct(
        private readonly ResolveMembershipCapabilities $memberships,
        private readonly AuthorizeBusinessCapability $authorize,
        private readonly ResolveCurrentEffectiveRecordVersion $effectiveRecords,
        private readonly ListAuthorizedDocuments $documents,
        private readonly OwnershipWorkflow $ownership,
        private readonly AuthorizeBusinessPackDownload $sourceAuthorization,
        private readonly RecordBusinessOccurrence $occurrence,
    ) {}

    /**
     * @param  list<string>  $requestedScope
     */
    public function execute(
        User $user,
        Business $business,
        array $requestedScope,
        LanguageMode $outputLanguage,
    ): ?BusinessPackExport {
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
                new Capability(CapabilityCatalog::REPORTS_MANAGE),
            )->allowed
        ) {
            return null;
        }

        $scope = $this->normalizeScope($requestedScope);
        $asOf = new DateTimeImmutable('now');

        return DB::transaction(function () use (
            $user,
            $business,
            $membership,
            $scope,
            $outputLanguage,
            $asOf,
        ): BusinessPackExport {
            $export = BusinessPackExport::query()->create([
                'business_id' => $business->getKey(),
                'requested_by_membership_id' => $membership->getKey(),
                'output_language' => $outputLanguage->value,
                'requested_scope' => $scope,
                'as_of_at' => $asOf,
                'frozen_manifest' => null,
                'manifest_hash' => null,
                'explicit_exclusions' => null,
                'status' => BusinessPackStatus::Requested,
            ]);

            $this->transition(
                $business,
                (string) $export->getKey(),
                null,
                BusinessPackStatus::Requested,
                (string) $membership->getKey(),
            );

            [$sources, $exclusions] = $this->buildAuthorizedSources(
                $user,
                $business,
                $scope,
                $asOf,
            );

            $manifest = [
                'schema_version' => 'pbr-business-pack-manifest-v1',
                'business' => [
                    'id' => (string) $business->getKey(),
                    'name' => (string) $business->name,
                    'base_currency' => (string) $business->base_currency,
                    'workspace_status' => $business->workspace_status->value,
                ],
                'requester' => [
                    'user_id' => (string) $user->getKey(),
                    'membership_id' => (string) $membership->getKey(),
                ],
                'output_language' => $outputLanguage->value,
                'requested_scope' => $scope,
                'as_of_at' => $asOf->format(DATE_ATOM),
                'sources' => $sources,
                'explicit_exclusions' => $exclusions,
            ];

            $manifestHash = $this->hash($manifest);

            $export->frozen_manifest = $manifest;
            $export->manifest_hash = $manifestHash;
            $export->explicit_exclusions = $exclusions;
            $export->status = BusinessPackStatus::ManifestFrozen;
            $export->save();

            $this->transition(
                $business,
                (string) $export->getKey(),
                BusinessPackStatus::Requested,
                BusinessPackStatus::ManifestFrozen,
                (string) $membership->getKey(),
            );

            $this->recordOccurrence(
                $user,
                $business,
                'reporting.business_pack.manifest_frozen',
                (string) $export->getKey(),
                [
                    'output_language' => $outputLanguage->value,
                    'scope_count' => count($scope),
                    'source_count' => count($sources),
                    'manifest_hash' => $manifestHash,
                ],
            );

            return $export->fresh();
        });
    }

    /**
     * @param  list<string>  $scope
     * @return array{0:list<array<string,mixed>>,1:list<array<string,string>>}
     */
    private function buildAuthorizedSources(
        User $user,
        Business $business,
        array $scope,
        DateTimeImmutable $asOf,
    ): array {
        $sources = [];
        $exclusions = [];

        foreach ($scope as $scopeKey) {
            $before = count($sources);

            if ($scopeKey === 'documents') {
                array_push(
                    $sources,
                    ...$this->documentSources(
                        $user,
                        $business,
                        $asOf,
                    ),
                );
            } elseif ($scopeKey === 'ownership') {
                $ownershipSource = $this->ownershipSource(
                    $user,
                    $business,
                    $asOf,
                );

                if ($ownershipSource !== null) {
                    $sources[] = $ownershipSource;
                }
            } else {
                array_push(
                    $sources,
                    ...$this->formalRecordSources(
                        $user,
                        $business,
                        $scopeKey,
                    ),
                );
            }

            if (count($sources) === $before) {
                $exclusions[] = [
                    'scope' => $scopeKey,
                    'reason' => 'no_authorized_source_included',
                ];
            }
        }

        usort(
            $sources,
            static fn (array $left, array $right): int => [$left['scope'], $left['source_kind'], $left['source_id']]
                <=>
                [$right['scope'], $right['source_kind'], $right['source_id']],
        );

        return [$sources, $exclusions];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function formalRecordSources(
        User $user,
        Business $business,
        string $scope,
    ): array {
        $recordTypes = self::RECORD_TYPES_BY_SCOPE[$scope] ?? [];

        if ($recordTypes === []) {
            return [];
        }

        $families = FormalRecordFamily::query()
            ->where('business_id', $business->getKey())
            ->whereIn('record_type', $recordTypes)
            ->orderBy('record_type')
            ->orderBy('id')
            ->get();

        $sources = [];

        foreach ($families as $family) {
            $version = $this->effectiveRecords->execute(
                $user,
                $business,
                new Capability(CapabilityCatalog::RECORDS_VIEW),
                (string) $family->getKey(),
            );

            if ($version === null) {
                continue;
            }

            $candidate = [
                'scope' => $scope,
                'source_kind' => 'formal_record_version',
                'source_id' => (string) $version->getKey(),
                'container_id' => (string) $family->getKey(),
                'record_type' => (string) $family->record_type,
                'subject_type' => (string) $family->subject_type,
                'subject_id' => (string) $family->subject_id,
                'version_number' => (int) $version->version_number,
                'source_hash' => (string) $version->content_hash,
                'effective_from' => $version->effective_from?->format(
                    DATE_ATOM,
                ),
                'effective_until' => $version->effective_until?->format(
                    DATE_ATOM,
                ),
                'label' => (string) $family->record_type,
                'summary' => $version->change_summary,
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
                    (string) $candidate['source_hash'],
                    $authorizedHash,
                )
            ) {
                continue;
            }

            $sources[] = $candidate;
        }

        return $sources;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function documentSources(
        User $user,
        Business $business,
        DateTimeImmutable $asOf,
    ): array {
        $documents = $this->documents->execute($user, $business);

        if ($documents === null) {
            return [];
        }

        $sources = [];

        foreach ($documents as $document) {
            $version = DocumentVersion::query()
                ->where('business_id', $business->getKey())
                ->where('document_id', $document->getKey())
                ->where(function ($query) use ($asOf): void {
                    $query
                        ->whereNull('effective_from')
                        ->orWhere('effective_from', '<=', $asOf);
                })
                ->orderByDesc('version_number')
                ->first();

            if ($version === null) {
                continue;
            }

            $candidate = [
                'scope' => 'documents',
                'source_kind' => 'document_version',
                'source_id' => (string) $version->getKey(),
                'container_id' => (string) $document->getKey(),
                'version_number' => (int) $version->version_number,
                'source_hash' => (string) $version->content_sha256,
                'effective_from' => $version->effective_from?->format(
                    DATE_ATOM,
                ),
                'effective_until' => null,
                'label' => (string) $document->title,
                'summary' => (string) $version->original_filename,
                'mime_type' => (string) $version->mime_type,
                'size_bytes' => (int) $version->size_bytes,
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
                    (string) $candidate['source_hash'],
                    $authorizedHash,
                )
            ) {
                continue;
            }

            $sources[] = $candidate;
        }

        return $sources;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function ownershipSource(
        User $user,
        Business $business,
        DateTimeImmutable $asOf,
    ): ?array {
        $version = $this->ownership->currentEffectiveRegisterVersion(
            $user,
            $business,
            $asOf,
        );

        if ($version === null) {
            return null;
        }

        $candidate = [
            'scope' => 'ownership',
            'source_kind' => 'ownership_register_version',
            'source_id' => (string) $version->id,
            'container_id' => (string) $version->ownership_register_id,
            'version_number' => (int) $version->version_number,
            'source_hash' => '',
            'effective_from' => $version->effective_from === null
                ? null
                : (new DateTimeImmutable(
                    (string) $version->effective_from,
                ))->format(DATE_ATOM),
            'effective_until' => $version->effective_until === null
                ? null
                : (new DateTimeImmutable(
                    (string) $version->effective_until,
                ))->format(DATE_ATOM),
            'label' => 'ownership_register',
            'summary' => sprintf(
                '%s · issued shares %s',
                (string) $version->currency,
                (string) $version->issued_shares,
            ),
        ];

        $hash = $this->sourceAuthorization->authorizedSourceHash(
            $user,
            $business,
            $candidate,
        );

        if ($hash === null) {
            return null;
        }

        $candidate['source_hash'] = $hash;

        return $candidate;
    }

    /**
     * @param  list<string>  $scope
     * @return list<string>
     */
    private function normalizeScope(array $scope): array
    {
        $allowed = [
            ...array_keys(self::RECORD_TYPES_BY_SCOPE),
            ...self::DIRECT_SCOPES,
        ];

        $normalized = [];

        foreach ($scope as $value) {
            if (! is_string($value)) {
                throw new InvalidArgumentException(
                    'Business Pack scope values must be strings.',
                );
            }

            $key = trim($value);

            if (
                $key === ''
                || $key === 'all'
                || $key === '*'
                || ! in_array($key, $allowed, true)
            ) {
                throw new InvalidArgumentException(
                    'Business Pack scope must use explicit supported categories.',
                );
            }

            if (! in_array($key, $normalized, true)) {
                $normalized[] = $key;
            }
        }

        if ($normalized === []) {
            throw new InvalidArgumentException(
                'At least one explicit Business Pack scope is required.',
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
        ?BusinessPackStatus $from,
        BusinessPackStatus $to,
        ?string $membershipId,
    ): void {
        DB::table('business_pack_export_transitions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'business_pack_export_id' => $exportId,
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
            'business_pack_export',
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
