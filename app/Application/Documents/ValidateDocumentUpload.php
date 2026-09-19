<?php

declare(strict_types=1);

namespace App\Application\Documents;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Symfony\Component\Process\Process;
use Throwable;

final class ValidateDocumentUpload
{
    private const int MAX_CONTENT_TYPES_BYTES = 262144;

    /**
     * @return array{
     *     original_filename:string,
     *     extension:string,
     *     mime_type:string,
     *     size_bytes:int,
     *     content_sha256:string,
     *     preview_safe:bool
     * }
     */
    public function execute(UploadedFile $file): array
    {
        $path = $file->getRealPath();

        if ($path === false || ! is_file($path)) {
            throw new InvalidArgumentException('Uploaded file is unavailable.');
        }

        $size = $file->getSize();

        if (
            $size === false
            || $size < 1
            || $size > (int) config('documents.max_bytes')
        ) {
            throw new InvalidArgumentException('Uploaded file size is not allowed.');
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $types = (array) config('documents.types');

        if (! array_key_exists($extension, $types)) {
            throw new InvalidArgumentException(
                'Uploaded file extension is not allowed.',
            );
        }

        $contract = (array) $types[$extension];
        $expectedMime = (string) $contract['mime'];
        $detectedMime = $this->detectMime($path);

        if (in_array($extension, ['docx', 'xlsx'], true)) {
            $this->assertOoxmlPackage(
                $path,
                (string) $contract['ooxml_root'],
                (string) $contract['ooxml_content_type'],
            );

            if (
                ! in_array(
                    $detectedMime,
                    [$expectedMime, 'application/zip'],
                    true,
                )
            ) {
                throw new InvalidArgumentException(
                    'Uploaded OOXML MIME type does not match its extension.',
                );
            }

            $detectedMime = $expectedMime;
        } elseif ($detectedMime !== $expectedMime) {
            throw new InvalidArgumentException(
                'Uploaded file MIME type does not match its extension.',
            );
        }

        $contentSha256 = hash_file('sha256', $path);

        if (
            ! is_string($contentSha256)
            || preg_match('/^[a-f0-9]{64}$/', $contentSha256) !== 1
        ) {
            throw new InvalidArgumentException(
                'Unable to calculate upload SHA-256.',
            );
        }

        return [
            'original_filename' => $file->getClientOriginalName(),
            'extension' => $extension,
            'mime_type' => $detectedMime,
            'size_bytes' => $size,
            'content_sha256' => $contentSha256,
            'preview_safe' => (bool) $contract['preview_safe'],
        ];
    }

    private function detectMime(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            throw new InvalidArgumentException(
                'Server MIME inspection is unavailable.',
            );
        }

        try {
            $mime = finfo_file($finfo, $path);
        } finally {
            finfo_close($finfo);
        }

        if (! is_string($mime) || $mime === '') {
            throw new InvalidArgumentException(
                'Unable to detect uploaded file MIME type.',
            );
        }

        return strtolower($mime);
    }

    private function assertOoxmlPackage(
        string $path,
        string $requiredRoot,
        string $requiredContentType,
    ): void {
        $listing = $this->runUnzip(
            ['-Z1', $path],
            1024 * 1024,
        );

        $entries = preg_split(
            '/\R/',
            trim($listing),
        );

        if (
            ! is_array($entries)
            || ! in_array(
                '[Content_Types].xml',
                $entries,
                true,
            )
            || ! in_array(
                $requiredRoot,
                $entries,
                true,
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid OOXML package structure.',
            );
        }

        $contentTypes = $this->runUnzip(
            [
                '-p',
                $path,
                '[[]Content_Types].xml',
            ],
            1024 * 1024,
        );

        if (
            ! str_contains(
                $contentTypes,
                $requiredContentType,
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid OOXML package content type.',
            );
        }
    }

    /**
     * @param  list<string>  $arguments
     */
    private function runUnzip(
        array $arguments,
        int $maxOutputBytes,
    ): string {
        $process = new Process(
            array_merge(
                ['/usr/bin/unzip'],
                $arguments,
            ),
        );

        $process->setTimeout(5.0);

        $output = '';

        try {
            $process->run(
                function (
                    string $type,
                    string $buffer,
                ) use (
                    &$output,
                    $maxOutputBytes,
                    $process,
                ): void {
                    if ($type !== Process::OUT) {
                        return;
                    }

                    $output .= $buffer;

                    if (
                        strlen($output)
                        > $maxOutputBytes
                    ) {
                        $process->stop(0.1);

                        throw new InvalidArgumentException(
                            'OOXML inspection output exceeds the safe limit.',
                        );
                    }
                },
            );
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                'Invalid OOXML package.',
                previous: $exception,
            );
        }

        if (! $process->isSuccessful()) {
            throw new InvalidArgumentException(
                'Invalid OOXML package.',
            );
        }

        return $output;
    }
}
