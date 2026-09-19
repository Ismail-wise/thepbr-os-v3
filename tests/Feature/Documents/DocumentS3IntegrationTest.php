<?php

namespace Tests\Feature\Documents;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentS3IntegrationTest extends TestCase
{
    public function test_business_documents_disk_round_trips_private_object_storage(): void
    {
        $integrationEnabled = filter_var(
            env('PBR_DOCUMENTS_S3_INTEGRATION', false),
            FILTER_VALIDATE_BOOL,
        );

        if (! $integrationEnabled) {
            $runningInCi = filter_var(
                env('CI', false),
                FILTER_VALIDATE_BOOL,
            );

            if ($runningInCi) {
                $this->fail(
                    'PBR_DOCUMENTS_S3_INTEGRATION must be enabled in CI.',
                );
            }

            $this->markTestSkipped(
                'Private S3 integration is enabled only for explicit integration runs.',
            );
        }

        $diskConfig = config('filesystems.disks.business_documents');

        $this->assertIsArray($diskConfig);
        $this->assertSame('s3', $diskConfig['driver'] ?? null);
        $this->assertTrue($diskConfig['throw'] ?? false);
        $this->assertFalse($diskConfig['report'] ?? true);

        $this->assertTrue(
            $diskConfig['use_path_style_endpoint'] ?? false,
        );

        $this->assertNotEmpty($diskConfig['key'] ?? null);
        $this->assertNotEmpty($diskConfig['secret'] ?? null);
        $this->assertNotEmpty($diskConfig['region'] ?? null);
        $this->assertNotEmpty($diskConfig['bucket'] ?? null);
        $this->assertNotEmpty($diskConfig['endpoint'] ?? null);

        $this->assertArrayNotHasKey('url', $diskConfig);
        $this->assertArrayNotHasKey('visibility', $diskConfig);

        $disk = Storage::disk('business_documents');

        $key = sprintf(
            '__r4_w1_5_integration__/%s.bin',
            bin2hex(random_bytes(16)),
        );

        $payload = random_bytes(4096);

        try {
            $this->assertTrue($disk->put($key, $payload));
            $this->assertTrue($disk->exists($key));
            $this->assertSame($payload, $disk->get($key));
        } finally {
            if ($disk->exists($key)) {
                $this->assertTrue($disk->delete($key));
            }
        }

        $this->assertFalse($disk->exists($key));
    }
}
