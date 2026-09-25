<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Application\Access\ResolveMembershipCapabilities;
use App\Application\Events\RecordBusinessOccurrence;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Audit\ValueObjects\AuditActor;
use App\Domain\Audit\ValueObjects\SafeAuditMetadata;
use App\Domain\Events\ValueObjects\OccurrenceTarget;
use App\Domain\Events\ValueObjects\SafeBusinessEventPayload;
use App\Domain\Governance\Enums\ApprovalOutcome;
use App\Domain\Governance\Enums\DecisionMethod;
use App\Domain\Governance\Enums\DecisionOutcome;
use App\Domain\Governance\Enums\DecisionStatus;
use App\Domain\Governance\Enums\ParticipantStatus;
use App\Domain\Governance\Enums\VoteChoice;
use App\Domain\Governance\ValueObjects\AuthorityEvaluation;
use App\Domain\Governance\ValueObjects\AuthorityThreshold;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Approval;
use App\Infrastructure\Persistence\Eloquent\Governance\AuthoritySnapshot;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Governance\DecisionParticipant;
use App\Infrastructure\Persistence\Eloquent\Governance\Vote;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ResolveGovernanceDecision
{
    public function __construct(
        private readonly AuthorizeBusinessCapability $authorizeBusinessCapability,
        private readonly ResolveMembershipCapabilities $membershipCapabilities,
        private readonly RecordBusinessOccurrence $recordBusinessOccurrence,
    ) {}

    /**
     * Resolve an Open Decision as Approved only after the exact captured
     * authority threshold is met.
     *
     * Rejected-resolution semantics are deliberately not invented in F3-S1.
     */
    public function approve(
        User $user,
        Business $currentBusiness,
        string $decisionId,
    ): ?Decision {
        $capability = new Capability(
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
        );

        $authorization = $this->authorizeBusinessCapability->decide(
            $user,
            $currentBusiness,
            $currentBusiness,
            $capability,
        );

        if (! $authorization->allowed) {
            return null;
        }

        if (
            $this->membershipCapabilities->activeMembership(
                $user,
                $currentBusiness,
            ) === null
        ) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $currentBusiness,
            $decisionId,
            $capability,
        ): ?Decision {
            $decision = Decision::query()
                ->where(
                    'business_id',
                    $currentBusiness->getKey(),
                )
                ->whereKey($decisionId)
                ->lockForUpdate()
                ->first();

            if (
                $decision === null
                || $decision->status !== DecisionStatus::Open
            ) {
                return null;
            }

            $resourceAuthorization =
                $this->authorizeBusinessCapability->decide(
                    $user,
                    $currentBusiness,
                    $currentBusiness,
                    $capability,
                    Decision::class,
                    (string) $decision->getKey(),
                );

            if (! $resourceAuthorization->allowed) {
                return null;
            }

            $snapshot = AuthoritySnapshot::query()
                ->where(
                    'business_id',
                    $currentBusiness->getKey(),
                )
                ->whereKey($decision->authority_snapshot_id)
                ->first();

            if ($snapshot === null) {
                return null;
            }

            $threshold = new AuthorityThreshold(
                $snapshot->decision_method,
                $snapshot->required_approvals,
                $snapshot->required_votes,
                $snapshot->quorum_count,
            );

            $eligibleCount = DecisionParticipant::query()
                ->where(
                    'business_id',
                    $currentBusiness->getKey(),
                )
                ->where(
                    'decision_id',
                    $decision->getKey(),
                )
                ->where(
                    'status',
                    ParticipantStatus::Eligible->value,
                )
                ->count();

            $eligibleParticipantIds = DecisionParticipant::query()
                ->select('id')
                ->where(
                    'business_id',
                    $currentBusiness->getKey(),
                )
                ->where(
                    'decision_id',
                    $decision->getKey(),
                )
                ->where(
                    'status',
                    ParticipantStatus::Eligible->value,
                );

            $approvalCount = Approval::query()
                ->where(
                    'business_id',
                    $currentBusiness->getKey(),
                )
                ->where(
                    'decision_id',
                    $decision->getKey(),
                )
                ->where(
                    'outcome',
                    ApprovalOutcome::Approved->value,
                )
                ->whereIn(
                    'decision_participant_id',
                    clone $eligibleParticipantIds,
                )
                ->count();

            $supportingVoteCount = Vote::query()
                ->where(
                    'business_id',
                    $currentBusiness->getKey(),
                )
                ->where(
                    'decision_id',
                    $decision->getKey(),
                )
                ->where(
                    'choice',
                    VoteChoice::For->value,
                )
                ->whereIn(
                    'decision_participant_id',
                    clone $eligibleParticipantIds,
                )
                ->count();

            $substantiveVoteCount = Vote::query()
                ->where(
                    'business_id',
                    $currentBusiness->getKey(),
                )
                ->where(
                    'decision_id',
                    $decision->getKey(),
                )
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
                    clone $eligibleParticipantIds,
                )
                ->count();

            $quorumPresentCount =
                $snapshot->decision_method === DecisionMethod::Approval
                    ? $approvalCount
                    : $substantiveVoteCount;

            $evaluation = new AuthorityEvaluation(
                $threshold,
                $eligibleCount,
                $approvalCount,
                $supportingVoteCount,
                $quorumPresentCount,
            );

            if (! $evaluation->requirementsMet()) {
                throw new RuntimeException(
                    'Captured governance authority threshold has not been met.',
                );
            }

            $decision->fill([
                'status' => DecisionStatus::Decided->value,
                'outcome' => DecisionOutcome::Approved->value,
                'resolved_at' => now(),
            ]);

            /*
             * PostgreSQL independently re-asserts the complete captured
             * participant set, approval threshold, supporting votes and quorum.
             */
            $decision->save();

            $occurredAt = now();
            $correlationId =
                $this->recordBusinessOccurrence->newCorrelationId();
            $actor =
                AuditActor::user((string) $user->getKey());

            $this->recordBusinessOccurrence->audit(
                $currentBusiness,
                $actor,
                'governance.decision.approved',
                new OccurrenceTarget(
                    (string) $currentBusiness->getKey(),
                    'governance_decision',
                    (string) $decision->getKey(),
                    (string) $decision->proposal_version_id,
                ),
                SafeAuditMetadata::from([
                    'outcome' => DecisionOutcome::Approved->value,
                    'approval_count' => $approvalCount,
                    'supporting_vote_count' => $supportingVoteCount,
                    'quorum_present_count' => $quorumPresentCount,
                ]),
                $occurredAt,
                $correlationId,
            );

            $this->recordBusinessOccurrence->businessEvent(
                $currentBusiness,
                'governance.decision.approved',
                new OccurrenceTarget(
                    (string) $currentBusiness->getKey(),
                    'governance_decision',
                    (string) $decision->getKey(),
                    (string) $decision->proposal_version_id,
                ),
                new OccurrenceTarget(
                    (string) $currentBusiness->getKey(),
                    'governance_decision',
                    (string) $decision->getKey(),
                ),
                SafeBusinessEventPayload::from([
                    'outcome' => DecisionOutcome::Approved->value,
                ]),
                $occurredAt,
                $actor,
                $correlationId,
            );

            return $decision->fresh();
        });
    }
}
