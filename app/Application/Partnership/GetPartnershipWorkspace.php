<?php

declare(strict_types=1);

namespace App\Application\Partnership;

use App\Domain\Access\CapabilityCatalog;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class GetPartnershipWorkspace
{
    public function __construct(
        private readonly PartnershipActorContext $actor,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function execute(
        User $user,
        Business $business,
    ): ?array {
        $canViewPartners = $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::PARTNERS_VIEW,
        );

        $canViewDueDiligence = $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::DUE_DILIGENCE_VIEW,
        );

        $canViewContributions = $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::CONTRIBUTIONS_VIEW,
        );

        $canViewOwnership = $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::OWNERSHIP_VIEW,
        );

        if (
            ! $canViewPartners
            && ! $canViewDueDiligence
            && ! $canViewContributions
            && ! $canViewOwnership
        ) {
            return null;
        }

        $businessId = (string) $business->getKey();

        $partners = $canViewPartners
            ? DB::table('partners')
                ->where('business_id', $businessId)
                ->orderBy('display_name')
                ->get([
                    'id',
                    'display_name',
                    'legal_name',
                    'email',
                    'status',
                    'revision',
                    'created_at',
                ])
                ->map(static fn (object $row): array => (array) $row)
                ->all()
            : [];

        $dueDiligence = $canViewDueDiligence
            ? DB::table('partner_due_diligence_cases')
                ->where('business_id', $businessId)
                ->orderByDesc('updated_at')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all()
            : [];

        $partnerDynamics = $canViewPartners
            ? DB::table('partner_dynamics_assessment_references')
                ->where('business_id', $businessId)
                ->orderByDesc('completed_at')
                ->get([
                    'id',
                    'partner_id',
                    'source_assessment_id',
                    'source_url',
                    'assessment_version',
                    'primary_profile',
                    'secondary_profile',
                    'completed_at',
                    'created_at',
                ])
                ->map(static fn (object $row): array => (array) $row)
                ->all()
            : [];

        $contributions = $canViewContributions
            ? DB::table('contributions')
                ->where('business_id', $businessId)
                ->orderByDesc('created_at')
                ->get([
                    'id',
                    'partner_id',
                    'contribution_type',
                    'status',
                    'currency',
                    'description',
                    'proposed_value',
                    'reviewed_value',
                    'approved_value',
                    'accepted_value',
                    'valuation_method',
                    'conditions',
                    'committed_date',
                    'due_date',
                    'approval_decision_id',
                    'revision',
                    'created_at',
                    'updated_at',
                ])
                ->map(static fn (object $row): array => (array) $row)
                ->all()
            : [];

        $scenarios = $canViewOwnership
            ? DB::table('ownership_scenarios')
                ->where('business_id', $businessId)
                ->orderByDesc('created_at')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all()
            : [];

        $contributionSubmissions = $canViewContributions
            ? DB::table('contribution_governance_submissions')
                ->where('business_id', $businessId)
                ->orderByDesc('created_at')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all()
            : [];

        $scenarioIds = collect($scenarios)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $scenarioShareClasses = $canViewOwnership
            && $scenarioIds !== []
            ? DB::table('ownership_scenario_share_classes')
                ->where('business_id', $businessId)
                ->whereIn('ownership_scenario_id', $scenarioIds)
                ->orderBy('id')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all()
            : [];

        $scenarioPositions = $canViewOwnership
            && $scenarioIds !== []
            ? DB::table('ownership_scenario_positions')
                ->where('business_id', $businessId)
                ->whereIn('ownership_scenario_id', $scenarioIds)
                ->orderBy('id')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all()
            : [];

        $ownershipSubmissions = $canViewOwnership
            ? DB::table('ownership_governance_submissions')
                ->where('business_id', $businessId)
                ->orderByDesc('created_at')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all()
            : [];

        $currentRegister = null;

        if ($canViewOwnership) {
            $currentRegister = DB::table('ownership_register_versions')
                ->where('business_id', $businessId)
                ->where('status', 'effective')
                ->where('effective_from', '<=', now())
                ->where(function ($query): void {
                    $query
                        ->whereNull('effective_until')
                        ->orWhere('effective_until', '>', now());
                })
                ->orderByDesc('effective_from')
                ->first();

            if ($currentRegister !== null) {
                $currentRegister = [
                    ...(array) $currentRegister,
                    'positions' => DB::table('ownership_register_positions')
                        ->where('business_id', $businessId)
                        ->where(
                            'ownership_register_version_id',
                            $currentRegister->id,
                        )
                        ->orderBy('id')
                        ->get()
                        ->map(
                            static fn (object $row): array => (array) $row,
                        )
                        ->all(),
                    'share_classes' => DB::table(
                        'ownership_register_share_classes',
                    )
                        ->where('business_id', $businessId)
                        ->where(
                            'ownership_register_version_id',
                            $currentRegister->id,
                        )
                        ->orderBy('id')
                        ->get()
                        ->map(
                            static fn (object $row): array => (array) $row,
                        )
                        ->all(),
                ];
            }
        }

        return [
            'business' => [
                'id' => $businessId,
                'name' => (string) $business->name,
            ],
            'permissions' => [
                'partners_view' => $canViewPartners,
                'partners_manage' => $this->actor->allows(
                    $user,
                    $business,
                    CapabilityCatalog::PARTNERS_MANAGE,
                ),
                'due_diligence_view' => $canViewDueDiligence,
                'due_diligence_manage' => $this->actor->allows(
                    $user,
                    $business,
                    CapabilityCatalog::DUE_DILIGENCE_MANAGE,
                ),
                'contributions_view' => $canViewContributions,
                'contributions_manage' => $this->actor->allows(
                    $user,
                    $business,
                    CapabilityCatalog::CONTRIBUTIONS_MANAGE,
                ),
                'ownership_view' => $canViewOwnership,
                'ownership_manage' => $this->actor->allows(
                    $user,
                    $business,
                    CapabilityCatalog::OWNERSHIP_MANAGE,
                ),
            ],
            'partners' => $partners,
            'due_diligence' => $dueDiligence,
            'partner_dynamics' => $partnerDynamics,
            'contributions' => $contributions,
            'contribution_submissions' => $contributionSubmissions,
            'ownership_scenarios' => $scenarios,
            'ownership_scenario_share_classes' => $scenarioShareClasses,
            'ownership_scenario_positions' => $scenarioPositions,
            'ownership_submissions' => $ownershipSubmissions,
            'current_ownership_register' => $currentRegister,
        ];
    }
}
