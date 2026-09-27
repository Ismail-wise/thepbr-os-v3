<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Application\Governance\GovernanceMeetingWorkflow;
use App\Application\Governance\UpdateGovernanceActionStatus;
use App\Application\Operations\CreateOperationsAction;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Governance\Enums\ActionStatus;
use App\Domain\Governance\Enums\DecisionMethod;
use App\Infrastructure\Persistence\Eloquent\Access\AccessPolicy;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Action;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityEstablishment;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityPolicyActor;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityPolicyRule;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\RecordFamilyEffectiveHead;
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionStateTransition;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F6BMeetingActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_held_meeting_history_is_immutable_and_preserves_quorum_evidence(): void
    {
        $context = $this->context();
        $this->seedFormationAuthority($context);

        $workflow = $this->app->make(
            GovernanceMeetingWorkflow::class,
        );

        $meetingId = $workflow->schedule(
            $context['manager'],
            $context['business'],
            'Monthly Governance Meeting',
            now()->addHour(),
            1,
            (string) $context['managerMembership']->getKey(),
            (string) $context['managerMembership']->getKey(),
            'Review reserved matters and current decisions.',
            [
                (string) $context['managerMembership']->getKey(),
                (string) $context['backupMembership']->getKey(),
            ],
            now(),
        );

        self::assertNotNull($meetingId);

        $held = $workflow->hold(
            $context['manager'],
            $context['business'],
            $meetingId,
            'The meeting considered the published agenda.',
            [
                (string) $context['managerMembership']->getKey() => 'present',
                (string) $context['backupMembership']->getKey() => 'recused',
            ],
        );

        self::assertTrue($held);

        $meeting = DB::table('governance_meetings')
            ->where('id', $meetingId)
            ->sole();

        self::assertSame('held', $meeting->status);
        self::assertSame(1, (int) $meeting->quorum_present);
        self::assertSame(1, (int) $meeting->quorum_required);
        self::assertSame(
            'recused',
            DB::table('governance_meeting_attendees')
                ->where('governance_meeting_id', $meetingId)
                ->where(
                    'membership_id',
                    $context['backupMembership']->getKey(),
                )
                ->value('attendance_status'),
        );

        $this->assertDatabaseRejects(function () use ($meetingId): void {
            DB::table('governance_meetings')
                ->where('id', $meetingId)
                ->update([
                    'minutes' => 'Silently rewritten historical minutes.',
                    'revision' => 3,
                    'updated_at' => now(),
                ]);
        });
    }

    public function test_operational_action_reuses_f3_action_lifecycle_and_requires_current_role_assignment(): void
    {
        $context = $this->context();
        $register = $this->seedEffectiveOperationsRegister(
            $context,
        );

        $create = $this->app->make(
            CreateOperationsAction::class,
        );

        $action = $create->execute(
            $context['manager'],
            $context['business'],
            $register['role_id'],
            (string) $context['managerMembership']->getKey(),
            'Close weekly operating review',
            'Prepare and circulate the operating close-out.',
            now()->addDay(),
        );

        self::assertInstanceOf(Action::class, $action);

        $this->assertDatabaseHas('operations_action_links', [
            'business_id' => $context['business']->getKey(),
            'action_id' => $action->getKey(),
            'formal_record_version_id' => $register['version_id'],
            'operations_role_id' => $register['role_id'],
        ]);

        $notAssigned = $create->execute(
            $context['manager'],
            $context['business'],
            $register['role_id'],
            (string) $context['backupMembership']->getKey(),
            'Unauthorized assignment',
        );

        self::assertNull($notAssigned);

        $completed = $this->app->make(
            UpdateGovernanceActionStatus::class,
        )->execute(
            $context['manager'],
            $context['business'],
            (string) $action->getKey(),
            ActionStatus::Completed,
        );

        self::assertNotNull($completed);
        self::assertSame(
            ActionStatus::Completed,
            $completed->status,
        );
        self::assertNotNull($completed->completed_at);

        $this->assertDatabaseRejects(function () use ($action): void {
            DB::table('operations_action_links')
                ->where('action_id', $action->getKey())
                ->update([
                    'operations_role_id' => (string) Str::uuid7(),
                ]);
        });
    }

    /**
     * @return array{
     *   business:Business,
     *   manager:User,
     *   managerMembership:Membership,
     *   backupMembership:Membership
     * }
     */
    private function context(): array
    {
        $business = Business::query()->create([
            'name' => 'F6 Meeting Action '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'planning',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);

        [$manager, $managerMembership] = $this->member(
            $business,
            'f6-meeting-manager',
        );
        [, $backupMembership] = $this->member(
            $business,
            'f6-meeting-backup',
        );

        $this->grant(
            $business,
            $managerMembership,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
            [],
        );
        $this->grant(
            $business,
            $managerMembership,
            CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
            [],
        );
        $this->grant(
            $business,
            $managerMembership,
            CapabilityCatalog::OPERATIONS_MANAGE,
            [Action::class],
        );
        $this->grant(
            $business,
            $managerMembership,
            CapabilityCatalog::OPERATIONS_VIEW,
            [],
        );

        return [
            'business' => $business,
            'manager' => $manager,
            'managerMembership' => $managerMembership,
            'backupMembership' => $backupMembership,
        ];
    }

    /** @param array<string,mixed> $context */
    private function seedFormationAuthority(array $context): void
    {
        $family = FormalRecordFamily::query()->create([
            'business_id' => $context['business']->getKey(),
            'record_type' => 'formation_authority_policy',
            'subject_type' => 'business',
            'subject_id' => (string) $context['business']->getKey(),
        ]);

        $version = FormalRecordVersion::query()->create([
            'business_id' => $context['business']->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'Meeting authority source',
            'created_by_user_id' => $context['manager']->getKey(),
            'last_changed_by_user_id' => $context['manager']->getKey(),
            'effective_from' => now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => str_repeat('c', 64),
            'frozen_at' => null,
        ]);

        $rule = FormationAuthorityPolicyRule::query()->create([
            'business_id' => $context['business']->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'sequence' => 1,
            'decision_type' => 'meeting_fixture',
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
            'business_id' => $context['business']->getKey(),
            'formation_authority_policy_rule_id' => $rule->getKey(),
            'membership_id' => $context['managerMembership']->getKey(),
            'capacity' => 'Formation Approver',
            'can_approve' => true,
            'can_vote' => false,
            'can_sign' => false,
        ]);

        $version->frozen_at = now();
        $version->save();

        $this->state(
            $version,
            $context['manager'],
            1,
            null,
            'draft',
        );
        $this->state(
            $version,
            $context['manager'],
            2,
            'draft',
            'ready_for_review',
        );

        FormationAuthorityEstablishment::query()->create([
            'business_id' => $context['business']->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'established_by_membership_id' => $context['managerMembership']->getKey(),
            'establishment_hash' => $version->content_hash,
            'established_at' => now(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array{version_id:string,role_id:string}
     */
    private function seedEffectiveOperationsRegister(
        array $context,
    ): array {
        $family = FormalRecordFamily::query()->create([
            'business_id' => $context['business']->getKey(),
            'record_type' => 'operations_register',
            'subject_type' => 'business',
            'subject_id' => (string) $context['business']->getKey(),
        ]);

        $version = FormalRecordVersion::query()->create([
            'business_id' => $context['business']->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'Effective Operations fixture',
            'created_by_user_id' => $context['manager']->getKey(),
            'last_changed_by_user_id' => $context['manager']->getKey(),
            'effective_from' => now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => str_repeat('d', 64),
            'frozen_at' => null,
        ]);

        DB::table('operations_register_versions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $context['business']->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'organization_name' => 'Effective Operations',
            'notes' => null,
            'created_at' => now(),
        ]);

        $roleId = (string) Str::uuid7();

        DB::table('operations_roles')->insert([
            'id' => $roleId,
            'business_id' => $context['business']->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'role_key' => 'operations_lead',
            'name' => 'Operations Lead',
            'function_name' => 'Operations',
            'purpose' => 'Deliver approved work.',
            'responsibilities' => 'Coordinate and close.',
            'operational_authority' => null,
            'reports_to_role_key' => null,
            'report_type' => 'Operating update',
            'reporting_frequency' => 'Weekly',
            'meeting_frequency' => 'Weekly',
            'review_frequency' => 'Quarterly',
            'status' => 'active',
            'created_at' => now(),
        ]);

        DB::table('operations_role_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $context['business']->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'operations_role_id' => $roleId,
            'membership_id' => $context['managerMembership']->getKey(),
            'assignment_type' => 'primary',
            'created_at' => now(),
        ]);

        $version->frozen_at = now();
        $version->save();

        $states = [
            [null, 'draft'],
            ['draft', 'ready_for_review'],
            ['ready_for_review', 'under_review'],
            ['under_review', 'approved'],
            ['approved', 'ready_for_effect'],
            ['ready_for_effect', 'effective'],
        ];

        foreach ($states as $index => [$from, $to]) {
            $this->state(
                $version,
                $context['manager'],
                $index + 1,
                $from,
                $to,
            );
        }

        RecordFamilyEffectiveHead::query()->create([
            'business_id' => $context['business']->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'activated_at' => now(),
        ]);

        return [
            'version_id' => (string) $version->getKey(),
            'role_id' => $roleId,
        ];
    }

    /** @return array{User,Membership} */
    private function member(
        Business $business,
        string $prefix,
    ): array {
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

    /** @param list<class-string> $resourceTypes */
    private function grant(
        Business $business,
        Membership $membership,
        string $capability,
        array $resourceTypes,
    ): void {
        $permission = Permission::query()->firstOrCreate([
            'key' => $capability,
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

    private function state(
        FormalRecordVersion $version,
        User $user,
        int $sequence,
        ?string $from,
        string $to,
    ): void {
        RecordVersionStateTransition::query()->create([
            'business_id' => $version->business_id,
            'formal_record_version_id' => $version->getKey(),
            'sequence' => $sequence,
            'from_state' => $from,
            'to_state' => $to,
            'transitioned_by_user_id' => $user->getKey(),
            'occurred_at' => now(),
        ]);
    }

    private function assertDatabaseRejects(callable $callback): void
    {
        DB::beginTransaction();

        try {
            $callback();
            DB::rollBack();
            $this->fail('Expected PostgreSQL to reject immutable F6 history.');
        } catch (QueryException) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $this->addToAssertionCount(1);
        }
    }
}
