<?php

declare(strict_types=1);

namespace App\Application\Formation;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class GetFormationWorkspace
{
    public function __construct(
        private readonly FormationActorContext $actor,
    ) {}

    public function execute(
        User $user,
        Business $business,
    ): ?array {
        if (
            ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::FORMATION_VIEW,
            )
        ) {
            return null;
        }

        $businessId = (string) $business->getKey();
        $isNew = $business->origin_type
            === BusinessOriginType::StartedThroughPbr;

        $canViewModel = $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::BUSINESS_MODEL_VIEW,
        );

        $canViewCapital = $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::CAPITAL_VIEW,
        );

        $promotions = $canViewCapital
            ? DB::table('capital_plan_promotions')
                ->where('business_id', $businessId)
                ->orderByDesc('created_at')
                ->get()
                ->map(function (object $row) use ($businessId): array {
                    $state = DB::table(
                        'record_version_state_transitions',
                    )
                        ->where('business_id', $businessId)
                        ->where(
                            'formal_record_version_id',
                            $row->formal_record_version_id,
                        )
                        ->orderByDesc('sequence')
                        ->value('to_state');

                    return [
                        'id' => (string) $row->id,
                        'capital_scenario_id' => (string) $row->capital_scenario_id,
                        'scenario_revision' => (int) $row->scenario_revision,
                        'formal_record_version_id' => (string) $row->formal_record_version_id,
                        'proposal_version_id' => (string) $row->proposal_version_id,
                        'content_hash' => (string) $row->content_hash,
                        'state' => $state === null
                            ? null
                            : (string) $state,
                        'created_at' => (string) $row->created_at,
                    ];
                })
                ->all()
            : [];

        $effectivePromotion = null;

        if ($canViewCapital) {
            $effective = DB::table('capital_plan_promotions as cpp')
                ->join(
                    'record_family_effective_heads as head',
                    function ($join): void {
                        $join
                            ->on(
                                'head.business_id',
                                '=',
                                'cpp.business_id',
                            )
                            ->on(
                                'head.formal_record_family_id',
                                '=',
                                'cpp.formal_record_family_id',
                            )
                            ->on(
                                'head.formal_record_version_id',
                                '=',
                                'cpp.formal_record_version_id',
                            );
                    },
                )
                ->where('cpp.business_id', $businessId)
                ->select([
                    'cpp.id',
                    'cpp.formal_record_version_id',
                    'cpp.capital_scenario_id',
                    'cpp.scenario_revision',
                    'head.activated_at',
                ])
                ->first();

            if ($effective !== null) {
                $effectivePromotion = [
                    'id' => (string) $effective->id,
                    'formal_record_version_id' => (string) $effective->formal_record_version_id,
                    'capital_scenario_id' => (string) $effective->capital_scenario_id,
                    'scenario_revision' => (int) $effective->scenario_revision,
                    'activated_at' => (string) $effective->activated_at,
                ];
            }
        }

        return [
            'business' => [
                'id' => $businessId,
                'name' => (string) $business->name,
                'origin_type' => $business->origin_type->value,
                'business_stage' => $business->business_stage->value,
                'setup_phase' => $business->setup_phase?->value,
                'base_currency' => (string) $business->base_currency,
            ],
            'journey' => $isNew ? 'new' : 'existing',
            'bmc' => $canViewModel
                ? $this->row(
                    'business_model_canvases',
                    $businessId,
                )
                : null,
            'permissions' => [
                'can_manage_formation' => $this->actor->allows(
                    $user,
                    $business,
                    CapabilityCatalog::FORMATION_MANAGE,
                ),
                'can_manage_bmc' => $this->actor->allows(
                    $user,
                    $business,
                    CapabilityCatalog::BUSINESS_MODEL_MANAGE,
                ),
                'can_manage_capital' => $this->actor->allows(
                    $user,
                    $business,
                    CapabilityCatalog::CAPITAL_MANAGE,
                ),
            ],
            'new_business' => $isNew
                ? [
                    'idea' => $this->row(
                        'business_ideas',
                        $businessId,
                    ),
                    'assumptions' => $this->rows(
                        'formation_assumptions',
                        $businessId,
                    ),
                    'validations' => $this->rows(
                        'validation_activities',
                        $businessId,
                    ),
                    'feasibility' => $this->rows(
                        'feasibility_scenarios',
                        $businessId,
                    ),
                    'partnership_fit' => $this->row(
                        'partnership_fit_assessments',
                        $businessId,
                    ),
                    'directions' => $this->rows(
                        'formation_direction_decisions',
                        $businessId,
                        'decided_at',
                    ),
                ]
                : null,
            'existing_business' => $isNew
                ? null
                : [
                    'profile' => $this->row(
                        'existing_business_profiles',
                        $businessId,
                    ),
                    'financial_snapshots' => $this->rows(
                        'financial_snapshots',
                        $businessId,
                        'as_of_date',
                    ),
                    'assets' => $this->rows(
                        'business_assets',
                        $businessId,
                    ),
                    'liabilities' => $this->rows(
                        'business_liabilities',
                        $businessId,
                    ),
                    'owner_positions' => $this->rows(
                        'existing_owner_positions',
                        $businessId,
                    ),
                    'obligations' => $this->rows(
                        'current_obligations',
                        $businessId,
                    ),
                    'risks' => $this->rows(
                        'current_risk_control_snapshots',
                        $businessId,
                    ),
                    'constraints' => $this->rows(
                        'agreement_constraints',
                        $businessId,
                    ),
                    'gap_assessment' => $this->row(
                        'gap_assessments',
                        $businessId,
                    ),
                    'conversion_plan' => $this->row(
                        'partnership_conversion_plans',
                        $businessId,
                    ),
                    'valuations' => $this->rows(
                        'valuations',
                        $businessId,
                        'as_of_date',
                    ),
                ],
            'capital' => [
                'scenarios' => $canViewCapital
                    ? $this->rows(
                        'capital_scenarios',
                        $businessId,
                        'scenario_kind',
                    )
                    : [],
                'promotions' => $promotions,
                'current_effective' => $effectivePromotion,
                'formula' => 'Pre-opening + Initial Assets/Inventory + Working Capital + Contingency Reserve',
                'scenario_notice' => 'Scenario is planning only and never changes live truth.',
            ],
        ];
    }

    private function row(
        string $table,
        string $businessId,
    ): ?array {
        $row = DB::table($table)
            ->where('business_id', $businessId)
            ->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(
        string $table,
        string $businessId,
        string $order = 'created_at',
    ): array {
        return DB::table($table)
            ->where('business_id', $businessId)
            ->orderByDesc($order)
            ->get()
            ->map(
                static fn (object $row): array => (array) $row,
            )
            ->all();
    }
}
