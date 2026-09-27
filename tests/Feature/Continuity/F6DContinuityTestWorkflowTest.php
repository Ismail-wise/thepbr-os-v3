<?php

declare(strict_types=1);

namespace Tests\Feature\Continuity;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\Continuity\ContinuityTestWorkflow;
use App\Domain\Continuity\Enums\ContinuityTestResult;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Continuity\ContinuityTest;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F6DContinuityTestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_continuity_test_binds_exact_plan_and_completed_result_is_final(): void
    {
        [$business, $user, $membership, $version] = $this->context();
        $workflow = $this->app->make(ContinuityTestWorkflow::class);

        $testId = $workflow->create($user, $business, [
            'scenario_name' => 'Primary operator unavailable',
            'scenario' => 'Run the backup process without the primary operator.',
            'owner_membership_id' => (string) $membership->getKey(),
            'next_test_date' => now()->addMonths(3)->toDateString(),
        ]);

        self::assertNotNull($testId);

        $this->assertDatabaseHas('continuity_tests', [
            'id' => $testId,
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'result' => 'planned',
        ]);

        self::assertTrue($workflow->recordResult(
            $user,
            $business,
            $testId,
            ContinuityTestResult::Running,
        ));

        self::assertTrue($workflow->recordResult(
            $user,
            $business,
            $testId,
            ContinuityTestResult::Failed,
            'Backup operator could not reach the recovery reference.',
            'Update secure reference and retest.',
        ));

        $test = ContinuityTest::query()->findOrFail($testId);
        self::assertSame(ContinuityTestResult::Failed, $test->result);
        self::assertNotNull($test->completed_at);
        self::assertSame(
            'Backup operator could not reach the recovery reference.',
            $test->failed_items,
        );

        self::assertFalse($workflow->recordResult(
            $user,
            $business,
            $testId,
            ContinuityTestResult::Passed,
        ));

        $preserved = ContinuityTest::query()->findOrFail($testId);
        self::assertSame(ContinuityTestResult::Failed, $preserved->result);
    }

    /** @return array{Business,User,Membership,FormalRecordVersion} */
    private function context(): array
    {
        $business = Business::query()->create([
            'name' => 'F6D Continuity Test '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'operating',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);

        $user = User::query()->create([
            'email' => Str::uuid7().'@example.test',
            'password' => Hash::make('test-password'),
            'status' => 'active',
            'password_changed_at' => now(),
        ]);

        $membership = Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => 'active',
        ]);

        $this->app->make(ProvisionStandardAccessProfiles::class)
            ->execute($business, $membership);

        $opsFamily = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'operations_register',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);
        $opsVersion = $this->version(
            $business,
            $user,
            $opsFamily,
            'Operations fixture',
            str_repeat('2', 64),
        );
        $this->effectiveLifecycle($business, $user, $opsVersion);

        DB::table('record_family_effective_heads')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $opsFamily->getKey(),
            'formal_record_version_id' => $opsVersion->getKey(),
            'activated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $planFamily = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'continuity_plan',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);
        $planVersion = $this->version(
            $business,
            $user,
            $planFamily,
            'Continuity fixture',
            str_repeat('3', 64),
        );

        DB::table('continuity_plan_versions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $planVersion->getKey(),
            'operations_formal_record_version_id' => $opsVersion->getKey(),
            'continuity_owner_membership_id' => $membership->getKey(),
            'governance_decision_type' => 'continuity_plan_approval',
            'review_frequency' => 'Quarterly',
            'test_frequency' => 'Quarterly',
            'notes' => null,
            'created_at' => now(),
        ]);

        $this->effectiveLifecycle($business, $user, $planVersion);

        DB::table('record_family_effective_heads')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $planFamily->getKey(),
            'formal_record_version_id' => $planVersion->getKey(),
            'activated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$business, $user, $membership, $planVersion];
    }

    private function version(
        Business $business,
        User $user,
        FormalRecordFamily $family,
        string $summary,
        string $hash,
    ): FormalRecordVersion {
        $version = FormalRecordVersion::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => $summary,
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
            'effective_from' => now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => $hash,
            'frozen_at' => now(),
        ]);

        return $version;
    }

    private function effectiveLifecycle(
        Business $business,
        User $user,
        FormalRecordVersion $version,
    ): void {
        foreach ([
            [null, 'draft'],
            ['draft', 'ready_for_review'],
            ['ready_for_review', 'under_review'],
            ['under_review', 'approved'],
            ['approved', 'ready_for_effect'],
            ['ready_for_effect', 'effective'],
        ] as $index => [$from, $to]) {
            DB::table('record_version_state_transitions')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'formal_record_version_id' => $version->getKey(),
                'sequence' => $index + 1,
                'from_state' => $from,
                'to_state' => $to,
                'transitioned_by_user_id' => $user->getKey(),
                'occurred_at' => now(),
                'created_at' => now(),
            ]);
        }
    }
}
