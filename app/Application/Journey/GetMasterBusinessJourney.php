<?php

declare(strict_types=1);

namespace App\Application\Journey;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Capital\CapitalApprovalContract;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class GetMasterBusinessJourney
{
    public function __construct(
        private readonly AuthorizeBusinessCapability $authorize,
    ) {}

    /**
     * Presentation-only orchestration over existing authorized truth.
     *
     * The journey never writes canonical data and never grants access.
     *
     * @param  array<string,mixed>|null  $health
     * @return array{
     *   variant:string,
     *   steps:list<array{
     *     key:string,
     *     route:string|null,
     *     state:string,
     *     disabled:bool
     *   }>
     * }
     */
    public function execute(
        User $user,
        Business $business,
        ?array $health,
    ): array {
        $businessId = (string) $business->getKey();
        $userId = (string) $user->getKey();
        $existingBusiness = $business->origin_type
            === BusinessOriginType::ExistingBusinessImportedIntoPbr;
        $currentAreas = $this->currentEffectiveAreas($health);

        $definitions = [
            [
                'key' => 'business_model',
                'route' => '/formation',
                'capabilities' => [
                    CapabilityCatalog::FORMATION_VIEW,
                    CapabilityCatalog::BUSINESS_MODEL_VIEW,
                ],
                'applicable' => true,
            ],
            [
                'key' => 'business_valuation',
                'route' => '/formation',
                'capabilities' => [CapabilityCatalog::FORMATION_VIEW],
                'applicable' => $existingBusiness,
            ],
            [
                'key' => 'deep_feasibility',
                'route' => '/formation?step=feasibility',
                'capabilities' => [
                    CapabilityCatalog::FORMATION_VIEW,
                    CapabilityCatalog::BUSINESS_MODEL_VIEW,
                ],
                'applicable' => ! $existingBusiness,
            ],
            [
                'key' => 'partner_dynamics',
                'route' => '/partner-dynamics',
                'capabilities' => [CapabilityCatalog::PARTNERS_VIEW],
                'applicable' => true,
            ],
            [
                'key' => 'capital',
                'route' => '/formation',
                'capabilities' => [
                    CapabilityCatalog::FORMATION_VIEW,
                    CapabilityCatalog::CAPITAL_VIEW,
                ],
                'applicable' => true,
            ],
            [
                'key' => 'contributions',
                'route' => '/partnership',
                'capabilities' => [CapabilityCatalog::CONTRIBUTIONS_VIEW],
                'applicable' => true,
            ],
            [
                'key' => 'equity',
                'route' => '/partnership',
                'capabilities' => [CapabilityCatalog::OWNERSHIP_VIEW],
                'applicable' => true,
            ],
            [
                'key' => 'governance',
                'route' => '/governance',
                'capabilities' => [CapabilityCatalog::GOVERNANCE_RECORDS_VIEW],
                'applicable' => true,
            ],
            [
                'key' => 'roles_operations',
                'route' => '/operations',
                'capabilities' => [CapabilityCatalog::OPERATIONS_VIEW],
                'applicable' => true,
            ],
            [
                'key' => 'finance',
                'route' => '/finance',
                'capabilities' => [CapabilityCatalog::FINANCE_VIEW],
                'applicable' => true,
            ],
            [
                'key' => 'rewards',
                'route' => '/rewards',
                'capabilities' => [CapabilityCatalog::REWARDS_VIEW],
                'applicable' => true,
            ],
            [
                'key' => 'transfer',
                'route' => '/changes/partner-changes',
                'capabilities' => [CapabilityCatalog::PARTNER_CHANGES_VIEW],
                'applicable' => true,
            ],
            [
                'key' => 'exit',
                'route' => '/changes/exit',
                'capabilities' => [CapabilityCatalog::EXIT_VIEW],
                'applicable' => true,
            ],
            [
                'key' => 'conflict',
                'route' => '/conflict',
                'capabilities' => [CapabilityCatalog::CONFLICT_VIEW],
                'applicable' => true,
            ],
            [
                'key' => 'closure',
                'route' => '/changes/closure',
                'capabilities' => [CapabilityCatalog::CLOSURE_VIEW],
                'applicable' => true,
            ],
        ];

        $steps = collect($definitions)
            ->filter(
                fn (array $step): bool => $step['applicable'] === true
                    && $this->allowsAll(
                        $user,
                        $business,
                        $step['capabilities'],
                    ),
            )
            ->map(function (array $step) use (
                $businessId,
                $userId,
                $currentAreas,
            ): array {
                return [
                    'key' => $step['key'],
                    'route' => $step['route'],
                    'recorded' => $this->hasRecordedData(
                        $step['key'],
                        $businessId,
                        $userId,
                        $currentAreas,
                    ),
                ];
            })
            ->values();

        $currentIndex = $steps->search(
            static fn (array $step): bool => $step['recorded'] === false,
        );
        $nextIndex = $currentIndex === false
            ? false
            : $steps->search(
                static fn (array $step, int $index): bool => $index > $currentIndex
                    && $step['recorded'] === false,
            );

        return [
            'variant' => $existingBusiness ? 'existing' : 'new',
            'steps' => $steps
                ->map(function (array $step, int $index) use (
                    $currentIndex,
                    $nextIndex,
                ): array {
                    $state = $step['recorded']
                        ? 'recorded'
                        : ($index === $currentIndex
                            ? 'current'
                            : ($index === $nextIndex ? 'next' : 'available'));

                    return [
                        'key' => $step['key'],
                        'route' => $step['route'],
                        'state' => $state,
                        'disabled' => $step['route'] === null,
                    ];
                })
                ->all(),
        ];
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function allowsAll(
        User $user,
        Business $business,
        array $capabilities,
    ): bool {
        foreach ($capabilities as $capability) {
            $decision = $this->authorize->decide(
                $user,
                $business,
                $business,
                new Capability($capability),
            );

            if (! $decision->allowed) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>|null  $health
     * @return array<string,true>
     */
    private function currentEffectiveAreas(?array $health): array
    {
        if ($health === null) {
            return [];
        }

        return collect($health['requirements'] ?? [])
            ->filter(
                static fn (array $row): bool => ($row['reason_code'] ?? null)
                    === 'current_effective_source',
            )
            ->mapWithKeys(
                static fn (array $row): array => [(string) $row['key'] => true],
            )
            ->all();
    }

    /**
     * @param  array<string,true>  $currentAreas
     */
    private function hasRecordedData(
        string $key,
        string $businessId,
        string $userId,
        array $currentAreas,
    ): bool {
        return match ($key) {
            'business_model' => DB::table('business_model_canvases')
                ->where('business_id', $businessId)
                ->exists()
                || DB::table('business_model_operating_profiles')
                    ->where('business_id', $businessId)
                    ->exists(),
            'business_valuation' => DB::table('valuations')
                ->where('business_id', $businessId)
                ->exists(),
            'deep_feasibility' => DB::table(
                'deep_feasibility_assessment_runs',
            )
                ->where('business_id', $businessId)
                ->exists(),
            'partner_dynamics' => DB::table('partner_dynamics_personal_assessments')
                ->where('user_id', $userId)
                ->where('assessment_version', (string) config(
                    'partner_dynamics.version',
                    'v1',
                ))
                ->where('status', 'completed')
                ->exists(),
            'capital' => $this->hasEstablishedCapitalActionPlan(
                $businessId,
            ),
            'contributions' => $this->hasCompletedContributionChapter(
                $businessId,
            ),
            'equity' => $this->hasCompletedOwnershipChapter(
                $businessId,
            ),
            'governance' => isset($currentAreas['governance']),
            'roles_operations' => isset($currentAreas['operations']),
            'finance' => isset($currentAreas['finance']),
            'rewards' => isset($currentAreas['rewards']),
            'transfer' => DB::table('partner_change_cases')
                ->where('business_id', $businessId)
                ->exists(),
            'exit' => DB::table('exit_cases')
                ->where('business_id', $businessId)
                ->exists(),
            'conflict' => isset($currentAreas['conflict'])
                || DB::table('conflict_cases')
                    ->where('business_id', $businessId)
                    ->exists(),
            'closure' => DB::table('closure_cases')
                ->where('business_id', $businessId)
                ->exists(),
            default => false,
        };
    }

    private function hasCompletedContributionChapter(
        string $businessId,
    ): bool {
        $setup = DB::table('contribution_setups')
            ->where('business_id', $businessId)
            ->first([
                'id',
                'revision',
                'currency',
            ]);

        if ($setup === null) {
            return false;
        }

        $accepted = DB::table('contributions')
            ->where('business_id', $businessId)
            ->where('status', 'accepted')
            ->whereNotNull('accepted_value')
            ->get([
                'id',
                'revision',
                'currency',
            ]);

        if ($accepted->isEmpty()) {
            return false;
        }

        if (
            $accepted->contains(
                static fn (object $row): bool => (string) $row->currency
                    !== (string) $setup->currency,
            )
        ) {
            return false;
        }

        $record = DB::table('contribution_decision_records')
            ->where('business_id', $businessId)
            ->where('contribution_setup_id', $setup->id)
            ->where(
                'contribution_setup_revision',
                $setup->revision,
            )
            ->orderByDesc('created_at')
            ->first();

        if ($record === null) {
            return false;
        }

        $sources = DB::table(
            'contribution_decision_record_sources',
        )
            ->where('business_id', $businessId)
            ->where(
                'contribution_decision_record_id',
                $record->id,
            )
            ->get([
                'contribution_id',
                'contribution_revision',
            ])
            ->keyBy('contribution_id');

        if ($sources->count() !== $accepted->count()) {
            return false;
        }

        foreach ($accepted as $row) {
            $source = $sources->get(
                (string) $row->id,
            );

            if (
                $source === null
                || (int) $source->contribution_revision
                    !== (int) $row->revision
            ) {
                return false;
            }
        }

        return true;
    }

    private function hasCompletedOwnershipChapter(
        string $businessId,
    ): bool {
        $register = DB::table('ownership_register_versions')
            ->where('business_id', $businessId)
            ->where('status', 'effective')
            ->where('effective_from', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('effective_until')
                    ->orWhere('effective_until', '>', now());
            })
            ->orderByDesc('effective_from')
            ->first();

        if (
            $register === null
            || $register->source_accepted_register_hash === null
            || $register->source_contribution_decision_record_id === null
        ) {
            return false;
        }

        $sourceDecision = DB::table('contribution_decision_records')
            ->where('id', $register->source_contribution_decision_record_id)
            ->where('business_id', $businessId)
            ->where(
                'accepted_register_hash',
                $register->source_accepted_register_hash,
            )
            ->exists();

        if (! $sourceDecision) {
            return false;
        }

        return DB::table('ownership_decision_records')
            ->where('business_id', $businessId)
            ->where('ownership_register_version_id', $register->id)
            ->where(
                'source_accepted_register_hash',
                $register->source_accepted_register_hash,
            )
            ->where(
                'source_ownership_scenario_id',
                $register->source_ownership_scenario_id,
            )
            ->where(
                'proposal_version_id',
                $register->proposal_version_id,
            )
            ->where(
                'governance_decision_id',
                $register->governance_decision_id,
            )
            ->exists();
    }

    private function hasEstablishedCapitalActionPlan(
        string $businessId,
    ): bool {
        $currentApprovedSnapshotId = DB::table(
            'capital_approval_snapshots as snapshot',
        )
            ->join('decisions as decision', function ($join): void {
                $join
                    ->on(
                        'decision.business_id',
                        '=',
                        'snapshot.business_id',
                    )
                    ->on(
                        'decision.proposal_version_id',
                        '=',
                        'snapshot.proposal_version_id',
                    );
            })
            ->where('snapshot.business_id', $businessId)
            ->where(
                'decision.decision_type',
                CapitalApprovalContract::DECISION_TYPE,
            )
            ->where('decision.status', 'decided')
            ->where('decision.outcome', 'approved')
            ->whereNotNull('decision.resolved_at')
            ->orderByDesc('decision.resolved_at')
            ->orderByDesc('snapshot.prepared_at')
            ->orderByDesc('snapshot.id')
            ->value('snapshot.id');

        if (! is_string($currentApprovedSnapshotId)) {
            return false;
        }

        $currentDecisionRecordId = DB::table(
            'capital_decision_records',
        )
            ->where('business_id', $businessId)
            ->where(
                'capital_approval_snapshot_id',
                $currentApprovedSnapshotId,
            )
            ->value('id');

        if (! is_string($currentDecisionRecordId)) {
            return false;
        }

        return DB::table('capital_action_links as link')
            ->join('actions as action', function ($join): void {
                $join
                    ->on('action.id', '=', 'link.action_id')
                    ->on(
                        'action.business_id',
                        '=',
                        'link.business_id',
                    );
            })
            ->where('link.business_id', $businessId)
            ->where(
                'link.capital_decision_record_id',
                $currentDecisionRecordId,
            )
            ->where('action.status', '!=', 'cancelled')
            ->exists();
    }
}
