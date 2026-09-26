<?php

declare(strict_types=1);

namespace App\Domain\Capital\ValueObjects;

use InvalidArgumentException;

final readonly class CapitalRequirement
{
    public string $preOpening;

    public string $initialAssetsInventory;

    public string $workingCapital;

    public string $contingencyReserve;

    public string $availableFunding;

    public string $totalRequirement;

    public string $fundingGap;

    public function __construct(
        string $preOpening,
        string $initialAssetsInventory,
        string $workingCapital,
        string $contingencyReserve,
        string $availableFunding,
    ) {
        $pre = self::minor($preOpening);
        $assets = self::minor($initialAssetsInventory);
        $working = self::minor($workingCapital);
        $contingency = self::minor($contingencyReserve);
        $funding = self::minor($availableFunding);

        $total = $pre + $assets + $working + $contingency;
        $gap = max($total - $funding, 0);

        $this->preOpening = self::major($pre);
        $this->initialAssetsInventory = self::major($assets);
        $this->workingCapital = self::major($working);
        $this->contingencyReserve = self::major($contingency);
        $this->availableFunding = self::major($funding);
        $this->totalRequirement = self::major($total);
        $this->fundingGap = self::major($gap);
    }

    private static function minor(string $value): int
    {
        $value = trim($value);

        if (preg_match('/\A\d{1,16}(?:\.\d{1,2})?\z/', $value) !== 1) {
            throw new InvalidArgumentException(
                'Capital amounts must be non-negative values with at most two decimal places.',
            );
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = str_pad($fraction, 2, '0');

        return ((int) $whole * 100) + (int) $fraction;
    }

    private static function major(int $minor): string
    {
        return sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }
}
