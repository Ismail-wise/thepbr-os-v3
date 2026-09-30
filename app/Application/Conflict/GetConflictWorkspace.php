<?php

declare(strict_types=1);

namespace App\Application\Conflict;

use App\Application\Governance\GovernanceActorContext;
use App\Domain\Access\CapabilityCatalog;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Conflict\ConflictCase;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class GetConflictWorkspace
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly ConflictRecordVisibility $visibility,
    ) {}

    /** @return array<string,mixed>|null */
    public function execute(
        User $user,
        Business $business,
        ?string $selectedCaseId = null,
    ): ?array {
        $membership = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::CONFLICT_VIEW,
        );

        if ($membership === null) {
            return null;
        }

        $canManage = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::CONFLICT_MANAGE,
        ) !== null;

        $canViewGovernance = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
        ) !== null;

        $canViewOperations = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::OPERATIONS_VIEW,
        ) !== null;

        $governanceVersionId = $this->effectiveRecordVersion(
            $business,
            'governance_charter',
        );
        $operationsVersionId = $this->effectiveRecordVersion(
            $business,
            'operations_register',
        );

        $governancePrerequisiteStatus = ! $canViewGovernance
            ? 'unknown'
            : ($governanceVersionId === null ? 'missing' : 'met');

        $operationsPrerequisiteStatus = ! $canViewOperations
            ? 'unknown'
            : ($operationsVersionId === null ? 'missing' : 'met');

        $policy = $this->currentPolicy($business);

        $authorizedCases = ConflictCase::query()
            ->where('business_id', $business->getKey())
            ->orderByDesc('raised_at')
            ->get()
            ->filter(fn (ConflictCase $case): bool => $this->visibility->canView(
                $user,
                $business,
                (string) $case->getKey(),
            ))
            ->values();

        $rows = $authorizedCases
            ->map(fn (ConflictCase $case): array => $this->caseSummary($case))
            ->all();

        $attention = $authorizedCases
            ->filter(function (ConflictCase $case): bool {
                if (in_array(
                    $case->status->value,
                    ['resolved', 'closed'],
                    true,
                )) {
                    return false;
                }

                return $case->urgency === 'critical'
                    || (
                        $case->review_due_at !== null
                        && $case->review_due_at->isPast()
                    );
            })
            ->count();

        $selected = null;

        if ($selectedCaseId !== null) {
            $selectedCase = $authorizedCases->first(
                static fn (ConflictCase $case): bool => (string) $case->getKey() === $selectedCaseId,
            );

            if ($selectedCase !== null) {
                $selected = $this->caseDetail(
                    $business,
                    $selectedCase,
                );
            }
        }

        $policyVersions = ! $canManage
            ? collect()
            : DB::table('formal_record_versions as v')
                ->join('formal_record_families as f', function ($join): void {
                    $join->on(
                        'f.id',
                        '=',
                        'v.formal_record_family_id',
                    )->on(
                        'f.business_id',
                        '=',
                        'v.business_id',
                    );
                })
                ->where('v.business_id', $business->getKey())
                ->where(
                    'f.record_type',
                    'conflict_resolution_policy',
                )
                ->orderByDesc('v.version_number')
                ->get([
                    'v.id',
                    'v.version_number',
                    'v.revision',
                    'v.frozen_at',
                    'v.effective_from',
                    'v.review_due_at',
                ])
                ->map(function (object $version) use ($business): object {
                    $version->state = DB::table(
                        'record_version_state_transitions',
                    )
                        ->where(
                            'business_id',
                            $business->getKey(),
                        )
                        ->where(
                            'formal_record_version_id',
                            $version->id,
                        )
                        ->orderByDesc('sequence')
                        ->value('to_state');

                    return $version;
                });

        $memberships = ! $canManage
            ? collect()
            : DB::table('memberships as m')
                ->join('users as u', 'u.id', '=', 'm.user_id')
                ->where('m.business_id', $business->getKey())
                ->where('m.access_status', 'active')
                ->orderBy('u.email')
                ->get(['m.id', 'u.email']);

        $operationsRoles = (
            $canManage
            && $canViewOperations
            && $operationsVersionId !== null
        )
            ? DB::table('operations_roles')
                ->where('business_id', $business->getKey())
                ->where(
                    'formal_record_version_id',
                    $operationsVersionId,
                )
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'role_key', 'name'])
            : collect();

        $governanceDecisionTypes = (
            $canManage
            && $canViewGovernance
            && $governanceVersionId !== null
        )
            ? DB::table('governance_charter_rules')
                ->where('business_id', $business->getKey())
                ->where(
                    'formal_record_version_id',
                    $governanceVersionId,
                )
                ->orderBy('sequence')
                ->pluck('decision_type')
                ->unique()
                ->values()
            : collect();

        return [
            'business' => [
                'id' => (string) $business->getKey(),
                'name' => (string) $business->name,
            ],
            'current_membership_id' => (string) $membership->getKey(),
            'capabilities' => [
                'can_view' => true,
                'can_manage' => $canManage,
            ],
            'policy' => $policy,
            'policy_versions' => $policyVersions,
            'memberships' => $memberships,
            'operations_roles' => $operationsRoles,
            'governance_decision_types' => $governanceDecisionTypes,
            'prerequisites' => [
                'governance_charter' => [
                    'status' => $governancePrerequisiteStatus,
                    'can_open_governance' => $canViewGovernance,
                ],
                'operations_register' => [
                    'status' => $operationsPrerequisiteStatus,
                    'can_open_operations' => $canViewOperations,
                ],
            ],
            'counts' => [
                'visible_cases' => count($rows),
                'needs_attention' => $attention,
                'open' => $authorizedCases
                    ->filter(
                        static fn (ConflictCase $case): bool => ! in_array(
                            $case->status->value,
                            ['resolved', 'closed'],
                            true,
                        ),
                    )
                    ->count(),
            ],
            'cases' => $rows,
            'selected_case' => $selected,
        ];
    }

    private function effectiveRecordVersion(
        Business $business,
        string $recordType,
    ): ?string {
        $id = DB::table('record_family_effective_heads as h')
            ->join(
                'formal_record_versions as v',
                'v.id',
                '=',
                'h.formal_record_version_id',
            )
            ->join(
                'formal_record_families as f',
                'f.id',
                '=',
                'v.formal_record_family_id',
            )
            ->where('h.business_id', $business->getKey())
            ->where('f.business_id', $business->getKey())
            ->where('f.record_type', $recordType)
            ->value('v.id');

        return $id === null ? null : (string) $id;
    }

    /** @return array<string,mixed>|null */
    private function currentPolicy(Business $business): ?array
    {
        $row = DB::table('record_family_effective_heads as h')
            ->join(
                'formal_record_families as f',
                function ($join): void {
                    $join->on(
                        'f.id',
                        '=',
                        'h.formal_record_family_id',
                    )->on(
                        'f.business_id',
                        '=',
                        'h.business_id',
                    );
                },
            )
            ->join(
                'formal_record_versions as v',
                function ($join): void {
                    $join->on(
                        'v.id',
                        '=',
                        'h.formal_record_version_id',
                    )->on(
                        'v.business_id',
                        '=',
                        'h.business_id',
                    );
                },
            )
            ->join(
                'conflict_policy_versions as p',
                function ($join): void {
                    $join->on(
                        'p.formal_record_version_id',
                        '=',
                        'v.id',
                    )->on(
                        'p.business_id',
                        '=',
                        'v.business_id',
                    );
                },
            )
            ->where('h.business_id', $business->getKey())
            ->where('f.record_type', 'conflict_resolution_policy')
            ->first([
                'v.id as formal_record_version_id',
                'v.version_number',
                'v.effective_from',
                'v.review_due_at',
                'p.conflict_owner_membership_id',
                'p.formal_decision_type',
                'p.deadlock_decision_type',
                'p.misconduct_decision_type',
                'p.urgent_risk_decision_type',
                'p.settlement_decision_type',
                'p.review_frequency',
            ]);

        return $row === null ? null : (array) $row;
    }

    /** @return array<string,mixed> */
    private function caseSummary(ConflictCase $case): array
    {
        return [
            'id' => (string) $case->getKey(),
            'case_number' => $case->case_number,
            'conflict_type' => $case->conflict_type->value,
            'urgency' => $case->urgency,
            'stage' => $case->stage->value,
            'status' => $case->status->value,
            'conflict_owner_membership_id' => (string) $case->conflict_owner_membership_id,
            'raised_at' => $case->raised_at?->toIso8601String(),
            'review_due_at' => $case->review_due_at?->toIso8601String(),
            'revision' => (int) $case->revision,
        ];
    }

    /** @return array<string,mixed> */
    private function caseDetail(
        Business $business,
        ConflictCase $case,
    ): array {
        $businessId = (string) $business->getKey();
        $caseId = (string) $case->getKey();

        return [
            ...$this->caseSummary($case),
            'description' => $case->description,
            'business_impact' => $case->business_impact,
            'related_rule_reference' => $case->related_rule_reference,
            'resolution_source_type' => $case->resolution_source_type,
            'resolution_source_id' => $case->resolution_source_id,
            'participants' => DB::table('conflict_case_participants')
                ->where('business_id', $businessId)
                ->where('conflict_case_id', $caseId)
                ->orderBy('added_at')
                ->get()
                ->map(static fn ($row): array => (array) $row)
                ->all(),
            'updates' => DB::table('conflict_case_updates')
                ->where('business_id', $businessId)
                ->where('conflict_case_id', $caseId)
                ->orderBy('sequence')
                ->get()
                ->map(static fn ($row): array => (array) $row)
                ->all(),
            'direct_discussions' => DB::table(
                'conflict_direct_discussions',
            )
                ->where('business_id', $businessId)
                ->where('conflict_case_id', $caseId)
                ->orderByDesc('meeting_at')
                ->get()
                ->map(static fn ($row): array => (array) $row)
                ->all(),
            'mediations' => DB::table('conflict_mediations')
                ->where('business_id', $businessId)
                ->where('conflict_case_id', $caseId)
                ->orderByDesc('mediation_at')
                ->get()
                ->map(static fn ($row): array => (array) $row)
                ->all(),
            'decision_submissions' => DB::table(
                'conflict_decision_submissions',
            )
                ->where('business_id', $businessId)
                ->where('conflict_case_id', $caseId)
                ->orderByDesc('created_at')
                ->get()
                ->map(static fn ($row): array => (array) $row)
                ->all(),
            'escalations' => DB::table('conflict_escalations')
                ->where('business_id', $businessId)
                ->where('conflict_case_id', $caseId)
                ->orderByDesc('entered_at')
                ->get()
                ->map(static fn ($row): array => (array) $row)
                ->all(),
            'deadlock' => DB::table('conflict_deadlock_records')
                ->where('business_id', $businessId)
                ->where('conflict_case_id', $caseId)
                ->first(),
            'investigations' => DB::table('conflict_investigations')
                ->where('business_id', $businessId)
                ->where('conflict_case_id', $caseId)
                ->orderByDesc('opened_at')
                ->get()
                ->map(static fn ($row): array => (array) $row)
                ->all(),
            'urgent_risks' => DB::table(
                'conflict_urgent_risk_records',
            )
                ->where('business_id', $businessId)
                ->where('conflict_case_id', $caseId)
                ->orderByDesc('created_at')
                ->get()
                ->map(static fn ($row): array => (array) $row)
                ->all(),
            'settlements' => DB::table('conflict_settlement_versions')
                ->where('business_id', $businessId)
                ->where('conflict_case_id', $caseId)
                ->orderByDesc('created_at')
                ->get()
                ->map(static fn ($row): array => (array) $row)
                ->all(),
            'referrals' => DB::table('conflict_exit_legal_referrals')
                ->where('business_id', $businessId)
                ->where('conflict_case_id', $caseId)
                ->orderByDesc('referred_at')
                ->get()
                ->map(static fn ($row): array => (array) $row)
                ->all(),
            'action_links' => DB::table('conflict_action_links')
                ->where('business_id', $businessId)
                ->where('conflict_case_id', $caseId)
                ->orderByDesc('created_at')
                ->get()
                ->map(static fn ($row): array => (array) $row)
                ->all(),
            'reviews' => DB::table('conflict_case_reviews')
                ->where('business_id', $businessId)
                ->where('conflict_case_id', $caseId)
                ->orderByDesc('created_at')
                ->get()
                ->map(static fn ($row): array => (array) $row)
                ->all(),
        ];
    }
}
