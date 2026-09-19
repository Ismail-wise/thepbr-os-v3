<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage\Documents;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class PrivateDocumentStorage
{
    private const string DISK = 'business_documents';

    public function newOpaqueKey(
        string $businessId,
        string $documentId,
        string $documentVersionId,
    ): string {
        return implode('/', [
            'businesses',
            $businessId,
            'documents',
            $documentId,
            'versions',
            $documentVersionId,
            (string) Str::uuid7(),
        ]);
    }

    public function write(string $storageKey, mixed $stream): void
    {
        if (! is_resource($stream)) {
            throw new InvalidArgumentException(
                'Private document storage requires a readable stream.',
            );
        }

        if (Storage::disk(self::DISK)->writeStream($storageKey, $stream) !== true) {
            throw new RuntimeException('Private document object write failed.');
        }
    }

    public function read(string $storageKey): mixed
    {
        $stream = Storage::disk(self::DISK)->readStream($storageKey);

        if (! is_resource($stream)) {
            throw new RuntimeException('Private document object read failed.');
        }

        return $stream;
    }

    public function deleteExact(string $storageKey): void
    {
        if ($storageKey === '' || str_ends_with($storageKey, '/')) {
            throw new InvalidArgumentException(
                'An exact private document object key is required.',
            );
        }

        if (! Storage::disk(self::DISK)->delete($storageKey)) {
            throw new RuntimeException('Private document orphan cleanup failed.');
        }
    }
}
