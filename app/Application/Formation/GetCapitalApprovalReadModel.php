<?php

declare(strict_types=1);

namespace App\Application\Formation;

use App\Application\Access\ResolveMembershipCapabilities;
use App\Application\Governance\GovernanceActorContext;
use App\Application\Governance\ResolveGovernanceAuthority;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Capital\CapitalApprovalContract;
use App\Domain\Governance\Enums\ApprovalOutcome;
use App\Domain\Governance\Enums\DecisionMethod;
use App\Domain\Governance\Enums\DecisionOutcome;
use App\Domain\Governance\Enums\DecisionStatus;
use App\Domain\Governance\Enums\ParticipantStatus;
use App\Domain\Governance\Enums\ProposalReviewOutcome;
use App\Domain\Governance\Enums\VoteChoice;
use App\Domain\Governance\ValueObjects\DecisionType;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Approval;
use App\Infrastructure\Persistence\Eloquent\Governance\AuthoritySnapshot;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Governance\DecisionParticipant;
use App\Infrastructure\Persistence\Eloquent\Governance\ProposalReview;
use App\Infrastructure\Persistence\Eloquent\Governance\Vote;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Support\Facades\DB;

final class GetCapitalApprovalReadModel
{
    public function __construct(
        private readonly FormationActorContext $actor,
        private readonly GovernanceActorContext $governanceActor,
        private readonly ResolveMembershipCapabilities $membershipCapabilities,
        private readonly BuildCapitalApprovalCandidate $candidate,
        private readonly ResolveGovernanceAuthority $authority,
    ) {}

    /**
     * @return array<string,mixed>|null
     */
    public function execute(
        User $user,
        Business $business,
    ): ?array {
        if (! $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::CAPITAL_VIEW,
        )) {
            return null;
        }

        $candidate = $this->candidate->execute($user, $business);

        if ($candidate === null) {
            return null;
        }

        $latest = DB::table('capital_approval_snapshots')
            ->where('business_id', $business->getKey())
            ->orderByDesc('prepared_at')
            ->first();

        $snapshot = $latest === null
            ? null
            : $this->snapshot($latest);

        $isCurrent = $this->snapshotIsCurrent(
            $business,
            $snapshot,
        );

        $formalState = $latest === null
            ? null
            : $this->latestFormalState(
                $business,
                (string) $latest->formal_record_version_id,
            );

        $review = $latest === null
            ? null
            : ProposalReview::query()
                ->where('business_id', $business->getKey())
                ->where('proposal_version_id', $latest->proposal_version_id)
                ->first();

        $decision = $latest === null
            ? null
            : Decision::query()
                ->where('business_id', $business->getKey())
                ->where('proposal_version_id', $latest->proposal_version_id)
                ->where('decision_type', CapitalApprovalContract::DECISION_TYPE)
                ->first();

        $authority = $this->authorityView(
            $business,
            $candidate,
            $decision,
        );

        $currentMembership = $this->membershipCapabilities
            ->activeMembership($user, $business);

        $canManageCapital = $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::CAPITAL_MANAGE,
        );
        $canManageRecords = $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::RECORDS_MANAGE,
        );
        $canManageGovernance = $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
        );

        $reviewView = $review === null
            ? null
            : [
                'status' => (string) $review->status,
                'outcome' => $review->outcome?->value,
                'notes' => $review->notes,
                'reviewer' => $this->membershipLabel(
                    $business,
                    (string) $review->reviewer_membership_id,
                ),
                'resolvedAt' => $this->timestamp($review->resolved_at),
            ];

        $decisionView = $decision === null
            ? null
            : $this->decisionView(
                $business,
                $decision,
                $currentMembership,
                $canManageGovernance,
            );

        $approved = $formalState === 'approved'
            && $decision?->status === DecisionStatus::Decided
            && $decision?->outcome === DecisionOutcome::Approved;

        $status = $this->status(
            $candidate,
            $snapshot,
            $isCurrent,
            $formalState,
            $review,
            $decision,
            $authority,
            $approved,
        );

        $canPrepare = $canManageCapital
            && $canManageRecords
            && $candidate['ready'] === true
            && ! $isCurrent;

        $canCreateReview = $isCurrent
            && $review === null
            && $formalState === 'ready_for_review'
            && $canManageRecords
            && $canManageGovernance;

        $canCompleteReview = $isCurrent
            && $review !== null
            && $review->status === 'open'
            && $currentMembership !== null
            && (string) $review->reviewer_membership_id
                === (string) $currentMembership->getKey()
            && $canManageGovernance
            && $this->governanceActor->canAccessResource(
                $user,
                $business,
                CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
                ProposalReview::class,
                (string) $review->getKey(),
            );

        $eligibleMeetings = $this->eligibleMeetings(
            $business,
            $authority,
        );

        $canOpenDecision = $isCurrent
            && $review?->status === 'completed'
            && $review?->outcome === ProposalReviewOutcome::Approved
            && $decision === null
            && $authority['configured'] === true
            && $canManageGovernance
            && (
                $authority['meetingRequired'] !== true
                || $eligibleMeetings !== []
            );

        $canResolve = $isCurrent
            && $decision?->status === DecisionStatus::Open
            && $canManageGovernance
            && $canManageRecords;

        $payload = $snapshot['payload']
            ?? $candidate['candidate']
            ?? null;

        return [
            'contractVersion' => 'capital-approval-read-model-v1',
            'approvalContractVersion' => CapitalApprovalContract::CONTRACT_VERSION,
            'decisionType' => CapitalApprovalContract::DECISION_TYPE,
            'candidateReady' => $candidate['ready'],
            'readinessReasons' => $candidate['reasons'],
            'hasPreparedVersion' => $snapshot !== null,
            'preparedVersionCurrent' => $isCurrent,
            'planChanged' => $snapshot !== null && ! $isCurrent,
            'formalState' => $formalState,
            'status' => $status,
            'approved' => $approved,
            'signed' => false,
            'effective' => false,
            'summary' => $this->summary($payload),
            'sourceRevisions' => $snapshot['sourceRevisions']
                ?? $this->candidateRevisions($candidate),
            'preferredPlan' => $payload['preferredPlan'] ?? null,
            'contentHash' => $snapshot['contentHash']
                ?? $candidate['contentHash'],
            'preparedAt' => $snapshot['preparedAt'] ?? null,
            'review' => $reviewView,
            'reviewers' => $this->reviewers($business),
            'authority' => $authority,
            'eligibleMeetings' => $eligibleMeetings,
            'decision' => $decisionView,
            'approvalEvidence' => $decision === null
                ? ['approvals' => [], 'votes' => []]
                : $this->approvalEvidence($business, $decision),
            'approvedBy' => $approved && $decision !== null
                ? $this->approvedBy($business, $decision)
                : [],
            'approvalDate' => $approved && $decision !== null
                ? $this->timestamp($decision->resolved_at)
                : null,
            'signatureRequired' => (bool) (
                $decisionView['signatureRequired']
                ?? $authority['signatureRequired']
                ?? false
            ),
            'actions' => [
                'canPrepare' => $canPrepare,
                'canCreateReview' => $canCreateReview,
                'canCompleteReview' => $canCompleteReview,
                'canOpenDecision' => $canOpenDecision,
                'canApprove' => (bool) ($decisionView['canApprove'] ?? false),
                'canVote' => (bool) ($decisionView['canVote'] ?? false),
                'canResolve' => $canResolve,
            ],
            'semantics' => [
                'approvalTruthOnly' => true,
                'signedTruth' => false,
                'effectiveTruth' => false,
                'decisionRecordComplete' => false,
                'actionPlanCreated' => false,
                'capitalCallExecuted' => false,
                'contributionTruth' => false,
                'acceptedContributionTruth' => false,
                'equityTruth' => false,
                'ownershipTruth' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    private function authorityView(
        Business $business,
        array $candidate,
        ?Decision $decision,
    ): array {
        if ($decision !== null) {
            $snapshot = AuthoritySnapshot::query()
                ->where('business_id', $business->getKey())
                ->whereKey($decision->authority_snapshot_id)
                ->first();

            if ($snapshot === null) {
                return $this->emptyAuthority();
            }

            $actors = DecisionParticipant::query()
                ->where('business_id', $business->getKey())
                ->where('decision_id', $decision->getKey())
                ->orderBy('membership_id')
                ->get()
                ->map(fn (DecisionParticipant $participant): array => [
                    'name' => $this->membershipLabel(
                        $business,
                        (string) $participant->membership_id,
                    ),
                    'capacity' => (string) $participant->capacity,
                    'status' => $participant->status->value,
                    'canApprove' => (bool) $participant->can_approve,
                    'canVote' => (bool) $participant->can_vote,
                ])
                ->values()
                ->all();

            return [
                'configured' => true,
                'sourceKind' => (string) $snapshot->source_kind,
                'method' => $snapshot->decision_method->value,
                'requiredApprovals' => (int) $snapshot->required_approvals,
                'requiredVotes' => (int) $snapshot->required_votes,
                'quorumCount' => (int) $snapshot->quorum_count,
                'signatureRequired' => (bool) $snapshot->signature_required,
                'meetingRequired' => (bool) $snapshot->meeting_required,
                'recordRequired' => (bool) $snapshot->record_required,
                'actors' => $actors,
                'captured' => true,
                'sourceVersionId' => (string) $snapshot->source_formal_record_version_id,
            ];
        }

        if (
            $candidate['ready'] !== true
            || ! is_array($candidate['candidate'])
        ) {
            return $this->emptyAuthority();
        }

        $amount = $candidate['candidate']['calculation'][
            'totalCapitalRequirement'
        ]['amount'] ?? null;

        if (! is_string($amount)) {
            return $this->emptyAuthority();
        }

        $resolved = $this->authority->resolve(
            $business,
            new DecisionType(CapitalApprovalContract::DECISION_TYPE),
            $amount,
        );

        if ($resolved === null) {
            return $this->emptyAuthority();
        }

        $method = $resolved['rule']['decision_method'];
        $methodValue = $method instanceof DecisionMethod
            ? $method->value
            : (string) $method;

        return [
            'configured' => true,
            'sourceKind' => (string) $resolved['source_kind'],
            'method' => $methodValue,
            'requiredApprovals' => (int) $resolved['rule']['required_approvals'],
            'requiredVotes' => (int) $resolved['rule']['required_votes'],
            'quorumCount' => (int) $resolved['rule']['quorum_count'],
            'signatureRequired' => (bool) $resolved['rule']['signature_required'],
            'meetingRequired' => (bool) $resolved['rule']['meeting_required'],
            'recordRequired' => (bool) $resolved['rule']['record_required'],
            'actors' => $resolved['actors']
                ->map(fn ($actor): array => [
                    'name' => $this->membershipLabel(
                        $business,
                        $actor->membershipId,
                    ),
                    'capacity' => $actor->capacity,
                    'status' => 'eligible',
                    'canApprove' => $actor->canApprove,
                    'canVote' => $actor->canVote,
                ])
                ->values()
                ->all(),
            'captured' => false,
            'sourceVersionId' => (string) $resolved['source_version']->getKey(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyAuthority(): array
    {
        return [
            'configured' => false,
            'sourceKind' => null,
            'method' => null,
            'requiredApprovals' => 0,
            'requiredVotes' => 0,
            'quorumCount' => 0,
            'signatureRequired' => false,
            'meetingRequired' => false,
            'recordRequired' => true,
            'actors' => [],
            'captured' => false,
            'sourceVersionId' => null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decisionView(
        Business $business,
        Decision $decision,
        ?Membership $membership,
        bool $canManageGovernance,
    ): array {
        $snapshot = AuthoritySnapshot::query()
            ->where('business_id', $business->getKey())
            ->whereKey($decision->authority_snapshot_id)
            ->first();

        $participant = $membership === null
            ? null
            : DecisionParticipant::query()
                ->where('business_id', $business->getKey())
                ->where('decision_id', $decision->getKey())
                ->where('membership_id', $membership->getKey())
                ->first();

        $approvalCount = Approval::query()
            ->where('business_id', $business->getKey())
            ->where('decision_id', $decision->getKey())
            ->where('outcome', ApprovalOutcome::Approved->value)
            ->whereIn(
                'decision_participant_id',
                DecisionParticipant::query()
                    ->select('id')
                    ->where('business_id', $business->getKey())
                    ->where('decision_id', $decision->getKey())
                    ->where('status', ParticipantStatus::Eligible->value),
            )
            ->count();

        $supportingVotes = Vote::query()
            ->where('business_id', $business->getKey())
            ->where('decision_id', $decision->getKey())
            ->where('choice', VoteChoice::For->value)
            ->whereIn(
                'decision_participant_id',
                DecisionParticipant::query()
                    ->select('id')
                    ->where('business_id', $business->getKey())
                    ->where('decision_id', $decision->getKey())
                    ->where('status', ParticipantStatus::Eligible->value),
            )
            ->count();

        $votesCast = Vote::query()
            ->where('business_id', $business->getKey())
            ->where('decision_id', $decision->getKey())
            ->whereIn(
                'choice',
                [
                    VoteChoice::For->value,
                    VoteChoice::Against->value,
                    VoteChoice::Abstain->value,
                ],
            )
            ->whereIn(
                'decision_participant_id',
                DecisionParticipant::query()
                    ->select('id')
                    ->where('business_id', $business->getKey())
                    ->where('decision_id', $decision->getKey())
                    ->where('status', ParticipantStatus::Eligible->value),
            )
            ->count();

        $hasApproval = $participant !== null
            && Approval::query()
                ->where('business_id', $business->getKey())
                ->where('decision_id', $decision->getKey())
                ->where('decision_participant_id', $participant->getKey())
                ->exists();

        $hasVote = $participant !== null
            && Vote::query()
                ->where('business_id', $business->getKey())
                ->where('decision_id', $decision->getKey())
                ->where('decision_participant_id', $participant->getKey())
                ->exists();

        $open = $decision->status === DecisionStatus::Open;
        $eligible = $participant?->status === ParticipantStatus::Eligible;

        return [
            'status' => $decision->status->value,
            'outcome' => $decision->outcome?->value,
            'method' => $snapshot?->decision_method->value,
            'requiredApprovals' => (int) ($snapshot?->required_approvals ?? 0),
            'requiredVotes' => (int) ($snapshot?->required_votes ?? 0),
            'quorumCount' => (int) ($snapshot?->quorum_count ?? 0),
            'signatureRequired' => (bool) ($snapshot?->signature_required ?? false),
            'meetingRequired' => (bool) ($snapshot?->meeting_required ?? false),
            'progress' => [
                'approvals' => $approvalCount,
                'supportingVotes' => $supportingVotes,
                'votesCast' => $votesCast,
            ],
            'openedAt' => $this->timestamp($decision->opened_at),
            'resolvedAt' => $this->timestamp($decision->resolved_at),
            'canApprove' => $open
                && $canManageGovernance
                && $eligible
                && (bool) $participant?->can_approve
                && ! $hasApproval,
            'canVote' => $open
                && $canManageGovernance
                && $eligible
                && (bool) $participant?->can_vote
                && ! $hasVote,
        ];
    }

    /**
     * @return list<array{name:string,recordedAt:?string}>
     */
    private function approvedBy(
        Business $business,
        Decision $decision,
    ): array {
        $rows = Approval::query()
            ->where('business_id', $business->getKey())
            ->where('decision_id', $decision->getKey())
            ->where('outcome', ApprovalOutcome::Approved->value)
            ->orderBy('recorded_at')
            ->get()
            ->map(fn (Approval $approval): array => [
                'name' => $this->membershipLabel(
                    $business,
                    (string) $approval->membership_id,
                ),
                'recordedAt' => $this->timestamp($approval->recorded_at),
            ])
            ->values()
            ->all();

        if ($rows !== []) {
            return $rows;
        }

        return Vote::query()
            ->where('business_id', $business->getKey())
            ->where('decision_id', $decision->getKey())
            ->where('choice', VoteChoice::For->value)
            ->orderBy('cast_at')
            ->get()
            ->map(fn (Vote $vote): array => [
                'name' => $this->membershipLabel(
                    $business,
                    (string) $vote->membership_id,
                ),
                'recordedAt' => $this->timestamp($vote->cast_at),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{approvals:list<array<string,mixed>>,votes:list<array<string,mixed>>}
     */
    private function approvalEvidence(
        Business $business,
        Decision $decision,
    ): array {
        $approvals = Approval::query()
            ->where('business_id', $business->getKey())
            ->where('decision_id', $decision->getKey())
            ->orderBy('recorded_at')
            ->get()
            ->map(fn (Approval $approval): array => [
                'name' => $this->membershipLabel(
                    $business,
                    (string) $approval->membership_id,
                ),
                'outcome' => $approval->outcome->value,
                'recordedAt' => $this->timestamp($approval->recorded_at),
            ])
            ->values()
            ->all();

        $votes = Vote::query()
            ->where('business_id', $business->getKey())
            ->where('decision_id', $decision->getKey())
            ->orderBy('cast_at')
            ->get()
            ->map(fn (Vote $vote): array => [
                'name' => $this->membershipLabel(
                    $business,
                    (string) $vote->membership_id,
                ),
                'choice' => $vote->choice->value,
                'recordedAt' => $this->timestamp($vote->cast_at),
            ])
            ->values()
            ->all();

        return [
            'approvals' => $approvals,
            'votes' => $votes,
        ];
    }

    /**
     * @return list<array{id:string,name:string}>
     */
    private function reviewers(Business $business): array
    {
        $capability = new Capability(
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
        );

        return Membership::query()
            ->with(['user.profile'])
            ->where('business_id', $business->getKey())
            ->where('access_status', 'active')
            ->get()
            ->filter(
                fn (Membership $membership): bool => $this->membershipCapabilities
                    ->decide($membership, $capability)
                    ->allowed,
            )
            ->map(fn (Membership $membership): array => [
                'id' => (string) $membership->getKey(),
                'name' => $this->label($membership),
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $authority
     * @return list<array{id:string,label:string}>
     */
    private function eligibleMeetings(
        Business $business,
        array $authority,
    ): array {
        if (
            $authority['configured'] !== true
            || $authority['meetingRequired'] !== true
            || ! is_string($authority['sourceVersionId'])
        ) {
            return [];
        }

        return DB::table('governance_meetings')
            ->where('business_id', $business->getKey())
            ->where(
                'authority_source_formal_record_version_id',
                $authority['sourceVersionId'],
            )
            ->where('status', 'held')
            ->whereColumn('quorum_present', '>=', 'quorum_required')
            ->orderByDesc('held_at')
            ->limit(20)
            ->get()
            ->map(static fn (object $meeting): array => [
                'id' => (string) $meeting->id,
                'label' => (string) $meeting->title,
            ])
            ->all();
    }

    /**
     * @param  array<string,mixed>|null  $payload
     * @return array<string,mixed>|null
     */
    private function summary(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $calculation = $payload['calculation'] ?? null;
        $rule = $payload['capitalRule'] ?? null;

        if (! is_array($calculation) || ! is_array($rule)) {
            return null;
        }

        return [
            'baseCurrency' => $payload['business']['baseCurrency'] ?? null,
            'preferredPlan' => $payload['preferredPlan'] ?? null,
            'totalCapitalRequirement' => $calculation[
                'totalCapitalRequirement'
            ]['amount'] ?? null,
            'confirmedFunding' => $calculation[
                'fundingPosition'
            ]['confirmedFunding'] ?? null,
            'fundingGap' => $calculation[
                'fundingPosition'
            ]['fundingGap'] ?? null,
            'fundingSurplus' => $calculation[
                'fundingPosition'
            ]['fundingSurplus'] ?? null,
            'fundedPercentage' => $calculation[
                'fundingPosition'
            ]['fundedPercentage'] ?? null,
            'shortfallResponses' => $rule['shortfallResponses'] ?? [],
            'allocationNotes' => $rule['allocationNotes'] ?? null,
            'shortfallRuleNotes' => $rule['shortfallRuleNotes'] ?? null,
            'capitalCallRuleNote' => $rule['capitalCallRuleNote'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $snapshot
     */
    private function snapshotIsCurrent(
        Business $business,
        ?array $snapshot,
    ): bool {
        if ($snapshot === null) {
            return false;
        }

        $row = DB::table('capital_approval_snapshots')
            ->where('business_id', $business->getKey())
            ->where('content_hash', $snapshot['contentHash'])
            ->where('preferred_plan', $snapshot['preferredPlan'])
            ->orderByDesc('prepared_at')
            ->first();

        if ($row === null) {
            return false;
        }

        $planning = DB::table('capital_planning_drafts')
            ->where('business_id', $business->getKey())
            ->first();
        $rule = DB::table('capital_rule_drafts')
            ->where('business_id', $business->getKey())
            ->first();
        $comparison = DB::table('capital_comparison_drafts')
            ->where('business_id', $business->getKey())
            ->first();

        if (
            $planning === null
            || $rule === null
            || $comparison === null
        ) {
            return false;
        }

        $comparisonPayload = json_decode(
            (string) $comparison->input_payload,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        if (! is_array($comparisonPayload)) {
            return false;
        }

        return (string) $planning->id
                === (string) $row->capital_planning_draft_id
            && (int) $planning->revision
                === (int) $row->capital_planning_revision
            && (string) $rule->id
                === (string) $row->capital_rule_draft_id
            && (int) $rule->revision
                === (int) $row->capital_rule_revision
            && (int) $rule->capital_planning_revision
                === (int) $row->capital_planning_revision
            && (string) $comparison->id
                === (string) $row->capital_comparison_draft_id
            && (int) $comparison->revision
                === (int) $row->capital_comparison_revision
            && (int) $comparison->capital_planning_revision
                === (int) $row->capital_planning_revision
            && ($comparisonPayload['preferredPlan'] ?? null)
                === (string) $row->preferred_plan
            && is_array(
                $comparisonPayload['scenarios'][
                    (string) $row->preferred_plan
                ] ?? null,
            );
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,int>|null
     */
    private function candidateRevisions(array $candidate): ?array
    {
        if (
            $candidate['ready'] !== true
            || ! is_array($candidate['candidate'])
        ) {
            return null;
        }

        return [
            'capitalPlanning' => (int) $candidate['candidate']['sources']['capitalPlanning']['revision'],
            'capitalRule' => (int) $candidate['candidate']['sources']['capitalRule']['revision'],
            'capitalComparison' => (int) $candidate['candidate']['sources']['capitalComparison']['revision'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(object $row): array
    {
        $payload = json_decode(
            (string) $row->snapshot_payload,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return [
            'contentHash' => (string) $row->content_hash,
            'preferredPlan' => (string) $row->preferred_plan,
            'sourceRevisions' => [
                'capitalPlanning' => (int) $row->capital_planning_revision,
                'capitalRule' => (int) $row->capital_rule_revision,
                'capitalComparison' => (int) $row->capital_comparison_revision,
            ],
            'preparedAt' => (string) $row->prepared_at,
            'payload' => is_array($payload) ? $payload : null,
        ];
    }

    private function latestFormalState(
        Business $business,
        string $formalRecordVersionId,
    ): ?string {
        $state = DB::table('record_version_state_transitions')
            ->where('business_id', $business->getKey())
            ->where('formal_record_version_id', $formalRecordVersionId)
            ->orderByDesc('sequence')
            ->value('to_state');

        return is_string($state) ? $state : null;
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<string,mixed>|null  $snapshot
     * @param  array<string,mixed>  $authority
     */
    private function status(
        array $candidate,
        ?array $snapshot,
        bool $isCurrent,
        ?string $formalState,
        ?ProposalReview $review,
        ?Decision $decision,
        array $authority,
        bool $approved,
    ): string {
        if ($snapshot !== null && ! $isCurrent) {
            return 'plan_changed';
        }

        if ($approved) {
            return 'approved';
        }

        if ($candidate['ready'] !== true) {
            return 'needs_upstream';
        }

        if ($snapshot === null) {
            return 'ready_to_prepare';
        }

        if ($review === null) {
            return 'ready_for_review';
        }

        if ($review->status === 'open') {
            return 'review_in_progress';
        }

        if ($review->outcome !== ProposalReviewOutcome::Approved) {
            return 'review_changes_required';
        }

        if ($decision === null) {
            return $authority['configured'] === true
                ? 'ready_for_approval'
                : 'authority_not_configured';
        }

        if ($decision->status === DecisionStatus::Open) {
            return 'approval_in_progress';
        }

        if (
            $decision->status === DecisionStatus::Decided
            && $decision->outcome === DecisionOutcome::Approved
            && $formalState !== 'approved'
        ) {
            return 'approval_resolved';
        }

        return 'not_yet_approved';
    }

    private function membershipLabel(
        Business $business,
        string $membershipId,
    ): string {
        $membership = Membership::query()
            ->with(['user.profile'])
            ->where('business_id', $business->getKey())
            ->whereKey($membershipId)
            ->first();

        return $membership === null
            ? 'Unavailable member'
            : $this->label($membership);
    }

    private function label(Membership $membership): string
    {
        $display = trim((string) ($membership->user?->profile?->display_name ?? ''));

        if ($display !== '') {
            return $display;
        }

        return (string) ($membership->user?->email ?? 'Business member');
    }

    private function timestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return method_exists($value, 'toIso8601String')
            ? $value->toIso8601String()
            : (string) $value;
    }
}
