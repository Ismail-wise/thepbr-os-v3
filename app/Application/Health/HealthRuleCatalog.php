<?php

declare(strict_types=1);

namespace App\Application\Health;

use App\Domain\Access\CapabilityCatalog;

final class HealthRuleCatalog
{
    /**
     * Static catalog only. Whether a rule is visible and what state it has are
     * decided at read time from current authorization and canonical truth.
     *
     * @return list<array{
     *   key:string,
     *   record_type:string,
     *   capability:string,
     *   route:string,
     *   next_action_code:string
     * }>
     */
    public function formalRecordRules(): array
    {
        return [
            [
                'key' => 'governance',
                'record_type' => 'governance_charter',
                'capability' => CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
                'route' => '/governance',
                'next_action_code' => 'open_governance',
            ],
            [
                'key' => 'operations',
                'record_type' => 'operations_register',
                'capability' => CapabilityCatalog::OPERATIONS_VIEW,
                'route' => '/operations',
                'next_action_code' => 'open_operations',
            ],
            [
                'key' => 'finance',
                'record_type' => 'finance_policy',
                'capability' => CapabilityCatalog::FINANCE_VIEW,
                'route' => '/finance',
                'next_action_code' => 'open_finance',
            ],
            [
                'key' => 'rewards',
                'record_type' => 'reward_policy',
                'capability' => CapabilityCatalog::REWARDS_VIEW,
                'route' => '/rewards',
                'next_action_code' => 'open_rewards',
            ],
            [
                'key' => 'risk',
                'record_type' => 'risk_register',
                'capability' => CapabilityCatalog::RISK_VIEW,
                'route' => '/risk',
                'next_action_code' => 'open_risk',
            ],
            [
                'key' => 'continuity',
                'record_type' => 'continuity_plan',
                'capability' => CapabilityCatalog::CONTINUITY_VIEW,
                'route' => '/continuity',
                'next_action_code' => 'open_continuity',
            ],
            [
                'key' => 'conflict',
                'record_type' => 'conflict_resolution_policy',
                'capability' => CapabilityCatalog::CONFLICT_VIEW,
                'route' => '/conflict',
                'next_action_code' => 'open_conflict',
            ],
        ];
    }
}
