<?php

declare(strict_types=1);

namespace App\Domain\Capital;

use InvalidArgumentException;

final class CapitalComparisonDraftContract
{
    public const string CONTRACT_VERSION = 'capital-comparison-draft-v1';

    public const array SCENARIO_KEYS = [
        'lean',
        'base',
        'growth',
    ];

    public function __construct(
        private readonly CapitalPlanningDraftContract $planning,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array{
     *     scenarios:array{lean:?array,base:?array,growth:?array},
     *     preferredPlan:?string
     * }
     */
    public function normalize(array $input): array
    {
        $this->assertNoUnknownKeys(
            $input,
            ['scenarios', 'preferredPlan'],
            'capital comparison draft',
        );

        $scenarios = $input['scenarios'] ?? [];

        if (! is_array($scenarios) || array_is_list($scenarios)) {
            throw new InvalidArgumentException(
                'capital comparison draft.scenarios must be an object.',
            );
        }

        $this->assertNoUnknownKeys(
            $scenarios,
            self::SCENARIO_KEYS,
            'capital comparison draft.scenarios',
        );

        $preferred = $input['preferredPlan'] ?? null;

        if ($preferred === '') {
            $preferred = null;
        }

        if (
            $preferred !== null
            && (
                ! is_string($preferred)
                || ! in_array($preferred, self::SCENARIO_KEYS, true)
            )
        ) {
            throw new InvalidArgumentException(
                'capital comparison draft.preferredPlan is unsupported.',
            );
        }

        return [
            'scenarios' => [
                'lean' => $this->scenario(
                    $scenarios['lean'] ?? null,
                    'lean',
                ),
                'base' => $this->scenario(
                    $scenarios['base'] ?? null,
                    'base',
                ),
                'growth' => $this->scenario(
                    $scenarios['growth'] ?? null,
                    'growth',
                ),
            ],
            'preferredPlan' => $preferred,
        ];
    }

    /**
     * @param  array<string,mixed>  $canonicalInput
     * @return array{
     *     scenarios:array{lean:array,base:array,growth:array},
     *     preferredPlan:null
     * }
     */
    public function initializeFromCanonical(array $canonicalInput): array
    {
        $canonical = $this->planning->normalize($canonicalInput);

        return [
            'scenarios' => [
                'lean' => $canonical,
                'base' => $canonical,
                'growth' => $canonical,
            ],
            'preferredPlan' => null,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function scenario(
        mixed $input,
        string $key,
    ): ?array {
        if ($input === null) {
            return null;
        }

        if (! is_array($input) || array_is_list($input)) {
            throw new InvalidArgumentException(
                "capital comparison draft.scenarios.{$key} must be an object or null.",
            );
        }

        return $this->planning->normalize($input);
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  list<string>  $allowed
     */
    private function assertNoUnknownKeys(
        array $input,
        array $allowed,
        string $path,
    ): void {
        $unknown = array_diff(array_keys($input), $allowed);

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                sprintf(
                    '%s contains unsupported field [%s].',
                    $path,
                    (string) reset($unknown),
                ),
            );
        }
    }
}
