<?php

declare(strict_types=1);

namespace App\Application\Conflict;

use App\Domain\Conflict\Enums\ConflictCaseStage;
use App\Domain\Conflict\Enums\ConflictType;
use App\Domain\Governance\Enums\DecisionOutcome;
use App\Domain\Governance\Enums\DecisionStatus;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Conflict\ConflictCase;
use App\Infrastructure\Persistence\Eloquent\Conflict\ConflictUrgentRisk;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ConflictUrgentRiskWorkflow
{
    public function __construct(
        private readonly ConflictRecordVisibility $visibility,
        private readonly ConflictCaseWorkflow $cases,
        private readonly RecordConflictOccurrence $occurrence,
    ) {}

    public function open(
        User $user,
        Business $business,
        string $caseId,
        int $expectedCaseRevision,
        string $urgentRisk,
        string $immediateAction,
        string $informedParties,
        \DateTimeInterface $reviewDeadline,
        ?string $emergencyAuthorityGrantId = null,
    ): ?string {
        if (! $this->visibility->canManage($user, $business, $caseId)) {
            return null;
        }

        $urgentRisk = trim($urgentRisk);
        $immediateAction = trim($immediateAction);
        $informedParties = trim($informedParties);

        if (
            $urgentRisk === ''
            || $immediateAction === ''
            || $informedParties === ''
        ) {
            throw new InvalidArgumentException(
                'Urgent Risk requires risk, immediate action and informed parties.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedCaseRevision,
            $urgentRisk,
            $immediateAction,
            $informedParties,
            $reviewDeadline,
            $emergencyAuthorityGrantId,
        ): ?string {
            $case = ConflictCase::query()
                ->where('business_id', $business->getKey())
                ->whereKey($caseId)
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

            if (
                $case->conflict_type !== ConflictType::UrgentRisk
                && $case->urgency !== 'critical'
            ) {
                return null;
            }

            $actorId = $this->activeActorMembershipId($user, $business);

            if ($actorId === null) {
                return null;
            }

            $authority = null;

            if ($emergencyAuthorityGrantId !== null) {
                $authority = $this->validAuthority(
                    $business,
                    $case,
                    $actorId,
                    $emergencyAuthorityGrantId,
                );

                if ($authority === null) {
                    return null;
                }
            }

            if ($case->stage !== ConflictCaseStage::UrgentRisk) {
                if ($this->cases->transition(
                    $user,
                    $business,
                    $caseId,
                    $expectedCaseRevision,
                    ConflictCaseStage::UrgentRisk,
                    'urgent_risk_entered',
                ) === null) {
                    return null;
                }
            }

            $id = (string) Str::uuid7();

            ConflictUrgentRisk::query()->create([
                'id' => $id,
                'business_id' => $business->getKey(),
                'conflict_case_id' => $caseId,
                'urgent_risk' => $urgentRisk,
                'immediate_action' => $immediateAction,
                'informed_parties' => $informedParties,
                'review_deadline' => $reviewDeadline,
                'emergency_authority_grant_id' => $emergencyAuthorityGrantId,
                'authority_starts_at' => $authority?->effective_from,
                'authority_expires_at' => $authority?->expires_at,
                'final_decision_id' => null,
                'status' => $authority === null ? 'open' : 'active',
                'revision' => 1,
                'created_by_membership_id' => $actorId,
                'completed_at' => null,
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'conflict.urgent_risk.opened',
                $caseId,
                [
                    'status' => $authority === null ? 'open' : 'active',
                    'authority_bound' => $authority !== null,
                ],
            );

            return $id;
        });
    }

    public function bindFinalDecision(
        User $user,
        Business $business,
        string $caseId,
        string $urgentRiskId,
        int $expectedRevision,
        string $decisionId,
    ): bool {
        if (! $this->visibility->canManage($user, $business, $caseId)) {
            return false;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $urgentRiskId,
            $expectedRevision,
            $decisionId,
        ): bool {
            $row = ConflictUrgentRisk::query()
                ->where('business_id', $business->getKey())
                ->where('conflict_case_id', $caseId)
                ->whereKey($urgentRiskId)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return false;
            }

            if ((int) $row->revision !== $expectedRevision) {
                throw new StaleRevision(
                    $expectedRevision,
                    (int) $row->revision,
                );
            }

            $decisionType = DB::table('conflict_cases as c')
                ->join(
                    'conflict_policy_versions as p',
                    function ($join): void {
                        $join->on(
                            'p.formal_record_version_id',
                            '=',
                            'c.conflict_policy_formal_record_version_id',
                        )->on('p.business_id', '=', 'c.business_id');
                    },
                )
                ->where('c.business_id', $business->getKey())
                ->where('c.id', $caseId)
                ->value('p.urgent_risk_decision_type');

            $decision = Decision::query()
                ->where('business_id', $business->getKey())
                ->whereKey($decisionId)
                ->where('decision_type', $decisionType)
                ->where('status', DecisionStatus::Decided->value)
                ->where('outcome', DecisionOutcome::Approved->value)
                ->first();

            if ($decision === null) {
                return false;
            }

            $submissionExists = DB::table(
                'conflict_decision_submissions',
            )
                ->where('business_id', $business->getKey())
                ->where('conflict_case_id', $caseId)
                ->where('decision_id', $decisionId)
                ->where(
                    'proposal_version_id',
                    $decision->proposal_version_id,
                )
                ->exists();

            if (! $submissionExists) {
                return false;
            }

            $updated = ConflictUrgentRisk::query()
                ->where('business_id', $business->getKey())
                ->whereKey($urgentRiskId)
                ->where('revision', $expectedRevision)
                ->update([
                    'final_decision_id' => $decisionId,
                    'status' => 'resolved',
                    'revision' => $expectedRevision + 1,
                    'completed_at' => now(),
                ]);

            if ($updated !== 1) {
                return false;
            }

            $this->occurrence->record(
                $user,
                $business,
                'conflict.urgent_risk.decision_bound',
                $caseId,
                [
                    'status' => 'resolved',
                    'revision' => $expectedRevision + 1,
                ],
            );

            return true;
        });
    }

    private function validAuthority(
        Business $business,
        ConflictCase $case,
        string $actorMembershipId,
        string $grantId,
    ): ?object {
        $decisionType = DB::table('conflict_policy_versions')
            ->where('business_id', $business->getKey())
            ->where(
                'formal_record_version_id',
                $case->conflict_policy_formal_record_version_id,
            )
            ->value('urgent_risk_decision_type');

        if (! is_string($decisionType) || $decisionType === '') {
            return null;
        }

        $grant = DB::table('emergency_authority_grants')
            ->where('business_id', $business->getKey())
            ->where('id', $grantId)
            ->where('grantee_membership_id', $actorMembershipId)
            ->where('decision_type', $decisionType)
            ->where('scope', 'conflict_case:'.(string) $case->getKey())
            ->where('status', 'active')
            ->where('effective_from', '<=', now())
            ->where('expires_at', '>', now())
            ->first();

        if ($grant === null) {
            return null;
        }

        $authorized = DB::table(
            'governance_authority_change_submissions',
        )
            ->where('business_id', $business->getKey())
            ->where('subject_type', 'emergency_authority')
            ->where('subject_id', $grantId)
            ->where('action', 'grant')
            ->whereNotNull('authorized_at')
            ->exists();

        return $authorized ? $grant : null;
    }

    private function activeActorMembershipId(
        User $user,
        Business $business,
    ): ?string {
        $id = DB::table('memberships')
            ->where('business_id', $business->getKey())
            ->where('user_id', $user->getKey())
            ->where('access_status', 'active')
            ->value('id');

        return $id === null ? null : (string) $id;
    }
}
