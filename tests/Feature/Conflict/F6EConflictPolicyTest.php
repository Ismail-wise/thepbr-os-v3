<?php

declare(strict_types=1);

namespace Tests\Feature\Conflict;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\Conflict\ConflictCaseWorkflow;
use App\Application\Conflict\ConflictPolicyWorkflow;
use App\Application\Conflict\GetConflictWorkspace;
use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Records\Enums\FormalRecordState;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class F6EConflictTestCase extends TestCase
{
    use RefreshDatabase;

    /** @return array<string,mixed> */
    protected function context(bool $withEffectivePolicy = true): array
    {
        $business = Business::query()->create([
            'name' => 'F6E Conflict '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'operating',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);

        [$user, $owner] = $this->member($business, 'owner');
        [$partyUser, $party] = $this->member($business, 'party');

        $this->app->make(ProvisionStandardAccessProfiles::class)
            ->execute($business, $owner);

        [$governanceVersionId, $operationsVersionId, $roleId] =
            $this->governanceAndOperations(
                $business,
                $user,
                $owner,
                $party,
            );

        $policyVersionId = $withEffectivePolicy
            ? $this->effectiveConflictPolicy(
                $business,
                $user,
                $owner,
                $governanceVersionId,
                $operationsVersionId,
                $roleId,
            )
            : null;

        return [
            'business' => $business,
            'user' => $user,
            'owner' => $owner,
            'party_user' => $partyUser,
            'party' => $party,
            'governance_version_id' => $governanceVersionId,
            'operations_version_id' => $operationsVersionId,
            'role_id' => $roleId,
            'policy_version_id' => $policyVersionId,
        ];
    }

    /** @return array{User,Membership} */
    protected function member(Business $business, string $prefix): array
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

    protected function grantCapability(
        Business $business,
        Membership $membership,
        string $capability,
    ): void {
        $permission = Permission::query()->firstOrCreate([
            'key' => $capability,
        ]);

        PermissionGrant::query()->updateOrCreate([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_id' => $permission->getKey(),
        ], [
            'effect' => PermissionEffect::Allow->value,
        ]);
    }

    /**
     * @return array{string,string,string}
     */
    protected function governanceAndOperations(
        Business $business,
        User $user,
        Membership $owner,
        Membership $party,
    ): array {
        $governanceFamily = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'governance_charter',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);
        $governanceVersion = $this->recordVersion(
            $business,
            $user,
            $governanceFamily,
            str_repeat('a', 64),
        );

        DB::table('governance_charter_versions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $governanceVersion->getKey(),
            'governance_owner_membership_id' => $owner->getKey(),
            'voting_basis' => 'one_member_one_vote',
            'default_approval_rule' => 'One authorized approval',
            'meeting_frequency' => 'As required',
            'default_quorum_count' => 1,
            'minutes_owner_membership_id' => $owner->getKey(),
            'conflict_of_interest_rule' => 'Affected actor must recuse.',
            'deadlock_rule' => 'Use Conflict Procedure; no automatic owner casting vote.',
            'remote_voting_allowed' => true,
            'written_resolution_allowed' => true,
            'created_at' => now(),
        ]);

        $decisionTypes = [
            'conflict_formal_decision',
            'conflict_deadlock_decision',
            'conflict_misconduct_decision',
            'conflict_urgent_risk_decision',
            'conflict_settlement_approval',
        ];

        foreach ($decisionTypes as $index => $decisionType) {
            $ruleId = (string) Str::uuid7();
            DB::table('governance_charter_rules')->insert([
                'id' => $ruleId,
                'business_id' => $business->getKey(),
                'formal_record_version_id' => $governanceVersion->getKey(),
                'sequence' => $index + 1,
                'decision_type' => $decisionType,
                'category' => 'major_business',
                'decision_method' => 'approval',
                'required_approvals' => 1,
                'required_votes' => 0,
                'quorum_count' => 1,
                'signature_required' => $decisionType === 'conflict_settlement_approval',
                'reserved_matter' => false,
                'meeting_required' => false,
                'record_required' => true,
                'amount_min' => null,
                'amount_max' => null,
                'created_at' => now(),
            ]);

            DB::table('governance_charter_rule_actors')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'governance_charter_rule_id' => $ruleId,
                'membership_id' => $owner->getKey(),
                'capacity' => 'Conflict Governance Approver',
                'is_decision_owner' => true,
                'is_consulted' => false,
                'can_approve' => true,
                'can_vote' => false,
                'can_sign' => true,
                'created_at' => now(),
            ]);
        }

        $governanceVersion->frozen_at = now();
        $governanceVersion->save();
        $this->effectiveLifecycle($business, $user, $governanceVersion);
        $this->effectiveHead(
            $business,
            $governanceFamily,
            $governanceVersion,
        );

        $operationsFamily = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'operations_register',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);
        $operationsVersion = $this->recordVersion(
            $business,
            $user,
            $operationsFamily,
            str_repeat('b', 64),
        );

        DB::table('operations_register_versions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $operationsVersion->getKey(),
            'organization_name' => 'Conflict Operations',
            'notes' => null,
            'created_at' => now(),
        ]);

        $roleId = (string) Str::uuid7();
        DB::table('operations_roles')->insert([
            'id' => $roleId,
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $operationsVersion->getKey(),
            'role_key' => 'conflict_owner',
            'name' => 'Conflict Operations Owner',
            'function_name' => 'Partner Operations',
            'purpose' => 'Deliver Conflict follow-up actions.',
            'responsibilities' => 'Coordinate operational resolution work.',
            'operational_authority' => null,
            'reports_to_role_key' => null,
            'report_type' => null,
            'reporting_frequency' => 'Weekly',
            'meeting_frequency' => null,
            'review_frequency' => 'Quarterly',
            'status' => 'active',
            'created_at' => now(),
        ]);

        foreach ([[$owner, 'primary'], [$party, 'backup']] as [$member, $type]) {
            DB::table('operations_role_assignments')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'formal_record_version_id' => $operationsVersion->getKey(),
                'operations_role_id' => $roleId,
                'membership_id' => $member->getKey(),
                'assignment_type' => $type,
                'created_at' => now(),
            ]);
        }

        $operationsVersion->frozen_at = now();
        $operationsVersion->save();
        $this->effectiveLifecycle($business, $user, $operationsVersion);
        $this->effectiveHead(
            $business,
            $operationsFamily,
            $operationsVersion,
        );

        return [
            (string) $governanceVersion->getKey(),
            (string) $operationsVersion->getKey(),
            $roleId,
        ];
    }

    protected function effectiveConflictPolicy(
        Business $business,
        User $user,
        Membership $owner,
        string $governanceVersionId,
        string $operationsVersionId,
        string $roleId,
    ): string {
        $family = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'conflict_resolution_policy',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);
        $version = $this->recordVersion(
            $business,
            $user,
            $family,
            str_repeat('c', 64),
        );

        DB::table('conflict_policy_versions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'governance_formal_record_version_id' => $governanceVersionId,
            'operations_formal_record_version_id' => $operationsVersionId,
            'conflict_owner_membership_id' => $owner->getKey(),
            'formal_decision_type' => 'conflict_formal_decision',
            'deadlock_decision_type' => 'conflict_deadlock_decision',
            'misconduct_decision_type' => 'conflict_misconduct_decision',
            'urgent_risk_decision_type' => 'conflict_urgent_risk_decision',
            'settlement_decision_type' => 'conflict_settlement_approval',
            'review_frequency' => 'Quarterly',
            'notes' => 'F6E fixture.',
            'created_at' => now(),
        ]);

        DB::table('conflict_escalation_rules')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'sequence' => 1,
            'step_key' => 'formal_review',
            'entry_condition' => 'Direct resolution failed.',
            'operations_role_id' => $roleId,
            'max_days' => 7,
            'required_evidence' => 'Case evidence.',
            'decision_type' => 'conflict_formal_decision',
            'resolution_exit_condition' => 'Resolved or escalated.',
            'next_step_key' => null,
            'status' => 'active',
            'created_at' => now(),
        ]);

        foreach ([
            ['deadlock', 'conflict_deadlock_decision'],
            ['misconduct', 'conflict_misconduct_decision'],
            ['urgent_risk', 'conflict_urgent_risk_decision'],
        ] as [$path, $decisionType]) {
            DB::table('conflict_special_path_rules')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'formal_record_version_id' => $version->getKey(),
                'path_type' => $path,
                'entry_condition' => 'Classified '.$path.' case.',
                'procedure_summary' => 'Use governed '.$path.' procedure.',
                'operations_role_id' => $roleId,
                'review_deadline_days' => 3,
                'decision_type' => $decisionType,
                'external_handoff_rule' => 'Refer when unresolved.',
                'created_at' => now(),
            ]);
        }

        $version->frozen_at = now();
        $version->save();
        $this->effectiveLifecycle($business, $user, $version);
        $this->effectiveHead($business, $family, $version);

        return (string) $version->getKey();
    }

    /** @return array<string,mixed> */
    protected function policyPayload(array $context): array
    {
        return [
            'conflict_owner_membership_id' => (string) $context['owner']->getKey(),
            'formal_decision_type' => 'conflict_formal_decision',
            'deadlock_decision_type' => 'conflict_deadlock_decision',
            'misconduct_decision_type' => 'conflict_misconduct_decision',
            'urgent_risk_decision_type' => 'conflict_urgent_risk_decision',
            'settlement_decision_type' => 'conflict_settlement_approval',
            'review_frequency' => 'Quarterly',
            'notes' => 'Policy workflow fixture.',
            'escalation_rules' => [[
                'step_key' => 'formal_review',
                'entry_condition' => 'Direct resolution failed.',
                'operations_role_id' => $context['role_id'],
                'max_days' => 7,
                'required_evidence' => 'Case evidence.',
                'decision_type' => 'conflict_formal_decision',
                'resolution_exit_condition' => 'Resolved or escalated.',
                'next_step_key' => null,
                'status' => 'active',
            ]],
            'special_path_rules' => [
                [
                    'path_type' => 'deadlock',
                    'entry_condition' => 'Deadlock classified.',
                    'procedure_summary' => 'Use governed deadlock procedure.',
                    'operations_role_id' => $context['role_id'],
                    'review_deadline_days' => 3,
                    'external_handoff_rule' => 'Refer if unresolved.',
                ],
                [
                    'path_type' => 'misconduct',
                    'entry_condition' => 'Misconduct alleged.',
                    'procedure_summary' => 'Investigate before governed remedy.',
                    'operations_role_id' => $context['role_id'],
                    'review_deadline_days' => 3,
                    'external_handoff_rule' => 'Refer if required.',
                ],
                [
                    'path_type' => 'urgent_risk',
                    'entry_condition' => 'Critical risk exists.',
                    'procedure_summary' => 'Contain and seek exact authority.',
                    'operations_role_id' => $context['role_id'],
                    'review_deadline_days' => 1,
                    'external_handoff_rule' => 'Escalate unresolved risk.',
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    protected function casePayload(array $context, string $type = 'ordinary_disagreement'): array
    {
        return [
            'conflict_type' => $type,
            'description' => 'A restricted partner conflict requires structured resolution.',
            'business_impact' => 'Delivery and partner coordination are affected.',
            'urgency' => $type === 'urgent_risk' ? 'critical' : 'normal',
            'related_rule_reference' => 'Partnership Agreement clause 12',
            'review_due_at' => now()->addWeek(),
            'participants' => [[
                'membership_id' => (string) $context['party']->getKey(),
                'external_reference' => null,
                'participant_role' => 'party',
            ]],
            'view_membership_ids' => [],
            'manage_membership_ids' => [],
        ];
    }

    /** @return array{id:string,case_number:string,revision:int} */
    protected function openCase(
        array $context,
        string $type = 'ordinary_disagreement',
    ): array {
        $opened = $this->app->make(ConflictCaseWorkflow::class)->open(
            $context['user'],
            $context['business'],
            $this->casePayload($context, $type),
        );
        self::assertNotNull($opened);

        return $opened;
    }

    protected function recordVersion(
        Business $business,
        User $user,
        FormalRecordFamily $family,
        string $hash,
    ): FormalRecordVersion {
        return FormalRecordVersion::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'F6E fixture',
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
            'effective_from' => now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => $hash,
            'frozen_at' => null,
        ]);
    }

    protected function effectiveLifecycle(
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

    protected function effectiveHead(
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

final class F6EConflictPolicyTest extends F6EConflictTestCase
{
    public function test_policy_draft_captures_exact_current_governance_and_operations(): void
    {
        $context = $this->context(false);

        $created = $this->app->make(ConflictPolicyWorkflow::class)
            ->createDraft(
                $context['user'],
                $context['business'],
                $this->policyPayload($context),
                now()->subMinute(),
                now()->addMonths(3),
            );

        self::assertNotNull($created);

        $this->assertDatabaseHas('conflict_policy_versions', [
            'business_id' => $context['business']->getKey(),
            'formal_record_version_id' => $created['formal_record_version_id'],
            'governance_formal_record_version_id' => $context['governance_version_id'],
            'operations_formal_record_version_id' => $context['operations_version_id'],
            'formal_decision_type' => 'conflict_formal_decision',
            'settlement_decision_type' => 'conflict_settlement_approval',
        ]);
        $this->assertDatabaseCount('conflict_escalation_rules', 1);
        $this->assertDatabaseCount('conflict_special_path_rules', 3);
    }

    public function test_submitted_policy_freezes_snapshot_and_db_rejects_child_rewrite(): void
    {
        $context = $this->context(false);
        $workflow = $this->app->make(ConflictPolicyWorkflow::class);

        $created = $workflow->createDraft(
            $context['user'],
            $context['business'],
            $this->policyPayload($context),
            now()->subMinute(),
        );
        self::assertNotNull($created);

        $submitted = $workflow->submitForGovernance(
            $context['user'],
            $context['business'],
            $created['formal_record_version_id'],
            1,
        );
        self::assertNotNull($submitted);

        $version = FormalRecordVersion::query()->findOrFail(
            $created['formal_record_version_id'],
        );
        self::assertNotNull($version->frozen_at);

        $this->expectException(QueryException::class);

        DB::table('conflict_escalation_rules')
            ->where(
                'formal_record_version_id',
                $created['formal_record_version_id'],
            )
            ->update(['max_days' => 99]);
    }

    public function test_invalid_governance_decision_type_is_rejected_before_truth_is_created(): void
    {
        $context = $this->context(false);
        $payload = $this->policyPayload($context);
        $payload['urgent_risk_decision_type'] = 'invented_authority';

        $this->expectException(\InvalidArgumentException::class);

        $this->app->make(ConflictPolicyWorkflow::class)->createDraft(
            $context['user'],
            $context['business'],
            $payload,
            now()->subMinute(),
        );
    }

    public function test_workspace_exposes_policy_authoring_prerequisites_and_exact_options(): void
    {
        $context = $this->context(false);

        $workspace = $this->app->make(GetConflictWorkspace::class)
            ->execute(
                $context['user'],
                $context['business'],
            );

        self::assertNotNull($workspace);

        self::assertSame(
            'met',
            $workspace['prerequisites']['governance_charter']['status'],
        );

        self::assertSame(
            'met',
            $workspace['prerequisites']['operations_register']['status'],
        );

        self::assertTrue(
            $workspace['governance_decision_types']
                ->contains('conflict_formal_decision'),
        );

        self::assertTrue(
            $workspace['governance_decision_types']
                ->contains('conflict_settlement_approval'),
        );

        self::assertNotEmpty($workspace['memberships']);
        self::assertNotEmpty($workspace['operations_roles']);

        DB::table('record_family_effective_heads')
            ->where(
                'business_id',
                $context['business']->getKey(),
            )
            ->where(
                'formal_record_version_id',
                $context['governance_version_id'],
            )
            ->delete();

        $missing = $this->app->make(GetConflictWorkspace::class)
            ->execute(
                $context['user'],
                $context['business'],
            );

        self::assertNotNull($missing);

        self::assertSame(
            'missing',
            $missing['prerequisites']['governance_charter']['status'],
        );

        self::assertSame(
            [],
            $missing['governance_decision_types']->all(),
        );
    }

    public function test_missing_governance_decision_returns_visible_validation_error_instead_of_404(): void
    {
        $context = $this->context(false);
        $workflow = $this->app->make(ConflictPolicyWorkflow::class);

        $created = $workflow->createDraft(
            $context['user'],
            $context['business'],
            $this->policyPayload($context),
            now()->subMinute(),
        );

        self::assertNotNull($created);

        $versionId = $created['formal_record_version_id'];

        self::assertNotNull(
            $workflow->submitForGovernance(
                $context['user'],
                $context['business'],
                $versionId,
                1,
            ),
        );

        self::assertTrue(
            $workflow->advanceContentReview(
                $context['user'],
                $context['business'],
                $versionId,
                FormalRecordState::UnderReview,
            ),
        );

        self::assertTrue(
            $workflow->advanceContentReview(
                $context['user'],
                $context['business'],
                $versionId,
                FormalRecordState::Approved,
            ),
        );

        $this
            ->actingAs($context['user'])
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => $context['business']->getKey(),
            ])
            ->from('/conflict')
            ->post(
                route('conflict.policy.sync-decision', [
                    'formalRecordVersion' => $versionId,
                ]),
            )
            ->assertRedirect('/conflict')
            ->assertSessionHasErrors('conflict');
    }
}
