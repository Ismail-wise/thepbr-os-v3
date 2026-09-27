<?php

declare(strict_types=1);

namespace Tests\Feature\Conflict;

require_once __DIR__.'/F6EConflictPolicyTest.php';

use App\Application\Conflict\ConflictCaseWorkflow;
use App\Application\Conflict\ConflictDecisionWorkflow;
use App\Application\Conflict\ConflictDirectDiscussionWorkflow;
use App\Application\Conflict\ConflictMediationWorkflow;
use App\Application\Governance\RecordGovernanceApproval;
use App\Application\Governance\ResolveGovernanceDecision;
use App\Domain\Conflict\Enums\ConflictCaseStage;
use App\Domain\Conflict\Enums\DirectDiscussionOutcome;
use App\Domain\Conflict\Enums\MediationResponseOutcome;
use App\Domain\Governance\Enums\ApprovalOutcome;
use App\Infrastructure\Persistence\Eloquent\Conflict\ConflictCase;
use App\Infrastructure\Persistence\Eloquent\Governance\AuthoritySnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class F6EMediationDecisionTest extends F6EConflictTestCase
{
    public function test_mediation_acceptance_is_not_governance_approval(): void
    {
        $context = $this->context();
        $opened = $this->openCase($context);

        $case = $this->app->make(ConflictCaseWorkflow::class)
            ->transition(
                $context['user'],
                $context['business'],
                $opened['id'],
                1,
                ConflictCaseStage::DirectDiscussion,
                'discussion_started',
            );
        self::assertNotNull($case);

        $discussionId = $this->app
            ->make(ConflictDirectDiscussionWorkflow::class)
            ->record(
                $context['user'],
                $context['business'],
                $opened['id'],
                (int) $case->revision,
                'Partner expectations and delivery concerns.',
                'Both parties stated their positions.',
                'Try neutral mediation.',
                DirectDiscussionOutcome::ContinueMediation,
                [(string) $context['party']->getKey()],
            );
        self::assertNotNull($discussionId);

        $case = ConflictCase::query()->findOrFail($opened['id']);
        self::assertSame(ConflictCaseStage::Mediation, $case->stage);

        $mediationId = $this->app
            ->make(ConflictMediationWorkflow::class)
            ->schedule(
                $context['user'],
                $context['business'],
                $opened['id'],
                (int) $case->revision,
                'external',
                'Mediator has no identified conflict with either party.',
                now()->addDay(),
                null,
                'Independent mediator M-001',
                now()->addDays(2),
            );
        self::assertNotNull($mediationId);

        $this->grantCapability(
            $context['business'],
            $context['party'],
            'conflict.view',
        );
        self::assertTrue(
            $this->app->make(ConflictCaseWorkflow::class)->grantAccess(
                $context['user'],
                $context['business'],
                $opened['id'],
                [(string) $context['party']->getKey()],
                false,
            ),
        );

        self::assertTrue(
            $this->app->make(ConflictMediationWorkflow::class)
                ->recordResponse(
                    $context['party_user'],
                    $context['business'],
                    $opened['id'],
                    $mediationId,
                    MediationResponseOutcome::Accepted,
                    'Party accepts the proposed settlement basis.',
                ),
        );

        $this->assertDatabaseHas('conflict_mediation_responses', [
            'conflict_mediation_id' => $mediationId,
            'membership_id' => $context['party']->getKey(),
            'response' => 'accepted',
        ]);
        $this->assertDatabaseCount('approvals', 0);
        $this->assertDatabaseCount('votes', 0);
        $this->assertDatabaseCount('signature_requests', 0);
    }

    public function test_formal_decision_reuses_f3_and_exact_f6a_authority_snapshot(): void
    {
        $context = $this->context();
        $opened = $this->openCase($context, 'governance_dispute');

        $case = $this->app->make(ConflictCaseWorkflow::class)
            ->transition(
                $context['user'],
                $context['business'],
                $opened['id'],
                1,
                ConflictCaseStage::FormalDecision,
                'formal_decision_required',
            );
        self::assertNotNull($case);

        $workflow = $this->app->make(ConflictDecisionWorkflow::class);
        $submission = $workflow->submit(
            $context['user'],
            $context['business'],
            $opened['id'],
            (int) $case->revision,
            (string) $context['owner']->getKey(),
            'Approve the exact conflict remedy package.',
            'Complete operational follow-up within 14 days.',
        );
        self::assertNotNull($submission);

        DB::table('proposal_reviews')
            ->where('id', $submission['proposal_review_id'])
            ->update([
                'status' => 'completed',
                'outcome' => 'approved',
                'notes' => 'Exact frozen package reviewed.',
                'resolved_at' => now(),
                'updated_at' => now(),
            ]);

        $decision = $workflow->open(
            $context['user'],
            $context['business'],
            $opened['id'],
            $submission['submission_id'],
        );
        self::assertNotNull($decision);

        $snapshot = AuthoritySnapshot::query()->findOrFail(
            $decision->authority_snapshot_id,
        );
        self::assertSame('governance_charter', $snapshot->source_kind);
        self::assertSame(
            $context['governance_version_id'],
            (string) $snapshot->source_formal_record_version_id,
        );
        self::assertSame(
            $submission['proposal_version_id'],
            (string) $decision->proposal_version_id,
        );

        $approval = $this->app
            ->make(RecordGovernanceApproval::class)
            ->execute(
                $context['user'],
                $context['business'],
                (string) $decision->getKey(),
                ApprovalOutcome::Approved,
                'Approved exact frozen conflict package.',
            );
        self::assertNotNull($approval);

        $resolved = $this->app
            ->make(ResolveGovernanceDecision::class)
            ->approve(
                $context['user'],
                $context['business'],
                (string) $decision->getKey(),
            );
        self::assertNotNull($resolved);

        $applied = $workflow->applyApprovedDecision(
            $context['user'],
            $context['business'],
            $opened['id'],
            $submission['submission_id'],
            (int) $case->revision,
            ConflictCaseStage::Settlement,
        );
        self::assertNotNull($applied);
        self::assertSame(ConflictCaseStage::Settlement, $applied->stage);
    }

    public function test_decision_package_hash_and_case_revision_are_immutable(): void
    {
        $context = $this->context();
        $opened = $this->openCase($context, 'governance_dispute');
        $case = $this->app->make(ConflictCaseWorkflow::class)
            ->transition(
                $context['user'],
                $context['business'],
                $opened['id'],
                1,
                ConflictCaseStage::FormalDecision,
            );
        self::assertNotNull($case);

        $submission = $this->app
            ->make(ConflictDecisionWorkflow::class)
            ->submit(
                $context['user'],
                $context['business'],
                $opened['id'],
                (int) $case->revision,
                (string) $context['owner']->getKey(),
                'Frozen remedy package.',
            );
        self::assertNotNull($submission);

        $this->expectException(QueryException::class);

        DB::table('conflict_decision_submissions')
            ->where('id', $submission['submission_id'])
            ->update(['package_hash' => str_repeat('f', 64)]);
    }
}
