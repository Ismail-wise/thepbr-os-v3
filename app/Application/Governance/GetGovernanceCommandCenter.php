<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Governance\Enums\DecisionStatus;
use App\Domain\Governance\Enums\ParticipantStatus;
use App\Domain\Governance\Enums\SignatureRequestStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Action;
use App\Infrastructure\Persistence\Eloquent\Governance\AmendmentRequest;
use App\Infrastructure\Persistence\Eloquent\Governance\Approval;
use App\Infrastructure\Persistence\Eloquent\Governance\AuthoritySnapshot;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Governance\DecisionParticipant;
use App\Infrastructure\Persistence\Eloquent\Governance\ProposalReview;
use App\Infrastructure\Persistence\Eloquent\Governance\Review;
use App\Infrastructure\Persistence\Eloquent\Governance\Signature;
use App\Infrastructure\Persistence\Eloquent\Governance\SignatureParticipant;
use App\Infrastructure\Persistence\Eloquent\Governance\SignatureRequest;
use App\Infrastructure\Persistence\Eloquent\Governance\Vote;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\ProposalVersion;
use App\Infrastructure\Persistence\Eloquent\Records\ProposalVersionRecord;
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionStateTransition;
use DateTimeInterface;

final class GetGovernanceCommandCenter
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly GetFormationAuthorityWorkspace $formationAuthority,
        private readonly ListGovernanceNotifications $notifications,
    ) {}

    /**
     * Permission-aware presentation read model.
     *
     * It never grants authority and never mutates canonical truth.
     *
     * @return array<string, mixed>|null
     */
    public function execute(
        User $user,
        Business $business,
    ): ?array {
        $membership = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
        );

        if ($membership === null) {
            return null;
        }

        $canManageGovernance = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
        ) !== null;

        $canManageActions = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_ACTION_MANAGE,
        ) !== null;

        $canSign = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_SIGNATURE_ACT,
        ) !== null;

        $businessId = (string) $business->getKey();
        $membershipId = (string) $membership->getKey();

        $recordRowsForProposalVersion = function (
            string $proposalVersionId,
        ) use (
            $user,
            $business,
            $businessId,
        ): array {
            return ProposalVersionRecord::query()
                ->where('business_id', $businessId)
                ->where(
                    'proposal_version_id',
                    $proposalVersionId,
                )
                ->orderBy('formal_record_version_id')
                ->get()
                ->map(function (
                    ProposalVersionRecord $binding,
                ) use (
                    $user,
                    $business,
                    $businessId,
                ): ?array {
                    $versionId =
                        (string) $binding->formal_record_version_id;

                    if (! $this->actorContext->canAccessResource(
                        $user,
                        $business,
                        CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
                        FormalRecordVersion::class,
                        $versionId,
                    )) {
                        return null;
                    }

                    $version = FormalRecordVersion::query()
                        ->where('business_id', $businessId)
                        ->whereKey($versionId)
                        ->first();

                    if ($version === null) {
                        return null;
                    }

                    $latest = RecordVersionStateTransition::query()
                        ->where('business_id', $businessId)
                        ->where(
                            'formal_record_version_id',
                            $versionId,
                        )
                        ->orderByDesc('sequence')
                        ->first();

                    return [
                        'id' => $versionId,
                        'versionNumber' => (int) $version->version_number,
                        'state' => $latest === null
                            ? null
                            : $latest->to_state->value,
                    ];
                })
                ->filter()
                ->values()
                ->all();
        };

        $proposalVersions = ProposalVersion::query()
            ->where('business_id', $businessId)
            ->orderBy('proposal_id')
            ->orderBy('version_number')
            ->limit(100)
            ->get()
            ->filter(
                fn (ProposalVersion $version): bool => $this->actorContext->canAccessResource(
                    $user,
                    $business,
                    CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
                    ProposalVersion::class,
                    (string) $version->getKey(),
                ),
            )
            ->map(function (ProposalVersion $version) use (
                $user,
                $business,
                $businessId,
                $membershipId,
                $canManageGovernance,
                $recordRowsForProposalVersion,
            ): array {
                $versionId = (string) $version->getKey();

                $review = ProposalReview::query()
                    ->where('business_id', $businessId)
                    ->where('proposal_version_id', $versionId)
                    ->first();

                $reviewVisible = $review !== null
                    && $this->actorContext->canAccessResource(
                        $user,
                        $business,
                        CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
                        ProposalReview::class,
                        (string) $review->getKey(),
                    );

                $visibleReview = $reviewVisible
                    ? $review
                    : null;

                $visibleDecisions = Decision::query()
                    ->where('business_id', $businessId)
                    ->where('proposal_version_id', $versionId)
                    ->orderBy('opened_at')
                    ->get()
                    ->filter(
                        fn (Decision $decision): bool => $this->actorContext->canAccessResource(
                            $user,
                            $business,
                            CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
                            Decision::class,
                            (string) $decision->getKey(),
                        ),
                    )
                    ->values();

                $decisionExists =
                    $visibleDecisions->isNotEmpty();

                $canManageProposal = $canManageGovernance
                    && $this->actorContext->canAccessResource(
                        $user,
                        $business,
                        CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
                        ProposalVersion::class,
                        $versionId,
                    );

                $canCompleteReview = $visibleReview !== null
                    && $visibleReview->status === 'open'
                    && (string) $visibleReview->reviewer_membership_id
                        === $membershipId
                    && $canManageGovernance
                    && $this->actorContext->canAccessResource(
                        $user,
                        $business,
                        CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
                        ProposalReview::class,
                        (string) $visibleReview->getKey(),
                    );

                $canOpenDecision = $visibleReview !== null
                    && $visibleReview->status === 'completed'
                    && $visibleReview->outcome?->value === 'approved'
                    && ! $decisionExists
                    && $canManageProposal;

                return [
                    'id' => $versionId,
                    'proposalId' => (string) $version->proposal_id,
                    'versionNumber' => (int) $version->version_number,
                    'proposalRevision' => (int) $version->proposal_revision,
                    'proposalContentHash' => (string) $version->proposal_content_hash,
                    'snapshotHash' => (string) $version->snapshot_hash,
                    'frozenAt' => $this->timestamp($version->frozen_at),
                    'recordVersions' => $recordRowsForProposalVersion($versionId),
                    'review' => $visibleReview === null
                        ? null
                        : [
                            'id' => (string) $visibleReview->getKey(),
                            'reviewerMembershipId' => (string) $visibleReview
                                ->reviewer_membership_id,
                            'status' => (string) $visibleReview->status,
                            'outcome' => $visibleReview->outcome?->value,
                            'notes' => $visibleReview->notes,
                            'dueAt' => $this->timestamp(
                                $visibleReview->due_at,
                            ),
                            'resolvedAt' => $this->timestamp(
                                $visibleReview->resolved_at,
                            ),
                        ],
                    'decisionIds' => $visibleDecisions
                        ->map(
                            static fn (Decision $decision): string => (string) $decision->getKey(),
                        )
                        ->all(),
                    'canCreateReview' => $visibleReview === null && $canManageProposal,
                    'canCompleteReview' => $canCompleteReview,
                    'canOpenDecision' => $canOpenDecision,
                ];
            })
            ->values();

        $decisions = Decision::query()
            ->where('business_id', $businessId)
            ->orderByDesc('opened_at')
            ->limit(50)
            ->get()
            ->filter(
                fn (Decision $decision): bool => $this->actorContext->canAccessResource(
                    $user,
                    $business,
                    CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
                    Decision::class,
                    (string) $decision->getKey(),
                ),
            )
            ->map(function (Decision $decision) use (
                $user,
                $business,
                $businessId,
                $membershipId,
                $canManageGovernance,
                $recordRowsForProposalVersion,
            ): array {
                $decisionId = (string) $decision->getKey();

                $snapshot = AuthoritySnapshot::query()
                    ->where('business_id', $businessId)
                    ->whereKey($decision->authority_snapshot_id)
                    ->first();

                $participant = DecisionParticipant::query()
                    ->where('business_id', $businessId)
                    ->where('decision_id', $decisionId)
                    ->where('membership_id', $membershipId)
                    ->first();

                $approvalCount = Approval::query()
                    ->where('business_id', $businessId)
                    ->where('decision_id', $decisionId)
                    ->where('outcome', 'approved')
                    ->whereIn(
                        'decision_participant_id',
                        DecisionParticipant::query()
                            ->select('id')
                            ->where('business_id', $businessId)
                            ->where('decision_id', $decisionId)
                            ->where(
                                'status',
                                ParticipantStatus::Eligible->value,
                            ),
                    )
                    ->count();

                $supportingVoteCount = Vote::query()
                    ->where('business_id', $businessId)
                    ->where('decision_id', $decisionId)
                    ->where('choice', 'for')
                    ->whereIn(
                        'decision_participant_id',
                        DecisionParticipant::query()
                            ->select('id')
                            ->where('business_id', $businessId)
                            ->where('decision_id', $decisionId)
                            ->where(
                                'status',
                                ParticipantStatus::Eligible->value,
                            ),
                    )
                    ->count();

                $voteCount = Vote::query()
                    ->where('business_id', $businessId)
                    ->where('decision_id', $decisionId)
                    ->whereIn('choice', ['for', 'against', 'abstain'])
                    ->whereIn(
                        'decision_participant_id',
                        DecisionParticipant::query()
                            ->select('id')
                            ->where('business_id', $businessId)
                            ->where('decision_id', $decisionId)
                            ->where(
                                'status',
                                ParticipantStatus::Eligible->value,
                            ),
                    )
                    ->count();

                $hasApproval = $participant !== null
                    && Approval::query()
                        ->where('business_id', $businessId)
                        ->where('decision_id', $decisionId)
                        ->where(
                            'decision_participant_id',
                            $participant->getKey(),
                        )
                        ->exists();

                $hasVote = $participant !== null
                    && Vote::query()
                        ->where('business_id', $businessId)
                        ->where('decision_id', $decisionId)
                        ->where(
                            'decision_participant_id',
                            $participant->getKey(),
                        )
                        ->exists();

                $canManageDecision = $canManageGovernance
                    && $this->actorContext->canAccessResource(
                        $user,
                        $business,
                        CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
                        Decision::class,
                        $decisionId,
                    );

                $isOpen = $decision->status === DecisionStatus::Open;
                $isEligible = $participant?->status
                    === ParticipantStatus::Eligible;

                $recordVersions = $recordRowsForProposalVersion(
                    (string) $decision->proposal_version_id,
                );

                $recordVersionIds = array_map(
                    static fn (array $row): string => $row['id'],
                    $recordVersions,
                );

                return [
                    'id' => $decisionId,
                    'proposalVersionId' => (string) $decision->proposal_version_id,
                    'recordVersionIds' => $recordVersionIds,
                    'recordVersions' => $recordVersions,
                    'type' => (string) $decision->decision_type,
                    'amount' => $decision->decision_amount,
                    'status' => $decision->status->value,
                    'outcome' => $decision->outcome?->value,
                    'openedAt' => $this->timestamp($decision->opened_at),
                    'resolvedAt' => $this->timestamp($decision->resolved_at),
                    'method' => $snapshot?->decision_method->value,
                    'requiredApprovals' => (int) ($snapshot?->required_approvals ?? 0),
                    'requiredVotes' => (int) ($snapshot?->required_votes ?? 0),
                    'quorumCount' => (int) ($snapshot?->quorum_count ?? 0),
                    'signatureRequired' => (bool) ($snapshot?->signature_required ?? false),
                    'reservedMatter' => (bool) ($snapshot?->reserved_matter ?? false),
                    'progress' => [
                        'approvals' => $approvalCount,
                        'supportingVotes' => $supportingVoteCount,
                        'votesCast' => $voteCount,
                    ],
                    'myParticipant' => $participant === null
                        ? null
                        : [
                            'id' => (string) $participant->getKey(),
                            'capacity' => (string) $participant->capacity,
                            'status' => $participant->status->value,
                            'canApprove' => (bool) $participant->can_approve,
                            'canVote' => (bool) $participant->can_vote,
                            'canSign' => (bool) $participant->can_sign,
                        ],
                    'actions' => [
                        'canApprove' => $isOpen
                            && $canManageDecision
                            && $isEligible
                            && (bool) $participant?->can_approve
                            && ! $hasApproval,
                        'canVote' => $isOpen
                            && $canManageDecision
                            && $isEligible
                            && (bool) $participant?->can_vote
                            && ! $hasVote,
                        'canRecuse' => $isOpen
                            && $canManageDecision
                            && $isEligible,
                        'canResolve' => $isOpen && $canManageDecision,
                        'canAdminister' => $canManageDecision,
                    ],
                ];
            })
            ->values();

        $signatureRequests = SignatureRequest::query()
            ->where('business_id', $businessId)
            ->orderByDesc('requested_at')
            ->limit(50)
            ->get()
            ->filter(
                fn (SignatureRequest $request): bool => $this->actorContext->canAccessResource(
                    $user,
                    $business,
                    CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
                    SignatureRequest::class,
                    (string) $request->getKey(),
                ),
            )
            ->map(function (SignatureRequest $request) use (
                $user,
                $business,
                $businessId,
                $membershipId,
                $canManageGovernance,
                $canSign,
            ): array {
                $requestId = (string) $request->getKey();

                $participant = SignatureParticipant::query()
                    ->where('business_id', $businessId)
                    ->where('signature_request_id', $requestId)
                    ->where('membership_id', $membershipId)
                    ->first();

                $alreadySigned = $participant !== null
                    && Signature::query()
                        ->where('business_id', $businessId)
                        ->where('signature_request_id', $requestId)
                        ->where(
                            'signature_participant_id',
                            $participant->getKey(),
                        )
                        ->exists();

                $adminAccess = $canManageGovernance
                    && $this->actorContext->canAccessResource(
                        $user,
                        $business,
                        CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
                        SignatureRequest::class,
                        $requestId,
                    );

                $signAccess = $canSign
                    && $this->actorContext->canAccessResource(
                        $user,
                        $business,
                        CapabilityCatalog::GOVERNANCE_SIGNATURE_ACT,
                        SignatureRequest::class,
                        $requestId,
                    );

                $participantCount = SignatureParticipant::query()
                    ->where('business_id', $businessId)
                    ->where('signature_request_id', $requestId)
                    ->count();

                $signatureCount = Signature::query()
                    ->where('business_id', $businessId)
                    ->where('signature_request_id', $requestId)
                    ->count();

                return [
                    'id' => $requestId,
                    'decisionId' => (string) $request->decision_id,
                    'documentVersionId' => (string) $request->document_version_id,
                    'documentHash' => (string) $request->document_content_sha256,
                    'status' => $request->status->value,
                    'requestedAt' => $this->timestamp($request->requested_at),
                    'sentAt' => $this->timestamp($request->sent_at),
                    'completedAt' => $this->timestamp($request->completed_at),
                    'signedCount' => $signatureCount,
                    'signerCount' => $participantCount,
                    'canSend' => $adminAccess
                        && $request->status
                            === SignatureRequestStatus::Draft,
                    'canComplete' => $adminAccess
                        && $request->status
                            === SignatureRequestStatus::FullySigned,
                    'canSign' => $participant !== null
                        && ! $alreadySigned
                        && $signAccess
                        && in_array(
                            $request->status,
                            [
                                SignatureRequestStatus::Sent,
                                SignatureRequestStatus::PartiallySigned,
                            ],
                            true,
                        ),
                    'canDecline' => $participant !== null
                        && ! $alreadySigned
                        && $signAccess
                        && in_array(
                            $request->status,
                            [
                                SignatureRequestStatus::Sent,
                                SignatureRequestStatus::PartiallySigned,
                            ],
                            true,
                        ),
                ];
            })
            ->values();

        $actions = Action::query()
            ->where('business_id', $businessId)
            ->orderByRaw('due_at IS NULL')
            ->orderBy('due_at')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->filter(
                fn (Action $action): bool => $this->actorContext->canAccessResource(
                    $user,
                    $business,
                    CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
                    Action::class,
                    (string) $action->getKey(),
                ),
            )
            ->map(function (Action $action) use (
                $user,
                $business,
                $canManageActions,
            ): array {
                $id = (string) $action->getKey();

                return [
                    'id' => $id,
                    'decisionId' => $action->decision_id === null
                        ? null
                        : (string) $action->decision_id,
                    'formalRecordVersionId' => $action->formal_record_version_id === null
                            ? null
                            : (string) $action->formal_record_version_id,
                    'assignedMembershipId' => (string) $action->assigned_membership_id,
                    'title' => (string) $action->title,
                    'description' => $action->description,
                    'status' => $action->status->value,
                    'blockedReason' => $action->blocked_reason,
                    'dueAt' => $this->timestamp($action->due_at),
                    'completedAt' => $this->timestamp($action->completed_at),
                    'canManage' => $canManageActions
                        && $this->actorContext->canAccessResource(
                            $user,
                            $business,
                            CapabilityCatalog::GOVERNANCE_ACTION_MANAGE,
                            Action::class,
                            $id,
                        ),
                ];
            })
            ->values();

        $reviews = Review::query()
            ->where('business_id', $businessId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->filter(
                fn (Review $review): bool => $this->actorContext->canAccessResource(
                    $user,
                    $business,
                    CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
                    Review::class,
                    (string) $review->getKey(),
                ),
            )
            ->map(function (Review $review) use (
                $user,
                $business,
                $membershipId,
                $canManageGovernance,
            ): array {
                $id = (string) $review->getKey();

                return [
                    'id' => $id,
                    'formalRecordVersionId' => (string) $review->formal_record_version_id,
                    'reviewerMembershipId' => (string) $review->reviewer_membership_id,
                    'status' => (string) $review->status,
                    'outcome' => $review->outcome?->value,
                    'notes' => $review->notes,
                    'dueAt' => $this->timestamp($review->due_at),
                    'resolvedAt' => $this->timestamp($review->resolved_at),
                    'canComplete' => $review->status === 'open'
                        && (string) $review->reviewer_membership_id
                            === $membershipId
                        && $canManageGovernance
                        && $this->actorContext->canAccessResource(
                            $user,
                            $business,
                            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
                            Review::class,
                            $id,
                        ),
                ];
            })
            ->values();

        $amendments = AmendmentRequest::query()
            ->where('business_id', $businessId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->filter(
                fn (AmendmentRequest $request): bool => $this->actorContext->canAccessResource(
                    $user,
                    $business,
                    CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
                    AmendmentRequest::class,
                    (string) $request->getKey(),
                ),
            )
            ->map(function (AmendmentRequest $request) use (
                $user,
                $business,
                $canManageGovernance,
            ): array {
                $id = (string) $request->getKey();

                return [
                    'id' => $id,
                    'formalRecordVersionId' => (string) $request->formal_record_version_id,
                    'reviewId' => $request->review_id === null
                        ? null
                        : (string) $request->review_id,
                    'reason' => (string) $request->reason,
                    'status' => (string) $request->status,
                    'resolvedAt' => $this->timestamp($request->resolved_at),
                    'canResolve' => $request->status === 'open'
                        && $canManageGovernance
                        && $this->actorContext->canAccessResource(
                            $user,
                            $business,
                            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
                            AmendmentRequest::class,
                            $id,
                        ),
                ];
            })
            ->values();

        $notificationRows = $this->notifications
            ->execute($user, $business, 30)
            ->map(
                fn ($notification): array => [
                    'id' => (string) $notification->getKey(),
                    'kind' => (string) $notification->kind,
                    'subjectType' => (string) $notification->subject_type,
                    'subjectId' => (string) $notification->subject_id,
                    'status' => $notification->status->value,
                    'createdAt' => $this->timestamp(
                        $notification->created_at,
                    ),
                ],
            )
            ->values();

        $memberRows = [];

        if ($canManageGovernance || $canManageActions) {
            $memberRows = Membership::query()
                ->where('business_id', $businessId)
                ->where('access_status', 'active')
                ->orderBy('created_at')
                ->get(['id'])
                ->map(
                    static fn (Membership $row): array => [
                        'id' => (string) $row->getKey(),
                    ],
                )
                ->values()
                ->all();
        }

        $needsAttention = $proposalVersions
            ->filter(
                static fn (array $proposal): bool => $proposal['canCompleteReview']
                    || $proposal['canOpenDecision'],
            )
            ->count()
            + $decisions
                ->filter(
                    static fn (array $decision): bool => $decision['actions']['canApprove']
                        || $decision['actions']['canVote']
                        || $decision['actions']['canResolve'],
                )
                ->count()
            + $signatureRequests
                ->filter(
                    static fn (array $request): bool => $request['canSign']
                        || $request['canComplete']
                        || $request['canSend'],
                )
                ->count()
            + $actions
                ->filter(
                    static fn (array $action): bool => $action['canManage']
                        && $action['status'] !== 'completed',
                )
                ->count()
            + $reviews
                ->filter(
                    static fn (array $review): bool => $review['canComplete'],
                )
                ->count();

        return [
            'business' => [
                'id' => $businessId,
                'name' => (string) $business->name,
            ],
            'membership' => [
                'id' => $membershipId,
            ],
            'authority' => $this->formationAuthority->execute(
                $user,
                $business,
            ),
            'summary' => [
                'needsAttention' => $needsAttention,
                'openDecisions' => $decisions
                    ->where('status', DecisionStatus::Open->value)
                    ->count(),
                'pendingSignatures' => $signatureRequests
                    ->filter(
                        static fn (array $row): bool => in_array(
                            $row['status'],
                            ['draft', 'sent', 'partially_signed', 'fully_signed'],
                            true,
                        ),
                    )
                    ->count(),
                'openActions' => $actions
                    ->whereNotIn('status', ['completed', 'cancelled'])
                    ->count(),
            ],
            'proposalVersions' => $proposalVersions->all(),
            'decisions' => $decisions->all(),
            'signatureRequests' => $signatureRequests->all(),
            'actions' => $actions->all(),
            'reviews' => $reviews->all(),
            'amendments' => $amendments->all(),
            'notifications' => $notificationRows->all(),
            'activeMemberships' => $memberRows,
            'permissions' => [
                'canManageGovernance' => $canManageGovernance,
                'canManageActions' => $canManageActions,
            ],
        ];
    }

    private function timestamp(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface
            ? $value->format(DATE_ATOM)
            : null;
    }
}
