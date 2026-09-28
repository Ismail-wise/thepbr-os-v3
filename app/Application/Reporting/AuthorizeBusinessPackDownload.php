<?php

declare(strict_types=1);

namespace App\Application\Reporting;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Application\Access\ResolveMembershipCapabilities;
use App\Application\Conflict\ConflictRecordVisibility;
use App\Application\Documents\GetAuthorizedDocument;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Documents\Enums\DocumentAccessRight;
use App\Domain\Reporting\Enums\BusinessPackStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentVersion;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Reporting\BusinessPackExport;
use App\Infrastructure\Storage\Documents\PrivateDocumentStorage;
use Illuminate\Support\Facades\DB;

final class AuthorizeBusinessPackDownload
{
    public function __construct(
        private readonly ResolveMembershipCapabilities $memberships,
        private readonly AuthorizeBusinessCapability $authorize,
        private readonly GetAuthorizedDocument $documents,
        private readonly ConflictRecordVisibility $conflictVisibility,
        private readonly PrivateDocumentStorage $storage,
    ) {}

    /**
     * @return array{
     *   stream:mixed,
     *   filename:string,
     *   mime_type:string,
     *   size_bytes:int,
     *   content_sha256:string
     * }|null
     */
    public function execute(
        User $user,
        Business $business,
        string $exportId,
    ): ?array {
        $export = $this->authorizedExport($user, $business, $exportId);

        if ($export === null) {
            return null;
        }

        return [
            'stream' => $this->storage->read((string) $export->storage_key),
            'filename' => (string) $export->output_filename,
            'mime_type' => (string) $export->mime_type,
            'size_bytes' => (int) $export->size_bytes,
            'content_sha256' => (string) $export->content_sha256,
        ];
    }

    public function canDownload(
        User $user,
        Business $business,
        string $exportId,
    ): bool {
        return $this->authorizedExport($user, $business, $exportId) !== null;
    }

    /**
     * Returns the current source hash only when the exact source remains
     * authorized to this User in this Business.
     *
     * @param  array<string,mixed>  $source
     */
    public function authorizedSourceHash(
        User $user,
        Business $business,
        array $source,
    ): ?string {
        $kind = (string) ($source['source_kind'] ?? '');
        $sourceId = (string) ($source['source_id'] ?? '');

        if ($sourceId === '') {
            return null;
        }

        return match ($kind) {
            'formal_record_version' => $this->formalRecordHash(
                $user,
                $business,
                $sourceId,
            ),
            'document_version' => $this->documentHash(
                $user,
                $business,
                $source,
            ),
            'ownership_register_version' => $this->ownershipHash(
                $user,
                $business,
                $sourceId,
            ),
            default => null,
        };
    }

    private function authorizedExport(
        User $user,
        Business $business,
        string $exportId,
    ): ?BusinessPackExport {
        $membership = $this->memberships->activeMembership(
            $user,
            $business,
        );

        if ($membership === null) {
            return null;
        }

        if (! $this->authorize->decide(
            $user,
            $business,
            $business,
            new Capability(CapabilityCatalog::REPORTS_VIEW),
        )->allowed) {
            return null;
        }

        $export = BusinessPackExport::query()
            ->where('business_id', $business->getKey())
            ->whereKey($exportId)
            ->first();

        if (
            $export === null
            || $export->status !== BusinessPackStatus::Available
            || (string) $export->requested_by_membership_id
                !== (string) $membership->getKey()
        ) {
            return null;
        }

        $manifest = $export->frozen_manifest;

        if (! is_array($manifest)) {
            return null;
        }

        foreach ((array) ($manifest['sources'] ?? []) as $source) {
            if (! is_array($source)) {
                return null;
            }

            $expected = (string) ($source['source_hash'] ?? '');
            $current = $this->authorizedSourceHash(
                $user,
                $business,
                $source,
            );

            if (
                $expected === ''
                || $current === null
                || ! hash_equals($expected, $current)
            ) {
                return null;
            }
        }

        return $export;
    }

    private function formalRecordHash(
        User $user,
        Business $business,
        string $versionId,
    ): ?string {
        $version = FormalRecordVersion::query()
            ->where('business_id', $business->getKey())
            ->whereKey($versionId)
            ->first();

        if ($version === null) {
            return null;
        }

        $family = DB::table('formal_record_families')
            ->where('business_id', $business->getKey())
            ->where('id', $version->formal_record_family_id)
            ->first(['record_type', 'subject_type', 'subject_id']);

        if ($family === null) {
            return null;
        }

        if (! $this->authorize->decide(
            $user,
            $business,
            $business,
            new Capability(CapabilityCatalog::RECORDS_VIEW),
            FormalRecordVersion::class,
            $versionId,
        )->allowed) {
            return null;
        }

        $recordType = (string) $family->record_type;
        $capability = $this->capabilityForRecordType($recordType);

        if (
            $capability !== null
            && ! $this->authorize->decide(
                $user,
                $business,
                $business,
                new Capability($capability),
            )->allowed
        ) {
            return null;
        }

        if (
            $recordType === 'conflict_settlement'
            && (
                (string) $family->subject_type !== 'conflict_case'
                || ! $this->conflictVisibility->canView(
                    $user,
                    $business,
                    (string) $family->subject_id,
                )
            )
        ) {
            return null;
        }

        return (string) $version->content_hash;
    }

    /**
     * @param  array<string,mixed>  $source
     */
    private function documentHash(
        User $user,
        Business $business,
        array $source,
    ): ?string {
        $documentId = (string) ($source['container_id'] ?? '');
        $versionId = (string) ($source['source_id'] ?? '');

        if ($documentId === '' || $versionId === '') {
            return null;
        }

        if ($this->documents->execute(
            $user,
            $business,
            $documentId,
            DocumentAccessRight::View,
            CapabilityCatalog::RECORDS_VIEW,
        ) === null) {
            return null;
        }

        $version = DocumentVersion::query()
            ->where('business_id', $business->getKey())
            ->where('document_id', $documentId)
            ->whereKey($versionId)
            ->first();

        return $version === null
            ? null
            : (string) $version->content_sha256;
    }

    private function ownershipHash(
        User $user,
        Business $business,
        string $versionId,
    ): ?string {
        if (! $this->authorize->decide(
            $user,
            $business,
            $business,
            new Capability(CapabilityCatalog::OWNERSHIP_VIEW),
        )->allowed) {
            return null;
        }

        $version = DB::table('ownership_register_versions')
            ->where('business_id', $business->getKey())
            ->where('id', $versionId)
            ->first();

        if ($version === null) {
            return null;
        }

        $classes = DB::table('ownership_register_share_classes')
            ->where('business_id', $business->getKey())
            ->where('ownership_register_version_id', $versionId)
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        $positions = DB::table('ownership_register_positions')
            ->where('business_id', $business->getKey())
            ->where('ownership_register_version_id', $versionId)
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        $payload = [
            'version' => (array) $version,
            'share_classes' => $classes,
            'positions' => $positions,
        ];

        return hash(
            'sha256',
            json_encode(
                $payload,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION,
            ),
        );
    }

    private function capabilityForRecordType(
        string $recordType,
    ): ?string {
        return match ($recordType) {
            'capital_plan' => CapabilityCatalog::CAPITAL_VIEW,
            'partner_contribution' => CapabilityCatalog::CONTRIBUTIONS_VIEW,
            'ownership_register' => CapabilityCatalog::OWNERSHIP_VIEW,
            'governance_charter',
            'formation_authority_policy' => CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
            'operations_register' => CapabilityCatalog::OPERATIONS_VIEW,
            'finance_policy',
            'finance_payment' => CapabilityCatalog::FINANCE_VIEW,
            'reward_policy',
            'distribution_run' => CapabilityCatalog::REWARDS_VIEW,
            'risk_register' => CapabilityCatalog::RISK_VIEW,
            'continuity_plan' => CapabilityCatalog::CONTINUITY_VIEW,
            'conflict_resolution_policy',
            'conflict_settlement' => CapabilityCatalog::CONFLICT_VIEW,
            'partner_change' => CapabilityCatalog::PARTNER_CHANGES_VIEW,
            'exit_case' => CapabilityCatalog::EXIT_VIEW,
            'closure_case' => CapabilityCatalog::CLOSURE_VIEW,
            default => null,
        };
    }
}
