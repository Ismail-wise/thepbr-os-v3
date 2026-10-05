<?php

declare(strict_types=1);

namespace App\Domain\Capital;

use DateTimeImmutable;
use InvalidArgumentException;

final class CapitalPlanningDraftContract
{
    public const string CONTRACT_VERSION = 'capital-planning-draft-v1';

    private const array TOP_LEVEL_KEYS = [
        'openingDate',
        'preOpeningItems',
        'initialAssetsInventoryItems',
        'workingCapital',
        'contingency',
        'confirmedFunding',
    ];

    private const array PRE_OPENING_CATEGORIES = [
        'registration_legal',
        'deposit',
        'renovation',
        'launch_marketing',
        'training',
        'other',
    ];

    private const array ASSET_CATEGORIES = [
        'equipment',
        'furniture',
        'technology',
        'opening_stock',
        'other',
    ];

    private const array WORKING_COST_CATEGORIES = [
        'salary',
        'rent',
        'utilities',
        'software',
        'monthly_marketing',
        'admin',
        'other',
    ];

    /**
     * Normalize editable input truth without calculating derived outputs.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function normalize(array $input): array
    {
        $this->assertNoUnknownKeys(
            $input,
            self::TOP_LEVEL_KEYS,
            'capital draft',
        );

        return [
            'openingDate' => $this->dateOrNull(
                $input['openingDate'] ?? null,
                'openingDate',
            ),
            'preOpeningItems' => $this->itemsOrNull(
                $input['preOpeningItems'] ?? null,
                self::PRE_OPENING_CATEGORIES,
                'preOpeningItems',
            ),
            'initialAssetsInventoryItems' => $this->itemsOrNull(
                $input['initialAssetsInventoryItems'] ?? null,
                self::ASSET_CATEGORIES,
                'initialAssetsInventoryItems',
            ),
            'workingCapital' => $this->workingCapital(
                $input['workingCapital'] ?? null,
            ),
            'contingency' => $this->contingency(
                $input['contingency'] ?? null,
            ),
            'confirmedFunding' => $this->moneyOrNull(
                $input['confirmedFunding'] ?? null,
                'confirmedFunding',
            ),
        ];
    }

    /**
     * @param  list<string>  $allowedCategories
     * @return list<array{category:string,label:string,amount:?string}>|null
     */
    private function itemsOrNull(
        mixed $items,
        array $allowedCategories,
        string $path,
    ): ?array {
        if ($items === null) {
            return null;
        }

        if (! is_array($items) || ! array_is_list($items)) {
            throw new InvalidArgumentException(
                "{$path} must be a list or null.",
            );
        }

        if (count($items) > 300) {
            throw new InvalidArgumentException(
                "{$path} may contain at most 300 items.",
            );
        }

        $normalized = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException(
                    "{$path}.{$index} must be an object.",
                );
            }

            $this->assertNoUnknownKeys(
                $item,
                ['category', 'label', 'amount'],
                "{$path}.{$index}",
            );

            $category = trim((string) ($item['category'] ?? ''));

            if (! in_array($category, $allowedCategories, true)) {
                throw new InvalidArgumentException(
                    "{$path}.{$index}.category is unsupported.",
                );
            }

            $label = $this->requiredText(
                $item['label'] ?? null,
                "{$path}.{$index}.label",
                160,
            );

            $normalized[] = [
                'category' => $category,
                'label' => $label,
                'amount' => $this->moneyOrNull(
                    $item['amount'] ?? null,
                    "{$path}.{$index}.amount",
                ),
            ];
        }

        return $normalized;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function workingCapital(mixed $input): ?array
    {
        if ($input === null) {
            return null;
        }

        if (! is_array($input)) {
            throw new InvalidArgumentException(
                'workingCapital must be an object or null.',
            );
        }

        $method = trim((string) ($input['method'] ?? ''));

        return match ($method) {
            'monthly_burn' => $this->monthlyBurnWorkingCapital($input),
            'monthly_costs' => $this->monthlyCostsWorkingCapital($input),
            'fixed_amount' => $this->fixedWorkingCapital($input),
            'canonical_operating_profile' => $this->canonicalWorkingCapital($input),
            default => throw new InvalidArgumentException(
                'workingCapital.method is unsupported.',
            ),
        };
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{method:string,months:?int,monthlyBurn:?string}
     */
    private function monthlyBurnWorkingCapital(array $input): array
    {
        $this->assertNoUnknownKeys(
            $input,
            ['method', 'months', 'monthlyBurn'],
            'workingCapital',
        );

        return [
            'method' => 'monthly_burn',
            'months' => $this->monthsOrNull(
                $input['months'] ?? null,
            ),
            'monthlyBurn' => $this->moneyOrNull(
                $input['monthlyBurn'] ?? null,
                'workingCapital.monthlyBurn',
            ),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{method:string,months:?int,items:?array}
     */
    private function monthlyCostsWorkingCapital(array $input): array
    {
        $this->assertNoUnknownKeys(
            $input,
            ['method', 'months', 'items'],
            'workingCapital',
        );

        return [
            'method' => 'monthly_costs',
            'months' => $this->monthsOrNull(
                $input['months'] ?? null,
            ),
            'items' => $this->itemsOrNull(
                $input['items'] ?? null,
                self::WORKING_COST_CATEGORIES,
                'workingCapital.items',
            ),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{method:string,amount:?string}
     */
    private function fixedWorkingCapital(array $input): array
    {
        $this->assertNoUnknownKeys(
            $input,
            ['method', 'amount'],
            'workingCapital',
        );

        return [
            'method' => 'fixed_amount',
            'amount' => $this->moneyOrNull(
                $input['amount'] ?? null,
                'workingCapital.amount',
            ),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{method:string,months:?int}
     */
    private function canonicalWorkingCapital(array $input): array
    {
        $this->assertNoUnknownKeys(
            $input,
            ['method', 'months'],
            'workingCapital',
        );

        return [
            'method' => 'canonical_operating_profile',
            'months' => $this->monthsOrNull(
                $input['months'] ?? null,
            ),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function contingency(mixed $input): ?array
    {
        if ($input === null) {
            return null;
        }

        if (! is_array($input)) {
            throw new InvalidArgumentException(
                'contingency must be an object or null.',
            );
        }

        $method = trim((string) ($input['method'] ?? ''));

        if ($method === 'percentage') {
            $this->assertNoUnknownKeys(
                $input,
                ['method', 'percentage'],
                'contingency',
            );

            return [
                'method' => 'percentage',
                'percentage' => $this->percentageOrNull(
                    $input['percentage'] ?? null,
                ),
            ];
        }

        if ($method === 'fixed_amount') {
            $this->assertNoUnknownKeys(
                $input,
                ['method', 'amount'],
                'contingency',
            );

            return [
                'method' => 'fixed_amount',
                'amount' => $this->moneyOrNull(
                    $input['amount'] ?? null,
                    'contingency.amount',
                ),
            ];
        }

        throw new InvalidArgumentException(
            'contingency.method is unsupported.',
        );
    }

    private function monthsOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_int($value) || $value < 0 || $value > 24) {
            throw new InvalidArgumentException(
                'workingCapital.months must be an integer from 0 to 24.',
            );
        }

        return $value;
    }

    private function moneyOrNull(
        mixed $value,
        string $path,
    ): ?string {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException(
                "{$path} must be a non-negative money value.",
            );
        }

        $value = trim((string) $value);

        if (
            preg_match(
                '/\A(\d{1,12})(?:\.(\d{1,2}))?\z/',
                $value,
                $matches,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                "{$path} must be a non-negative money value with at most two decimal places.",
            );
        }

        $whole = (int) $matches[1];
        $fraction = (int) str_pad($matches[2] ?? '', 2, '0');

        return sprintf('%d.%02d', $whole, $fraction);
    }

    private function percentageOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException(
                'contingency.percentage must be from 0 to 100.',
            );
        }

        $value = trim((string) $value);

        if (
            preg_match(
                '/\A(\d{1,3})(?:\.(\d{1,2}))?\z/',
                $value,
                $matches,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'contingency.percentage must be from 0 to 100.',
            );
        }

        $hundredths = ((int) $matches[1] * 100)
            + (int) str_pad($matches[2] ?? '', 2, '0');

        if ($hundredths > 10_000) {
            throw new InvalidArgumentException(
                'contingency.percentage must be from 0 to 100.',
            );
        }

        return sprintf(
            '%d.%02d',
            intdiv($hundredths, 100),
            $hundredths % 100,
        );
    }

    private function dateOrNull(
        mixed $value,
        string $path,
    ): ?string {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                "{$path} must be an ISO date.",
            );
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if (
            $date === false
            || $date->format('Y-m-d') !== $value
        ) {
            throw new InvalidArgumentException(
                "{$path} must be a valid ISO date.",
            );
        }

        return $value;
    }

    private function requiredText(
        mixed $value,
        string $path,
        int $max,
    ): string {
        if (! is_string($value)) {
            throw new InvalidArgumentException(
                "{$path} is required.",
            );
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > $max) {
            throw new InvalidArgumentException(
                "{$path} must be 1 to {$max} characters.",
            );
        }

        return $value;
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
