<?php

declare(strict_types=1);

namespace App\Application\Formation;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Capital\CapitalCalculationEngine;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class CalculateCapitalFoundation
{
    public function __construct(
        private readonly FormationActorContext $actor,
        private readonly BusinessModelEconomicsCalculator $economics,
        private readonly CapitalCalculationEngine $calculator,
    ) {}

    /**
     * Authorized calculation-only foundation.
     *
     * No Capital, Contribution, Equity, Ownership, Approval, Signature or
     * Effective record is persisted by this operation.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>|null
     */
    public function execute(
        User $user,
        Business $business,
        array $input,
    ): ?array {
        if (
            ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::FORMATION_VIEW,
            )
            || ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::CAPITAL_VIEW,
            )
        ) {
            return null;
        }

        $resolved = $input;
        $reuse = [
            'requested' => false,
            'status' => 'not_requested',
            'source' => null,
            'value' => null,
            'reasonCode' => null,
        ];

        $working = $input['workingCapital'] ?? null;

        if (
            is_array($working)
            && ($working['method'] ?? null) === 'canonical_operating_profile'
        ) {
            $reuse['requested'] = true;
            $reuse['source'] = 'business_model_operating_profile';

            $resolved['workingCapital'] = [
                'method' => 'monthly_burn',
                'months' => $working['months'] ?? null,
                'monthlyBurn' => null,
            ];

            if (
                ! $this->actor->allows(
                    $user,
                    $business,
                    CapabilityCatalog::BUSINESS_MODEL_VIEW,
                )
            ) {
                $reuse['status'] = 'unavailable';
                $reuse['reasonCode'] = 'business_model_view_not_authorized';
            } else {
                $canonical = $this->canonicalMonthlyOperatingCost(
                    (string) $business->getKey(),
                );

                if ($canonical === null) {
                    $reuse['status'] = 'unavailable';
                    $reuse['reasonCode'] = 'canonical_operating_cost_incomplete';
                } else {
                    $resolved['workingCapital']['monthlyBurn'] = $canonical;
                    $reuse['status'] = 'reused';
                    $reuse['value'] = $canonical;
                    $reuse['reasonCode'] = null;
                }
            }
        }

        return [
            ...$this->calculator->calculate($resolved),
            'business' => [
                'currency' => (string) $business->base_currency,
            ],
            'canonicalReuse' => [
                'monthlyOperatingCost' => $reuse,
            ],
        ];
    }

    private function canonicalMonthlyOperatingCost(
        string $businessId,
    ): ?string {
        $profile = DB::table('business_model_operating_profiles')
            ->where('business_id', $businessId)
            ->first();

        if ($profile === null) {
            return null;
        }

        $economics = $this->economics->calculate((array) $profile);

        if (
            $economics['status'] !== 'ready'
            || $economics['expectedMonthlyRevenue'] === null
            || $economics['expectedMonthlyGrossProfit'] === null
        ) {
            return null;
        }

        $revenue = $this->minor(
            (string) $economics['expectedMonthlyRevenue'],
        );
        $grossProfit = $this->minor(
            (string) $economics['expectedMonthlyGrossProfit'],
        );
        $fixed = $this->minor((string) $profile->monthly_fixed_cost);

        if (
            $revenue === null
            || $grossProfit === null
            || $fixed === null
            || $grossProfit > $revenue
        ) {
            return null;
        }

        return $this->decimal(
            ($revenue - $grossProfit) + $fixed,
        );
    }

    private function minor(string $value): ?int
    {
        if (
            preg_match(
                '/\A(\d+)(?:\.(\d{1,2}))?\z/',
                trim($value),
                $matches,
            ) !== 1
        ) {
            return null;
        }

        return ((int) $matches[1] * 100)
            + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function decimal(int $minor): string
    {
        return sprintf(
            '%d.%02d',
            intdiv($minor, 100),
            $minor % 100,
        );
    }
}
