<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

use InvalidArgumentException;
use JsonException;

final class JsonImportParser implements ImportParser
{
    private const int MAX_ROWS = 5000;

    public function identity(): string
    {
        return 'pbr.json';
    }

    public function version(): string
    {
        return '1.0';
    }

    public function parse(string $source): array
    {
        try {
            $decoded = json_decode(
                $source,
                true,
                128,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'JSON import is not valid JSON.',
                previous: $exception,
            );
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new InvalidArgumentException(
                'JSON import must be a top-level array of records.',
            );
        }

        if ($decoded === []) {
            throw new InvalidArgumentException(
                'JSON import contains no records.',
            );
        }

        if (count($decoded) > self::MAX_ROWS) {
            throw new InvalidArgumentException(
                'JSON import exceeds the supported row limit.',
            );
        }

        $rows = [];

        foreach ($decoded as $row) {
            if (
                ! is_array($row)
                || $row === []
                || array_is_list($row)
            ) {
                throw new InvalidArgumentException(
                    'Every JSON import record must be an object.',
                );
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
