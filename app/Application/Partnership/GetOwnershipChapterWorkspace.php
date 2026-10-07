<?php

declare(strict_types=1);

namespace App\Application\Partnership;

use App\Domain\Access\CapabilityCatalog;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class GetOwnershipChapterWorkspace
{
    public function __construct(
        private readonly PartnershipActorContext $actor,
        private readonly GetAcceptedContributionRegister $acceptedRegister,
        private readonly GetOwnershipDecisionRecordReadModel $decisionRecord,
        private readonly GetOwnershipActionPlanReadModel $actionPlan,
    ) {}

    /** @return array<string,mixed>|null */
    public function execute(User $user, Business $business): ?array
    {
        if (! $this->actor->allows($user, $business, CapabilityCatalog::OWNERSHIP_VIEW)
            || ! $this->actor->allows($user, $business, CapabilityCatalog::CONTRIBUTIONS_VIEW)) {
            return null;
        }

        $businessId = (string) $business->getKey();
        $accepted = $this->acceptedRegister->execute($user, $business);

        if ($accepted === null) {
            return null;
        }

        $sourceDecision = null;
        if (is_string($accepted['registerHash'] ?? null)) {
            $sourceDecision = DB::table('contribution_decision_records')
                ->where('business_id', $businessId)
                ->where('accepted_register_hash', $accepted['registerHash'])
                ->where('contribution_setup_revision', $accepted['setupRevision'] ?? -1)
                ->first();
        }

        $sourceReady = ($accepted['decisionReady'] ?? false) === true
            && $sourceDecision !== null;

        $scenarios = DB::table('ownership_scenarios')
            ->where('business_id', $businessId)
            ->orderByDesc('created_at')
            ->get();

        $activeScenario = $scenarios->first(
            static fn (object $row): bool => in_array(
                (string) $row->status,
                ['draft', 'frozen', 'proposed'],
                true,
            ),
        );

        $scenario = $activeScenario === null
            ? null
            : $this->scenario($businessId, $activeScenario, $accepted);

        $currentRegister = DB::table('ownership_register_versions')
            ->where('business_id', $businessId)
            ->where('status', 'effective')
            ->where('effective_from', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('effective_until')
                    ->orWhere('effective_until', '>', now());
            })
            ->orderByDesc('effective_from')
            ->first();

        $current = $currentRegister === null
            ? null
            : $this->register($businessId, $currentRegister);

        $history = DB::table('ownership_register_versions')
            ->where('business_id', $businessId)
            ->orderByDesc('version_number')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'versionNumber' => (int) $row->version_number,
                'status' => (string) $row->status,
                'effectiveFrom' => $row->effective_from === null ? null : (string) $row->effective_from,
                'effectiveUntil' => $row->effective_until === null ? null : (string) $row->effective_until,
                'issuedShares' => (string) $row->issued_shares,
                'current' => $currentRegister !== null
                    && (string) $row->id === (string) $currentRegister->id,
            ])
            ->all();

        $scenarioHistory = $scenarios
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'status' => (string) $row->status,
                'revision' => (int) $row->revision,
                'frozenAt' => $row->frozen_at === null
                    ? null
                    : (string) $row->frozen_at,
                'createdAt' => (string) $row->created_at,
            ])
            ->all();

        $approvalHistory = DB::table('ownership_governance_submissions as submission')
            ->join('ownership_scenarios as scenario', function ($join): void {
                $join->on('scenario.id', '=', 'submission.ownership_scenario_id')
                    ->on('scenario.business_id', '=', 'submission.business_id');
            })
            ->leftJoin('decisions as decision', function ($join): void {
                $join->on('decision.proposal_version_id', '=', 'submission.proposal_version_id')
                    ->on('decision.business_id', '=', 'submission.business_id')
                    ->where('decision.decision_type', 'ownership_approval');
            })
            ->leftJoin('ownership_register_versions as register', function ($join): void {
                $join->on('register.id', '=', 'submission.effective_register_version_id')
                    ->on('register.business_id', '=', 'submission.business_id');
            })
            ->where('submission.business_id', $businessId)
            ->orderByDesc('submission.created_at')
            ->get([
                'submission.id',
                'scenario.name as scenario_name',
                'decision.status as decision_status',
                'decision.outcome as decision_outcome',
                'decision.resolved_at',
                'submission.effective_from',
                'register.version_number',
                'register.status as register_status',
                'submission.created_at',
            ])
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'scenarioName' => (string) $row->scenario_name,
                'decisionStatus' => $row->decision_status === null
                    ? null
                    : (string) $row->decision_status,
                'decisionOutcome' => $row->decision_outcome === null
                    ? null
                    : (string) $row->decision_outcome,
                'approvalDate' => $row->resolved_at === null
                    ? null
                    : (string) $row->resolved_at,
                'effectiveFrom' => $row->effective_from === null
                    ? null
                    : (string) $row->effective_from,
                'registerVersionNumber' => $row->version_number === null
                    ? null
                    : (int) $row->version_number,
                'registerStatus' => $row->register_status === null
                    ? null
                    : (string) $row->register_status,
                'createdAt' => (string) $row->created_at,
            ])
            ->all();

        $decision = $this->decisionRecord->execute($user, $business);
        $actions = $this->actionPlan->execute($user, $business);

        $officialSourceBound = $currentRegister !== null
            && $currentRegister->source_accepted_register_hash !== null
            && $currentRegister->source_contribution_decision_record_id !== null;

        $currentDecisionRecorded = ($decision['recorded'] ?? false) === true
            && is_array($decision['current'] ?? null)
            && $currentRegister !== null
            && ($decision['current']['registerVersionId'] ?? null)
                === (string) $currentRegister->id;

        $upstreamChanged = $currentRegister !== null
            && $currentRegister->source_accepted_register_hash !== null
            && is_string($accepted['registerHash'] ?? null)
            && (string) $currentRegister->source_accepted_register_hash
                !== $accepted['registerHash'];

        $progress = $this->progress(
            $sourceReady,
            $scenario,
            $current !== null,
            $currentDecisionRecorded,
        );

        return [
            'contractVersion' => 'ownership-equity-chapter-v1',
            'canManage' => $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::OWNERSHIP_MANAGE,
            ),
            'source' => [
                'ready' => $sourceReady,
                'available' => ($accepted['available'] ?? false) === true,
                'currency' => $accepted['currency'] ?? null,
                'rows' => collect($accepted['rows'] ?? [])
                    ->map(static fn (array $row): array => [
                        'key' => (string) $row['contributionId'],
                        'partner' => (string) $row['partnerName'],
                        'type' => (string) $row['type'],
                        'acceptedValue' => (string) $row['acceptedValue'],
                        'currency' => (string) $row['currency'],
                        'status' => 'Accepted',
                        'description' => (string) $row['description'],
                        'conditions' => $row['conditions'] === null
                            ? null
                            : (string) $row['conditions'],
                        'evidence' => $row['evidence'],
                    ])
                    ->values()
                    ->all(),
                'acceptedCount' => (int) ($accepted['acceptedCount'] ?? 0),
                'registerHash' => $accepted['registerHash'] ?? null,
                'setupRevision' => $accepted['setupRevision'] ?? null,
                'decisionRecorded' => $sourceDecision !== null,
                'warnings' => $accepted['warnings'] ?? [],
            ],
            'scenario' => $scenario,
            'currentRegister' => $current,
            'scenarioHistory' => $scenarioHistory,
            'approvalHistory' => $approvalHistory,
            'registerHistory' => $history,
            'decisionRecord' => $decision,
            'actionPlan' => $actions,
            'progress' => [
                ...$progress,
                'chapterComplete' => $current !== null
                    && $officialSourceBound
                    && $currentDecisionRecorded,
            ],
            'warnings' => [
                'sourceChangedReviewNeeded' => $upstreamChanged,
                'legacyOfficialSourceBinding' => $currentRegister !== null
                    && ! $officialSourceBound,
            ],
            'routes' => [
                'contributions' => '/partnership?section=contributions',
                'governance' => '/governance',
                'tools' => '/tools',
                'continue' => '/governance',
            ],
            'boundaries' => [
                'shareCountCanonical' => true,
                'ownershipPercentDerived' => true,
                'votingIsGovernanceAuthority' => false,
                'ownershipIsSalary' => false,
                'ownershipIsRole' => false,
                'ownershipIsProfitDistributionPolicy' => false,
                'approvalIsSignature' => false,
                'signatureIsEffective' => false,
                'actionChangesOwnership' => false,
                'issuanceRuleIssuesShares' => false,
                'simulatorIsOfficialOwnership' => false,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function scenario(
        string $businessId,
        object $scenario,
        array $accepted,
    ): array {
        $positions = DB::select(
            <<<'SQL'
SELECT
    p.id,
    p.partner_id,
    partner.display_name AS partner_name,
    c.id AS share_class_id,
    c.name AS share_class_name,
    p.accepted_contribution_minor_units,
    p.shares_issued::text,
    p.shares_vested::text,
    (p.shares_issued - p.shares_vested)::text AS shares_unvested,
    p.vesting_applies,
    p.voting_rights::text,
    p.profit_rights::text,
    p.issue_date,
    p.vesting_start_date,
    p.vesting_period_months,
    p.vesting_cliff_months,
    p.vesting_conditions,
    p.early_exit_treatment,
    CASE
        WHEN SUM(p.shares_issued) OVER () = 0 THEN '0.0000'
        ELSE ROUND(
            (p.shares_issued / SUM(p.shares_issued) OVER ()) * 100,
            4
        )::text
    END AS ownership_percentage
FROM ownership_scenario_positions p
JOIN partners partner
  ON partner.id = p.partner_id
 AND partner.business_id = p.business_id
JOIN ownership_scenario_share_classes c
  ON c.id = p.share_class_id
 AND c.business_id = p.business_id
 AND c.ownership_scenario_id = p.ownership_scenario_id
WHERE p.business_id = ?
  AND p.ownership_scenario_id = ?
ORDER BY partner.display_name, p.id
SQL,
            [$businessId, $scenario->id],
        );

        $classes = DB::table('ownership_scenario_share_classes')
            ->where('business_id', $businessId)
            ->where('ownership_scenario_id', $scenario->id)
            ->orderBy('name')
            ->get()
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'votingRightPerShare' => (string) $row->voting_right_per_share,
                'profitRightPerShare' => (string) $row->profit_right_per_share,
                'transferAllowed' => (bool) $row->transfer_allowed,
                'restrictions' => $row->restrictions === null ? null : (string) $row->restrictions,
                'specialRights' => $row->special_rights === null ? null : (string) $row->special_rights,
            ])
            ->all();

        $issued = DB::table('ownership_scenario_positions')
            ->where('business_id', $businessId)
            ->where('ownership_scenario_id', $scenario->id)
            ->sum('shares_issued');

        $available = DB::selectOne(
            'SELECT (CAST(? AS numeric) - CAST(? AS numeric) - CAST(? AS numeric))::text AS value',
            [
                (string) $scenario->authorized_shares,
                (string) $issued,
                (string) $scenario->reserved_unissued_shares,
            ],
        );

        $rule = DB::table('ownership_scenario_issuance_rules')
            ->where('business_id', $businessId)
            ->where('ownership_scenario_id', $scenario->id)
            ->first();

        $submission = DB::table('ownership_governance_submissions')
            ->where('business_id', $businessId)
            ->where('ownership_scenario_id', $scenario->id)
            ->orderByDesc('created_at')
            ->first();

        $approval = $this->approvalStatus($businessId, $scenario, $submission);

        $sourceStale = ! is_string($accepted['registerHash'] ?? null)
            || $scenario->source_accepted_register_hash === null
            || (string) $scenario->source_accepted_register_hash
                !== $accepted['registerHash'];

        $vestingReviewed = $positions !== []
            && collect($positions)->every(
                static fn (object $row): bool => $row->vesting_applies !== null,
            );

        $ruleComplete = $rule !== null
            && trim((string) $rule->approval_rule) !== ''
            && trim((string) $rule->valuation_method) !== ''
            && (float) $rule->approval_threshold_percent > 0
            && (bool) $rule->dilution_acknowledged;

        return [
            'id' => (string) $scenario->id,
            'name' => (string) $scenario->name,
            'status' => (string) $scenario->status,
            'revision' => (int) $scenario->revision,
            'currency' => (string) $scenario->currency,
            'shareValue' => number_format(
                ((int) $scenario->share_value_minor_units) / 100,
                2,
                '.',
                '',
            ),
            'shareValueMinorUnits' => (int) $scenario->share_value_minor_units,
            'sourceStale' => $sourceStale,
            'sourceBound' => $scenario->source_accepted_register_hash !== null
                && $scenario->source_contribution_decision_record_id !== null,
            'positions' => collect($positions)->map(
                static fn (object $row): array => [
                    'id' => (string) $row->id,
                    'partnerId' => (string) $row->partner_id,
                    'partner' => (string) $row->partner_name,
                    'shareClassId' => (string) $row->share_class_id,
                    'shareClass' => (string) $row->share_class_name,
                    'acceptedValue' => number_format(
                        ((int) $row->accepted_contribution_minor_units) / 100,
                        2,
                        '.',
                        '',
                    ),
                    'sharesIssued' => (string) $row->shares_issued,
                    'sharesVested' => (string) $row->shares_vested,
                    'sharesUnvested' => (string) $row->shares_unvested,
                    'ownershipPercentage' => (string) $row->ownership_percentage,
                    'vestingApplies' => $row->vesting_applies === null
                        ? null
                        : (bool) $row->vesting_applies,
                    'votingRights' => (string) $row->voting_rights,
                    'profitRights' => (string) $row->profit_rights,
                    'issueDate' => $row->issue_date === null ? null : (string) $row->issue_date,
                    'vestingStartDate' => $row->vesting_start_date === null ? null : (string) $row->vesting_start_date,
                    'vestingPeriodMonths' => $row->vesting_period_months === null ? null : (int) $row->vesting_period_months,
                    'vestingCliffMonths' => $row->vesting_cliff_months === null ? null : (int) $row->vesting_cliff_months,
                    'vestingConditions' => $row->vesting_conditions === null ? null : (string) $row->vesting_conditions,
                    'earlyExitTreatment' => $row->early_exit_treatment === null ? null : (string) $row->early_exit_treatment,
                ],
            )->all(),
            'shareClasses' => $classes,
            'rightsReviewed' => $scenario->share_rights_reviewed_at !== null,
            'vestingReviewed' => $vestingReviewed,
            'capacity' => [
                'authorized' => (string) $scenario->authorized_shares,
                'issued' => (string) $issued,
                'reserved' => (string) $scenario->reserved_unissued_shares,
                'available' => $available === null ? null : (string) $available->value,
                'reviewed' => $scenario->capacity_reviewed_at !== null,
            ],
            'issuanceRule' => $rule === null ? null : [
                'approvalRule' => (string) $rule->approval_rule,
                'approvalThresholdPercent' => (string) $rule->approval_threshold_percent,
                'preemptionRight' => (bool) $rule->preemption_right,
                'valuationMethod' => (string) $rule->valuation_method,
                'dilutionAcknowledged' => (bool) $rule->dilution_acknowledged,
            ],
            'issuanceRuleComplete' => $ruleComplete,
            'approval' => $approval,
        ];
    }

    /** @return array<string,mixed> */
    private function approvalStatus(
        string $businessId,
        object $scenario,
        ?object $submission,
    ): array {
        if ($submission === null) {
            return [
                'state' => $scenario->status === 'draft'
                    ? 'draft'
                    : 'ready_for_approval',
                'submissionId' => null,
                'formalState' => null,
                'approvedAt' => null,
                'approvedBy' => [],
                'signatureRequired' => false,
                'signatureStatus' => null,
                'effectiveFrom' => null,
            ];
        }

        $decision = DB::table('decisions')
            ->where('business_id', $businessId)
            ->where('proposal_version_id', $submission->proposal_version_id)
            ->where('decision_type', 'ownership_approval')
            ->orderByDesc('created_at')
            ->first();

        $formalState = DB::table('record_version_state_transitions')
            ->where('business_id', $businessId)
            ->where('formal_record_version_id', $submission->formal_record_version_id)
            ->orderByDesc('sequence')
            ->value('to_state');

        $signatureRequired = false;
        $signatureStatus = null;

        if ($decision !== null) {
            $signatureRequired = (bool) DB::table('authority_snapshots')
                ->where('business_id', $businessId)
                ->where('id', $decision->authority_snapshot_id)
                ->value('signature_required');

            if ($signatureRequired) {
                $signatureStatus = DB::table('signature_requests')
                    ->where('business_id', $businessId)
                    ->where('decision_id', $decision->id)
                    ->orderByDesc('requested_at')
                    ->value('status');
            }
        }

        $effectiveFrom = $submission->effective_from === null
            ? null
            : (string) $submission->effective_from;

        $state = 'approval_in_progress';

        if ($submission->effective_register_version_id !== null) {
            $state = 'current_effective';
        } elseif ($decision !== null
            && $decision->status === 'decided'
            && $decision->outcome === 'approved') {
            if ($signatureRequired && $signatureStatus !== 'completed') {
                $state = 'approved_signature_pending';
            } elseif ($effectiveFrom !== null
                && CarbonImmutable::parse($effectiveFrom)->isFuture()) {
                $state = 'approved_waiting_effective_date';
            } else {
                $state = 'approved_ready_for_effect';
            }
        }

        return [
            'state' => $state,
            'submissionId' => (string) $submission->id,
            'formalState' => $formalState === null ? null : (string) $formalState,
            'approvedAt' => $decision?->resolved_at === null
                ? null
                : (string) $decision->resolved_at,
            'approvedBy' => $decision === null
                ? []
                : $this->approvalActorLabels(
                    $businessId,
                    (string) $decision->id,
                ),
            'signatureRequired' => $signatureRequired,
            'signatureStatus' => $signatureStatus === null ? null : (string) $signatureStatus,
            'effectiveFrom' => $effectiveFrom,
        ];
    }

    /** @return list<string> */
    private function approvalActorLabels(
        string $businessId,
        string $decisionId,
    ): array {
        $membershipIds = DB::table('approvals as approval')
            ->join('decision_participants as participant', function ($join): void {
                $join->on('participant.id', '=', 'approval.decision_participant_id')
                    ->on('participant.business_id', '=', 'approval.business_id')
                    ->on('participant.decision_id', '=', 'approval.decision_id');
            })
            ->where('approval.business_id', $businessId)
            ->where('approval.decision_id', $decisionId)
            ->where('approval.outcome', 'approved')
            ->where('participant.status', 'eligible')
            ->pluck('approval.membership_id')
            ->merge(
                DB::table('votes as vote')
                    ->join('decision_participants as participant', function ($join): void {
                        $join->on('participant.id', '=', 'vote.decision_participant_id')
                            ->on('participant.business_id', '=', 'vote.business_id')
                            ->on('participant.decision_id', '=', 'vote.decision_id');
                    })
                    ->where('vote.business_id', $businessId)
                    ->where('vote.decision_id', $decisionId)
                    ->where('vote.choice', 'for')
                    ->where('participant.status', 'eligible')
                    ->pluck('vote.membership_id'),
            )
            ->unique()
            ->values();

        if ($membershipIds->isEmpty()) {
            return [];
        }

        $memberships = Membership::query()
            ->with(['user.profile'])
            ->where('business_id', $businessId)
            ->whereIn('id', $membershipIds->all())
            ->get()
            ->keyBy(fn (Membership $membership): string => (string) $membership->getKey());

        return $membershipIds
            ->map(function (mixed $id) use ($memberships): string {
                $membership = $memberships->get((string) $id);
                if (! $membership instanceof Membership) {
                    return 'Unavailable member';
                }

                $displayName = trim((string) ($membership->user?->profile?->display_name ?? ''));

                return $displayName !== ''
                    ? $displayName
                    : (string) ($membership->user?->email ?? 'Business member');
            })
            ->all();
    }

    /** @return array<string,mixed> */
    private function register(string $businessId, object $register): array
    {
        $positions = DB::select(
            <<<'SQL'
SELECT
    p.id,
    partner.display_name AS partner_name,
    c.name AS share_class_name,
    p.shares_issued::text,
    p.shares_vested::text,
    (p.shares_issued - p.shares_vested)::text AS shares_unvested,
    p.vesting_applies,
    p.voting_rights::text,
    p.profit_rights::text,
    p.issue_date,
    CASE
        WHEN SUM(p.shares_issued) OVER () = 0 THEN '0.0000'
        ELSE ROUND(
            (p.shares_issued / SUM(p.shares_issued) OVER ()) * 100,
            4
        )::text
    END AS ownership_percentage
FROM ownership_register_positions p
JOIN partners partner
  ON partner.id = p.partner_id
 AND partner.business_id = p.business_id
JOIN ownership_register_share_classes c
  ON c.id = p.share_class_id
 AND c.business_id = p.business_id
 AND c.ownership_register_version_id = p.ownership_register_version_id
WHERE p.business_id = ?
  AND p.ownership_register_version_id = ?
ORDER BY partner.display_name, p.id
SQL,
            [$businessId, $register->id],
        );

        $rule = DB::table('ownership_register_issuance_rules')
            ->where('business_id', $businessId)
            ->where('ownership_register_version_id', $register->id)
            ->first();

        return [
            'id' => (string) $register->id,
            'versionNumber' => (int) $register->version_number,
            'status' => (string) $register->status,
            'currency' => (string) $register->currency,
            'shareValue' => number_format(
                ((int) $register->share_value_minor_units) / 100,
                2,
                '.',
                '',
            ),
            'effectiveFrom' => $register->effective_from === null ? null : (string) $register->effective_from,
            'effectiveUntil' => $register->effective_until === null ? null : (string) $register->effective_until,
            'capacity' => [
                'authorized' => (string) $register->authorized_shares,
                'issued' => (string) $register->issued_shares,
                'reserved' => (string) $register->reserved_unissued_shares,
                'available' => (string) $register->available_shares,
            ],
            'positions' => collect($positions)->map(
                static fn (object $row): array => [
                    'key' => (string) $row->id,
                    'partner' => (string) $row->partner_name,
                    'shareClass' => (string) $row->share_class_name,
                    'sharesIssued' => (string) $row->shares_issued,
                    'sharesVested' => (string) $row->shares_vested,
                    'sharesUnvested' => (string) $row->shares_unvested,
                    'vestingApplies' => $row->vesting_applies === null ? null : (bool) $row->vesting_applies,
                    'ownershipPercentage' => (string) $row->ownership_percentage,
                    'votingRights' => (string) $row->voting_rights,
                    'profitRights' => (string) $row->profit_rights,
                    'issueDate' => $row->issue_date === null ? null : (string) $row->issue_date,
                    'status' => 'Current / Effective',
                ],
            )->all(),
            'issuanceRule' => $rule === null ? null : [
                'approvalRule' => (string) $rule->approval_rule,
                'approvalThresholdPercent' => (string) $rule->approval_threshold_percent,
                'preemptionRight' => (bool) $rule->preemption_right,
                'valuationMethod' => (string) $rule->valuation_method,
                'dilutionAcknowledged' => (bool) $rule->dilution_acknowledged,
            ],
            'sourceBound' => $register->source_accepted_register_hash !== null
                && $register->source_contribution_decision_record_id !== null,
        ];
    }

    /** @return array{steps:list<array{key:string,state:string}>,nextStep:?string} */
    private function progress(
        bool $sourceReady,
        ?array $scenario,
        bool $hasCurrentRegister,
        bool $decisionRecorded,
    ): array {
        $states = [
            'accepted_contributions' => $sourceReady,
            'share_value' => $scenario !== null,
            'allocation' => $scenario !== null && ($scenario['positions'] ?? []) !== [],
            'share_classes_rights' => $scenario !== null && ($scenario['rightsReviewed'] ?? false),
            'vesting' => $scenario !== null && ($scenario['vestingReviewed'] ?? false),
            'voting_profit_rights' => $scenario !== null
                && ($scenario['rightsReviewed'] ?? false)
                && ($scenario['vestingReviewed'] ?? false)
                && ($scenario['positions'] ?? []) !== [],
            'share_capacity' => $scenario !== null && ($scenario['capacity']['reviewed'] ?? false),
            'new_share_rule' => $scenario !== null && ($scenario['issuanceRuleComplete'] ?? false),
            'review_approve' => $scenario !== null
                && in_array(
                    $scenario['approval']['state'] ?? '',
                    [
                        'approval_in_progress',
                        'approved_signature_pending',
                        'approved_waiting_effective_date',
                        'approved_ready_for_effect',
                        'current_effective',
                    ],
                    true,
                ),
            'share_register' => $hasCurrentRegister,
            'decision_record' => $decisionRecorded,
            'action_plan' => $decisionRecorded,
            'continue_governance' => $decisionRecorded,
        ];

        $firstMissing = collect(array_keys($states))->first(
            static fn (string $key): bool => ! $states[$key],
        );

        $steps = [];
        foreach ($states as $key => $complete) {
            $steps[] = [
                'key' => $key,
                'state' => $complete
                    ? 'recorded'
                    : ($firstMissing === $key ? 'current' : 'available'),
            ];
        }

        return [
            'steps' => $steps,
            'nextStep' => $firstMissing,
        ];
    }
}
