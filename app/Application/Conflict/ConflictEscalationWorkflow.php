<?php

declare(strict_types=1);

namespace App\Application\Conflict;

use App\Domain\Conflict\Enums\ConflictCaseStage;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Conflict\ConflictCase;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ConflictEscalationWorkflow
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
        string $escalationRuleId,
    ): ?string {
        if (! $this->visibility->canManage($user, $business, $caseId)) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedCaseRevision,
            $escalationRuleId,
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

            $rule = DB::table('conflict_escalation_rules')
                ->where('business_id', $business->getKey())
                ->where(
                    'formal_record_version_id',
                    $case->conflict_policy_formal_record_version_id,
                )
                ->where('id', $escalationRuleId)
                ->where('status', 'active')
                ->first();

            if ($rule === null) {
                return null;
            }

            if ($case->stage !== ConflictCaseStage::Escalation) {
                $transitioned = $this->cases->transition(
                    $user,
                    $business,
                    $caseId,
                    $expectedCaseRevision,
                    ConflictCaseStage::Escalation,
                    'escalation_entered',
                );

                if ($transitioned === null) {
                    return null;
                }
            }

            $escalationId = (string) Str::uuid7();

            DB::table('conflict_escalations')->insert([
                'id' => $escalationId,
                'business_id' => $business->getKey(),
                'conflict_case_id' => $caseId,
                'conflict_escalation_rule_id' => $escalationRuleId,
                'entered_at' => now(),
                'due_at' => $rule->max_days === null
                    ? null
                    : now()->addDays((int) $rule->max_days),
                'operations_role_id' => $rule->operations_role_id,
                'status' => 'active',
                'outcome' => null,
                'decision_submission_id' => null,
                'created_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'conflict.escalation.entered',
                $caseId,
                [
                    'step_key' => (string) $rule->step_key,
                    'status' => 'active',
                ],
            );

            return $escalationId;
        });
    }

    public function resolve(
        User $user,
        Business $business,
        string $caseId,
        string $escalationId,
        string $outcome,
        ?string $decisionSubmissionId = null,
    ): bool {
        if (! $this->visibility->canManage($user, $business, $caseId)) {
            return false;
        }

        $outcome = trim($outcome);

        if ($outcome === '') {
            throw new InvalidArgumentException(
                'Conflict escalation outcome is required.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $escalationId,
            $outcome,
            $decisionSubmissionId,
        ): bool {
            if ($decisionSubmissionId !== null) {
                $valid = DB::table('conflict_decision_submissions')
                    ->where('business_id', $business->getKey())
                    ->where('conflict_case_id', $caseId)
                    ->where('id', $decisionSubmissionId)
                    ->exists();

                if (! $valid) {
                    return false;
                }
            }

            $updated = DB::table('conflict_escalations')
                ->where('business_id', $business->getKey())
                ->where('conflict_case_id', $caseId)
                ->where('id', $escalationId)
                ->where('status', 'active')
                ->update([
                    'status' => 'resolved',
                    'outcome' => $outcome,
                    'decision_submission_id' => $decisionSubmissionId,
                ]);

            if ($updated !== 1) {
                return false;
            }

            $this->occurrence->record(
                $user,
                $business,
                'conflict.escalation.resolved',
                $caseId,
                ['status' => 'resolved'],
            );

            return true;
        });
    }
}
