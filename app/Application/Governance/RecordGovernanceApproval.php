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
use App\Domain\Governance\Enums\DecisionStatus;
use App\Domain\Governance\Enums\ParticipantStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Approval;
use App\Infrastructure\Persistence\Eloquent\Governance\ApprovalRequirement;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Governance\DecisionParticipant;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class RecordGovernanceApproval
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
        ApprovalOutcome $outcome,
        ?string $rationale = null,
    ): ?Approval {
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
            $outcome,
            $rationale,
            $capability,
            $membership,
        ): ?Approval {
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
                || ! $participant->can_approve
            ) {
                return null;
            }

            $requirement = ApprovalRequirement::query()
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
                    'approval',
                )
                ->first();

            if ($requirement === null) {
                return null;
            }

            $approval = Approval::query()->create([
                'business_id' => $currentBusiness->getKey(),
                'decision_id' => $decision->getKey(),
                'proposal_version_id' => $decision->proposal_version_id,
                'authority_snapshot_id' => $decision->authority_snapshot_id,
                'approval_requirement_id' => $requirement->getKey(),
                'decision_participant_id' => $participant->getKey(),
                'membership_id' => $membership->getKey(),
                'outcome' => $outcome->value,
                'rationale' => $rationale,
                'recorded_at' => now(),
            ]);

            $occurredAt = now();
            $correlationId =
                $this->recordBusinessOccurrence->newCorrelationId();
            $actor =
                AuditActor::user((string) $user->getKey());

            $this->recordBusinessOccurrence->audit(
                $currentBusiness,
                $actor,
                'governance.approval.recorded',
                new OccurrenceTarget(
                    (string) $currentBusiness->getKey(),
                    'governance_approval',
                    (string) $approval->getKey(),
                    (string) $decision->proposal_version_id,
                ),
                SafeAuditMetadata::from([
                    'decision_id' => (string) $decision->getKey(),
                    'outcome' => $outcome->value,
                ]),
                $occurredAt,
                $correlationId,
            );

            $this->recordBusinessOccurrence->businessEvent(
                $currentBusiness,
                'governance.approval.recorded',
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
                    'outcome' => $outcome->value,
                ]),
                $occurredAt,
                $actor,
                $correlationId,
            );

            return $approval;
        });
    }
}
