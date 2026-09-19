<?php

namespace Tests\Feature\Documents;

use App\Application\Documents\ValidateDocumentUpload;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Tests\TestCase;

final class DocumentUploadValidationTest extends TestCase
{
    private const string DOCX_FIXTURE = 'UEsDBBQAAAAAAAAAIQCRp0i1AgEAAAIBAAATAAAAW0NvbnRlbnRfVHlwZXNdLnhtbDw/eG1sIHZlcnNpb249IjEuMCIgZW5jb2Rpbmc9IlVURi04Ij8+PFR5cGVzIHhtbG5zPSJodHRwOi8vc2NoZW1hcy5vcGVueG1sZm9ybWF0cy5vcmcvcGFja2FnZS8yMDA2L2NvbnRlbnQtdHlwZXMiPjxPdmVycmlkZSBQYXJ0TmFtZT0iL3dvcmQvZG9jdW1lbnQueG1sIiBDb250ZW50VHlwZT0iYXBwbGljYXRpb24vdm5kLm9wZW54bWxmb3JtYXRzLW9mZmljZWRvY3VtZW50LndvcmRwcm9jZXNzaW5nbWwuZG9jdW1lbnQubWFpbit4bWwiLz48L1R5cGVzPlBLAwQUAAAAAAAAACEA3VqGDQ0AAAANAAAAEQAAAHdvcmQvZG9jdW1lbnQueG1sPHc6ZG9jdW1lbnQvPlBLAQIUAxQAAAAAAAAAIQCRp0i1AgEAAAIBAAATAAAAAAAAAAAAAACAAQAAAABbQ29udGVudF9UeXBlc10ueG1sUEsBAhQDFAAAAAAAAAAhAN1ahg0NAAAADQAAABEAAAAAAAAAAAAAAIABMwEAAHdvcmQvZG9jdW1lbnQueG1sUEsFBgAAAAACAAIAgAAAAG8BAAAAAA==';

    private const string XLSX_FIXTURE = 'UEsDBBQAAAAAAAAAIQCsYzx/+gAAAPoAAAATAAAAW0NvbnRlbnRfVHlwZXNdLnhtbDw/eG1sIHZlcnNpb249IjEuMCIgZW5jb2Rpbmc9IlVURi04Ij8+PFR5cGVzIHhtbG5zPSJodHRwOi8vc2NoZW1hcy5vcGVueG1sZm9ybWF0cy5vcmcvcGFja2FnZS8yMDA2L2NvbnRlbnQtdHlwZXMiPjxPdmVycmlkZSBQYXJ0TmFtZT0iL3hsL3dvcmtib29rLnhtbCIgQ29udGVudFR5cGU9ImFwcGxpY2F0aW9uL3ZuZC5vcGVueG1sZm9ybWF0cy1vZmZpY2Vkb2N1bWVudC5zcHJlYWRzaGVldG1sLnNoZWV0Lm1haW4reG1sIi8+PC9UeXBlcz5QSwMEFAAAAAAAAAAhAM6emBMLAAAACwAAAA8AAAB4bC93b3JrYm9vay54bWw8d29ya2Jvb2svPlBLAQIUAxQAAAAAAAAAIQCsYzx/+gAAAPoAAAATAAAAAAAAAAAAAACAAQAAAABbQ29udGVudF9UeXBlc10ueG1sUEsBAhQDFAAAAAAAAAAhAM6emBMLAAAACwAAAA8AAAAAAAAAAAAAAIABKwEAAHhsL3dvcmtib29rLnhtbFBLBQYAAAAAAgACAH4AAABjAQAAAAA=';

    private const string GENERIC_ZIP_FIXTURE = 'UEsDBBQAAAAAAAAAIQCJyS4lCQAAAAkAAAALAAAAcGF5bG9hZC50eHRub3QgT09YTUxQSwECFAMUAAAAAAAAACEAickuJQkAAAAJAAAACwAAAAAAAAAAAAAAgAEAAAAAcGF5bG9hZC50eHRQSwUGAAAAAAEAAQA5AAAAMgAAAAAA';

    public function test_document_upload_contract_has_exact_size_and_type_boundaries(): void
    {
        $this->assertSame(
            25 * 1024 * 1024,
            config('documents.max_bytes'),
        );

        $types = config('documents.types');

        $this->assertIsArray($types);

        $extensions = array_keys($types);
        sort($extensions);

        $this->assertSame(
            ['docx', 'jpeg', 'jpg', 'pdf', 'png', 'xlsx'],
            $extensions,
        );

        $this->assertFalse(
            $types['docx']['preview_safe'],
        );

        $this->assertFalse(
            $types['xlsx']['preview_safe'],
        );

        $this->assertTrue(
            $types['pdf']['preview_safe'],
        );

        $this->assertTrue(
            $types['png']['preview_safe'],
        );

        $this->assertTrue(
            $types['jpg']['preview_safe'],
        );

        $this->assertTrue(
            $types['jpeg']['preview_safe'],
        );
    }

    public function test_valid_docx_and_xlsx_structures_are_accepted(): void
    {
        $cases = [
            [
                self::DOCX_FIXTURE,
                'document.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],
            [
                self::XLSX_FIXTURE,
                'workbook.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        ];

        foreach ($cases as [
            $fixture,
            $filename,
            $expectedMime,
        ]) {
            $path = $this->writeFixture($fixture);

            try {
                $file = new UploadedFile(
                    $path,
                    $filename,
                    null,
                    null,
                    true,
                );

                $metadata = app(
                    ValidateDocumentUpload::class,
                )->execute($file);

                $this->assertSame(
                    $filename,
                    $metadata['original_filename'],
                );

                $this->assertSame(
                    $expectedMime,
                    $metadata['mime_type'],
                );

                $this->assertSame(
                    filesize($path),
                    $metadata['size_bytes'],
                );

                $this->assertSame(
                    hash_file('sha256', $path),
                    $metadata['content_sha256'],
                );

                $this->assertFalse(
                    $metadata['preview_safe'],
                );
            } finally {
                @unlink($path);
            }
        }
    }

    public function test_generic_zip_disguised_as_docx_is_rejected(): void
    {
        $path = $this->writeFixture(
            self::GENERIC_ZIP_FIXTURE,
        );

        try {
            $file = new UploadedFile(
                $path,
                'fake.docx',
                null,
                null,
                true,
            );

            $this->expectException(
                InvalidArgumentException::class,
            );

            app(
                ValidateDocumentUpload::class,
            )->execute($file);
        } finally {
            @unlink($path);
        }
    }

    public function test_html_disguised_as_pdf_is_rejected(): void
    {
        $path = tempnam(
            sys_get_temp_dir(),
            'pbr-r4-html-',
        );

        $this->assertIsString($path);

        file_put_contents(
            $path,
            '<html><body>not a pdf</body></html>',
        );

        try {
            $file = new UploadedFile(
                $path,
                'fake.pdf',
                null,
                null,
                true,
            );

            $this->expectException(
                InvalidArgumentException::class,
            );

            app(
                ValidateDocumentUpload::class,
            )->execute($file);
        } finally {
            @unlink($path);
        }
    }

    public function test_exact_25_mib_pdf_boundary_is_accepted(): void
    {
        $path = $this->makePdfSized(
            25 * 1024 * 1024,
        );

        try {
            $file = new UploadedFile(
                $path,
                'boundary.pdf',
                null,
                null,
                true,
            );

            $metadata = app(
                ValidateDocumentUpload::class,
            )->execute($file);

            $this->assertSame(
                25 * 1024 * 1024,
                $metadata['size_bytes'],
            );

            $this->assertSame(
                'application/pdf',
                $metadata['mime_type'],
            );
        } finally {
            @unlink($path);
        }
    }

    public function test_25_mib_plus_one_byte_is_rejected(): void
    {
        $path = $this->makePdfSized(
            (25 * 1024 * 1024) + 1,
        );

        try {
            $file = new UploadedFile(
                $path,
                'too-large.pdf',
                null,
                null,
                true,
            );

            $this->expectException(
                InvalidArgumentException::class,
            );

            app(
                ValidateDocumentUpload::class,
            )->execute($file);
        } finally {
            @unlink($path);
        }
    }

    private function writeFixture(
        string $base64,
    ): string {
        $path = tempnam(
            sys_get_temp_dir(),
            'pbr-r4-ooxml-',
        );

        $this->assertIsString($path);

        $bytes = base64_decode(
            $base64,
            true,
        );

        $this->assertIsString($bytes);

        file_put_contents(
            $path,
            $bytes,
        );

        return $path;
    }

    private function makePdfSized(
        int $bytes,
    ): string {
        $path = tempnam(
            sys_get_temp_dir(),
            'pbr-r4-size-',
        );

        $this->assertIsString($path);

        $stream = fopen(
            $path,
            'w+b',
        );

        $this->assertIsResource($stream);

        fwrite(
            $stream,
            "%PDF-1.4\n",
        );

        $this->assertTrue(
            ftruncate(
                $stream,
                $bytes,
            ),
        );

        fclose($stream);

        return $path;
    }
}
