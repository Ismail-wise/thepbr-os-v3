<?php

declare(strict_types=1);

namespace Tests\Feature\Conflict;

require_once __DIR__.'/F6EConflictPolicyTest.php';

use App\Application\Conflict\ConflictCaseWorkflow;
use App\Application\Conflict\ConflictDirectDiscussionWorkflow;
use App\Domain\Conflict\Enums\ConflictCaseStage;
use App\Domain\Conflict\Enums\DirectDiscussionOutcome;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class F6EConflictDatabaseInvariantTest extends F6EConflictTestCase
{
    public function test_cross_business_participant_identity_is_rejected_by_composite_fk(): void
    {
        $context = $this->context();
        $foreign = $this->context();
        $opened = $this->openCase($context);

        $this->expectException(QueryException::class);

        DB::table('conflict_case_participants')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $context['business']->getKey(),
            'conflict_case_id' => $opened['id'],
            'membership_id' => $foreign['party']->getKey(),
            'external_reference' => null,
            'participant_role' => 'party',
            'status' => 'active',
            'added_at' => now(),
            'removed_at' => null,
        ]);
    }

    public function test_frozen_policy_snapshot_cannot_be_repointed_to_another_governance_version(): void
    {
        $context = $this->context();

        $family = FormalRecordFamily::query()
            ->where('business_id', $context['business']->getKey())
            ->where('record_type', 'governance_charter')
            ->where('subject_type', 'business')
            ->where('subject_id', $context['business']->getKey())
            ->sole();

        $otherVersion =
            FormalRecordVersion::query()
                ->create([
                    'business_id' => $context['business']->getKey(),
                    'formal_record_family_id' => $family->getKey(),
                    'version_number' => 2,
                    'predecessor_version_id' => $context['governance_version_id'],
                    'revision' => 1,
                    'change_summary' => 'Non-current Governance version for invariant test.',
                    'created_by_user_id' => $context['user']->getKey(),
                    'last_changed_by_user_id' => $context['user']->getKey(),
                    'effective_from' => now()->addDay(),
                    'effective_until' => null,
                    'review_due_at' => null,
                    'content_hash' => str_repeat('d', 64),
                    'frozen_at' => null,
                ]);

        $this->expectException(QueryException::class);

        DB::table('conflict_policy_versions')
            ->where(
                'formal_record_version_id',
                $context['policy_version_id'],
            )
            ->update([
                'governance_formal_record_version_id' => $otherVersion->getKey(),
            ]);
    }

    public function test_direct_discussion_is_append_only_even_with_raw_sql(): void
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

        $discussionId = $this->app
            ->make(ConflictDirectDiscussionWorkflow::class)
            ->record(
                $context['user'],
                $context['business'],
                $opened['id'],
                (int) $case->revision,
                'Issues recorded exactly once.',
                'Party positions preserved.',
                'Continue through governed process.',
                DirectDiscussionOutcome::ContinueFormalDecision,
                [(string) $context['party']->getKey()],
            );
        self::assertNotNull($discussionId);

        $this->expectException(QueryException::class);

        DB::table('conflict_direct_discussions')
            ->where('id', $discussionId)
            ->update(['issues_discussed' => 'rewritten']);
    }

    public function test_case_business_id_cannot_be_rewritten(): void
    {
        $context = $this->context();
        $foreign = Business::query()->create([
            'name' => 'Foreign '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'operating',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);
        $opened = $this->openCase($context);

        $this->expectException(QueryException::class);

        DB::table('conflict_cases')
            ->where('id', $opened['id'])
            ->update(['business_id' => $foreign->getKey()]);
    }

    public function test_case_history_cannot_be_deleted(): void
    {
        $context = $this->context();
        $opened = $this->openCase($context);

        $this->expectException(QueryException::class);

        DB::table('conflict_case_updates')
            ->where('conflict_case_id', $opened['id'])
            ->delete();
    }
}
