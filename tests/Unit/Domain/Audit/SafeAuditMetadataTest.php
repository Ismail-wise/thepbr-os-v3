<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Audit;

use App\Domain\Audit\ValueObjects\SafeAuditMetadata;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SafeAuditMetadataTest extends TestCase
{
    public function test_safe_flat_metadata_is_preserved(): void
    {
        $metadata = SafeAuditMetadata::from([
            'version_number' => 2,
            'changed' => true,
            'state' => 'draft',
            'optional' => null,
        ]);

        $this->assertSame([
            'version_number' => 2,
            'changed' => true,
            'state' => 'draft',
            'optional' => null,
        ], $metadata->toArray());
    }

    #[DataProvider('unsafeMetadata')]
    public function test_unsafe_metadata_is_rejected(array $metadata): void
    {
        $this->expectException(InvalidArgumentException::class);

        SafeAuditMetadata::from($metadata);
    }

    public static function unsafeMetadata(): array
    {
        return [
            'secret-key' => [['api_secret' => 'value']],
            'token-key' => [['access_token' => 'value']],
            'nested-value' => [['context' => ['nested' => true]]],
            'invalid-key' => [['Bad Key' => 'value']],
            'oversized-value' => [['note' => str_repeat('a', 257)]],
        ];
    }
}
