<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Domain\Documents\Enums\DocumentAccessRight;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentVersion;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Storage\Documents\PrivateDocumentStorage;

final class DownloadDocumentVersion
{
    public function __construct(
        private readonly GetAuthorizedDocument $documents,
        private readonly PrivateDocumentStorage $storage,
    ) {}

    /**
     * @return array{
     *     stream:mixed,
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
        string $documentVersionId,
    ): ?array {
        if (
            $this->documents->execute(
                $user,
                $currentBusiness,
                $documentId,
                DocumentAccessRight::View,
                'records.view',
            ) === null
        ) {
            return null;
        }

        $version = DocumentVersion::query()
            ->where('business_id', $currentBusiness->getKey())
            ->where('document_id', $documentId)
            ->whereKey($documentVersionId)
            ->first();

        if ($version === null) {
            return null;
        }

        return [
            'stream' => $this->storage->read(
                (string) $version->storage_key,
            ),
            'filename' => (string) $version->original_filename,
            'mime_type' => (string) $version->mime_type,
            'size_bytes' => (int) $version->size_bytes,
            'content_sha256' => (string) $version->content_sha256,
        ];
    }
}
