<?php

declare(strict_types=1);

namespace Tests\Feature\Conflict;

require_once __DIR__.'/F6EConflictPolicyTest.php';

use App\Application\Conflict\ConflictCaseWorkflow;
use App\Application\Conflict\ConflictDeadlockWorkflow;
use App\Application\Conflict\ConflictEscalationWorkflow;
use App\Application\Conflict\ConflictInvestigationWorkflow;
use App\Application\Conflict\ConflictReferralWorkflow;
use App\Application\Conflict\ConflictReviewWorkflow;
use App\Application\Conflict\ConflictUrgentRiskWorkflow;
use App\Application\Conflict\CreateConflictAction;
use App\Domain\Conflict\Enums\ConflictCaseStage;
use App\Infrastructure\Persistence\Eloquent\Conflict\ConflictCase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class F6ESpecialPathsTest extends F6EConflictTestCase
{
    public function test_escalation_uses_captured_policy_rule_without_creating_authority(): void
    {
        $context = $this->context();
        $opened = $this->openCase($context);
        $case = $this->app->make(ConflictCaseWorkflow::class)->transition(
            $context['user'],
            $context['business'],
            $opened['id'],
            1,
            ConflictCaseStage::DirectDiscussion,
        );
        self::assertNotNull($case);

        $ruleId = (string) DB::table('conflict_escalation_rules')
            ->where(
                'formal_record_version_id',
                $context['policy_version_id'],
            )
            ->value('id');

        $beforeGrants = DB::table('emergency_authority_grants')->count();

        $escalationId = $this->app
            ->make(ConflictEscalationWorkflow::class)
            ->enter(
                $context['user'],
                $context['business'],
                $opened['id'],
                (int) $case->revision,
                $ruleId,
            );

        self::assertNotNull($escalationId);
        $this->assertDatabaseHas('conflict_escalations', [
            'id' => $escalationId,
            'conflict_case_id' => $opened['id'],
            'conflict_escalation_rule_id' => $ruleId,
            'operations_role_id' => $context['role_id'],
            'status' => 'active',
        ]);
        self::assertSame(
            $beforeGrants,
            DB::table('emergency_authority_grants')->count(),
        );

        $case = ConflictCase::query()->findOrFail($opened['id']);
        self::assertSame(ConflictCaseStage::Escalation, $case->stage);

        self::assertTrue(
            $this->app->make(ConflictEscalationWorkflow::class)
                ->resolve(
                    $context['user'],
                    $context['business'],
                    $opened['id'],
                    $escalationId,
                    'Resolved without creating new authority.',
                ),
        );
    }

    public function test_deadlock_record_is_case_state_not_decision_authority(): void
    {
        $context = $this->context();
        $opened = $this->openCase($context, 'deadlock_50_50');

        $deadlockId = $this->app->make(ConflictDeadlockWorkflow::class)
            ->enter(
                $context['user'],
                $context['business'],
                $opened['id'],
                1,
                2,
                now()->addDay(),
                'Independent neutral N-01',
            );

        self::assertNotNull($deadlockId);
        $this->assertDatabaseHas('conflict_deadlock_records', [
            'id' => $deadlockId,
            'conflict_case_id' => $opened['id'],
            'failed_vote_count' => 2,
            'status' => 'cooling_off',
            'decision_id' => null,
        ]);
        $this->assertDatabaseCount('decisions', 0);
        $this->assertDatabaseCount('approvals', 0);
        $this->assertDatabaseCount('votes', 0);
    }

    public function test_misconduct_investigation_records_finding_but_does_not_apply_restriction(): void
    {
        $context = $this->context();
        $opened = $this->openCase($context, 'misconduct');

        $beforePermissionGrants = DB::table('permission_grants')->count();
        $beforeEmergencyGrants = DB::table('emergency_authority_grants')->count();

        $investigationId = $this->app
            ->make(ConflictInvestigationWorkflow::class)
            ->open(
                $context['user'],
                $context['business'],
                $opened['id'],
                1,
                (string) $context['owner']->getKey(),
                'A specific misconduct allegation requires investigation.',
                'Propose temporary access restriction for separate authorization.',
            );

        self::assertNotNull($investigationId);

        $case = ConflictCase::query()->findOrFail($opened['id']);
        self::assertSame(
            ConflictCaseStage::MisconductInvestigation,
            $case->stage,
        );

        self::assertTrue(
            $this->app->make(ConflictInvestigationWorkflow::class)
                ->completeWithFinding(
                    $context['user'],
                    $context['business'],
                    $opened['id'],
                    $investigationId,
                    (int) $case->revision,
                    1,
                    'Evidence supports a defined policy breach.',
                    'Governance should review the proposed remedy.',
                    'Appeal route remains available.',
                    false,
                ),
        );

        $this->assertDatabaseHas('conflict_investigation_findings', [
            'conflict_investigation_id' => $investigationId,
            'exit_trigger_recommended' => false,
        ]);
        self::assertSame(
            $beforePermissionGrants,
            DB::table('permission_grants')->count(),
        );
        self::assertSame(
            $beforeEmergencyGrants,
            DB::table('emergency_authority_grants')->count(),
        );

        $case = ConflictCase::query()->findOrFail($opened['id']);
        self::assertSame(ConflictCaseStage::FormalDecision, $case->stage);
    }

    public function test_urgent_risk_containment_without_grant_does_not_manufacture_authority(): void
    {
        $context = $this->context();
        $opened = $this->openCase($context, 'urgent_risk');

        $before = DB::table('emergency_authority_grants')->count();

        $urgentId = $this->app
            ->make(ConflictUrgentRiskWorkflow::class)
            ->open(
                $context['user'],
                $context['business'],
                $opened['id'],
                1,
                'Immediate operational loss risk.',
                'Pause the exposed operational step.',
                'Conflict owner and affected operators.',
                now()->addHours(6),
                null,
            );

        self::assertNotNull($urgentId);
        $this->assertDatabaseHas('conflict_urgent_risk_records', [
            'id' => $urgentId,
            'conflict_case_id' => $opened['id'],
            'emergency_authority_grant_id' => null,
            'status' => 'open',
            'revision' => 1,
        ]);
        self::assertSame(
            $before,
            DB::table('emergency_authority_grants')->count(),
        );
    }

    public function test_urgent_risk_rejects_scope_time_grantee_and_ungoverned_grant_expansion(): void
    {
        $context = $this->context();
        $opened = $this->openCase($context, 'urgent_risk');

        $createGrant = function (
            string $granteeMembershipId,
            string $scope,
            \DateTimeInterface $effectiveFrom,
            \DateTimeInterface $expiresAt,
        ) use ($context): string {
            $grantId = (string) Str::uuid7();

            DB::table('emergency_authority_grants')->insert([
                'id' => $grantId,
                'business_id' => $context['business']->getKey(),
                'grantee_membership_id' => $granteeMembershipId,
                'decision_type' => 'conflict_urgent_risk_decision',
                'scope' => $scope,
                'reason' => 'Invariant fixture only.',
                'status' => 'active',
                'effective_from' => $effectiveFrom,
                'expires_at' => $expiresAt,
                'created_by_membership_id' => $context['owner']->getKey(),
                'revoked_by_membership_id' => null,
                'revoked_at' => null,
                'capacity' => null,
                'can_approve' => null,
                'can_vote' => null,
                'can_sign' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $grantId;
        };

        $workflow = $this->app->make(ConflictUrgentRiskWorkflow::class);
        $open = fn (string $grantId): ?string => $workflow->open(
            $context['user'],
            $context['business'],
            $opened['id'],
            1,
            'Exact authority is required for the governed emergency step.',
            'Contain operational exposure only.',
            'Conflict owner informed.',
            now()->addHour(),
            $grantId,
        );

        $wrongScopeGrant = $createGrant(
            (string) $context['owner']->getKey(),
            'conflict_case:'.(string) Str::uuid7(),
            now()->subMinute(),
            now()->addHour(),
        );
        self::assertNull($open($wrongScopeGrant));

        $expiredGrant = $createGrant(
            (string) $context['owner']->getKey(),
            'conflict_case:'.$opened['id'],
            now()->subHours(2),
            now()->subHour(),
        );
        self::assertNull($open($expiredGrant));

        $wrongGranteeGrant = $createGrant(
            (string) $context['party']->getKey(),
            'conflict_case:'.$opened['id'],
            now()->subMinute(),
            now()->addHour(),
        );
        self::assertNull($open($wrongGranteeGrant));

        $ungovernedGrant = $createGrant(
            (string) $context['owner']->getKey(),
            'conflict_case:'.$opened['id'],
            now()->subMinute(),
            now()->addHour(),
        );
        self::assertNull(
            $open($ungovernedGrant),
            'Exact grant fields are still insufficient until the grant is governed/authorized.',
        );

        $this->assertDatabaseCount('conflict_urgent_risk_records', 0);
    }

    public function test_review_referral_and_operations_action_preserve_separate_domains(): void
    {
        $context = $this->context();
        $opened = $this->openCase($context);

        $reviewId = $this->app->make(ConflictReviewWorkflow::class)
            ->create(
                $context['user'],
                $context['business'],
                $opened['id'],
                (string) $context['owner']->getKey(),
                now()->addDay(),
            );
        self::assertNotNull($reviewId);

        self::assertTrue(
            $this->app->make(ConflictReviewWorkflow::class)->complete(
                $context['user'],
                $context['business'],
                $opened['id'],
                $reviewId,
                1,
                'continue',
                'Continue structured resolution.',
            ),
        );

        $action = $this->app->make(CreateConflictAction::class)->execute(
            $context['user'],
            $context['business'],
            $opened['id'],
            $context['role_id'],
            (string) $context['owner']->getKey(),
            'Prepare partner meeting facts',
            'conflict_case',
            $opened['id'],
            'Operational follow-up only.',
            now()->addDay(),
        );
        self::assertNotNull($action);

        $this->assertDatabaseHas('conflict_action_links', [
            'business_id' => $context['business']->getKey(),
            'conflict_case_id' => $opened['id'],
            'action_id' => $action->getKey(),
            'operations_formal_record_version_id' => $context['operations_version_id'],
            'operations_role_id' => $context['role_id'],
            'source_type' => 'conflict_case',
            'source_id' => $opened['id'],
        ]);

        $case = $this->app->make(ConflictCaseWorkflow::class)->transition(
            $context['user'],
            $context['business'],
            $opened['id'],
            1,
            ConflictCaseStage::FormalDecision,
        );
        self::assertNotNull($case);

        $referralId = $this->app->make(ConflictReferralWorkflow::class)
            ->refer(
                $context['user'],
                $context['business'],
                $opened['id'],
                (int) $case->revision,
                'legal_counsel',
                'Internal resolution requires external legal advice.',
                'LEGAL-REF-001',
            );
        self::assertNotNull($referralId);

        $this->assertDatabaseHas('conflict_exit_legal_referrals', [
            'id' => $referralId,
            'conflict_case_id' => $opened['id'],
            'referral_type' => 'legal_counsel',
            'status' => 'referred',
        ]);

        $this->assertDatabaseCount('ownership_register_versions', 0);

        try {
            DB::table('conflict_exit_legal_referrals')
                ->where('id', $referralId)
                ->update(['status' => 'completed']);
            self::fail('Referral history must be append-only.');
        } catch (QueryException) {
            self::addToAssertionCount(1);
        }
    }
}
