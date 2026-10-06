<?php

declare(strict_types=1);

namespace App\Domain\Partnership;

use InvalidArgumentException;

final class AcceptedContributionRegisterContract
{
    public const string CONTRACT_VERSION = 'accepted-contribution-register-v1';

    public const string READ_MODEL_VERSION =
        'accepted-contribution-register-read-model-v1';

    public function addMinorUnits(
        int $left,
        int $right,
    ): int {
        if (
            $left < 0
            || $right < 0
            || $right > PHP_INT_MAX - $left
        ) {
            throw new InvalidArgumentException(
                'Accepted Contribution total exceeds exact integer capacity.',
            );
        }

        return $left + $right;
    }

    public function decimal(int $minorUnits): string
    {
        if ($minorUnits < 0) {
            throw new InvalidArgumentException(
                'Accepted Contribution Value cannot be negative.',
            );
        }

        return intdiv($minorUnits, 100).'.'.str_pad(
            (string) ($minorUnits % 100),
            2,
            '0',
            STR_PAD_LEFT,
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function hash(array $payload): string
    {
        return hash(
            'sha256',
            json_encode(
                $this->canonicalize($payload),
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE,
            ),
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->canonicalize($item),
                $value,
            );
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
