<?php

declare(strict_types=1);

namespace Tests\Feature\Risk;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\Risk\GetRiskWorkspace;
use App\Application\Risk\RiskIncidentWorkflow;
use App\Domain\Access\Enums\StandardAccessProfile;
use App\Domain\Risk\Enums\IncidentStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Risk\RiskIncident;
use App\Infrastructure\Persistence\Eloquent\Risk\RiskItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F6DRiskTenantPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_restricted_risk_and_incident_existence_are_filtered_before_counts(): void
    {
        [$business, $ownerUser, $owner, $viewerUser, $viewer, $riskId] = $this->context();

        $incidentId = $this->app->make(RiskIncidentWorkflow::class)->open(
            $ownerUser,
            $business,
            [
                'risk_item_id' => $riskId,
                'incident_at' => now(),
                'incident_type' => 'confidential_investigation',
                'description' => 'Restricted incident content.',
                'business_impact' => 'Restricted impact.',
                'immediate_action' => 'Restricted response.',
                'loss_amount_minor_units' => null,
                'currency' => null,
                'confidentiality' => 'restricted',
            ],
        );

        self::assertNotNull($incidentId);

        $ownerWorkspace = $this->app->make(GetRiskWorkspace::class)
            ->execute($ownerUser, $business);
        self::assertNotNull($ownerWorkspace);
        self::assertCount(1, $ownerWorkspace['current']['risks']);
        self::assertCount(1, $ownerWorkspace['incidents']);
        self::assertSame(1, $ownerWorkspace['attention']['incidents']);

        $viewerWorkspace = $this->app->make(GetRiskWorkspace::class)
            ->execute($viewerUser, $business);
        self::assertNotNull($viewerWorkspace);
        self::assertCount(0, $viewerWorkspace['current']['risks']);
        self::assertCount(0, $viewerWorkspace['incidents']);
        self::assertSame(0, $viewerWorkspace['attention']['incidents']);

        self::assertFalse(
            $this->app->make(RiskIncidentWorkflow::class)->transition(
                $viewerUser,
                $business,
                $incidentId,
                IncidentStatus::Investigating,
                1,
            ),
        );

        $otherBusiness = Business::query()->create([
            'name' => 'Other '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'operating',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);

        self::assertNull(
            RiskIncident::query()
                ->where('business_id', $otherBusiness->getKey())
                ->whereKey($incidentId)
                ->first(),
        );

        self::assertNotSame((string) $owner->getKey(), (string) $viewer->getKey());
    }

    /** @return array{Business,User,Membership,User,Membership,string} */
    private function context(): array
    {
        $business = Business::query()->create([
            'name' => 'F6D Restricted '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'operating',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);
        [$ownerUser, $owner] = $this->member($business, 'owner');
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

        $family = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'risk_register',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);
        $version = FormalRecordVersion::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'Restricted risk fixture',
            'created_by_user_id' => $ownerUser->getKey(),
            'last_changed_by_user_id' => $ownerUser->getKey(),
            'effective_from' => now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => str_repeat('c', 64),
            'frozen_at' => null,
        ]);

        DB::table('risk_register_versions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'risk_owner_membership_id' => $owner->getKey(),
            'low_max_score' => 4,
            'medium_max_score' => 9,
            'high_max_score' => 15,
            'review_frequency' => 'Quarterly',
            'notes' => null,
            'created_at' => now(),
        ]);

        $riskId = (string) Str::uuid7();
        DB::table('risk_items')->insert([
            'id' => $riskId,
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'operations_formal_record_version_id' => null,
            'operations_role_id' => null,
            'owner_membership_id' => $owner->getKey(),
            'category' => 'people_key_person',
            'title' => 'Restricted key-person risk',
            'description' => 'Existence is restricted.',
            'likelihood' => 2,
            'impact' => 5,
            'risk_score' => 10,
            'risk_level' => 'high',
            'warning_indicator' => null,
            'mitigation' => 'Restricted mitigation.',
            'response_plan' => 'Restricted response.',
            'review_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
            'confidentiality' => 'restricted',
            'created_at' => now(),
        ]);

        $riskViewPermission = DB::table('permissions')->where('key', 'risk.view')->value('id');
        $riskManagePermission = DB::table('permissions')->where('key', 'risk.manage')->value('id');

        foreach ([$riskViewPermission, $riskManagePermission] as $permissionId) {
            DB::table('record_access_rules')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'membership_id' => $owner->getKey(),
                'permission_profile_id' => null,
                'permission_id' => $permissionId,
                'resource_type' => RiskItem::class,
                'resource_id' => $riskId,
                'effect' => 'allow',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $version->frozen_at = now();
        $version->save();

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
                'transitioned_by_user_id' => $ownerUser->getKey(),
                'occurred_at' => now(),
                'created_at' => now(),
            ]);
        }

        DB::table('record_family_effective_heads')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'activated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$business, $ownerUser, $owner, $viewerUser, $viewer, $riskId];
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
}
