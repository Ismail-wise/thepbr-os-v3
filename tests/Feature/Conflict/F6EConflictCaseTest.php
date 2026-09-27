<?php

declare(strict_types=1);

namespace Tests\Feature\Conflict;

require_once __DIR__.'/F6EConflictPolicyTest.php';

use App\Application\Conflict\ConflictCaseWorkflow;
use App\Domain\Conflict\Enums\ConflictCaseStage;
use App\Domain\Records\Exceptions\StaleRevision;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class F6EConflictCaseTest extends F6EConflictTestCase
{
    public function test_case_captures_exact_policy_and_is_restricted_by_default(): void
    {
        $context = $this->context();
        $opened = $this->openCase($context);

        $this->assertDatabaseHas('conflict_cases', [
            'id' => $opened['id'],
            'business_id' => $context['business']->getKey(),
            'conflict_policy_formal_record_version_id' => $context['policy_version_id'],
            'confidentiality' => 'restricted',
            'stage' => 'intake',
            'status' => 'open',
            'revision' => 1,
        ]);

        $this->assertDatabaseHas('conflict_case_participants', [
            'business_id' => $context['business']->getKey(),
            'conflict_case_id' => $opened['id'],
            'membership_id' => $context['party']->getKey(),
            'participant_role' => 'party',
        ]);

        foreach (['conflict.view', 'conflict.manage'] as $capability) {
            $permissionId = DB::table('permissions')
                ->where('key', $capability)
                ->value('id');

            self::assertNotNull($permissionId);
            $this->assertDatabaseHas('record_access_rules', [
                'business_id' => $context['business']->getKey(),
                'membership_id' => $context['owner']->getKey(),
                'permission_id' => $permissionId,
                'resource_id' => $opened['id'],
                'effect' => 'allow',
            ]);
        }

        $this->assertDatabaseMissing('record_access_rules', [
            'business_id' => $context['business']->getKey(),
            'membership_id' => $context['party']->getKey(),
            'resource_id' => $opened['id'],
            'effect' => 'allow',
        ]);
    }

    public function test_case_transition_is_revision_checked_and_history_is_append_only(): void
    {
        $context = $this->context();
        $opened = $this->openCase($context);
        $workflow = $this->app->make(ConflictCaseWorkflow::class);

        $updated = $workflow->transition(
            $context['user'],
            $context['business'],
            $opened['id'],
            1,
            ConflictCaseStage::DirectDiscussion,
            'discussion_started',
        );

        self::assertNotNull($updated);
        self::assertSame(2, (int) $updated->revision);
        self::assertSame(
            ConflictCaseStage::DirectDiscussion,
            $updated->stage,
        );

        $this->expectException(StaleRevision::class);

        $workflow->transition(
            $context['user'],
            $context['business'],
            $opened['id'],
            1,
            ConflictCaseStage::Mediation,
        );
    }

    public function test_case_update_history_cannot_be_rewritten(): void
    {
        $context = $this->context();
        $opened = $this->openCase($context);

        $this->expectException(QueryException::class);

        DB::table('conflict_case_updates')
            ->where('conflict_case_id', $opened['id'])
            ->update(['note_code' => 'rewritten']);
    }

    public function test_case_cannot_open_without_effective_conflict_procedure(): void
    {
        $context = $this->context(false);

        $this->expectException(\InvalidArgumentException::class);

        $this->app->make(ConflictCaseWorkflow::class)->open(
            $context['user'],
            $context['business'],
            $this->casePayload($context),
        );
    }

    public function test_case_source_identity_cannot_be_silently_rewritten(): void
    {
        $context = $this->context();
        $opened = $this->openCase($context);

        $this->expectException(QueryException::class);

        DB::table('conflict_cases')
            ->where('id', $opened['id'])
            ->update(['description' => 'Silently rewritten allegation.']);
    }
}
