<?php

declare(strict_types=1);

namespace App\Domain\Capital;

use InvalidArgumentException;

final class CapitalCalculationEngine
{
    public const string CONTRACT_VERSION = 'capital-calculation-v1';

    private const int MAX_MINOR = 99_999_999_999_999;

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
     * Pure deterministic calculation contract.
     *
     * Missing sections remain unavailable. An explicit empty item list or
     * "0.00" remains an intentional zero and is never conflated with missing
     * evidence.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function calculate(array $input): array
    {
        $preOpening = $this->itemSection(
            $input['preOpeningItems'] ?? null,
            self::PRE_OPENING_CATEGORIES,
            'preOpeningItems',
        );
        $assets = $this->itemSection(
            $input['initialAssetsInventoryItems'] ?? null,
            self::ASSET_CATEGORIES,
            'initialAssetsInventoryItems',
        );
        $workingCapital = $this->workingCapital(
            $input['workingCapital'] ?? null,
        );

        $base = $this->sumWhenCalculable([
            $preOpening['subtotalMinor'],
            $assets['subtotalMinor'],
            $workingCapital['amountMinor'],
        ]);

        $contingency = $this->contingency(
            $input['contingency'] ?? null,
            $base,
        );

        $total = $this->sumWhenCalculable([
            $preOpening['subtotalMinor'],
            $assets['subtotalMinor'],
            $workingCapital['amountMinor'],
            $contingency['amountMinor'],
        ]);

        $funding = $this->funding(
            array_key_exists('confirmedFunding', $input)
                ? $input['confirmedFunding']
                : null,
            $total,
        );

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'preOpening' => $this->publicSection($preOpening),
            'initialAssetsInventory' => $this->publicSection($assets),
            'workingCapital' => [
                'status' => $workingCapital['status'],
                'method' => $workingCapital['method'],
                'months' => $workingCapital['months'],
                'monthlyBurn' => $workingCapital['monthlyBurnMinor'] === null
                    ? null
                    : $this->decimal($workingCapital['monthlyBurnMinor']),
                'amount' => $workingCapital['amountMinor'] === null
                    ? null
                    : $this->decimal($workingCapital['amountMinor']),
                'monthlyCosts' => $workingCapital['monthlyCosts'],
                'reasonCode' => $workingCapital['reasonCode'],
            ],
            'contingency' => [
                'status' => $contingency['status'],
                'method' => $contingency['method'],
                'percentage' => $contingency['percentage'],
                'baseAmount' => $contingency['baseMinor'] === null
                    ? null
                    : $this->decimal($contingency['baseMinor']),
                'amount' => $contingency['amountMinor'] === null
                    ? null
                    : $this->decimal($contingency['amountMinor']),
                'reasonCode' => $contingency['reasonCode'],
            ],
            'totalCapitalRequirement' => [
                'status' => $total === null ? 'unavailable' : 'calculable',
                'amount' => $total === null
                    ? null
                    : $this->decimal($total),
                'formula' => 'pre_opening + initial_assets_inventory + working_capital + contingency_reserve',
                'reasonCode' => $total === null
                    ? 'required_capital_inputs_incomplete'
                    : null,
            ],
            'fundingPosition' => $funding,
            'runway' => [
                'status' => 'unavailable',
                'months' => null,
                'reasonCode' => 'available_operating_cash_not_explicit',
            ],
            'semantics' => [
                'capitalRequirementIsPartnerContribution' => false,
                'fundingBecomesEquityAutomatically' => false,
                'createsOwnershipTruth' => false,
                'createsApprovedTruth' => false,
                'createsSignedTruth' => false,
                'createsEffectiveTruth' => false,
                'businessSuccessPrediction' => false,
            ],
        ];
    }

    /**
     * @param  list<string>  $allowedCategories
     * @return array<string,mixed>
     */
    private function itemSection(
        mixed $items,
        array $allowedCategories,
        string $path,
    ): array {
        if ($items === null) {
            return [
                'status' => 'missing',
                'subtotalMinor' => null,
                'categories' => [],
                'reasonCode' => 'section_not_provided',
            ];
        }

        if (! is_array($items) || ! array_is_list($items)) {
            throw new InvalidArgumentException(
                "{$path} must be a list.",
            );
        }

        $categoryTotals = array_fill_keys($allowedCategories, 0);
        $categoryMissing = array_fill_keys($allowedCategories, false);
        $hasMissing = false;

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException(
                    "{$path}.{$index} must be an object.",
                );
            }

            $category = (string) ($item['category'] ?? '');

            if (! in_array($category, $allowedCategories, true)) {
                throw new InvalidArgumentException(
                    "{$path}.{$index}.category is unsupported.",
                );
            }

            if (
                ! array_key_exists('amount', $item)
                || $item['amount'] === null
                || $item['amount'] === ''
            ) {
                $hasMissing = true;
                $categoryMissing[$category] = true;

                continue;
            }

            $amount = $this->money(
                $item['amount'],
                "{$path}.{$index}.amount",
            );

            $categoryTotals[$category] = $this->add(
                $categoryTotals[$category],
                $amount,
                $path,
            );
        }

        $categories = [];

        foreach ($allowedCategories as $category) {
            $categories[$category] = [
                'status' => $categoryMissing[$category]
                    ? 'incomplete'
                    : 'calculable',
                'subtotal' => $categoryMissing[$category]
                    ? null
                    : $this->decimal($categoryTotals[$category]),
            ];
        }

        if ($hasMissing) {
            return [
                'status' => 'incomplete',
                'subtotalMinor' => null,
                'categories' => $categories,
                'reasonCode' => 'item_amount_missing',
            ];
        }

        $subtotal = 0;

        foreach ($categoryTotals as $amount) {
            $subtotal = $this->add($subtotal, $amount, $path);
        }

        return [
            'status' => 'calculable',
            'subtotalMinor' => $subtotal,
            'categories' => $categories,
            'reasonCode' => null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function publicSection(array $section): array
    {
        return [
            'status' => $section['status'],
            'subtotal' => $section['subtotalMinor'] === null
                ? null
                : $this->decimal($section['subtotalMinor']),
            'categories' => $section['categories'],
            'reasonCode' => $section['reasonCode'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function workingCapital(mixed $input): array
    {
        if ($input === null) {
            return [
                'status' => 'missing',
                'method' => null,
                'months' => null,
                'monthlyBurnMinor' => null,
                'amountMinor' => null,
                'monthlyCosts' => null,
                'reasonCode' => 'working_capital_not_provided',
            ];
        }

        if (! is_array($input)) {
            throw new InvalidArgumentException(
                'workingCapital must be an object.',
            );
        }

        $method = (string) ($input['method'] ?? '');

        if (! in_array(
            $method,
            ['monthly_burn', 'monthly_costs', 'fixed_amount'],
            true,
        )) {
            throw new InvalidArgumentException(
                'workingCapital.method is unsupported.',
            );
        }

        if ($method === 'fixed_amount') {
            if (
                ! array_key_exists('amount', $input)
                || $input['amount'] === null
                || $input['amount'] === ''
            ) {
                return [
                    'status' => 'incomplete',
                    'method' => $method,
                    'months' => null,
                    'monthlyBurnMinor' => null,
                    'amountMinor' => null,
                    'monthlyCosts' => null,
                    'reasonCode' => 'working_capital_amount_missing',
                ];
            }

            return [
                'status' => 'calculable',
                'method' => $method,
                'months' => null,
                'monthlyBurnMinor' => null,
                'amountMinor' => $this->money(
                    $input['amount'],
                    'workingCapital.amount',
                ),
                'monthlyCosts' => null,
                'reasonCode' => null,
            ];
        }

        $months = $this->months($input['months'] ?? null);

        if ($months === null) {
            return [
                'status' => 'incomplete',
                'method' => $method,
                'months' => null,
                'monthlyBurnMinor' => null,
                'amountMinor' => null,
                'monthlyCosts' => null,
                'reasonCode' => 'working_capital_months_missing',
            ];
        }

        if ($method === 'monthly_costs') {
            $costs = $this->itemSection(
                $input['items'] ?? null,
                self::WORKING_COST_CATEGORIES,
                'workingCapital.items',
            );

            if ($costs['subtotalMinor'] === null) {
                return [
                    'status' => $costs['status'],
                    'method' => $method,
                    'months' => $months,
                    'monthlyBurnMinor' => null,
                    'amountMinor' => null,
                    'monthlyCosts' => $this->publicSection($costs),
                    'reasonCode' => $costs['reasonCode'],
                ];
            }

            $amount = $this->multiply(
                $costs['subtotalMinor'],
                $months,
                'workingCapital',
            );

            return [
                'status' => 'calculable',
                'method' => $method,
                'months' => $months,
                'monthlyBurnMinor' => $costs['subtotalMinor'],
                'amountMinor' => $amount,
                'monthlyCosts' => $this->publicSection($costs),
                'reasonCode' => null,
            ];
        }

        if (
            ! array_key_exists('monthlyBurn', $input)
            || $input['monthlyBurn'] === null
            || $input['monthlyBurn'] === ''
        ) {
            return [
                'status' => 'incomplete',
                'method' => $method,
                'months' => $months,
                'monthlyBurnMinor' => null,
                'amountMinor' => null,
                'monthlyCosts' => null,
                'reasonCode' => 'monthly_burn_missing',
            ];
        }

        $monthlyBurn = $this->money(
            $input['monthlyBurn'],
            'workingCapital.monthlyBurn',
        );

        return [
            'status' => 'calculable',
            'method' => $method,
            'months' => $months,
            'monthlyBurnMinor' => $monthlyBurn,
            'amountMinor' => $this->multiply(
                $monthlyBurn,
                $months,
                'workingCapital',
            ),
            'monthlyCosts' => null,
            'reasonCode' => null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function contingency(
        mixed $input,
        ?int $base,
    ): array {
        if ($input === null) {
            return [
                'status' => 'missing',
                'method' => null,
                'percentage' => null,
                'baseMinor' => $base,
                'amountMinor' => null,
                'reasonCode' => 'contingency_not_provided',
            ];
        }

        if (! is_array($input)) {
            throw new InvalidArgumentException(
                'contingency must be an object.',
            );
        }

        $method = (string) ($input['method'] ?? '');

        if (! in_array($method, ['percentage', 'fixed_amount'], true)) {
            throw new InvalidArgumentException(
                'contingency.method is unsupported.',
            );
        }

        if ($method === 'fixed_amount') {
            if (
                ! array_key_exists('amount', $input)
                || $input['amount'] === null
                || $input['amount'] === ''
            ) {
                return [
                    'status' => 'incomplete',
                    'method' => $method,
                    'percentage' => null,
                    'baseMinor' => $base,
                    'amountMinor' => null,
                    'reasonCode' => 'contingency_amount_missing',
                ];
            }

            return [
                'status' => 'calculable',
                'method' => $method,
                'percentage' => null,
                'baseMinor' => $base,
                'amountMinor' => $this->money(
                    $input['amount'],
                    'contingency.amount',
                ),
                'reasonCode' => null,
            ];
        }

        if (
            ! array_key_exists('percentage', $input)
            || $input['percentage'] === null
            || $input['percentage'] === ''
        ) {
            return [
                'status' => 'incomplete',
                'method' => $method,
                'percentage' => null,
                'baseMinor' => $base,
                'amountMinor' => null,
                'reasonCode' => 'contingency_percentage_missing',
            ];
        }

        $percentage = $this->percentage(
            $input['percentage'],
            'contingency.percentage',
        );

        if ($base === null) {
            return [
                'status' => 'incomplete',
                'method' => $method,
                'percentage' => $this->percentageDecimal($percentage),
                'baseMinor' => null,
                'amountMinor' => null,
                'reasonCode' => 'contingency_base_incomplete',
            ];
        }

        $reserve = intdiv(
            ($base * $percentage) + 5_000,
            10_000,
        );

        $this->assertWithinLimit(
            $reserve,
            'contingency.percentage',
        );

        return [
            'status' => 'calculable',
            'method' => $method,
            'percentage' => $this->percentageDecimal($percentage),
            'baseMinor' => $base,
            'amountMinor' => $reserve,
            'reasonCode' => null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function funding(mixed $confirmed, ?int $total): array
    {
        if ($confirmed === null || $confirmed === '') {
            return [
                'status' => 'missing',
                'confirmedFunding' => null,
                'fundingGap' => null,
                'fundingSurplus' => null,
                'fundedPercentage' => null,
                'reasonCode' => 'confirmed_funding_not_provided',
            ];
        }

        $funding = $this->money(
            $confirmed,
            'confirmedFunding',
        );

        if ($total === null) {
            return [
                'status' => 'unavailable',
                'confirmedFunding' => $this->decimal($funding),
                'fundingGap' => null,
                'fundingSurplus' => null,
                'fundedPercentage' => null,
                'reasonCode' => 'total_capital_requirement_unavailable',
            ];
        }

        $gap = max($total - $funding, 0);
        $surplus = max($funding - $total, 0);

        $fundedPercentage = $total === 0
            ? null
            : $this->percentageFromAmounts($funding, $total);

        return [
            'status' => 'calculable',
            'confirmedFunding' => $this->decimal($funding),
            'fundingGap' => $this->decimal($gap),
            'fundingSurplus' => $this->decimal($surplus),
            'fundedPercentage' => $fundedPercentage,
            'reasonCode' => $total === 0
                ? 'zero_requirement_percentage_unavailable'
                : null,
        ];
    }

    private function months(mixed $value): ?int
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

    private function money(mixed $value, string $path): int
    {
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

        $minor = ((int) $matches[1] * 100)
            + (int) str_pad($matches[2] ?? '', 2, '0');

        $this->assertWithinLimit($minor, $path);

        return $minor;
    }

    private function percentage(mixed $value, string $path): int
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException(
                "{$path} must be a percentage from 0 to 100.",
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
                "{$path} must be a percentage from 0 to 100.",
            );
        }

        $hundredths = ((int) $matches[1] * 100)
            + (int) str_pad($matches[2] ?? '', 2, '0');

        if ($hundredths > 10_000) {
            throw new InvalidArgumentException(
                "{$path} must be a percentage from 0 to 100.",
            );
        }

        return $hundredths;
    }

    /**
     * @param  list<?int>  $values
     */
    private function sumWhenCalculable(array $values): ?int
    {
        if (in_array(null, $values, true)) {
            return null;
        }

        $total = 0;

        foreach ($values as $value) {
            $total = $this->add(
                $total,
                (int) $value,
                'capital requirement',
            );
        }

        return $total;
    }

    private function add(int $left, int $right, string $path): int
    {
        if ($right > self::MAX_MINOR - $left) {
            throw new InvalidArgumentException(
                "{$path} exceeds the supported money limit.",
            );
        }

        return $left + $right;
    }

    private function multiply(int $money, int $factor, string $path): int
    {
        if ($factor !== 0 && $money > intdiv(self::MAX_MINOR, $factor)) {
            throw new InvalidArgumentException(
                "{$path} exceeds the supported money limit.",
            );
        }

        return $money * $factor;
    }

    private function assertWithinLimit(int $value, string $path): void
    {
        if ($value < 0 || $value > self::MAX_MINOR) {
            throw new InvalidArgumentException(
                "{$path} exceeds the supported money limit.",
            );
        }
    }

    private function decimal(int $minor): string
    {
        return sprintf(
            '%d.%02d',
            intdiv($minor, 100),
            $minor % 100,
        );
    }

    private function percentageDecimal(int $hundredths): string
    {
        return sprintf(
            '%d.%02d',
            intdiv($hundredths, 100),
            $hundredths % 100,
        );
    }

    private function percentageFromAmounts(
        int $numerator,
        int $denominator,
    ): string {
        $hundredths = intdiv(
            ($numerator * 10_000) + intdiv($denominator, 2),
            $denominator,
        );

        return $this->percentageDecimal($hundredths);
    }
}
