<?php

declare(strict_types=1);

namespace App\Infrastructure\Portability;

use App\Infrastructure\Persistence\Eloquent\Portability\BusinessPortabilityExport;
use RuntimeException;

final class BusinessExportRenderer
{
    /**
     * @return array{
     *   content:string,
     *   filename:string,
     *   mime_type:string,
     *   size_bytes:int,
     *   content_sha256:string
     * }
     */
    public function render(BusinessPortabilityExport $export): array
    {
        $manifest = $export->frozen_manifest;

        if (! is_array($manifest)) {
            throw new RuntimeException(
                'Business Portability Export cannot render without a frozen manifest.',
            );
        }

        $payload = [
            'schema_version' => 'pbr-business-portability-package-v1',
            'business_id' => (string) $export->business_id,
            'export_id' => (string) $export->getKey(),
            'manifest_hash' => (string) $export->manifest_hash,
            'representation_notice' => 'Generated representation only. Canonical truth remains in thePBR OS structured records.',
            'manifest' => $manifest,
        ];

        $content = json_encode(
            $payload,
            JSON_THROW_ON_ERROR
            | JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION,
        ).PHP_EOL;

        $filename = 'pbr-business-export-'
            .(string) $export->business_id
            .'-'
            .(string) $export->getKey()
            .'.json';

        return [
            'content' => $content,
            'filename' => $filename,
            'mime_type' => 'application/json; charset=UTF-8',
            'size_bytes' => strlen($content),
            'content_sha256' => hash('sha256', $content),
        ];
    }
}
