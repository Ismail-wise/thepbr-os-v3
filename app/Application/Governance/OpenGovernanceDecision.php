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
use App\Domain\Governance\Enums\DecisionMethod;
use App\Domain\Governance\Enums\DecisionStatus;
use App\Domain\Governance\Enums\ParticipantStatus;
use App\Domain\Governance\Enums\ProposalReviewOutcome;
use App\Domain\Governance\ValueObjects\DecisionType;
use App\Domain\Governance\ValueObjects\ResolvedAuthorityActor;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\ApprovalRequirement;
use App\Infrastructure\Persistence\Eloquent\Governance\AuthoritySnapshot;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Governance\DecisionParticipant;
use App\Infrastructure\Persistence\Eloquent\Governance\ProposalReview;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Records\ProposalVersion;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class OpenGovernanceDecision
{
    public function __construct(
        private readonly AuthorizeBusinessCapability $authorizeBusinessCapability,
        private readonly ResolveMembershipCapabilities $membershipCapabilities,
        private readonly ResolveGovernanceAuthority $resolveGovernanceAuthority,
        private readonly RecordBusinessOccurrence $recordBusinessOccurrence,
    ) {}

    /**
     * @throws JsonException
     */
    public function execute(
        User $user,
        Business $currentBusiness,
        string $proposalVersionId,
        DecisionType $decisionType,
        ?string $decisionAmount = null,
        ?string $meetingId = null,
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

        $membership = $this->membershipCapabilities->activeMembership(
            $user,
            $currentBusiness,
        );

        if ($membership === null) {
            return null;
        }

        $amount = $this->normalizeAmount($decisionAmount);

        return DB::transaction(function () use (
            $user,
            $currentBusiness,
            $proposalVersionId,
            $decisionType,
            $amount,
            $meetingId,
            $capability,
            $membership,
        ): ?Decision {
            $proposalVersion = ProposalVersion::query()
                ->where(
                    'business_id',
                    $currentBusiness->getKey(),
                )
                ->whereKey($proposalVersionId)
                ->lockForUpdate()
                ->first();

            if (
                $proposalVersion === null
                || $proposalVersion->frozen_at === null
            ) {
                return null;
            }

            $resourceAuthorization =
                $this->authorizeBusinessCapability->decide(
                    $user,
                    $currentBusiness,
                    $currentBusiness,
                    $capability,
                    ProposalVersion::class,
                    (string) $proposalVersion->getKey(),
                );

            if (! $resourceAuthorization->allowed) {
                return null;
            }

            $reviewApproved = ProposalReview::query()
                ->where(
                    'business_id',
                    $currentBusiness->getKey(),
                )
                ->where(
                    'proposal_version_id',
                    $proposalVersion->getKey(),
                )
                ->where('status', 'completed')
                ->where(
                    'outcome',
                    ProposalReviewOutcome::Approved->value,
                )
                ->exists();

            if (! $reviewApproved) {
                throw new RuntimeException(
                    'Decision requires an Approved Review of the exact Frozen Proposal Version.',
                );
            }

            $authority = $this->resolveGovernanceAuthority->resolve(
                $currentBusiness,
                $decisionType,
                $amount,
            );

            if ($authority === null) {
                return null;
            }

            $sourceVersion = $authority['source_version'];
            $rule = $authority['rule'];
            $actors = $authority['actors'];

            $resolvedMeetingId = null;

            if ($rule['meeting_required'] || $meetingId !== null) {
                if ($meetingId === null) {
                    throw new RuntimeException(
                        'This Governance rule requires a held, quorum-met Meeting.',
                    );
                }

                $meeting = DB::table('governance_meetings')
                    ->where('business_id', $currentBusiness->getKey())
                    ->where('id', $meetingId)
                    ->where('status', 'held')
                    ->first();

                if (
                    $meeting === null
                    || (string) $meeting->authority_source_formal_record_version_id
                        !== (string) $sourceVersion->getKey()
                    || (int) $meeting->quorum_present
                        < (int) $meeting->quorum_required
                ) {
                    throw new RuntimeException(
                        'Decision Meeting must be held under the same authority source with quorum satisfied.',
                    );
                }

                $resolvedMeetingId = (string) $meeting->id;
            }

            $actorProjection = $actors
                ->map(
                    static function (
                        ResolvedAuthorityActor $actor,
                    ): array {
                        return [
                            'membership_id' => $actor->membershipId,
                            'capacity' => $actor->capacity,
                            'can_approve' => $actor->canApprove,
                            'can_vote' => $actor->canVote,
                            'can_sign' => $actor->canSign,
                            'source' => $actor->source,
                        ];
                    },
                )
                ->values()
                ->all();

            $snapshotPayload = [
                'proposal_version_id' => (string) $proposalVersion->getKey(),
                'source_formal_record_version_id' => (string) $sourceVersion->getKey(),
                'source_rule_sequence' => $rule['sequence'],
                'source_content_hash' => (string) $sourceVersion->content_hash,
                'source_kind' => $authority['source_kind'],
                'decision_type' => $decisionType->value(),
                'decision_method' => $rule['decision_method']->value,
                'required_approvals' => $rule['required_approvals'],
                'required_votes' => $rule['required_votes'],
                'quorum_count' => $rule['quorum_count'],
                'signature_required' => $rule['signature_required'],
                'reserved_matter' => $rule['reserved_matter'],
                'meeting_required' => $rule['meeting_required'],
                'record_required' => $rule['record_required'],
                'amount_min' => $rule['amount_min'],
                'amount_max' => $rule['amount_max'],
                'meeting_id' => $resolvedMeetingId,
                'actors' => $actorProjection,
            ];

            $snapshotHash = hash(
                'sha256',
                json_encode(
                    $snapshotPayload,
                    JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
                ),
            );

            $snapshot = AuthoritySnapshot::query()->create([
                'business_id' => $currentBusiness->getKey(),
                'proposal_version_id' => $proposalVersion->getKey(),
                'source_formal_record_version_id' => $sourceVersion->getKey(),
                'source_rule_sequence' => $rule['sequence'],
                'source_content_hash' => $sourceVersion->content_hash,
                'source_kind' => $authority['source_kind'],
                'decision_type' => $decisionType->value(),
                'decision_method' => $rule['decision_method']->value,
                'required_approvals' => $rule['required_approvals'],
                'required_votes' => $rule['required_votes'],
                'quorum_count' => $rule['quorum_count'],
                'signature_required' => $rule['signature_required'],
                'reserved_matter' => $rule['reserved_matter'],
                'meeting_required' => $rule['meeting_required'],
                'record_required' => $rule['record_required'],
                'amount_min' => $rule['amount_min'],
                'amount_max' => $rule['amount_max'],
                'snapshot_hash' => $snapshotHash,
                'captured_by_membership_id' => $membership->getKey(),
                'captured_at' => now(),
            ]);

            $decision = Decision::query()->create([
                'business_id' => $currentBusiness->getKey(),
                'proposal_version_id' => $proposalVersion->getKey(),
                'authority_snapshot_id' => $snapshot->getKey(),
                'decision_type' => $decisionType->value(),
                'decision_amount' => $amount,
                'meeting_id' => $resolvedMeetingId,
                'status' => DecisionStatus::Open->value,
                'outcome' => null,
                'opened_by_membership_id' => $membership->getKey(),
                'opened_at' => now(),
                'resolved_at' => null,
            ]);

            $sequence = 1;

            if (
                in_array(
                    $rule['decision_method'],
                    [
                        DecisionMethod::Approval,
                        DecisionMethod::ApprovalAndVote,
                    ],
                    true,
                )
            ) {
                ApprovalRequirement::query()->create([
                    'business_id' => $currentBusiness->getKey(),
                    'decision_id' => $decision->getKey(),
                    'proposal_version_id' => $proposalVersion->getKey(),
                    'authority_snapshot_id' => $snapshot->getKey(),
                    'sequence' => $sequence++,
                    'requirement_kind' => 'approval',
                    'required_count' => $rule['required_approvals'],
                    'quorum_count' => null,
                ]);
            }

            if (
                in_array(
                    $rule['decision_method'],
                    [
                        DecisionMethod::Vote,
                        DecisionMethod::ApprovalAndVote,
                    ],
                    true,
                )
            ) {
                ApprovalRequirement::query()->create([
                    'business_id' => $currentBusiness->getKey(),
                    'decision_id' => $decision->getKey(),
                    'proposal_version_id' => $proposalVersion->getKey(),
                    'authority_snapshot_id' => $snapshot->getKey(),
                    'sequence' => $sequence,
                    'requirement_kind' => 'vote',
                    'required_count' => $rule['required_votes'],
                    'quorum_count' => $rule['quorum_count'],
                ]);
            }

            foreach ($actors as $actor) {
                DecisionParticipant::query()->create([
                    'business_id' => $currentBusiness->getKey(),
                    'decision_id' => $decision->getKey(),
                    'proposal_version_id' => $proposalVersion->getKey(),
                    'authority_snapshot_id' => $snapshot->getKey(),
                    'membership_id' => $actor->membershipId,
                    'capacity' => $actor->capacity,
                    'can_approve' => $actor->canApprove,
                    'can_vote' => $actor->canVote,
                    'can_sign' => $actor->canSign,
                    'status' => ParticipantStatus::Eligible->value,
                    'recusal_reason' => null,
                    'recused_by_membership_id' => null,
                    'recused_at' => null,
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
                'governance.decision.opened',
                new OccurrenceTarget(
                    (string) $currentBusiness->getKey(),
                    'governance_decision',
                    (string) $decision->getKey(),
                    (string) $proposalVersion->getKey(),
                ),
                SafeAuditMetadata::from([
                    'decision_type' => $decisionType->value(),
                    'decision_method' => $rule['decision_method']->value,
                    'signature_required' => $rule['signature_required'],
                    'reserved_matter' => $rule['reserved_matter'],
                    'source_kind' => $authority['source_kind'],
                ]),
                $occurredAt,
                $correlationId,
            );

            $this->recordBusinessOccurrence->businessEvent(
                $currentBusiness,
                'governance.decision.opened',
                new OccurrenceTarget(
                    (string) $currentBusiness->getKey(),
                    'governance_decision',
                    (string) $decision->getKey(),
                    (string) $proposalVersion->getKey(),
                ),
                new OccurrenceTarget(
                    (string) $currentBusiness->getKey(),
                    'governance_decision',
                    (string) $decision->getKey(),
                ),
                SafeBusinessEventPayload::from([
                    'decision_type' => $decisionType->value(),
                    'decision_method' => $rule['decision_method']->value,
                ]),
                $occurredAt,
                $actor,
                $correlationId,
            );

            return $decision->fresh();
        });
    }

    private function normalizeAmount(
        ?string $amount,
    ): ?string {
        if ($amount === null) {
            return null;
        }

        if (
            $amount === ''
            || $amount !== trim($amount)
            || preg_match(
                '/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?\z/',
                $amount,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Decision amount must use non-negative fixed two-decimal precision.',
            );
        }

        [$whole, $fraction] = array_pad(
            explode('.', $amount, 2),
            2,
            '',
        );

        return $whole.'.'.str_pad(
            $fraction,
            2,
            '0',
        );
    }
}
