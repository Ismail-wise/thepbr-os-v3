<?php

declare(strict_types=1);

namespace App\Application\Conflict;

use App\Domain\Conflict\Enums\ConflictCaseStage;
use App\Domain\Conflict\Enums\ConflictType;
use App\Domain\Governance\Enums\DecisionStatus;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Conflict\ConflictCase;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ConflictDeadlockWorkflow
{
    public function __construct(
        private readonly ConflictRecordVisibility $visibility,
        private readonly ConflictCaseWorkflow $cases,
        private readonly RecordConflictOccurrence $occurrence,
    ) {}

    public function enter(
        User $user,
        Business $business,
        string $caseId,
        int $expectedCaseRevision,
        int $failedVoteCount,
        ?\DateTimeInterface $coolingOffUntil = null,
        ?string $neutralReference = null,
    ): ?string {
        if (! $this->visibility->canManage($user, $business, $caseId)) {
            return null;
        }

        if ($failedVoteCount < 0) {
            throw new InvalidArgumentException(
                'Deadlock failed-vote count cannot be negative.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedCaseRevision,
            $failedVoteCount,
            $coolingOffUntil,
            $neutralReference,
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
                $case->conflict_type !== ConflictType::Deadlock5050
                && ! in_array(
                    $case->stage,
                    [
                        ConflictCaseStage::FormalDecision,
                        ConflictCaseStage::Escalation,
                    ],
                    true,
                )
            ) {
                return null;
            }

            if (DB::table('conflict_deadlock_records')
                ->where('business_id', $business->getKey())
                ->where('conflict_case_id', $caseId)
                ->exists()) {
                return null;
            }

            if ($case->stage !== ConflictCaseStage::Deadlock) {
                if ($this->cases->transition(
                    $user,
                    $business,
                    $caseId,
                    $expectedCaseRevision,
                    ConflictCaseStage::Deadlock,
                    'deadlock_entered',
                ) === null) {
                    return null;
                }
            }

            $actor = DB::table('memberships')
                ->where('business_id', $business->getKey())
                ->where('user_id', $user->getKey())
                ->where('access_status', 'active')
                ->value('id');

            if ($actor === null) {
                return null;
            }

            $id = (string) Str::uuid7();

            DB::table('conflict_deadlock_records')->insert([
                'id' => $id,
                'business_id' => $business->getKey(),
                'conflict_case_id' => $caseId,
                'failed_vote_count' => $failedVoteCount,
                'cooling_off_until' => $coolingOffUntil,
                'neutral_reference' => $this->nullableText($neutralReference),
                'decision_id' => null,
                'status' => $coolingOffUntil === null
                    ? 'open'
                    : 'cooling_off',
                'revision' => 1,
                'outcome' => null,
                'created_by_membership_id' => $actor,
                'created_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'conflict.deadlock.entered',
                $caseId,
                [
                    'failed_vote_count' => $failedVoteCount,
                    'status' => $coolingOffUntil === null
                        ? 'open'
                        : 'cooling_off',
                ],
            );

            return $id;
        });
    }

    public function bindDecision(
        User $user,
        Business $business,
        string $caseId,
        string $deadlockId,
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
            $deadlockId,
            $expectedRevision,
            $decisionId,
        ): bool {
            $row = DB::table('conflict_deadlock_records')
                ->where('business_id', $business->getKey())
                ->where('conflict_case_id', $caseId)
                ->where('id', $deadlockId)
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

            $policyDecisionType = DB::table('conflict_cases as c')
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
                ->value('p.deadlock_decision_type');

            $decision = Decision::query()
                ->where('business_id', $business->getKey())
                ->whereKey($decisionId)
                ->where('decision_type', $policyDecisionType)
                ->where('status', DecisionStatus::Decided->value)
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

            $updated = DB::table('conflict_deadlock_records')
                ->where('business_id', $business->getKey())
                ->where('id', $deadlockId)
                ->where('revision', $expectedRevision)
                ->update([
                    'decision_id' => $decisionId,
                    'status' => 'decision',
                    'revision' => $expectedRevision + 1,
                ]);

            if ($updated !== 1) {
                return false;
            }

            $this->occurrence->record(
                $user,
                $business,
                'conflict.deadlock.decision_bound',
                $caseId,
                [
                    'status' => 'decision',
                    'revision' => $expectedRevision + 1,
                ],
            );

            return true;
        });
    }

    public function resolve(
        User $user,
        Business $business,
        string $caseId,
        string $deadlockId,
        int $expectedRevision,
        string $outcome,
    ): bool {
        if (! $this->visibility->canManage($user, $business, $caseId)) {
            return false;
        }

        $outcome = trim($outcome);

        if ($outcome === '') {
            throw new InvalidArgumentException(
                'Deadlock outcome is required.',
            );
        }

        $updated = DB::table('conflict_deadlock_records')
            ->where('business_id', $business->getKey())
            ->where('conflict_case_id', $caseId)
            ->where('id', $deadlockId)
            ->where('revision', $expectedRevision)
            ->whereIn('status', [
                'open',
                'cooling_off',
                'mediation',
                'decision',
            ])
            ->update([
                'status' => 'resolved',
                'outcome' => $outcome,
                'revision' => $expectedRevision + 1,
            ]);

        if ($updated !== 1) {
            return false;
        }

        $this->occurrence->record(
            $user,
            $business,
            'conflict.deadlock.resolved',
            $caseId,
            [
                'status' => 'resolved',
                'revision' => $expectedRevision + 1,
            ],
        );

        return true;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
