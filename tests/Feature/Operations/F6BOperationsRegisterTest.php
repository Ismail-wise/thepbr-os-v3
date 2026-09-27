<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Application\Governance\GovernanceActorContext;
use App\Application\Operations\OperationsRegisterWorkflow;
use App\Application\Records\CreateAmendedDraftVersion;
use App\Application\Records\CreateDraftRecordVersion;
use App\Application\Records\CreateFormalRecordFamily;
use App\Application\Records\CreateProposal;
use App\Application\Records\FreezeProposalVersion;
use App\Application\Records\SubmitRecordVersionForReview;
use App\Application\Records\TransitionFormalRecordVersion;
use App\Domain\Access\CapabilityCatalog;
use App\Infrastructure\Persistence\Eloquent\Access\AccessPolicy;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Action;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\Proposal;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

final class F6BOperationsRegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_operations_orchestrator_reuses_f2_and_keeps_governance_out_of_operations(): void
    {
        $constructor = (new ReflectionClass(
            OperationsRegisterWorkflow::class,
        ))->getConstructor();

        self::assertNotNull($constructor);

        $types = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            self::assertNotNull($type);
            $types[] = $type->getName();
        }

        foreach ([
            GovernanceActorContext::class,
            CreateFormalRecordFamily::class,
            CreateDraftRecordVersion::class,
            CreateAmendedDraftVersion::class,
            SubmitRecordVersionForReview::class,
            TransitionFormalRecordVersion::class,
            CreateProposal::class,
            FreezeProposalVersion::class,
        ] as $required) {
            self::assertContains($required, $types);
        }

        $source = file_get_contents(
            (new ReflectionClass(
                OperationsRegisterWorkflow::class,
            ))->getFileName(),
        );

        self::assertIsString($source);
        self::assertStringNotContainsString(
            "DB::table('approvals')->insert",
            $source,
        );
        self::assertStringNotContainsString(
            "DB::table('votes')->insert",
            $source,
        );
        self::assertStringNotContainsString(
            "DB::table('authority_snapshots')->insert",
            $source,
        );
    }

    public function test_roles_backup_raci_and_kpi_are_versioned_and_frozen_history_is_immutable(): void
    {
        $context = $this->context();

        $workflow = $this->app->make(
            OperationsRegisterWorkflow::class,
        );

        $created = $workflow->createDraft(
            $context['manager'],
            $context['business'],
            [
                'organization_name' => 'F6 Operations Business',
                'notes' => 'Canonical responsibility register.',
                'roles' => [
                    [
                        'role_key' => 'operations_lead',
                        'name' => 'Operations Lead',
                        'function_name' => 'Operations',
                        'purpose' => 'Own operating delivery.',
                        'responsibilities' => 'Plan, coordinate, report and close work.',
                        'operational_authority' => 'Coordinate approved operating work only.',
                        'reports_to_role_key' => null,
                        'report_type' => 'Operating update',
                        'reporting_frequency' => 'Weekly',
                        'meeting_frequency' => 'Weekly',
                        'review_frequency' => 'Quarterly',
                        'assignments' => [
                            [
                                'membership_id' => (string) $context[
                                        'managerMembership'
                                    ]->getKey(),
                                'assignment_type' => 'primary',
                            ],
                            [
                                'membership_id' => (string) $context[
                                        'backupMembership'
                                    ]->getKey(),
                                'assignment_type' => 'backup',
                            ],
                        ],
                    ],
                ],
                'raci' => [
                    [
                        'activity' => 'Weekly operating plan',
                        'result' => 'Approved work is delivered.',
                        'assignments' => [
                            [
                                'role_key' => 'operations_lead',
                                'responsibility' => 'AR',
                            ],
                        ],
                    ],
                ],
                'kpis' => [
                    [
                        'role_key' => 'operations_lead',
                        'name' => 'On-time action closure',
                        'target' => '>= 90%',
                        'measurement_method' => 'Completed by due date / due actions',
                        'frequency' => 'Monthly',
                        'current_status' => 'on_track',
                    ],
                ],
            ],
            now()->subMinute(),
        );

        self::assertNotNull($created);
        self::assertDatabaseCount('operations_register_versions', 1);
        self::assertDatabaseCount('operations_roles', 1);
        self::assertDatabaseCount('operations_role_assignments', 2);
        self::assertDatabaseCount('operations_raci_items', 1);
        self::assertDatabaseCount('operations_raci_assignments', 1);
        self::assertDatabaseCount('operations_kpis', 1);

        $this->assertDatabaseHas('operations_role_assignments', [
            'membership_id' => $context['managerMembership']->getKey(),
            'assignment_type' => 'primary',
        ]);
        $this->assertDatabaseHas('operations_role_assignments', [
            'membership_id' => $context['backupMembership']->getKey(),
            'assignment_type' => 'backup',
        ]);

        $submitted = $workflow->submitForGovernance(
            $context['manager'],
            $context['business'],
            $created['formal_record_version_id'],
            1,
        );

        self::assertNotNull($submitted);
        self::assertDatabaseHas('proposal_version_records', [
            'proposal_version_id' => $submitted['proposal_version_id'],
            'formal_record_version_id' => $created['formal_record_version_id'],
        ]);

        $version = FormalRecordVersion::query()
            ->whereKey($created['formal_record_version_id'])
            ->sole();

        self::assertNotNull($version->frozen_at);

        $role = DB::table('operations_roles')
            ->where(
                'formal_record_version_id',
                $version->getKey(),
            )
            ->sole();

        $this->assertDatabaseRejects(function () use ($role): void {
            DB::table('operations_roles')
                ->where('id', $role->id)
                ->update([
                    'responsibilities' => 'Silently overwrite historical responsibility.',
                ]);
        });

        self::assertSame(
            'Plan, coordinate, report and close work.',
            DB::table('operations_roles')
                ->where('id', $role->id)
                ->value('responsibilities'),
        );
    }

    public function test_postgresql_rejects_cross_version_role_assignment_raci_and_kpi_links(): void
    {
        $context = $this->context();

        $family = FormalRecordFamily::query()->create([
            'business_id' => $context['business']->getKey(),
            'record_type' => 'operations_register',
            'subject_type' => 'business',
            'subject_id' => (string) $context['business']->getKey(),
        ]);

        $v1 = $this->draftVersion(
            $context,
            $family,
            1,
            null,
            str_repeat('a', 64),
        );
        $v2 = $this->draftVersion(
            $context,
            $family,
            2,
            $v1,
            str_repeat('b', 64),
        );

        DB::table('operations_register_versions')->insert([
            [
                'id' => (string) Str::uuid7(),
                'business_id' => $context['business']->getKey(),
                'formal_record_version_id' => $v1->getKey(),
                'organization_name' => 'V1',
                'notes' => null,
                'created_at' => now(),
            ],
            [
                'id' => (string) Str::uuid7(),
                'business_id' => $context['business']->getKey(),
                'formal_record_version_id' => $v2->getKey(),
                'organization_name' => 'V2',
                'notes' => null,
                'created_at' => now(),
            ],
        ]);

        $roleV1 = $this->role(
            $context['business'],
            $v1,
            'role_v1',
        );
        $roleV2 = $this->role(
            $context['business'],
            $v2,
            'role_v2',
        );

        $itemV2 = (string) Str::uuid7();

        DB::table('operations_raci_items')->insert([
            'id' => $itemV2,
            'business_id' => $context['business']->getKey(),
            'formal_record_version_id' => $v2->getKey(),
            'sequence' => 1,
            'activity' => 'V2 activity',
            'result' => null,
            'created_at' => now(),
        ]);

        $this->assertDatabaseRejects(function () use (
            $context,
            $v2,
            $roleV1,
        ): void {
            DB::table('operations_role_assignments')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $context['business']->getKey(),
                'formal_record_version_id' => $v2->getKey(),
                'operations_role_id' => $roleV1,
                'membership_id' => $context['managerMembership']->getKey(),
                'assignment_type' => 'primary',
                'created_at' => now(),
            ]);
        });

        $this->assertDatabaseRejects(function () use (
            $context,
            $itemV2,
            $roleV1,
        ): void {
            DB::table('operations_raci_assignments')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $context['business']->getKey(),
                'operations_raci_item_id' => $itemV2,
                'operations_role_id' => $roleV1,
                'responsibility' => 'AR',
                'created_at' => now(),
            ]);
        });

        $this->assertDatabaseRejects(function () use (
            $context,
            $v2,
            $roleV1,
        ): void {
            DB::table('operations_kpis')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $context['business']->getKey(),
                'formal_record_version_id' => $v2->getKey(),
                'operations_role_id' => $roleV1,
                'name' => 'Cross-version KPI',
                'target' => 'Never',
                'measurement_method' => 'Invalid',
                'frequency' => 'Monthly',
                'current_status' => 'not_started',
                'created_at' => now(),
            ]);
        });

        self::assertNotSame($roleV1, $roleV2);
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
            'name' => 'F6 Operations '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'planning',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);

        [$manager, $managerMembership] = $this->member(
            $business,
            'operations-manager',
        );
        [, $backupMembership] = $this->member(
            $business,
            'operations-backup',
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
        $this->grant(
            $business,
            $managerMembership,
            CapabilityCatalog::RECORDS_MANAGE,
            [
                FormalRecordFamily::class,
                FormalRecordVersion::class,
                Proposal::class,
            ],
        );

        return [
            'business' => $business,
            'manager' => $manager,
            'managerMembership' => $managerMembership,
            'backupMembership' => $backupMembership,
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

    private function draftVersion(
        array $context,
        FormalRecordFamily $family,
        int $number,
        ?FormalRecordVersion $predecessor,
        string $hash,
    ): FormalRecordVersion {
        return FormalRecordVersion::query()->create([
            'business_id' => $context['business']->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => $number,
            'predecessor_version_id' => $predecessor?->getKey(),
            'revision' => 1,
            'change_summary' => 'Operations v'.$number,
            'created_by_user_id' => $context['manager']->getKey(),
            'last_changed_by_user_id' => $context['manager']->getKey(),
            'effective_from' => now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => $hash,
            'frozen_at' => null,
        ]);
    }

    private function role(
        Business $business,
        FormalRecordVersion $version,
        string $key,
    ): string {
        $id = (string) Str::uuid7();

        DB::table('operations_roles')->insert([
            'id' => $id,
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'role_key' => $key,
            'name' => $key,
            'function_name' => 'Operations',
            'purpose' => 'Test role.',
            'responsibilities' => 'Test responsibilities.',
            'operational_authority' => null,
            'reports_to_role_key' => null,
            'report_type' => null,
            'reporting_frequency' => null,
            'meeting_frequency' => null,
            'review_frequency' => 'Quarterly',
            'status' => 'active',
            'created_at' => now(),
        ]);

        return $id;
    }

    private function assertDatabaseRejects(callable $callback): void
    {
        DB::beginTransaction();

        try {
            $callback();
            DB::rollBack();
            $this->fail('Expected PostgreSQL to reject invalid Operations write.');
        } catch (QueryException) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $this->addToAssertionCount(1);
        }
    }
}
