<?php

declare(strict_types=1);

namespace Tests\Feature\Continuity;

use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F6DContinuityDatabaseInvariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_frozen_continuity_snapshot_children_cannot_be_mutated(): void
    {
        $context = $this->context();

        $this->expectException(QueryException::class);

        DB::table('continuity_critical_functions')
            ->where('id', $context['function_id'])
            ->update(['function_name' => 'Silently rewritten']);
    }

    public function test_completed_continuity_test_is_immutable_at_database_layer(): void
    {
        $context = $this->context();

        $testId = (string) Str::uuid7();
        DB::table('continuity_tests')->insert([
            'id' => $testId,
            'business_id' => $context['business']->getKey(),
            'formal_record_version_id' => $context['plan_version']->getKey(),
            'scenario_name' => 'Completed drill',
            'scenario' => 'Completed immutable scenario.',
            'tested_at' => now(),
            'result' => 'failed',
            'failed_items' => 'Fixture gap',
            'improvement_actions' => 'Fixture action',
            'owner_membership_id' => $context['owner']->getKey(),
            'next_test_date' => now()->addMonth()->toDateString(),
            'completed_at' => now(),
            'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('continuity_tests')
            ->where('id', $testId)
            ->update(['result' => 'passed']);
    }

    public function test_emergency_activation_source_identity_and_history_cannot_be_rewritten(): void
    {
        $context = $this->context();

        $activationId = (string) Str::uuid7();
        DB::table('continuity_emergency_access_activations')->insert([
            'id' => $activationId,
            'business_id' => $context['business']->getKey(),
            'formal_record_version_id' => $context['plan_version']->getKey(),
            'emergency_access_record_id' => $context['access_id'],
            'activated_by_membership_id' => $context['owner']->getKey(),
            'emergency_authority_grant_id' => null,
            'required_decision_type' => null,
            'trigger' => 'Fixture trigger',
            'reason' => 'Fixture reason',
            'starts_at' => now()->subMinute(),
            'expires_at' => now()->addHour(),
            'status' => 'requested',
            'ended_at' => null,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            DB::table('continuity_emergency_access_activations')
                ->where('id', $activationId)
                ->update(['reason' => 'Rewritten reason']);
            self::fail('Activation source identity must be immutable.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        $this->expectException(QueryException::class);

        DB::table('continuity_emergency_access_activations')
            ->where('id', $activationId)
            ->delete();
    }

    public function test_database_rejects_continuity_role_from_different_operations_version(): void
    {
        $context = $this->context();

        $otherOpsFamily = FormalRecordFamily::query()->create([
            'business_id' => $context['business']->getKey(),
            'record_type' => 'operations_register',
            'subject_type' => 'alternate',
            'subject_id' => (string) Str::uuid7(),
        ]);
        $otherOpsVersion = $this->version(
            $context['business'],
            $context['user'],
            $otherOpsFamily,
            'Other operations',
            str_repeat('8', 64),
            false,
        );

        $otherRole = (string) Str::uuid7();
        DB::table('operations_roles')->insert([
            'id' => $otherRole,
            'business_id' => $context['business']->getKey(),
            'formal_record_version_id' => $otherOpsVersion->getKey(),
            'role_key' => 'wrong_role',
            'name' => 'Wrong Role',
            'function_name' => 'Other',
            'purpose' => 'Wrong source.',
            'responsibilities' => 'Wrong source.',
            'operational_authority' => null,
            'reports_to_role_key' => null,
            'report_type' => null,
            'reporting_frequency' => 'Weekly',
            'meeting_frequency' => null,
            'review_frequency' => 'Quarterly',
            'status' => 'active',
            'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('continuity_recovery_actions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $context['business']->getKey(),
            'formal_record_version_id' => $context['plan_version']->getKey(),
            'sequence' => 1,
            'timeline_band' => '0_24_hours',
            'action' => 'Invalid cross-version action.',
            'operations_role_id' => $otherRole,
            'required_resource' => null,
            'created_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function context(): array
    {
        $business = Business::query()->create([
            'name' => 'F6D Continuity DB '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'operating',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);
        $user = User::query()->create([
            'email' => Str::uuid7().'@example.test',
            'password' => 'not-a-real-hash',
            'status' => 'active',
            'password_changed_at' => now(),
        ]);
        $owner = Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => 'active',
        ]);

        $backupUser = User::query()->create([
            'email' => Str::uuid7().'@example.test',
            'password' => 'not-a-real-hash',
            'status' => 'active',
            'password_changed_at' => now(),
        ]);
        $backup = Membership::query()->create([
            'user_id' => $backupUser->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => 'active',
        ]);

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
            'Operations DB fixture',
            str_repeat('6', 64),
            false,
        );

        DB::table('operations_register_versions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $opsVersion->getKey(),
            'organization_name' => 'Continuity DB Operations',
            'notes' => null,
            'created_at' => now(),
        ]);

        $roleId = (string) Str::uuid7();
        DB::table('operations_roles')->insert([
            'id' => $roleId,
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $opsVersion->getKey(),
            'role_key' => 'db_role',
            'name' => 'DB Role',
            'function_name' => 'Operations',
            'purpose' => 'Invariant fixture.',
            'responsibilities' => 'Invariant fixture.',
            'operational_authority' => null,
            'reports_to_role_key' => null,
            'report_type' => null,
            'reporting_frequency' => 'Weekly',
            'meeting_frequency' => null,
            'review_frequency' => 'Quarterly',
            'status' => 'active',
            'created_at' => now(),
        ]);

        $opsVersion->frozen_at = now();
        $opsVersion->save();

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
            'Continuity DB fixture',
            str_repeat('7', 64),
            false,
        );

        DB::table('continuity_plan_versions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $planVersion->getKey(),
            'operations_formal_record_version_id' => $opsVersion->getKey(),
            'continuity_owner_membership_id' => $owner->getKey(),
            'governance_decision_type' => 'continuity_plan_approval',
            'review_frequency' => 'Quarterly',
            'test_frequency' => 'Quarterly',
            'notes' => null,
            'created_at' => now(),
        ]);

        $functionId = (string) Str::uuid7();
        DB::table('continuity_critical_functions')->insert([
            'id' => $functionId,
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $planVersion->getKey(),
            'operations_role_id' => $roleId,
            'function_name' => 'Frozen function',
            'critical_process' => 'Frozen process.',
            'maximum_downtime_minutes' => 60,
            'primary_owner_membership_id' => $owner->getKey(),
            'first_backup_membership_id' => $backup->getKey(),
            'second_backup_membership_id' => null,
            'recovery_priority' => 1,
            'minimum_resources' => 'Fixture resources.',
            'review_date' => null,
            'status' => 'ready',
            'created_at' => now(),
        ]);

        $accessId = (string) Str::uuid7();
        DB::table('continuity_emergency_access_records')->insert([
            'id' => $accessId,
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $planVersion->getKey(),
            'system_asset' => 'Fixture system',
            'primary_access_membership_id' => $owner->getKey(),
            'backup_access_membership_id' => $backup->getKey(),
            'access_level' => 'Emergency operator',
            'emergency_access_procedure' => 'Fixture procedure.',
            'secure_storage_reference' => 'Vault fixture',
            'last_tested_date' => null,
            'review_date' => null,
            'removal_trigger' => 'Event ends.',
            'status' => 'active',
            'confidentiality' => 'restricted',
            'created_at' => now(),
        ]);

        $planVersion->frozen_at = now();
        $planVersion->save();

        return [
            'business' => $business,
            'user' => $user,
            'owner' => $owner,
            'backup' => $backup,
            'ops_version' => $opsVersion,
            'role_id' => $roleId,
            'plan_version' => $planVersion,
            'function_id' => $functionId,
            'access_id' => $accessId,
        ];
    }

    private function version(
        Business $business,
        User $user,
        FormalRecordFamily $family,
        string $summary,
        string $hash,
        bool $frozen,
    ): FormalRecordVersion {
        return FormalRecordVersion::query()->create([
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
            'frozen_at' => $frozen ? now() : null,
        ]);
    }
}
