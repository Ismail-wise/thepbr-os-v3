<?php

declare(strict_types=1);

namespace App\Application\Formation;

use App\Application\Governance\CastGovernanceVote;
use App\Application\Governance\CompleteProposalReview;
use App\Application\Governance\CreateProposalReview;
use App\Application\Governance\OpenGovernanceDecision;
use App\Application\Governance\RecordGovernanceApproval;
use App\Application\Governance\ResolveGovernanceAuthority;
use App\Application\Governance\ResolveGovernanceDecision;
use App\Application\Records\CreateDraftRecordVersion;
use App\Application\Records\CreateFormalRecordFamily;
use App\Application\Records\CreateProposal;
use App\Application\Records\FreezeProposalVersion;
use App\Application\Records\SubmitRecordVersionForReview;
use App\Application\Records\TransitionFormalRecordVersion;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Capital\CapitalApprovalContract;
use App\Domain\Governance\Enums\ApprovalOutcome;
use App\Domain\Governance\Enums\DecisionOutcome;
use App\Domain\Governance\Enums\DecisionStatus;
use App\Domain\Governance\Enums\ProposalReviewOutcome;
use App\Domain\Governance\Enums\VoteChoice;
use App\Domain\Governance\ValueObjects\DecisionType;
use App\Domain\Records\Enums\FormalRecordState;
use App\Domain\Records\ValueObjects\RecordScope;
use App\Domain\Records\ValueObjects\RequirementResult;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Governance\ProposalReview;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class CapitalApprovalWorkflow
{
    public function __construct(
        private readonly FormationActorContext $actor,
        private readonly FormationOccurrence $occurrence,
        private readonly BuildCapitalApprovalCandidate $candidate,
        private readonly CreateFormalRecordFamily $createFamily,
        private readonly CreateDraftRecordVersion $createDraft,
        private readonly SubmitRecordVersionForReview $submitForReview,
        private readonly CreateProposal $createProposal,
        private readonly FreezeProposalVersion $freezeProposal,
        private readonly CreateProposalReview $createReview,
        private readonly CompleteProposalReview $completeReview,
        private readonly ResolveGovernanceAuthority $authority,
        private readonly OpenGovernanceDecision $openDecision,
        private readonly RecordGovernanceApproval $recordApproval,
        private readonly CastGovernanceVote $castVote,
        private readonly ResolveGovernanceDecision $resolveDecision,
        private readonly TransitionFormalRecordVersion $transitionRecord,
    ) {}

    /**
     * @return array<string,mixed>|null
     */
    public function prepare(
        User $user,
        Business $business,
    ): ?array {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::CAPITAL_MANAGE,
        );

        if (
            $membership === null
            || ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::RECORDS_MANAGE,
            )
        ) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $membership,
        ): array {
            $this->lockSources($business);

            $candidate = $this->requireReadyCandidate($user, $business);
            $payload = $candidate['candidate'];
            $contentHash = (string) $candidate['contentHash'];
            $sources = $payload['sources'];

            $existing = DB::table('capital_approval_snapshots')
                ->where('business_id', $business->getKey())
                ->where('capital_planning_revision', $sources['capitalPlanning']['revision'])
                ->where('capital_rule_revision', $sources['capitalRule']['revision'])
                ->where('capital_comparison_revision', $sources['capitalComparison']['revision'])
                ->where('preferred_plan', $payload['preferredPlan'])
                ->where('content_hash', $contentHash)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $this->snapshotEnvelope($existing, false);
            }

            $recordsCapability = new Capability(
                CapabilityCatalog::RECORDS_MANAGE,
            );

            $family = FormalRecordFamily::query()
                ->where('business_id', $business->getKey())
                ->where('record_type', 'capital_plan')
                ->where('subject_type', 'business')
                ->where('subject_id', (string) $business->getKey())
                ->lockForUpdate()
                ->first();

            if ($family === null) {
                $family = $this->createFamily->execute(
                    $user,
                    $business,
                    $recordsCapability,
                    new RecordScope(
                        'capital_plan',
                        'business',
                        (string) $business->getKey(),
                    ),
                );

                if ($family === null) {
                    throw new RuntimeException(
                        'Capital approval record could not be prepared with the current Records access.',
                    );
                }
            }

            $predecessor = FormalRecordVersion::query()
                ->where('business_id', $business->getKey())
                ->where('formal_record_family_id', $family->getKey())
                ->orderByDesc('version_number')
                ->lockForUpdate()
                ->first();

            $recordVersion = $this->createDraft->execute(
                $user,
                $business,
                $recordsCapability,
                (string) $family->getKey(),
                $contentHash,
                $predecessor === null
                    ? 'Initial governed Capital Plan approval candidate.'
                    : 'Updated governed Capital Plan approval candidate.',
                null,
                null,
                null,
                $predecessor === null
                    ? null
                    : (string) $predecessor->getKey(),
            );

            if ($recordVersion === null) {
                throw new RuntimeException(
                    'Capital approval record could not be prepared with the current Records access.',
                );
            }

            $frozenRecord = $this->submitForReview->execute(
                $user,
                $business,
                $recordsCapability,
                (string) $recordVersion->getKey(),
                1,
            );

            if ($frozenRecord === null) {
                throw new RuntimeException(
                    'Capital approval version could not be frozen for review.',
                );
            }

            $proposal = $this->createProposal->execute(
                $user,
                $business,
                $recordsCapability,
                $contentHash,
            );

            if ($proposal === null) {
                throw new RuntimeException(
                    'Capital approval proposal could not be prepared.',
                );
            }

            $proposalVersion = $this->freezeProposal->execute(
                $user,
                $business,
                $recordsCapability,
                (string) $proposal->getKey(),
                1,
                [(string) $frozenRecord->getKey()],
            );

            if ($proposalVersion === null) {
                throw new RuntimeException(
                    'Capital approval proposal could not be frozen.',
                );
            }

            $id = (string) Str::uuid7();
            $preparedAt = now();

            DB::table('capital_approval_snapshots')->insert([
                'id' => $id,
                'business_id' => $business->getKey(),
                'contract_version' => CapitalApprovalContract::CONTRACT_VERSION,
                'capital_planning_draft_id' => $sources['capitalPlanning']['id'],
                'capital_planning_revision' => $sources['capitalPlanning']['revision'],
                'capital_rule_draft_id' => $sources['capitalRule']['id'],
                'capital_rule_revision' => $sources['capitalRule']['revision'],
                'capital_comparison_draft_id' => $sources['capitalComparison']['id'],
                'capital_comparison_revision' => $sources['capitalComparison']['revision'],
                'preferred_plan' => $payload['preferredPlan'],
                'snapshot_payload' => json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
                ),
                'content_hash' => $contentHash,
                'formal_record_version_id' => $frozenRecord->getKey(),
                'proposal_version_id' => $proposalVersion->getKey(),
                'prepared_by_membership_id' => $membership->getKey(),
                'prepared_at' => $preparedAt,
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'capital.approval.prepared',
                'capital_approval_snapshot',
                $id,
                [
                    'contract_version' => CapitalApprovalContract::CONTRACT_VERSION,
                    'preferred_plan' => $payload['preferredPlan'],
                    'capital_planning_revision' => $sources['capitalPlanning']['revision'],
                    'capital_rule_revision' => $sources['capitalRule']['revision'],
                    'capital_comparison_revision' => $sources['capitalComparison']['revision'],
                    'formal_record_version_id' => (string) $frozenRecord->getKey(),
                    'proposal_version_id' => (string) $proposalVersion->getKey(),
                ],
            );

            $row = DB::table('capital_approval_snapshots')
                ->where('business_id', $business->getKey())
                ->where('id', $id)
                ->sole();

            return $this->snapshotEnvelope($row, true);
        });
    }

    public function createProposalReview(
        User $user,
        Business $business,
        string $reviewerMembershipId,
    ): ?ProposalReview {
        if (
            ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::CAPITAL_VIEW,
            )
            || ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::RECORDS_MANAGE,
            )
            || ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
            )
        ) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $reviewerMembershipId,
        ): ProposalReview {
            $snapshot = $this->requireFreshSnapshot($user, $business);

            $existing = ProposalReview::query()
                ->where('business_id', $business->getKey())
                ->where('proposal_version_id', $snapshot->proposal_version_id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $review = $this->createReview->execute(
                $user,
                $business,
                (string) $snapshot->proposal_version_id,
                $reviewerMembershipId,
            );

            if ($review === null) {
                throw new RuntimeException(
                    'The selected reviewer cannot be assigned to this Capital Plan.',
                );
            }

            $transitioned = $this->transitionRecord->execute(
                $user,
                $business,
                new Capability(CapabilityCatalog::RECORDS_MANAGE),
                (string) $snapshot->formal_record_version_id,
                FormalRecordState::UnderReview,
                [RequirementResult::met('proposal_review_created')],
            );

            if ($transitioned === null) {
                throw new RuntimeException(
                    'Capital Plan review could not be started.',
                );
            }

            return $review->fresh();
        });
    }

    public function completeProposalReview(
        User $user,
        Business $business,
        ProposalReviewOutcome $outcome,
        ?string $notes = null,
    ): ?ProposalReview {
        if (
            ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::CAPITAL_VIEW,
            )
            || ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
            )
        ) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $outcome,
            $notes,
        ): ProposalReview {
            $snapshot = $this->requireFreshSnapshot($user, $business);

            $review = ProposalReview::query()
                ->where('business_id', $business->getKey())
                ->where('proposal_version_id', $snapshot->proposal_version_id)
                ->lockForUpdate()
                ->first();

            if ($review === null) {
                throw new RuntimeException(
                    'Review Final Plan must be assigned before it can be confirmed.',
                );
            }

            if ($review->status === 'completed') {
                return $review;
            }

            $completed = $this->completeReview->execute(
                $user,
                $business,
                (string) $review->getKey(),
                $outcome,
                $notes,
            );

            if ($completed === null) {
                throw new RuntimeException(
                    'Only the assigned reviewer can confirm this Capital Plan review.',
                );
            }

            return $completed;
        });
    }

    public function openDecision(
        User $user,
        Business $business,
        ?string $meetingId = null,
    ): ?Decision {
        if (
            ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::CAPITAL_VIEW,
            )
            || ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
            )
        ) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $meetingId,
        ): Decision {
            $snapshot = $this->requireFreshSnapshot($user, $business);
            $payload = $this->payload($snapshot);

            $review = ProposalReview::query()
                ->where('business_id', $business->getKey())
                ->where('proposal_version_id', $snapshot->proposal_version_id)
                ->lockForUpdate()
                ->first();

            if (
                $review === null
                || $review->status !== 'completed'
                || $review->outcome !== ProposalReviewOutcome::Approved
            ) {
                throw new RuntimeException(
                    'Review Final Plan must be confirmed before Capital approval can begin.',
                );
            }

            $existing = Decision::query()
                ->where('business_id', $business->getKey())
                ->where('proposal_version_id', $snapshot->proposal_version_id)
                ->where('decision_type', CapitalApprovalContract::DECISION_TYPE)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $decisionAmount = $payload['calculation'][
                'totalCapitalRequirement'
            ]['amount'] ?? null;

            if (! is_string($decisionAmount)) {
                throw new RuntimeException(
                    'The Preferred Capital Plan is no longer calculable.',
                );
            }

            $authority = $this->authority->resolve(
                $business,
                new DecisionType(CapitalApprovalContract::DECISION_TYPE),
                $decisionAmount,
            );

            if ($authority === null) {
                throw new RuntimeException(
                    'Capital approval authority has not been configured yet.',
                );
            }

            if (
                $authority['rule']['meeting_required']
                && $meetingId === null
            ) {
                throw new RuntimeException(
                    'This Capital approval requires a held Governance Meeting with quorum.',
                );
            }

            $decision = $this->openDecision->execute(
                $user,
                $business,
                (string) $snapshot->proposal_version_id,
                new DecisionType(CapitalApprovalContract::DECISION_TYPE),
                $decisionAmount,
                $meetingId,
            );

            if ($decision === null) {
                throw new RuntimeException(
                    'Capital approval could not be opened with the current Governance authority.',
                );
            }

            return $decision;
        });
    }

    public function approve(
        User $user,
        Business $business,
        ?string $rationale = null,
    ): mixed {
        if (
            ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::CAPITAL_VIEW,
            )
            || ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
            )
        ) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $rationale,
        ): mixed {
            $snapshot = $this->requireFreshSnapshot($user, $business);
            $decision = $this->openCapitalDecision(
                $business,
                (string) $snapshot->proposal_version_id,
            );

            if ($decision === null) {
                throw new RuntimeException(
                    'Capital approval has not been opened yet.',
                );
            }

            return $this->recordApproval->execute(
                $user,
                $business,
                (string) $decision->getKey(),
                ApprovalOutcome::Approved,
                $rationale,
            );
        });
    }

    public function vote(
        User $user,
        Business $business,
        VoteChoice $choice,
        ?string $rationale = null,
    ): mixed {
        if (
            ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::CAPITAL_VIEW,
            )
            || ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
            )
        ) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $choice,
            $rationale,
        ): mixed {
            $snapshot = $this->requireFreshSnapshot($user, $business);
            $decision = $this->openCapitalDecision(
                $business,
                (string) $snapshot->proposal_version_id,
            );

            if ($decision === null) {
                throw new RuntimeException(
                    'Capital approval has not been opened yet.',
                );
            }

            return $this->castVote->execute(
                $user,
                $business,
                (string) $decision->getKey(),
                $choice,
                $rationale,
            );
        });
    }

    public function resolve(
        User $user,
        Business $business,
    ): ?FormalRecordVersion {
        if (
            ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::CAPITAL_VIEW,
            )
            || ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::RECORDS_MANAGE,
            )
            || ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
            )
        ) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
        ): FormalRecordVersion {
            $snapshot = $this->requireFreshSnapshot($user, $business);

            $decision = Decision::query()
                ->where('business_id', $business->getKey())
                ->where('proposal_version_id', $snapshot->proposal_version_id)
                ->where('decision_type', CapitalApprovalContract::DECISION_TYPE)
                ->lockForUpdate()
                ->first();

            if ($decision === null) {
                throw new RuntimeException(
                    'Capital approval has not been opened yet.',
                );
            }

            if (
                $decision->status === DecisionStatus::Open
            ) {
                $resolved = $this->resolveDecision->approve(
                    $user,
                    $business,
                    (string) $decision->getKey(),
                );

                if ($resolved === null) {
                    throw new RuntimeException(
                        'Capital approval could not be resolved.',
                    );
                }

                $decision = $resolved;
            }

            if (
                $decision->status !== DecisionStatus::Decided
                || $decision->outcome !== DecisionOutcome::Approved
            ) {
                throw new RuntimeException(
                    'Governance approval requirements have not been satisfied.',
                );
            }

            $state = $this->latestRecordState(
                $business,
                (string) $snapshot->formal_record_version_id,
            );

            if ($state === FormalRecordState::Approved) {
                return FormalRecordVersion::query()
                    ->where('business_id', $business->getKey())
                    ->whereKey($snapshot->formal_record_version_id)
                    ->sole();
            }

            if ($state !== FormalRecordState::UnderReview) {
                throw new RuntimeException(
                    'The frozen Capital Plan is not in the expected review state.',
                );
            }

            $approved = $this->transitionRecord->execute(
                $user,
                $business,
                new Capability(CapabilityCatalog::RECORDS_MANAGE),
                (string) $snapshot->formal_record_version_id,
                FormalRecordState::Approved,
                [
                    RequirementResult::met(
                        'governance_decision_approved',
                        'Exact frozen Capital proposal received governed approval.',
                    ),
                ],
            );

            if ($approved === null) {
                throw new RuntimeException(
                    'Approved Governance evidence could not be applied to the Capital Plan record.',
                );
            }

            $this->occurrence->record(
                $user,
                $business,
                'capital.approval.completed',
                'capital_approval_snapshot',
                (string) $snapshot->id,
                [
                    'formal_record_version_id' => (string) $snapshot->formal_record_version_id,
                    'proposal_version_id' => (string) $snapshot->proposal_version_id,
                    'decision_id' => (string) $decision->getKey(),
                ],
            );

            return $approved;
        });
    }

    private function requireFreshSnapshot(
        User $user,
        Business $business,
    ): object {
        $this->lockSources($business);

        $snapshot = DB::table('capital_approval_snapshots')
            ->where('business_id', $business->getKey())
            ->orderByDesc('prepared_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($snapshot === null) {
            throw new RuntimeException(
                'Prepare the Final Capital Plan for approval first.',
            );
        }

        $planning = DB::table('capital_planning_drafts')
            ->where('business_id', $business->getKey())
            ->lockForUpdate()
            ->first();
        $rule = DB::table('capital_rule_drafts')
            ->where('business_id', $business->getKey())
            ->lockForUpdate()
            ->first();
        $comparison = DB::table('capital_comparison_drafts')
            ->where('business_id', $business->getKey())
            ->lockForUpdate()
            ->first();

        $comparisonPayload = $comparison === null
            ? null
            : json_decode(
                (string) $comparison->input_payload,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

        $fresh = $planning !== null
            && $rule !== null
            && $comparison !== null
            && is_array($comparisonPayload)
            && (string) $planning->id
                === (string) $snapshot->capital_planning_draft_id
            && (int) $planning->revision
                === (int) $snapshot->capital_planning_revision
            && (string) $rule->id
                === (string) $snapshot->capital_rule_draft_id
            && (int) $rule->revision
                === (int) $snapshot->capital_rule_revision
            && (int) $rule->capital_planning_revision
                === (int) $snapshot->capital_planning_revision
            && (string) $comparison->id
                === (string) $snapshot->capital_comparison_draft_id
            && (int) $comparison->revision
                === (int) $snapshot->capital_comparison_revision
            && (int) $comparison->capital_planning_revision
                === (int) $snapshot->capital_planning_revision
            && ($comparisonPayload['preferredPlan'] ?? null)
                === (string) $snapshot->preferred_plan
            && is_array(
                $comparisonPayload['scenarios'][
                    (string) $snapshot->preferred_plan
                ] ?? null,
            );

        if (! $fresh) {
            $this->recordStale($user, $business, $snapshot);

            throw new RuntimeException(
                'Capital Plan changed — prepare a new approval version.',
            );
        }

        return $snapshot;
    }

    private function recordStale(
        User $user,
        Business $business,
        object $snapshot,
    ): void {
        $this->occurrence->record(
            $user,
            $business,
            'capital.approval.stale_detected',
            'capital_approval_snapshot',
            (string) $snapshot->id,
            [
                'preferred_plan' => (string) $snapshot->preferred_plan,
                'capital_planning_revision' => (int) $snapshot->capital_planning_revision,
                'capital_rule_revision' => (int) $snapshot->capital_rule_revision,
                'capital_comparison_revision' => (int) $snapshot->capital_comparison_revision,
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function requireReadyCandidate(
        User $user,
        Business $business,
    ): array {
        $candidate = $this->candidate->execute($user, $business);

        if ($candidate === null) {
            throw new RuntimeException(
                'Capital approval information is not available for this account.',
            );
        }

        if ($candidate['ready'] !== true) {
            throw new RuntimeException(
                $this->readinessMessage($candidate['reasons']),
            );
        }

        return $candidate;
    }

    /**
     * @param  list<string>  $reasons
     */
    private function readinessMessage(array $reasons): string
    {
        if (in_array('capital_plan_required', $reasons, true)) {
            return 'Complete and save the Capital Plan before preparing approval.';
        }

        if (
            in_array('capital_rule_needs_review', $reasons, true)
            || in_array('capital_rule_not_ready', $reasons, true)
        ) {
            return 'Review and save the current Capital Rule before preparing approval.';
        }

        if (
            in_array('capital_shortfall_rule_required', $reasons, true)
        ) {
            return 'Record the Funding Gap response in the Capital Rule before preparing approval.';
        }

        if (
            in_array('capital_comparison_needs_review', $reasons, true)
        ) {
            return 'The Capital comparison needs review against the current Capital Plan.';
        }

        if (
            in_array('capital_comparison_required', $reasons, true)
            || in_array('capital_comparison_incomplete', $reasons, true)
        ) {
            return 'Complete Lean, Base and Growth comparison before preparing approval.';
        }

        if (in_array('preferred_plan_required', $reasons, true)) {
            return 'Choose a Preferred Plan before preparing Capital approval.';
        }

        if (in_array('preferred_plan_incomplete', $reasons, true)) {
            return 'The Preferred Plan must be complete and calculable before approval.';
        }

        return 'Capital approval prerequisites are not complete yet.';
    }

    private function lockSources(Business $business): void
    {
        foreach ([
            'capital_planning_drafts',
            'capital_rule_drafts',
            'capital_comparison_drafts',
        ] as $table) {
            DB::table($table)
                ->where('business_id', $business->getKey())
                ->lockForUpdate()
                ->first();
        }
    }

    private function openCapitalDecision(
        Business $business,
        string $proposalVersionId,
    ): ?Decision {
        return Decision::query()
            ->where('business_id', $business->getKey())
            ->where('proposal_version_id', $proposalVersionId)
            ->where('decision_type', CapitalApprovalContract::DECISION_TYPE)
            ->where('status', DecisionStatus::Open->value)
            ->first();
    }

    private function latestRecordState(
        Business $business,
        string $formalRecordVersionId,
    ): ?FormalRecordState {
        $state = DB::table('record_version_state_transitions')
            ->where('business_id', $business->getKey())
            ->where('formal_record_version_id', $formalRecordVersionId)
            ->orderByDesc('sequence')
            ->value('to_state');

        return is_string($state)
            ? FormalRecordState::tryFrom($state)
            : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(object $snapshot): array
    {
        $payload = json_decode(
            (string) $snapshot->snapshot_payload,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        if (! is_array($payload)) {
            throw new RuntimeException(
                'Frozen Capital approval snapshot is invalid.',
            );
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshotEnvelope(
        object $snapshot,
        bool $created,
    ): array {
        return [
            'id' => (string) $snapshot->id,
            'created' => $created,
            'contractVersion' => (string) $snapshot->contract_version,
            'contentHash' => (string) $snapshot->content_hash,
            'preferredPlan' => (string) $snapshot->preferred_plan,
            'formalRecordVersionId' => (string) $snapshot->formal_record_version_id,
            'proposalVersionId' => (string) $snapshot->proposal_version_id,
            'preparedAt' => (string) $snapshot->prepared_at,
        ];
    }
}
