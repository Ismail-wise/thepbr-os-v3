<?php

declare(strict_types=1);

namespace App\Domain\Capital;

use JsonException;

final class CapitalApprovalContract
{
    public const string CONTRACT_VERSION = 'capital-approval-v1';

    public const string DECISION_TYPE = 'capital_plan_approval';

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    public function normalize(array $candidate): array
    {
        return $this->sortAssociative($candidate);
    }

    /**
     * @param  array<string,mixed>  $candidate
     *
     * @throws JsonException
     */
    public function contentHash(array $candidate): string
    {
        return hash(
            'sha256',
            json_encode(
                $this->normalize($candidate),
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE,
            ),
        );
    }

    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    private function sortAssociative(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => is_array($item)
                    ? $this->sortAssociative($item)
                    : $item,
                $value,
            );
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortAssociative($item);
            }
        }

        return $value;
    }
}
