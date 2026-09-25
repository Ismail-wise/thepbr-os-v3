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
use App\Domain\Governance\Enums\DecisionStatus;
use App\Domain\Governance\Enums\ParticipantStatus;
use App\Domain\Governance\Enums\VoteChoice;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\ApprovalRequirement;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Governance\DecisionParticipant;
use App\Infrastructure\Persistence\Eloquent\Governance\Vote;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RecuseGovernanceDecisionParticipant
{
    public function __construct(
        private readonly AuthorizeBusinessCapability $authorizeBusinessCapability,
        private readonly ResolveMembershipCapabilities $membershipCapabilities,
        private readonly RecordBusinessOccurrence $recordBusinessOccurrence,
    ) {}

    public function execute(
        User $user,
        Business $currentBusiness,
        string $decisionId,
        string $reason,
    ): ?DecisionParticipant {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Recusal reason must not be empty.',
            );
        }

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

        $membership = $this->membershipCapabilities->activeMembership(
            $user,
            $currentBusiness,
        );

        if ($membership === null) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $currentBusiness,
            $decisionId,
            $reason,
            $capability,
            $membership,
        ): ?DecisionParticipant {
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

            /*
             * F3-S1 minimum COI contract is explicit self-recusal.
             * No administrator/system permission may invent a forced
             * governance recusal authority that is not yet source-defined.
             */
            $participant = DecisionParticipant::query()
                ->where(
                    'business_id',
                    $currentBusiness->getKey(),
                )
                ->where(
                    'decision_id',
                    $decision->getKey(),
                )
                ->where(
                    'membership_id',
                    $membership->getKey(),
                )
                ->lockForUpdate()
                ->first();

            if (
                $participant === null
                || $participant->status
                    !== ParticipantStatus::Eligible
            ) {
                return null;
            }

            $participant->fill([
                'status' => ParticipantStatus::Recused->value,
                'recusal_reason' => $reason,
                'recused_by_membership_id' => $membership->getKey(),
                'recused_at' => now(),
            ]);

            $participant->save();

            $voteRequirement = ApprovalRequirement::query()
                ->where(
                    'business_id',
                    $currentBusiness->getKey(),
                )
                ->where(
                    'decision_id',
                    $decision->getKey(),
                )
                ->where(
                    'requirement_kind',
                    'vote',
                )
                ->first();

            $existingVote = Vote::query()
                ->where(
                    'business_id',
                    $currentBusiness->getKey(),
                )
                ->where(
                    'decision_id',
                    $decision->getKey(),
                )
                ->where(
                    'decision_participant_id',
                    $participant->getKey(),
                )
                ->exists();

            if (
                $participant->can_vote
                && $voteRequirement !== null
                && ! $existingVote
            ) {
                Vote::query()->create([
                    'business_id' => $currentBusiness->getKey(),
                    'decision_id' => $decision->getKey(),
                    'proposal_version_id' => $decision->proposal_version_id,
                    'authority_snapshot_id' => $decision->authority_snapshot_id,
                    'approval_requirement_id' => $voteRequirement->getKey(),
                    'decision_participant_id' => $participant->getKey(),
                    'membership_id' => $membership->getKey(),
                    'choice' => VoteChoice::Recused->value,
                    'rationale' => null,
                    'cast_at' => now(),
                ]);
            }

            $occurredAt = now();
            $correlationId =
                $this->recordBusinessOccurrence->newCorrelationId();
            $actor =
                AuditActor::user((string) $user->getKey());

            $this->recordBusinessOccurrence->audit(
                $currentBusiness,
                $actor,
                'governance.participant.recused',
                new OccurrenceTarget(
                    (string) $currentBusiness->getKey(),
                    'governance_decision_participant',
                    (string) $participant->getKey(),
                    (string) $decision->proposal_version_id,
                ),
                SafeAuditMetadata::from([
                    'decision_id' => (string) $decision->getKey(),
                    'self_recusal' => true,
                ]),
                $occurredAt,
                $correlationId,
            );

            $this->recordBusinessOccurrence->businessEvent(
                $currentBusiness,
                'governance.participant.recused',
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
                    'self_recusal' => true,
                ]),
                $occurredAt,
                $actor,
                $correlationId,
            );

            return $participant->fresh();
        });
    }
}
