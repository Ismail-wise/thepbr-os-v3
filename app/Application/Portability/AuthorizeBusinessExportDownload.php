<?php

declare(strict_types=1);

namespace App\Application\Portability;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Application\Access\ResolveMembershipCapabilities;
use App\Application\Reporting\AuthorizeBusinessPackDownload;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Portability\Enums\BusinessExportStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Portability\BusinessPortabilityExport;
use App\Infrastructure\Storage\Documents\PrivateDocumentStorage;
use Illuminate\Support\Facades\DB;

final class AuthorizeBusinessExportDownload
{
    public function __construct(
        private readonly ResolveMembershipCapabilities $memberships,
        private readonly AuthorizeBusinessCapability $authorize,
        private readonly AuthorizeBusinessPackDownload $sourceAuthorization,
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
        $export = $this->authorizedExport(
            $user,
            $business,
            $exportId,
        );

        if ($export === null) {
            return null;
        }

        return [
            'stream' => $this->storage->read(
                (string) $export->storage_key,
            ),
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
        return $this->authorizedExport(
            $user,
            $business,
            $exportId,
        ) !== null;
    }

    /**
     * @param  array<string,mixed>  $source
     */
    public function sourceAuthorized(
        User $user,
        Business $business,
        array $source,
    ): bool {
        $kind = (string) ($source['source_kind'] ?? '');
        $sourceId = (string) ($source['source_id'] ?? '');
        $expectedHash = (string) ($source['source_hash'] ?? '');

        if ($sourceId === '' || $expectedHash === '') {
            return false;
        }

        if (
            $kind === 'document_version'
            && ! isset($source['container_id'])
            && isset($source['document_id'])
        ) {
            $source['container_id'] = $source['document_id'];
        }

        return match ($kind) {
            'business_snapshot' => $sourceId === (string) $business->getKey(),

            'formal_record_version',
            'document_version',
            'ownership_register_version' => $this->versionedSourceAuthorized(
                $user,
                $business,
                $source,
                $expectedHash,
            ),

            'partner_snapshot' => $this->partnerSourceAuthorized(
                $user,
                $business,
                $sourceId,
            ),

            default => false,
        };
    }

    private function authorizedExport(
        User $user,
        Business $business,
        string $exportId,
    ): ?BusinessPortabilityExport {
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
            new Capability(CapabilityCatalog::PORTABILITY_VIEW),
        )->allowed) {
            return null;
        }

        $export = BusinessPortabilityExport::query()
            ->where('business_id', $business->getKey())
            ->whereKey($exportId)
            ->first();

        if (
            $export === null
            || $export->status !== BusinessExportStatus::Available
            || (string) $export->requested_by_membership_id
                !== (string) $membership->getKey()
        ) {
            return null;
        }

        $manifest = $export->frozen_manifest;

        if (
            ! is_array($manifest)
            || ! hash_equals(
                (string) $export->manifest_hash,
                $this->hash($manifest),
            )
        ) {
            return null;
        }

        foreach ((array) ($manifest['sources'] ?? []) as $source) {
            if (
                ! is_array($source)
                || ! $this->sourceAuthorized(
                    $user,
                    $business,
                    $source,
                )
            ) {
                return null;
            }
        }

        return $export;
    }

    /**
     * @param  array<string,mixed>  $source
     */
    private function versionedSourceAuthorized(
        User $user,
        Business $business,
        array $source,
        string $expectedHash,
    ): bool {
        $current = $this->sourceAuthorization
            ->authorizedSourceHash(
                $user,
                $business,
                $source,
            );

        return $current !== null
            && hash_equals($expectedHash, $current);
    }

    private function partnerSourceAuthorized(
        User $user,
        Business $business,
        string $partnerId,
    ): bool {
        if (! $this->authorize->decide(
            $user,
            $business,
            $business,
            new Capability(CapabilityCatalog::PARTNERS_VIEW),
        )->allowed) {
            return false;
        }

        return DB::table('partners')
            ->where('business_id', $business->getKey())
            ->where('id', $partnerId)
            ->exists();
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
}
