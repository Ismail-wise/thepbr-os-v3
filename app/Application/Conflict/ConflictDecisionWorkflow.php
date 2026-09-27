<?php

declare(strict_types=1);

namespace App\Application\Conflict;

use App\Application\Governance\CreateProposalReview;
use App\Application\Governance\GovernanceActorContext;
use App\Application\Governance\OpenGovernanceDecision;
use App\Application\Records\CreateProposal;
use App\Application\Records\FreezeProposalVersion;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Conflict\Enums\ConflictCaseStage;
use App\Domain\Governance\Enums\DecisionOutcome;
use App\Domain\Governance\Enums\DecisionStatus;
use App\Domain\Governance\ValueObjects\DecisionType;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Conflict\ConflictCase;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ConflictDecisionWorkflow
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly ConflictRecordVisibility $visibility,
        private readonly CreateProposal $createProposal,
        private readonly FreezeProposalVersion $freezeProposal,
        private readonly CreateProposalReview $createReview,
        private readonly OpenGovernanceDecision $openDecision,
        private readonly ConflictCaseWorkflow $cases,
        private readonly RecordConflictOccurrence $occurrence,
    ) {}

    /**
     * @return array{
     *   submission_id:string,
     *   proposal_version_id:string,
     *   proposal_review_id:string,
     *   package_hash:string
     * }|null
     */
    public function submit(
        User $user,
        Business $business,
        string $caseId,
        int $expectedCaseRevision,
        string $reviewerMembershipId,
        string $proposedDecisionSummary,
        ?string $conditions = null,
        ?string $appealReference = null,
        ?\DateTimeInterface $reviewDueAt = null,
    ): ?array {
        if (
            ! $this->visibility->canManage($user, $business, $caseId)
            || $this->actorContext->membership(
                $user,
                $business,
                CapabilityCatalog::RECORDS_MANAGE,
            ) === null
            || $this->actorContext->membership(
                $user,
                $business,
                CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
            ) === null
        ) {
            return null;
        }

        $proposedDecisionSummary = trim($proposedDecisionSummary);

        if ($proposedDecisionSummary === '') {
            throw new InvalidArgumentException(
                'Conflict formal decision summary is required.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedCaseRevision,
            $reviewerMembershipId,
            $proposedDecisionSummary,
            $conditions,
            $appealReference,
            $reviewDueAt,
        ): ?array {
            $case = ConflictCase::query()
                ->where('business_id', $business->getKey())
                ->whereKey($caseId)
                ->lockForUpdate()
                ->first();

            if ($case === null) {
                return null;
            }

            if ((int) $case->revision !== $expectedCaseRevision) {
                throw new StaleRevision(
                    $expectedCaseRevision,
                    (int) $case->revision,
                );
            }

            $decisionType = $this->decisionTypeForCase($business, $case);

            if ($decisionType === null) {
                return null;
            }

            $package = [
                'business_id' => (string) $business->getKey(),
                'conflict_case_id' => $caseId,
                'case_revision' => $expectedCaseRevision,
                'decision_type' => $decisionType,
                'proposed_decision_summary' => $proposedDecisionSummary,
                'conditions' => $this->nullableText($conditions),
                'appeal_reference' => $this->nullableText($appealReference),
            ];

            $packageHash = hash(
                'sha256',
                json_encode(
                    $package,
                    JSON_THROW_ON_ERROR
                        | JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE,
                ),
            );

            $capability = new Capability(
                CapabilityCatalog::RECORDS_MANAGE,
            );

            $proposal = $this->createProposal->execute(
                $user,
                $business,
                $capability,
                $packageHash,
            );

            if ($proposal === null) {
                return null;
            }

            $proposalVersion = $this->freezeProposal->execute(
                $user,
                $business,
                $capability,
                (string) $proposal->getKey(),
                1,
                [],
            );

            if ($proposalVersion === null) {
                return null;
            }

            $review = $this->createReview->execute(
                $user,
                $business,
                (string) $proposalVersion->getKey(),
                $reviewerMembershipId,
                $reviewDueAt,
            );

            if ($review === null) {
                return null;
            }

            $creator = $this->actorContext->membership(
                $user,
                $business,
                CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
            );

            if ($creator === null) {
                return null;
            }

            $submissionId = (string) Str::uuid7();

            DB::table('conflict_decision_submissions')->insert([
                'id' => $submissionId,
                'business_id' => $business->getKey(),
                'conflict_case_id' => $caseId,
                'case_revision' => $expectedCaseRevision,
                'package_hash' => $packageHash,
                'decision_type' => $decisionType,
                'proposed_decision_summary' => $proposedDecisionSummary,
                'conditions' => $this->nullableText($conditions),
                'appeal_reference' => $this->nullableText($appealReference),
                'proposal_version_id' => $proposalVersion->getKey(),
                'decision_id' => null,
                'created_by_membership_id' => $creator->getKey(),
                'created_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'conflict.decision.submitted',
                $caseId,
                [
                    'case_revision' => $expectedCaseRevision,
                    'decision_type' => $decisionType,
                ],
                (string) $proposalVersion->getKey(),
            );

            return [
                'submission_id' => $submissionId,
                'proposal_version_id' => (string) $proposalVersion->getKey(),
                'proposal_review_id' => (string) $review->getKey(),
                'package_hash' => $packageHash,
            ];
        });
    }

    public function open(
        User $user,
        Business $business,
        string $caseId,
        string $submissionId,
        ?string $meetingId = null,
    ): ?Decision {
        if (
            ! $this->visibility->canManage($user, $business, $caseId)
            || $this->actorContext->membership(
                $user,
                $business,
                CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
            ) === null
        ) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $submissionId,
            $meetingId,
        ): ?Decision {
            $case = ConflictCase::query()
                ->where('business_id', $business->getKey())
                ->whereKey($caseId)
                ->lockForUpdate()
                ->first();

            $submission = DB::table('conflict_decision_submissions')
                ->where('business_id', $business->getKey())
                ->where('conflict_case_id', $caseId)
                ->where('id', $submissionId)
                ->lockForUpdate()
                ->first();

            if (
                $case === null
                || $submission === null
                || $submission->decision_id !== null
                || (int) $case->revision !==
                    (int) $submission->case_revision
            ) {
                return null;
            }

            $decision = $this->openDecision->execute(
                $user,
                $business,
                (string) $submission->proposal_version_id,
                new DecisionType((string) $submission->decision_type),
                meetingId: $meetingId,
            );

            if ($decision === null) {
                return null;
            }

            $updated = DB::table('conflict_decision_submissions')
                ->where('business_id', $business->getKey())
                ->where('id', $submissionId)
                ->whereNull('decision_id')
                ->update([
                    'decision_id' => $decision->getKey(),
                ]);

            if ($updated !== 1) {
                return null;
            }

            $this->occurrence->record(
                $user,
                $business,
                'conflict.decision.opened',
                $caseId,
                [
                    'decision_type' => (string) $submission->decision_type,
                    'case_revision' => (int) $submission->case_revision,
                ],
                (string) $submission->proposal_version_id,
            );

            return $decision->fresh();
        });
    }

    public function applyApprovedDecision(
        User $user,
        Business $business,
        string $caseId,
        string $submissionId,
        int $expectedCaseRevision,
        ConflictCaseStage $target,
    ): ?ConflictCase {
        if (! $this->visibility->canManage($user, $business, $caseId)) {
            return null;
        }

        if (! in_array($target, [
            ConflictCaseStage::Settlement,
            ConflictCaseStage::Escalation,
            ConflictCaseStage::ExitLegal,
            ConflictCaseStage::Resolved,
        ], true)) {
            throw new InvalidArgumentException(
                'Approved Conflict Decision target is invalid.',
            );
        }

        $submission = DB::table('conflict_decision_submissions')
            ->where('business_id', $business->getKey())
            ->where('conflict_case_id', $caseId)
            ->where('id', $submissionId)
            ->first();

        if (
            $submission === null
            || $submission->decision_id === null
            || (int) $submission->case_revision !== $expectedCaseRevision
        ) {
            return null;
        }

        $decision = Decision::query()
            ->where('business_id', $business->getKey())
            ->whereKey($submission->decision_id)
            ->where(
                'proposal_version_id',
                $submission->proposal_version_id,
            )
            ->where('decision_type', $submission->decision_type)
            ->first();

        if (
            $decision === null
            || $decision->status !== DecisionStatus::Decided
            || $decision->outcome !== DecisionOutcome::Approved
        ) {
            return null;
        }

        return $this->cases->transition(
            $user,
            $business,
            $caseId,
            $expectedCaseRevision,
            $target,
            'governance_decision_approved',
            $target === ConflictCaseStage::Resolved
                ? 'governance_decision'
                : null,
            $target === ConflictCaseStage::Resolved
                ? (string) $decision->getKey()
                : null,
        );
    }

    private function decisionTypeForCase(
        Business $business,
        ConflictCase $case,
    ): ?string {
        $policy = DB::table('conflict_policy_versions')
            ->where('business_id', $business->getKey())
            ->where(
                'formal_record_version_id',
                $case->conflict_policy_formal_record_version_id,
            )
            ->first();

        if ($policy === null) {
            return null;
        }

        return match ($case->stage) {
            ConflictCaseStage::FormalDecision => (string) $policy->formal_decision_type,
            ConflictCaseStage::Deadlock => (string) $policy->deadlock_decision_type,
            ConflictCaseStage::MisconductInvestigation => (string) $policy->misconduct_decision_type,
            ConflictCaseStage::UrgentRisk => (string) $policy->urgent_risk_decision_type,
            default => null,
        };
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
