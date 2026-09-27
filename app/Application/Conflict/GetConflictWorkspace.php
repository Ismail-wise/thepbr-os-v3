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

        return [
            'current_membership_id' => (string) $membership->getKey(),
            'capabilities' => [
                'can_view' => true,
                'can_manage' => $this->actorContext->membership(
                    $user,
                    $business,
                    CapabilityCatalog::CONFLICT_MANAGE,
                ) !== null,
            ],
            'policy' => $policy,
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
