<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Events\RecordBusinessOccurrence;
use App\Domain\Audit\ValueObjects\AuditActor;
use App\Domain\Audit\ValueObjects\SafeAuditMetadata;
use App\Domain\Documents\Enums\DocumentAccessRight;
use App\Domain\Documents\Enums\DocumentCategory;
use App\Domain\Events\ValueObjects\OccurrenceTarget;
use App\Domain\Events\ValueObjects\SafeBusinessEventPayload;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Documents\Document;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentAccessGrant;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentVersion;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Storage\Documents\PrivateDocumentStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class UploadDocument
{
    public function __construct(
        private readonly ValidateDocumentUpload $validation,
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
        string $title,
        DocumentCategory $category,
        UploadedFile $file,
    ): ?array {
        if (trim($title) === '') {
            throw new \InvalidArgumentException(
                'Document title is required.',
            );
        }

        if (
            $this->authorization->activeMembershipWithCapability(
                $user,
                $currentBusiness,
                'records.manage',
            ) === null
        ) {
            return null;
        }

        $metadata = $this->validation->execute($file);
        $businessId = (string) $currentBusiness->getKey();
        $documentId = (string) Str::uuid7();
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
            $committed = DB::transaction(
                function () use (
                    $user,
                    $currentBusiness,
                    $title,
                    $category,
                    $metadata,
                    $businessId,
                    $documentId,
                    $documentVersionId,
                    $storageKey,
                ): bool {
                    $membership = $this->authorization
                        ->activeMembershipWithCapability(
                            $user,
                            $currentBusiness,
                            'records.manage',
                        );

                    if ($membership === null) {
                        return false;
                    }

                    $document = Document::query()->create([
                        'id' => $documentId,
                        'business_id' => $businessId,
                        'title' => $title,
                        'category' => $category,
                        'created_by_membership_id' => $membership->getKey(),
                    ]);

                    DocumentVersion::query()->create([
                        'id' => $documentVersionId,
                        'business_id' => $businessId,
                        'document_id' => $document->getKey(),
                        'version_number' => 1,
                        'original_filename' => $metadata['original_filename'],
                        'storage_key' => $storageKey,
                        'size_bytes' => $metadata['size_bytes'],
                        'mime_type' => $metadata['mime_type'],
                        'content_sha256' => $metadata['content_sha256'],
                        'uploaded_by_membership_id' => $membership->getKey(),
                        'effective_from' => null,
                        'supersedes_document_version_id' => null,
                    ]);

                    foreach (
                        [
                            DocumentAccessRight::View,
                            DocumentAccessRight::Manage,
                        ] as $right
                    ) {
                        $grant = DocumentAccessGrant::query()->create([
                            'business_id' => $businessId,
                            'membership_id' => $membership->getKey(),
                            'document_id' => $document->getKey(),
                            'right' => $right,
                            'effect' => 'allow',
                        ]);

                        $this->appendAudit(
                            $user,
                            $currentBusiness,
                            'document.access_grant_created',
                            'document_access_grant',
                            (string) $grant->getKey(),
                            null,
                            [
                                'document_id' => $documentId,
                                'right' => $right->value,
                                'effect' => 'allow',
                            ],
                        );
                    }

                    $this->appendAudit(
                        $user,
                        $currentBusiness,
                        'document.created',
                        'document',
                        $documentId,
                        $documentVersionId,
                        ['category' => $category->value],
                    );

                    $this->appendAudit(
                        $user,
                        $currentBusiness,
                        'document.version_created',
                        'document',
                        $documentId,
                        $documentVersionId,
                        [
                            'version_number' => 1,
                            'content_sha256' => $metadata['content_sha256'],
                        ],
                    );

                    $this->appendBusinessEvent(
                        $user,
                        $currentBusiness,
                        'document.created',
                        'document',
                        $documentId,
                        $documentVersionId,
                        'document',
                        $documentId,
                        [
                            'category' => $category->value,
                            'version_number' => 1,
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
                        ['version_number' => 1],
                    );

                    return true;
                },
            );
        } catch (Throwable $exception) {
            $this->cleanupExactOrphan($storageKey, $exception);

            throw $exception;
        }

        if (! $committed) {
            $this->cleanupExactOrphan($storageKey);

            return null;
        }

        return [
            'document_id' => $documentId,
            'document_version_id' => $documentVersionId,
            'version_number' => 1,
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
                'Document transaction failed and exact orphan cleanup failed.',
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
