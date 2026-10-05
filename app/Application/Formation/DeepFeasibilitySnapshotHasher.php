<?php

declare(strict_types=1);

namespace App\Application\Formation;

use JsonException;

final class DeepFeasibilitySnapshotHasher
{
    /**
     * @param  array<string,mixed>  $snapshot
     */
    public function hash(array $snapshot): string
    {
        return hash(
            'sha256',
            json_encode(
                $this->normalize($snapshot),
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PRESERVE_ZERO_FRACTION,
            ),
        );
    }

    /**
     * @throws JsonException
     */
    private function normalize(mixed $value): mixed
    {
        if (is_float($value) && is_finite($value)) {
            if (floor($value) === $value) {
                return (int) $value;
            }

            return $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->normalize($item),
                $value,
            );
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
