<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Events\RecordBusinessOccurrence;
use App\Domain\Audit\ValueObjects\AuditActor;
use App\Domain\Audit\ValueObjects\SafeAuditMetadata;
use App\Domain\Documents\Enums\DocumentAccessRight;
use App\Domain\Events\ValueObjects\OccurrenceTarget;
use App\Domain\Events\ValueObjects\SafeBusinessEventPayload;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Documents\Document;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentVersion;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Storage\Documents\PrivateDocumentStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class UploadDocumentVersion
{
    public function __construct(
        private readonly ValidateDocumentUpload $validation,
        private readonly GetAuthorizedDocument $documents,
        private readonly AuthorizeDocumentAccess $authorization,
        private readonly PrivateDocumentStorage $storage,
        private readonly RecordBusinessOccurrence $recordBusinessOccurrence,
    ) {}

    /**
     * @return array{
     *     document_id:string,
     *     document_version_id:string,
     *     version_number:int,
     *     filename:string,
     *     mime_type:string,
     *     size_bytes:int,
     *     content_sha256:string
     * }|null
     */
    public function execute(
        User $user,
        Business $currentBusiness,
        string $documentId,
        UploadedFile $file,
    ): ?array {
        if (
            $this->documents->execute(
                $user,
                $currentBusiness,
                $documentId,
                DocumentAccessRight::Manage,
                'records.manage',
            ) === null
        ) {
            return null;
        }

        $metadata = $this->validation->execute($file);
        $businessId = (string) $currentBusiness->getKey();
        $documentVersionId = (string) Str::uuid7();

        $storageKey = $this->storage->newOpaqueKey(
            $businessId,
            $documentId,
            $documentVersionId,
        );

        $path = $file->getRealPath();
        $stream = $path === false ? false : fopen($path, 'rb');

        if (! is_resource($stream)) {
            throw new RuntimeException(
                'Unable to open validated document stream.',
            );
        }

        try {
            $this->storage->write($storageKey, $stream);
        } finally {
            fclose($stream);
        }

        try {
            $versionNumber = DB::transaction(
                function () use (
                    $user,
                    $currentBusiness,
                    $businessId,
                    $documentId,
                    $documentVersionId,
                    $metadata,
                    $storageKey,
                ): ?int {
                    $document = Document::query()
                        ->where('business_id', $businessId)
                        ->whereKey($documentId)
                        ->lockForUpdate()
                        ->first();

                    if ($document === null) {
                        return null;
                    }

                    $membership = $this->authorization->allows(
                        $user,
                        $currentBusiness,
                        $document,
                        DocumentAccessRight::Manage,
                        'records.manage',
                    );

                    if ($membership === null) {
                        return null;
                    }

                    $latest = DocumentVersion::query()
                        ->where('business_id', $businessId)
                        ->where('document_id', $documentId)
                        ->orderByDesc('version_number')
                        ->first();

                    $nextVersionNumber = $latest === null
                        ? 1
                        : ((int) $latest->version_number + 1);

                    DocumentVersion::query()->create([
                        'id' => $documentVersionId,
                        'business_id' => $businessId,
                        'document_id' => $documentId,
                        'version_number' => $nextVersionNumber,
                        'original_filename' => $metadata['original_filename'],
                        'storage_key' => $storageKey,
                        'size_bytes' => $metadata['size_bytes'],
                        'mime_type' => $metadata['mime_type'],
                        'content_sha256' => $metadata['content_sha256'],
                        'uploaded_by_membership_id' => $membership->getKey(),
                        'effective_from' => null,
                        'supersedes_document_version_id' => $latest?->getKey(),
                    ]);

                    $this->appendAudit(
                        $user,
                        $currentBusiness,
                        'document.version_created',
                        'document',
                        $documentId,
                        $documentVersionId,
                        [
                            'version_number' => $nextVersionNumber,
                            'supersedes_document_version_id' => $latest?->getKey(),
                            'content_sha256' => $metadata['content_sha256'],
                        ],
                    );

                    $this->appendBusinessEvent(
                        $user,
                        $currentBusiness,
                        'document.version_created',
                        'document',
                        $documentId,
                        $documentVersionId,
                        'document',
                        $documentId,
                        ['version_number' => $nextVersionNumber],
                    );

                    return $nextVersionNumber;
                },
            );
        } catch (Throwable $exception) {
            $this->cleanupExactOrphan($storageKey, $exception);

            throw $exception;
        }

        if ($versionNumber === null) {
            $this->cleanupExactOrphan($storageKey);

            return null;
        }

        return [
            'document_id' => $documentId,
            'document_version_id' => $documentVersionId,
            'version_number' => $versionNumber,
            'filename' => $metadata['original_filename'],
            'mime_type' => $metadata['mime_type'],
            'size_bytes' => $metadata['size_bytes'],
            'content_sha256' => $metadata['content_sha256'],
        ];
    }

    private function cleanupExactOrphan(
        string $storageKey,
        ?Throwable $original = null,
    ): void {
        try {
            $this->storage->deleteExact($storageKey);
        } catch (Throwable $cleanupFailure) {
            throw new RuntimeException(
                'Document version transaction failed and exact orphan cleanup failed.',
                previous: $original ?? $cleanupFailure,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function appendAudit(
        User $user,
        Business $business,
        string $action,
        string $targetType,
        string $targetId,
        ?string $targetVersionId,
        array $metadata,
    ): void {
        $this->recordBusinessOccurrence->audit(
            $business,
            AuditActor::user((string) $user->getKey()),
            $action,
            new OccurrenceTarget(
                (string) $business->getKey(),
                $targetType,
                $targetId,
                $targetVersionId,
            ),
            SafeAuditMetadata::from($metadata),
            now(),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function appendBusinessEvent(
        User $user,
        Business $business,
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        ?string $aggregateVersionId,
        string $visibilityResourceType,
        string $visibilityResourceId,
        array $payload,
    ): void {
        $this->recordBusinessOccurrence->businessEvent(
            $business,
            $eventType,
            new OccurrenceTarget(
                (string) $business->getKey(),
                $aggregateType,
                $aggregateId,
                $aggregateVersionId,
            ),
            new OccurrenceTarget(
                (string) $business->getKey(),
                $visibilityResourceType,
                $visibilityResourceId,
            ),
            SafeBusinessEventPayload::from($payload),
            now(),
            AuditActor::user((string) $user->getKey()),
        );
    }
}
