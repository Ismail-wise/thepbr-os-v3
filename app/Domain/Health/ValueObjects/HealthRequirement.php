<?php

declare(strict_types=1);

namespace App\Domain\Health\ValueObjects;

use App\Domain\Health\Enums\HealthRequirementState;
use InvalidArgumentException;

final readonly class HealthRequirement
{
    public function __construct(
        public string $key,
        public HealthRequirementState $state,
        public string $reasonCode,
        public string $nextActionCode,
        public ?string $sourceType = null,
        public ?string $sourceId = null,
        public ?int $sourceVersion = null,
        public ?string $sourceHash = null,
        public ?string $lastVerifiedAt = null,
        public ?string $route = null,
    ) {
        foreach ([
            'key' => $key,
            'reasonCode' => $reasonCode,
            'nextActionCode' => $nextActionCode,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(
                    $field.' must not be blank.',
                );
            }
        }

        if ($sourceVersion !== null && $sourceVersion < 1) {
            throw new InvalidArgumentException(
                'sourceVersion must be positive when provided.',
            );
        }

        if (
            $sourceHash !== null
            && preg_match('/^[a-f0-9]{64}$/', $sourceHash) !== 1
        ) {
            throw new InvalidArgumentException(
                'sourceHash must be a lowercase SHA-256 hash.',
            );
        }

        if (
            ($sourceType === null) !== ($sourceId === null)
        ) {
            throw new InvalidArgumentException(
                'sourceType and sourceId must be provided together.',
            );
        }
    }

    /**
     * @return array{
     *   key:string,
     *   state:string,
     *   reason_code:string,
     *   next_action_code:string,
     *   source:array{
     *     type:string,
     *     id:string,
     *     version:int|null,
     *     hash:string|null
     *   }|null,
     *   last_verified_at:string|null,
     *   route:string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'state' => $this->state->value,
            'reason_code' => $this->reasonCode,
            'next_action_code' => $this->nextActionCode,
            'source' => $this->sourceType === null
                ? null
                : [
                    'type' => $this->sourceType,
                    'id' => $this->sourceId,
                    'version' => $this->sourceVersion,
                    'hash' => $this->sourceHash,
                ],
            'last_verified_at' => $this->lastVerifiedAt,
            'route' => $this->route,
        ];
    }
}
