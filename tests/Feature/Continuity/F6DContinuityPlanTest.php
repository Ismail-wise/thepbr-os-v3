<?php

declare(strict_types=1);

namespace Tests\Feature\Continuity;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\Continuity\ContinuityPlanWorkflow;
use App\Application\Continuity\ContinuityRecordVisibility;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class F6DContinuityPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_continuity_plan_binds_exact_operations_and_keeps_backup_successor_separate(): void
    {
        $context = $this->context();
        $workflow = $this->app->make(ContinuityPlanWorkflow::class);

        $created = $workflow->createDraft(
            $context['user'],
            $context['business'],
            $this->payload($context),
            now()->subMinute(),
            now()->addMonths(3),
        );

        self::assertNotNull($created);
        $versionId = $created['formal_record_version_id'];

        $this->assertDatabaseHas('continuity_plan_versions', [
            'business_id' => $context['business']->getKey(),
            'formal_record_version_id' => $versionId,
            'operations_formal_record_version_id' => $context['operations_version_id'],
            'continuity_owner_membership_id' => $context['membership']->getKey(),
            'governance_decision_type' => 'continuity_plan_approval',
        ]);

        $this->assertDatabaseHas('continuity_critical_functions', [
            'formal_record_version_id' => $versionId,
            'operations_role_id' => $context['role_id'],
            'primary_owner_membership_id' => $context['membership']->getKey(),
            'first_backup_membership_id' => $context['backup']->getKey(),
        ]);

        $this->assertDatabaseHas('continuity_successor_candidates', [
            'formal_record_version_id' => $versionId,
            'operations_role_id' => $context['role_id'],
            'current_owner_membership_id' => $context['membership']->getKey(),
            'candidate_membership_id' => $context['successor']->getKey(),
            'readiness_level' => 'developing',
        ]);

        $access = DB::table('continuity_emergency_access_records')
            ->where('formal_record_version_id', $versionId)
            ->sole();

        self::assertSame('restricted', $access->confidentiality);

        foreach (['continuity.view', 'continuity.manage'] as $capability) {
            $permissionId = Permission::query()->where('key', $capability)->value('id');
            self::assertNotNull($permissionId);

            foreach ([
                $context['membership']->getKey(),
                $context['backup']->getKey(),
            ] as $membershipId) {
                $this->assertDatabaseHas('record_access_rules', [
                    'business_id' => $context['business']->getKey(),
                    'membership_id' => $membershipId,
                    'permission_id' => $permissionId,
                    'resource_type' => ContinuityRecordVisibility::EMERGENCY_ACCESS_RESOURCE,
                    'resource_id' => $access->id,
                    'effect' => 'allow',
                ]);
            }
        }

        $this->assertDatabaseCount('emergency_authority_grants', 0);
        $this->assertDatabaseCount('permission_grants', 0);

        $submitted = $workflow->submitForGovernance(
            $context['user'],
            $context['business'],
            $versionId,
            1,
        );

        self::assertNotNull($submitted);
        self::assertNotNull(
            FormalRecordVersion::query()->findOrFail($versionId)->frozen_at,
        );
    }

    public function test_primary_backup_cannot_be_same_person(): void
    {
        $context = $this->context();
        $payload = $this->payload($context);
        $payload['critical_functions'][0]['first_backup_membership_id'] =
            (string) $context['membership']->getKey();

        $this->expectException(InvalidArgumentException::class);

        $this->app->make(ContinuityPlanWorkflow::class)->createDraft(
            $context['user'],
            $context['business'],
            $payload,
            now()->subMinute(),
        );
    }

    public function test_secret_like_emergency_access_payload_is_rejected(): void
    {
        $context = $this->context();
        $payload = $this->payload($context);
        $payload['emergency_access'][0]['credential'] = 'never-store-me';

        $this->expectException(InvalidArgumentException::class);

        $this->app->make(ContinuityPlanWorkflow::class)->createDraft(
            $context['user'],
            $context['business'],
            $payload,
            now()->subMinute(),
        );
    }

    /** @return array<string,mixed> */
    private function context(): array
    {
        $business = Business::query()->create([
            'name' => 'F6D Continuity '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'operating',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);

        [$user, $membership] = $this->member($business, 'owner');
        [, $backup] = $this->member($business, 'backup');
        [, $successor] = $this->member($business, 'successor');

        $this->app->make(ProvisionStandardAccessProfiles::class)
            ->execute($business, $membership);

        [$operationsVersionId, $roleId] = $this->operations(
            $business,
            $user,
            $membership,
            $backup,
        );

        return [
            'business' => $business,
            'user' => $user,
            'membership' => $membership,
            'backup' => $backup,
            'successor' => $successor,
            'operations_version_id' => $operationsVersionId,
            'role_id' => $roleId,
        ];
    }

    /** @return array{User,Membership} */
    private function member(Business $business, string $prefix): array
    {
        $user = User::query()->create([
            'email' => $prefix.'-'.Str::uuid7().'@example.test',
            'password' => Hash::make('test-password'),
            'status' => 'active',
            'password_changed_at' => now(),
        ]);
        $membership = Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => 'active',
        ]);

        return [$user, $membership];
    }

    /** @return array{string,string} */
    private function operations(
        Business $business,
        User $user,
        Membership $owner,
        Membership $backup,
    ): array {
        $family = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'operations_register',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);
        $version = FormalRecordVersion::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'F6D continuity operations fixture',
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
            'effective_from' => now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => str_repeat('e', 64),
            'frozen_at' => null,
        ]);

        DB::table('operations_register_versions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'organization_name' => 'Continuity Operations',
            'notes' => null,
            'created_at' => now(),
        ]);

        $roleId = (string) Str::uuid7();
        DB::table('operations_roles')->insert([
            'id' => $roleId,
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'role_key' => 'critical_operations',
            'name' => 'Critical Operations',
            'function_name' => 'Operations',
            'purpose' => 'Deliver critical function.',
            'responsibilities' => 'Operate and recover critical function.',
            'operational_authority' => null,
            'reports_to_role_key' => null,
            'report_type' => null,
            'reporting_frequency' => 'Daily',
            'meeting_frequency' => null,
            'review_frequency' => 'Quarterly',
            'status' => 'active',
            'created_at' => now(),
        ]);

        foreach ([[$owner, 'primary'], [$backup, 'backup']] as [$member, $type]) {
            DB::table('operations_role_assignments')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'formal_record_version_id' => $version->getKey(),
                'operations_role_id' => $roleId,
                'membership_id' => $member->getKey(),
                'assignment_type' => $type,
                'created_at' => now(),
            ]);
        }

        $version->frozen_at = now();
        $version->save();
        $this->effectiveLifecycle($business, $user, $version);

        DB::table('record_family_effective_heads')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'activated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [(string) $version->getKey(), $roleId];
    }

    /** @param array<string,mixed> $context */
    private function payload(array $context): array
    {
        return [
            'continuity_owner_membership_id' => (string) $context['membership']->getKey(),
            'governance_decision_type' => 'continuity_plan_approval',
            'review_frequency' => 'Quarterly',
            'test_frequency' => 'Quarterly',
            'notes' => 'Continuity fixture.',
            'critical_functions' => [[
                'operations_role_id' => $context['role_id'],
                'function_name' => 'Order fulfilment',
                'critical_process' => 'Accept, prepare and dispatch orders.',
                'maximum_downtime_minutes' => 240,
                'primary_owner_membership_id' => (string) $context['membership']->getKey(),
                'first_backup_membership_id' => (string) $context['backup']->getKey(),
                'second_backup_membership_id' => null,
                'recovery_priority' => 1,
                'minimum_resources' => 'Phone, laptop, fulfilment access.',
                'review_date' => now()->addMonth()->toDateString(),
                'status' => 'ready',
            ]],
            'emergency_access' => [[
                'system_asset' => 'Order platform',
                'primary_access_membership_id' => (string) $context['membership']->getKey(),
                'backup_access_membership_id' => (string) $context['backup']->getKey(),
                'access_level' => 'Emergency operator',
                'emergency_access_procedure' => 'Use approved secure recovery process and named access owner.',
                'secure_storage_reference' => 'Vault reference CONT-001',
                'last_tested_date' => now()->subMonth()->toDateString(),
                'review_date' => now()->addMonth()->toDateString(),
                'removal_trigger' => 'Remove emergency access when continuity event ends.',
                'status' => 'active',
            ]],
            'interim_authority_plans' => [[
                'operations_role_id' => $context['role_id'],
                'interim_membership_id' => (string) $context['backup']->getKey(),
                'trigger' => 'Primary owner unavailable during declared continuity event.',
                'governance_decision_type' => 'continuity_emergency_authority',
                'spending_limit_minor_units' => 100000,
                'currency' => 'USD',
                'decision_limit' => 'Only continuity recovery decisions.',
                'maximum_interim_hours' => 24,
                'reporting_requirement' => 'Report all actions to Governance.',
                'status' => 'active',
            ]],
            'successors' => [[
                'operations_role_id' => $context['role_id'],
                'current_owner_membership_id' => (string) $context['membership']->getKey(),
                'candidate_membership_id' => (string) $context['successor']->getKey(),
                'readiness_level' => 'developing',
                'skills_gap' => 'Needs supplier negotiation training.',
                'development_required' => 'Shadow current role for one quarter.',
                'target_ready_date' => now()->addMonths(3)->toDateString(),
                'status' => 'candidate',
            ]],
            'communication_steps' => [[
                'event_type' => 'continuity_activation',
                'stakeholder' => 'Core team',
                'owner_membership_id' => (string) $context['membership']->getKey(),
                'channel' => 'Phone tree',
                'timing' => 'Within 30 minutes',
                'message_reference' => 'CONT-COMMS-001',
                'governance_approval_may_be_required' => false,
            ]],
            'recovery_actions' => [[
                'timeline_band' => '0_24_hours',
                'action' => 'Activate backup fulfilment process.',
                'operations_role_id' => $context['role_id'],
                'required_resource' => 'Backup access and supplier contacts.',
            ]],
        ];
    }

    private function effectiveLifecycle(
        Business $business,
        User $user,
        FormalRecordVersion $version,
    ): void {
        foreach ([
            [null, 'draft'], ['draft', 'ready_for_review'],
            ['ready_for_review', 'under_review'], ['under_review', 'approved'],
            ['approved', 'ready_for_effect'], ['ready_for_effect', 'effective'],
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
