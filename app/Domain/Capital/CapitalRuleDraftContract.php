<?php

declare(strict_types=1);

namespace App\Domain\Capital;

use InvalidArgumentException;

final class CapitalRuleDraftContract
{
    public const string CONTRACT_VERSION = 'capital-rule-draft-v1';

    public const array SHORTFALL_RESPONSES = [
        'reduce_scope',
        'delay',
        'borrow',
        'capital_call',
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array{
     *   shortfallResponses:list<string>,
     *   allocationNotes:?string,
     *   shortfallRuleNotes:?string,
     *   capitalCallRuleNote:?string
     * }
     */
    public function normalize(array $input): array
    {
        $this->assertNoUnknownKeys(
            $input,
            [
                'shortfallResponses',
                'allocationNotes',
                'shortfallRuleNotes',
                'capitalCallRuleNote',
            ],
        );

        $responses = $input['shortfallResponses'] ?? [];

        if (! is_array($responses) || ! array_is_list($responses)) {
            throw new InvalidArgumentException(
                'shortfallResponses must be an ordered list.',
            );
        }

        $normalizedResponses = [];

        foreach ($responses as $response) {
            if (
                ! is_string($response)
                || ! in_array($response, self::SHORTFALL_RESPONSES, true)
            ) {
                throw new InvalidArgumentException(
                    'shortfallResponses contains an unsupported response.',
                );
            }

            if (in_array($response, $normalizedResponses, true)) {
                throw new InvalidArgumentException(
                    'shortfallResponses cannot contain duplicates.',
                );
            }

            $normalizedResponses[] = $response;
        }

        $capitalCallRuleNote = $this->optionalText(
            $input['capitalCallRuleNote'] ?? null,
            'capitalCallRuleNote',
            1000,
        );

        if (
            $capitalCallRuleNote !== null
            && ! in_array('capital_call', $normalizedResponses, true)
        ) {
            throw new InvalidArgumentException(
                'capitalCallRuleNote requires capital_call.',
            );
        }

        return [
            'shortfallResponses' => $normalizedResponses,
            'allocationNotes' => $this->optionalText(
                $input['allocationNotes'] ?? null,
                'allocationNotes',
                2000,
            ),
            'shortfallRuleNotes' => $this->optionalText(
                $input['shortfallRuleNotes'] ?? null,
                'shortfallRuleNotes',
                2000,
            ),
            'capitalCallRuleNote' => $capitalCallRuleNote,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  list<string>  $allowed
     */
    private function assertNoUnknownKeys(
        array $input,
        array $allowed,
    ): void {
        $unknown = array_diff(array_keys($input), $allowed);

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Capital Rule draft contains unsupported field [%s].',
                (string) reset($unknown),
            ));
        }
    }

    private function optionalText(
        mixed $value,
        string $path,
        int $max,
    ): ?string {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                "{$path} must be text or null.",
            );
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException(
                "{$path} exceeds {$max} characters.",
            );
        }

        return $value;
    }
}
