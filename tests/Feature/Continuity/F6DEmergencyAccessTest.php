<?php

declare(strict_types=1);

namespace Tests\Feature\Continuity;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\Continuity\ContinuityRecordVisibility;
use App\Application\Continuity\EmergencyAccessWorkflow;
use App\Application\Governance\GovernanceAuthorityChangeWorkflow;
use App\Application\Governance\OpenGovernanceDecision;
use App\Application\Governance\RecordGovernanceApproval;
use App\Application\Governance\ResolveGovernanceDecision;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Continuity\Enums\EmergencyAccessActivationStatus;
use App\Domain\Governance\Enums\ApprovalOutcome;
use App\Domain\Governance\Enums\DecisionMethod;
use App\Domain\Governance\ValueObjects\DecisionType;
use App\Infrastructure\Persistence\Eloquent\Access\AccessPolicy;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Continuity\ContinuityEmergencyAccessActivation;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Governance\EmergencyAuthorityGrant;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityEstablishment;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityPolicyActor;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityPolicyRule;
use App\Infrastructure\Persistence\Eloquent\Governance\ProposalReview;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\Proposal;
use App\Infrastructure\Persistence\Eloquent\Records\ProposalVersion;
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionStateTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F6DEmergencyAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_activation_is_time_bounded_restricted_and_never_creates_permission_or_authority(): void
    {
        [$business, $user, $membership, $backup, $accessId] = $this->context();
        $workflow = $this->app->make(EmergencyAccessWorkflow::class);

        $permissionCount = DB::table('permission_grants')->count();
        $authorityCount = DB::table('emergency_authority_grants')->count();

        $activationId = $workflow->request(
            $user,
            $business,
            $accessId,
            'Primary operator unavailable.',
            'Continue order fulfilment during incident.',
            now()->subMinute(),
            now()->addMinutes(30),
        );

        self::assertNotNull($activationId);
        $this->assertDatabaseHas('continuity_emergency_access_activations', [
            'id' => $activationId,
            'business_id' => $business->getKey(),
            'emergency_access_record_id' => $accessId,
            'activated_by_membership_id' => $membership->getKey(),
            'status' => 'requested',
            'revision' => 1,
        ]);
        self::assertSame($permissionCount, DB::table('permission_grants')->count());
        self::assertSame($authorityCount, DB::table('emergency_authority_grants')->count());

        self::assertTrue($workflow->activate(
            $user,
            $business,
            $activationId,
            1,
        ));

        self::assertTrue($workflow->end(
            $user,
            $business,
            $activationId,
            2,
            EmergencyAccessActivationStatus::Closed,
        ));

        $activation = ContinuityEmergencyAccessActivation::query()
            ->findOrFail($activationId);
        self::assertSame('closed', $activation->status->value);
        self::assertNotNull($activation->ended_at);

        self::assertSame($permissionCount, DB::table('permission_grants')->count());
        self::assertSame($authorityCount, DB::table('emergency_authority_grants')->count());

        $viewPermission = DB::table('permissions')
            ->where('key', 'continuity.view')
            ->value('id');

        foreach ([$membership, $backup] as $member) {
            $this->assertDatabaseHas('record_access_rules', [
                'business_id' => $business->getKey(),
                'membership_id' => $member->getKey(),
                'permission_id' => $viewPermission,
                'resource_type' => ContinuityEmergencyAccessActivation::class,
                'resource_id' => $activationId,
                'effect' => 'allow',
            ]);
        }
    }

    public function test_governance_authority_required_activation_fails_without_exact_governed_grant(): void
    {
        [$business, $user, , , $accessId] = $this->context();

        $activationId = $this->app->make(EmergencyAccessWorkflow::class)->request(
            $user,
            $business,
            $accessId,
            'Emergency decision needed.',
            'Authority is required for this action.',
            now()->subMinute(),
            now()->addMinutes(30),
            'continuity_emergency_authority',
            null,
        );

        self::assertNull($activationId);
        $this->assertDatabaseCount('continuity_emergency_access_activations', 0);
        $this->assertDatabaseCount('emergency_authority_grants', 0);
    }

    public function test_exact_governed_emergency_authority_grant_binds_activation_without_scope_or_time_expansion(): void
    {
        [$business, $user, $membership, , $accessId] = $this->context();

        $this->establishEmergencyAuthorityDecisionRule(
            $business,
            $user,
            $membership,
        );

        $authorityWorkflow = $this->app->make(
            GovernanceAuthorityChangeWorkflow::class,
        );

        $submission = $authorityWorkflow->proposeEmergencyAuthority(
            $user,
            $business,
            (string) $membership->getKey(),
            'continuity_emergency_authority',
            'continuity_emergency_access:'.$accessId,
            'Continuity Emergency Operator',
            true,
            false,
            false,
            'Exact continuity emergency-access exception.',
            now()->addHour(),
            now()->subMinute(),
        );

        self::assertNotNull($submission);

        $this->approveAuthorityChange(
            $business,
            $user,
            $membership,
            $submission['proposal_version_id'],
        );

        self::assertTrue(
            $authorityWorkflow->authorize(
                $user,
                $business,
                $submission['submission_id'],
            ),
        );

        $this->assertDatabaseHas('governance_authority_change_submissions', [
            'business_id' => $business->getKey(),
            'subject_type' => 'emergency_authority',
            'subject_id' => $submission['subject_id'],
            'action' => 'grant',
        ]);

        $activationWorkflow = $this->app->make(
            EmergencyAccessWorkflow::class,
        );

        $activationId = $activationWorkflow->request(
            $user,
            $business,
            $accessId,
            'Governed emergency recovery required.',
            'Use exact governed exception for continuity recovery.',
            now(),
            now()->addMinutes(30),
            'continuity_emergency_authority',
            $submission['subject_id'],
        );

        self::assertNotNull($activationId);

        $this->assertDatabaseHas(
            'continuity_emergency_access_activations',
            [
                'id' => $activationId,
                'business_id' => $business->getKey(),
                'emergency_access_record_id' => $accessId,
                'emergency_authority_grant_id' => $submission['subject_id'],
                'required_decision_type' => 'continuity_emergency_authority',
                'status' => 'requested',
            ],
        );

        self::assertNull(
            $activationWorkflow->request(
                $user,
                $business,
                $accessId,
                'Attempted time expansion.',
                'Must fail outside exact grant expiry.',
                now(),
                now()->addHours(2),
                'continuity_emergency_authority',
                $submission['subject_id'],
            ),
        );

        self::assertNull(
            $activationWorkflow->request(
                $user,
                $business,
                $accessId,
                'Attempted decision-type expansion.',
                'Must fail outside exact grant decision type.',
                now(),
                now()->addMinutes(15),
                'different_emergency_authority',
                $submission['subject_id'],
            ),
        );

        $this->assertDatabaseCount(
            'continuity_emergency_access_activations',
            1,
        );
    }

    public function test_expired_requested_activation_cannot_be_activated(): void
    {
        [$business, $user, , , $accessId] = $this->context();
        $workflow = $this->app->make(EmergencyAccessWorkflow::class);

        $activationId = $workflow->request(
            $user,
            $business,
            $accessId,
            'Historical drill.',
            'Time-window validation.',
            now()->subHours(2),
            now()->subHour(),
        );

        self::assertNotNull($activationId);
        self::assertFalse($workflow->activate(
            $user,
            $business,
            $activationId,
            1,
        ));
        $this->assertDatabaseHas('continuity_emergency_access_activations', [
            'id' => $activationId,
            'status' => 'requested',
            'revision' => 1,
        ]);
    }

    private function establishEmergencyAuthorityDecisionRule(
        Business $business,
        User $user,
        Membership $membership,
    ): void {
        $this->grantGovernanceResourceAccess(
            $business,
            $membership,
            [
                Proposal::class,
                ProposalVersion::class,
                Decision::class,
                EmergencyAuthorityGrant::class,
            ],
        );

        $family = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'formation_authority_policy',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);

        $version = FormalRecordVersion::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'F6D emergency-authority decision fixture',
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
            'effective_from' => now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => str_repeat('9', 64),
            'frozen_at' => null,
        ]);

        $rule = FormationAuthorityPolicyRule::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'sequence' => 1,
            'decision_type' => 'emergency_authority',
            'decision_method' => DecisionMethod::Approval->value,
            'required_approvals' => 1,
            'required_votes' => 0,
            'quorum_count' => 1,
            'signature_required' => false,
            'reserved_matter' => false,
            'amount_min' => null,
            'amount_max' => null,
        ]);

        FormationAuthorityPolicyActor::query()->create([
            'business_id' => $business->getKey(),
            'formation_authority_policy_rule_id' => $rule->getKey(),
            'membership_id' => $membership->getKey(),
            'capacity' => 'Formation Emergency Approver',
            'can_approve' => true,
            'can_vote' => false,
            'can_sign' => false,
        ]);

        $version->frozen_at = now();
        $version->save();

        RecordVersionStateTransition::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'sequence' => 1,
            'from_state' => null,
            'to_state' => 'draft',
            'transitioned_by_user_id' => $user->getKey(),
            'occurred_at' => now(),
        ]);

        RecordVersionStateTransition::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'sequence' => 2,
            'from_state' => 'draft',
            'to_state' => 'ready_for_review',
            'transitioned_by_user_id' => $user->getKey(),
            'occurred_at' => now(),
        ]);

        FormationAuthorityEstablishment::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'established_by_membership_id' => $membership->getKey(),
            'establishment_hash' => $version->content_hash,
            'established_at' => now(),
        ]);
    }

    private function approveAuthorityChange(
        Business $business,
        User $user,
        Membership $membership,
        string $proposalVersionId,
    ): void {
        $proposalVersion = ProposalVersion::query()
            ->where('business_id', $business->getKey())
            ->whereKey($proposalVersionId)
            ->sole();

        $review = ProposalReview::query()->create([
            'business_id' => $business->getKey(),
            'proposal_version_id' => $proposalVersion->getKey(),
            'reviewer_membership_id' => $membership->getKey(),
            'created_by_membership_id' => $membership->getKey(),
            'status' => 'open',
            'outcome' => null,
            'notes' => null,
            'due_at' => null,
            'resolved_at' => null,
        ]);

        $review->fill([
            'status' => 'completed',
            'outcome' => 'approved',
            'notes' => 'Approved exact F6D Emergency Authority proposal.',
            'resolved_at' => now(),
        ])->save();

        $decision = $this->app->make(
            OpenGovernanceDecision::class,
        )->execute(
            $user,
            $business,
            $proposalVersionId,
            new DecisionType('emergency_authority'),
        );

        self::assertInstanceOf(Decision::class, $decision);

        $approval = $this->app->make(
            RecordGovernanceApproval::class,
        )->execute(
            $user,
            $business,
            (string) $decision->getKey(),
            ApprovalOutcome::Approved,
            'Approved exact frozen Emergency Authority change.',
        );

        self::assertNotNull($approval);

        $resolved = $this->app->make(
            ResolveGovernanceDecision::class,
        )->approve(
            $user,
            $business,
            (string) $decision->getKey(),
        );

        self::assertNotNull($resolved);
        self::assertSame('approved', $resolved->outcome?->value);
    }

    /** @param list<class-string> $resourceTypes */
    private function grantGovernanceResourceAccess(
        Business $business,
        Membership $membership,
        array $resourceTypes,
    ): void {
        $permission = Permission::query()->firstOrCreate([
            'key' => CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
        ]);

        PermissionGrant::query()->firstOrCreate([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_id' => $permission->getKey(),
        ], [
            'effect' => 'allow',
        ]);

        foreach ($resourceTypes as $resourceType) {
            AccessPolicy::query()->firstOrCreate([
                'business_id' => $business->getKey(),
                'membership_id' => $membership->getKey(),
                'permission_profile_id' => null,
                'permission_id' => $permission->getKey(),
                'resource_type' => $resourceType,
            ], [
                'effect' => 'allow',
            ]);
        }
    }

    /** @return array{Business,User,Membership,Membership,string} */
    private function context(): array
    {
        $business = Business::query()->create([
            'name' => 'F6D Emergency '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'operating',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);
        [$user, $membership] = $this->member($business, 'owner');
        [, $backup] = $this->member($business, 'backup');

        $this->app->make(ProvisionStandardAccessProfiles::class)
            ->execute($business, $membership);

        [$opsVersion, $roleId] = $this->operations(
            $business,
            $user,
            $membership,
            $backup,
        );

        $family = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'continuity_plan',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);
        $version = FormalRecordVersion::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'Emergency access fixture',
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
            'effective_from' => now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => str_repeat('f', 64),
            'frozen_at' => null,
        ]);

        DB::table('continuity_plan_versions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'operations_formal_record_version_id' => $opsVersion,
            'continuity_owner_membership_id' => $membership->getKey(),
            'governance_decision_type' => 'continuity_plan_approval',
            'review_frequency' => 'Quarterly',
            'test_frequency' => 'Quarterly',
            'notes' => null,
            'created_at' => now(),
        ]);

        $accessId = (string) Str::uuid7();
        DB::table('continuity_emergency_access_records')->insert([
            'id' => $accessId,
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'system_asset' => 'Order platform',
            'primary_access_membership_id' => $membership->getKey(),
            'backup_access_membership_id' => $backup->getKey(),
            'access_level' => 'Emergency operator',
            'emergency_access_procedure' => 'Use secure recovery process.',
            'secure_storage_reference' => 'Vault CONT-ACCESS',
            'last_tested_date' => now()->subMonth()->toDateString(),
            'review_date' => now()->addMonth()->toDateString(),
            'removal_trigger' => 'Event ended.',
            'status' => 'active',
            'confidentiality' => 'restricted',
            'created_at' => now(),
        ]);

        $visibility = $this->app->make(ContinuityRecordVisibility::class);
        $visibility->grantRestrictedAccess(
            $business,
            ContinuityRecordVisibility::EMERGENCY_ACCESS_RESOURCE,
            $accessId,
            [
                (string) $membership->getKey(),
                (string) $backup->getKey(),
            ],
        );

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

        return [$business, $user, $membership, $backup, $accessId];
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
            'change_summary' => 'Emergency operations fixture',
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
            'effective_from' => now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => str_repeat('1', 64),
            'frozen_at' => null,
        ]);

        DB::table('operations_register_versions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'organization_name' => 'Emergency Operations',
            'notes' => null,
            'created_at' => now(),
        ]);

        $roleId = (string) Str::uuid7();
        DB::table('operations_roles')->insert([
            'id' => $roleId,
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'role_key' => 'critical_operator',
            'name' => 'Critical Operator',
            'function_name' => 'Operations',
            'purpose' => 'Operate critical service.',
            'responsibilities' => 'Operate and recover.',
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
