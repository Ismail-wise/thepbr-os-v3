<?php

declare(strict_types=1);

namespace Tests\Feature\Continuity;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\Continuity\ContinuityRecordVisibility;
use App\Application\Continuity\EmergencyAccessWorkflow;
use App\Application\Continuity\GetContinuityWorkspace;
use App\Domain\Access\Enums\StandardAccessProfile;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F6DContinuityTenantPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_restricted_emergency_access_existence_and_activation_counts_fail_closed(): void
    {
        [
            $business,
            $ownerUser,
            $owner,
            $viewerUser,
            $viewer,
            $accessId,
        ] = $this->context();

        $activationId = $this->app->make(EmergencyAccessWorkflow::class)->request(
            $ownerUser,
            $business,
            $accessId,
            'Primary operator unavailable.',
            'Restricted continuity activation.',
            now()->subMinute(),
            now()->addHour(),
        );

        self::assertNotNull($activationId);

        $ownerWorkspace = $this->app->make(GetContinuityWorkspace::class)
            ->execute($ownerUser, $business);

        self::assertNotNull($ownerWorkspace);
        self::assertCount(1, $ownerWorkspace['current']['emergency_access']);
        self::assertCount(1, $ownerWorkspace['activations']);
        self::assertSame(1, $ownerWorkspace['attention']['requested_activations']);

        $viewerWorkspace = $this->app->make(GetContinuityWorkspace::class)
            ->execute($viewerUser, $business);

        self::assertNotNull($viewerWorkspace);
        self::assertCount(0, $viewerWorkspace['current']['emergency_access']);
        self::assertCount(0, $viewerWorkspace['activations']);
        self::assertSame(0, $viewerWorkspace['attention']['requested_activations']);
        self::assertSame(0, $viewerWorkspace['attention']['active_activations']);

        self::assertFalse(
            $this->app->make(EmergencyAccessWorkflow::class)->activate(
                $viewerUser,
                $business,
                $activationId,
                1,
            ),
        );

        $otherBusiness = Business::query()->create([
            'name' => 'F6D Other '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'operating',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);

        self::assertNull(
            DB::table('continuity_emergency_access_activations')
                ->where('business_id', $otherBusiness->getKey())
                ->where('id', $activationId)
                ->first(),
        );

        self::assertNotSame((string) $owner->getKey(), (string) $viewer->getKey());
    }

    /** @return array{Business,User,Membership,User,Membership,string} */
    private function context(): array
    {
        $business = Business::query()->create([
            'name' => 'F6D Continuity Privacy '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'operating',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);

        [$ownerUser, $owner] = $this->member($business, 'owner');
        [$backupUser, $backup] = $this->member($business, 'backup');
        [$viewerUser, $viewer] = $this->member($business, 'viewer');

        $profiles = $this->app->make(ProvisionStandardAccessProfiles::class)
            ->execute($business, $owner);

        DB::table('membership_permission_profiles')->insert([
            'business_id' => $business->getKey(),
            'membership_id' => $viewer->getKey(),
            'permission_profile_id' => $profiles[StandardAccessProfile::AuditorViewer->value],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $opsFamily = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'operations_register',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);
        $opsVersion = $this->version(
            $business,
            $ownerUser,
            $opsFamily,
            'Privacy Operations',
            str_repeat('4', 64),
        );

        DB::table('operations_register_versions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $opsVersion->getKey(),
            'organization_name' => 'Privacy Operations',
            'notes' => null,
            'created_at' => now(),
        ]);

        $roleId = (string) Str::uuid7();
        DB::table('operations_roles')->insert([
            'id' => $roleId,
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $opsVersion->getKey(),
            'role_key' => 'continuity_operator',
            'name' => 'Continuity Operator',
            'function_name' => 'Operations',
            'purpose' => 'Deliver continuity.',
            'responsibilities' => 'Operate recovery process.',
            'operational_authority' => null,
            'reports_to_role_key' => null,
            'report_type' => null,
            'reporting_frequency' => 'Daily',
            'meeting_frequency' => null,
            'review_frequency' => 'Quarterly',
            'status' => 'active',
            'created_at' => now(),
        ]);

        foreach ([[$owner, 'primary'], [$backup, 'backup']] as [$membership, $type]) {
            DB::table('operations_role_assignments')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'formal_record_version_id' => $opsVersion->getKey(),
                'operations_role_id' => $roleId,
                'membership_id' => $membership->getKey(),
                'assignment_type' => $type,
                'created_at' => now(),
            ]);
        }

        $this->freezeVersion($opsVersion);
        $this->effectiveLifecycle($business, $ownerUser, $opsVersion);
        $this->setEffectiveHead($business, $opsFamily, $opsVersion);

        $planFamily = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'continuity_plan',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);
        $planVersion = $this->version(
            $business,
            $ownerUser,
            $planFamily,
            'Privacy Continuity',
            str_repeat('5', 64),
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

        DB::table('continuity_critical_functions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $planVersion->getKey(),
            'operations_role_id' => $roleId,
            'function_name' => 'Order fulfilment',
            'critical_process' => 'Keep orders moving.',
            'maximum_downtime_minutes' => 240,
            'primary_owner_membership_id' => $owner->getKey(),
            'first_backup_membership_id' => $backup->getKey(),
            'second_backup_membership_id' => null,
            'recovery_priority' => 1,
            'minimum_resources' => 'Laptop and network.',
            'review_date' => now()->addMonth()->toDateString(),
            'status' => 'ready',
            'created_at' => now(),
        ]);

        $accessId = (string) Str::uuid7();
        DB::table('continuity_emergency_access_records')->insert([
            'id' => $accessId,
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $planVersion->getKey(),
            'system_asset' => 'Restricted ERP access',
            'primary_access_membership_id' => $owner->getKey(),
            'backup_access_membership_id' => $backup->getKey(),
            'access_level' => 'Emergency operator',
            'emergency_access_procedure' => 'Use secure recovery procedure.',
            'secure_storage_reference' => 'Vault CONT-PRIVACY',
            'last_tested_date' => now()->subMonth()->toDateString(),
            'review_date' => now()->addMonth()->toDateString(),
            'removal_trigger' => 'Continuity event ends.',
            'status' => 'active',
            'confidentiality' => 'restricted',
            'created_at' => now(),
        ]);

        $this->app->make(ContinuityRecordVisibility::class)
            ->grantRestrictedAccess(
                $business,
                ContinuityRecordVisibility::EMERGENCY_ACCESS_RESOURCE,
                $accessId,
                [
                    (string) $owner->getKey(),
                    (string) $backup->getKey(),
                ],
            );

        $this->freezeVersion($planVersion);
        $this->effectiveLifecycle($business, $ownerUser, $planVersion);
        $this->setEffectiveHead($business, $planFamily, $planVersion);

        return [
            $business,
            $ownerUser,
            $owner,
            $viewerUser,
            $viewer,
            $accessId,
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

    private function version(
        Business $business,
        User $user,
        FormalRecordFamily $family,
        string $summary,
        string $hash,
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
            'frozen_at' => null,
        ]);
    }

    private function freezeVersion(FormalRecordVersion $version): void
    {
        $version->frozen_at = now();
        $version->save();
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

    private function setEffectiveHead(
        Business $business,
        FormalRecordFamily $family,
        FormalRecordVersion $version,
    ): void {
        DB::table('record_family_effective_heads')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'activated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
