<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

use InvalidArgumentException;

final class CsvImportParser implements ImportParser
{
    private const int MAX_ROWS = 5000;

    private const int MAX_COLUMNS = 100;

    public function identity(): string
    {
        return 'pbr.csv';
    }

    public function version(): string
    {
        return '1.0';
    }

    public function parse(string $source): array
    {
        $stream = fopen('php://temp', 'w+b');

        if (! is_resource($stream)) {
            throw new InvalidArgumentException(
                'CSV import stream could not be opened.',
            );
        }

        try {
            fwrite($stream, $source);
            rewind($stream);

            $header = fgetcsv($stream, escape: '');

            if (! is_array($header) || $header === []) {
                throw new InvalidArgumentException(
                    'CSV import requires a header row.',
                );
            }

            $header = array_map(
                static fn (mixed $value): string => trim((string) $value),
                $header,
            );

            if (isset($header[0])) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0])
                    ?? $header[0];
            }

            if (
                count($header) > self::MAX_COLUMNS
                || in_array('', $header, true)
                || count(array_unique($header)) !== count($header)
            ) {
                throw new InvalidArgumentException(
                    'CSV import headers must be unique, non-empty and within the supported column limit.',
                );
            }

            $rows = [];

            while (($values = fgetcsv($stream, escape: '')) !== false) {
                if (
                    count($values) === 1
                    && trim((string) ($values[0] ?? '')) === ''
                ) {
                    continue;
                }

                if (count($values) !== count($header)) {
                    throw new InvalidArgumentException(
                        'CSV import row column count does not match the header.',
                    );
                }

                $row = array_combine($header, $values);

                if ($row === false) {
                    throw new InvalidArgumentException(
                        'CSV import row could not be mapped to headers.',
                    );
                }

                $rows[] = $row;

                if (count($rows) > self::MAX_ROWS) {
                    throw new InvalidArgumentException(
                        'CSV import exceeds the supported row limit.',
                    );
                }
            }
        } finally {
            fclose($stream);
        }

        if ($rows === []) {
            throw new InvalidArgumentException(
                'CSV import contains no data rows.',
            );
        }

        return $rows;
    }
}
