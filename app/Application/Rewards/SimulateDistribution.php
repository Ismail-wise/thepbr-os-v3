<?php

declare(strict_types=1);

namespace App\Application\Rewards;

use App\Application\Governance\GovernanceActorContext;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Rewards\Services\DistributionCalculator;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;

final class SimulateDistribution
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly DistributionCalculator $calculator,
    ) {}

    /** @param array<string,mixed> $payload */
    public function execute(
        User $user,
        Business $business,
        array $payload,
    ): ?array {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::REWARDS_VIEW,
        ) === null) {
            return null;
        }

        $waterfall = $this->calculator->waterfall(
            (int) ($payload['approved_net_profit_minor_units'] ?? 0),
            (int) ($payload['tax_due_minor_units'] ?? 0),
            (int) ($payload['debt_due_minor_units'] ?? 0),
            (int) ($payload['required_reserve_minor_units'] ?? 0),
            (int) ($payload['reinvestment_minor_units'] ?? 0),
            (int) ($payload['adjustments_minor_units'] ?? 0),
        );

        $weights = $payload['weights'] ?? [];
        $allocations = is_array($weights) && $weights !== []
            ? $this->calculator->allocate(
                $waterfall['distributable_profit_minor_units'],
                array_map(static fn ($v): string => (string) $v, $weights),
            )
            : [];

        return [
            'scenario_only' => true,
            'waterfall' => $waterfall,
            'allocations' => $allocations,
        ];
    }
}
