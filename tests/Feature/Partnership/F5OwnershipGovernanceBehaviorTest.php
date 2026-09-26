<?php

declare(strict_types=1);

namespace Tests\Feature\Partnership;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\Governance\CompleteProposalReview;
use App\Application\Governance\CreateProposalReview;
use App\Application\Governance\OpenGovernanceDecision;
use App\Application\Governance\RecordGovernanceApproval;
use App\Application\Governance\ResolveGovernanceDecision;
use App\Application\Partnership\OwnershipGovernanceWorkflow;
use App\Application\Partnership\OwnershipWorkflow;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Governance\Enums\ApprovalOutcome;
use App\Domain\Governance\Enums\DecisionMethod;
use App\Domain\Governance\Enums\ProposalReviewOutcome;
use App\Domain\Governance\ValueObjects\DecisionType;
use App\Domain\Records\Enums\FormalRecordState;
use App\Infrastructure\Persistence\Eloquent\Access\AccessPolicy;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityEstablishment;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityPolicyActor;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityPolicyRule;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionStateTransition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F5OwnershipGovernanceBehaviorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->resetTestSchema();
    }

    protected function tearDown(): void
    {
        try {
            $this->resetTestSchema();
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Keep this clock-sensitive E2E outside RefreshDatabase's long-lived
     * transaction while returning PostgreSQL to a clean, fully migrated
     * state for every surrounding test.
     */
    private function resetTestSchema(): void
    {
        $exitCode = Artisan::call(
            'migrate:fresh',
            ['--force' => true],
        );

        self::assertSame(
            0,
            $exitCode,
            Artisan::output(),
        );

        RefreshDatabaseState::$migrated = true;
    }

    public function test_future_effective_ownership_waits_then_becomes_current_on_due_date(): void
    {
        $context = $this->ownershipGovernanceContext(
            15,
        );

        $firstAttempt = $this->app
            ->make(OwnershipGovernanceWorkflow::class)
            ->effectApprovedGovernance(
                $context['manager'],
                $context['business'],
                $context['submissionId'],
            );

        self::assertFalse($firstAttempt);

        self::assertSame(
            FormalRecordState::ReadyForEffect->value,
            $this->latestState(
                $context['formalRecordVersionId'],
            ),
        );

        self::assertDatabaseCount(
            'ownership_register_versions',
            0,
        );

        self::assertNull(
            DB::table('ownership_governance_submissions')
                ->where('id', $context['submissionId'])
                ->value('effective_register_version_id'),
        );

        $effectiveFrom = CarbonImmutable::parse(
            (string) DB::table('formal_record_versions')
                ->where(
                    'id',
                    $context['formalRecordVersionId'],
                )
                ->value('effective_from'),
        );

        $dueNow = $this->waitForDatabaseTime(
            $effectiveFrom,
        );

        $secondAttempt = $this->app
            ->make(OwnershipGovernanceWorkflow::class)
            ->effectApprovedGovernance(
                $context['manager'],
                $context['business'],
                $context['submissionId'],
            );

        self::assertTrue($secondAttempt);

        self::assertSame(
            FormalRecordState::Effective->value,
            $this->latestState(
                $context['formalRecordVersionId'],
            ),
        );

        self::assertDatabaseCount(
            'ownership_register_versions',
            1,
        );

        $registerVersion = DB::table(
            'ownership_register_versions',
        )
            ->where(
                'business_id',
                $context['business']->getKey(),
            )
            ->sole();

        self::assertSame(
            'effective',
            $registerVersion->status,
        );

        self::assertSame(
            $context['scenarioId'],
            (string) $registerVersion
                ->source_ownership_scenario_id,
        );

        self::assertSame(
            $context['proposalVersionId'],
            (string) $registerVersion
                ->proposal_version_id,
        );

        self::assertSame(
            $context['decisionId'],
            (string) $registerVersion
                ->governance_decision_id,
        );

        self::assertNotNull(
            $registerVersion->authority_snapshot_id,
        );

        $submission = DB::table(
            'ownership_governance_submissions',
        )
            ->where('id', $context['submissionId'])
            ->sole();

        self::assertSame(
            (string) $registerVersion->id,
            (string) $submission
                ->effective_register_version_id,
        );

        $current = $this->app
            ->make(OwnershipWorkflow::class)
            ->currentEffectiveRegisterVersion(
                $context['manager'],
                $context['business'],
                $dueNow,
            );

        self::assertNotNull($current);

        self::assertSame(
            (string) $registerVersion->id,
            (string) $current->id,
        );

        $idempotent = $this->app
            ->make(OwnershipGovernanceWorkflow::class)
            ->effectApprovedGovernance(
                $context['manager'],
                $context['business'],
                $context['submissionId'],
            );

        self::assertTrue($idempotent);

        self::assertDatabaseCount(
            'ownership_register_versions',
            1,
        );
    }

    public function test_immediately_effective_approved_ownership_creates_current_register(): void
    {
        $context = $this->ownershipGovernanceContext(
            -60,
        );

        $result = $this->app
            ->make(OwnershipGovernanceWorkflow::class)
            ->effectApprovedGovernance(
                $context['manager'],
                $context['business'],
                $context['submissionId'],
            );

        self::assertTrue($result);

        self::assertSame(
            FormalRecordState::Effective->value,
            $this->latestState(
                $context['formalRecordVersionId'],
            ),
        );

        self::assertDatabaseHas(
            'ownership_register_versions',
            [
                'business_id' => $context['business']->getKey(),
                'source_ownership_scenario_id' => $context['scenarioId'],
                'proposal_version_id' => $context['proposalVersionId'],
                'governance_decision_id' => $context['decisionId'],
                'status' => 'effective',
            ],
        );
    }

    /**
     * @return array{
     *     business: Business,
     *     manager: User,
     *     scenarioId: string,
     *     submissionId: string,
     *     formalRecordVersionId: string,
     *     proposalVersionId: string,
     *     decisionId: string
     * }
     */
    private function ownershipGovernanceContext(
        int $effectiveOffsetSeconds,
    ): array {
        $business = Business::query()->create([
            'name' => 'F5 Ownership Governance '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'planning',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'THB',
        ]);

        [$manager, $managerMembership] =
            $this->userMembership(
                $business,
                'ownership-manager',
            );

        [$approver, $approverMembership] =
            $this->userMembership(
                $business,
                'ownership-approver',
            );

        $this->app
            ->make(ProvisionStandardAccessProfiles::class)
            ->execute(
                $business,
                $managerMembership,
            );

        $this->grant(
            $business,
            $approverMembership,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
            [Decision::class],
        );

        $this->seedFormationAuthority(
            $business,
            $manager,
            $managerMembership,
            $approverMembership,
        );

        $scenarioId = $this->seedFrozenScenario(
            $business,
            $managerMembership,
        );

        $databaseEffectiveFrom = DB::selectOne(
            <<<'SQL'
SELECT
    CURRENT_TIMESTAMP
    + (
        CAST(? AS integer)
        * INTERVAL '1 second'
    ) AS effective_from
SQL,
            [$effectiveOffsetSeconds],
        );

        self::assertNotNull($databaseEffectiveFrom);

        $effectiveFrom = CarbonImmutable::parse(
            (string) $databaseEffectiveFrom->effective_from,
        );

        $submission = $this->app
            ->make(OwnershipGovernanceWorkflow::class)
            ->submitGovernance(
                $manager,
                $business,
                $scenarioId,
                $effectiveFrom,
            );

        self::assertNotNull($submission);

        $workflow = $this->app
            ->make(OwnershipGovernanceWorkflow::class);

        self::assertTrue(
            $workflow->advanceContentReview(
                $manager,
                $business,
                $submission['id'],
                FormalRecordState::UnderReview,
            ),
        );

        self::assertTrue(
            $workflow->advanceContentReview(
                $manager,
                $business,
                $submission['id'],
                FormalRecordState::Approved,
            ),
        );

        $review = $this->app
            ->make(CreateProposalReview::class)
            ->execute(
                $manager,
                $business,
                $submission['proposal_version_id'],
                (string) $managerMembership->getKey(),
            );

        self::assertNotNull($review);

        $completedReview = $this->app
            ->make(CompleteProposalReview::class)
            ->execute(
                $manager,
                $business,
                (string) $review->getKey(),
                ProposalReviewOutcome::Approved,
                'Ownership proposal reviewed and approved.',
            );

        self::assertNotNull($completedReview);

        $decision = $this->app
            ->make(OpenGovernanceDecision::class)
            ->execute(
                $manager,
                $business,
                $submission['proposal_version_id'],
                new DecisionType('ownership_approval'),
            );

        self::assertInstanceOf(
            Decision::class,
            $decision,
        );

        $approval = $this->app
            ->make(RecordGovernanceApproval::class)
            ->execute(
                $approver,
                $business,
                (string) $decision->getKey(),
                ApprovalOutcome::Approved,
                'Approved exact frozen Ownership proposal.',
            );

        self::assertNotNull($approval);

        $resolved = $this->app
            ->make(ResolveGovernanceDecision::class)
            ->approve(
                $manager,
                $business,
                (string) $decision->getKey(),
            );

        self::assertNotNull($resolved);

        return [
            'business' => $business,
            'manager' => $manager,
            'scenarioId' => $scenarioId,
            'submissionId' => $submission['id'],
            'formalRecordVersionId' => $submission['formal_record_version_id'],
            'proposalVersionId' => $submission['proposal_version_id'],
            'decisionId' => (string) $decision->getKey(),
        ];
    }

    private function seedFormationAuthority(
        Business $business,
        User $manager,
        Membership $managerMembership,
        Membership $approverMembership,
    ): void {
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
            'change_summary' => 'F5 Ownership temporary Formation Authority.',
            'created_by_user_id' => $manager->getKey(),
            'last_changed_by_user_id' => $manager->getKey(),
            'effective_from' => now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => str_repeat('1', 64),
            'frozen_at' => null,
        ]);

        RecordVersionStateTransition::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'sequence' => 1,
            'from_state' => null,
            'to_state' => 'draft',
            'transitioned_by_user_id' => $manager->getKey(),
            'occurred_at' => now()->subSeconds(10),
        ]);

        $rule =
            FormationAuthorityPolicyRule::query()->create([
                'business_id' => $business->getKey(),
                'formal_record_version_id' => $version->getKey(),
                'sequence' => 1,
                'decision_type' => 'ownership_approval',
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
            'membership_id' => $approverMembership->getKey(),
            'capacity' => 'Formation Ownership Approver',
            'can_approve' => true,
            'can_vote' => false,
            'can_sign' => false,
        ]);

        $version->frozen_at = now()->subSeconds(5);
        $version->save();

        RecordVersionStateTransition::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'sequence' => 2,
            'from_state' => 'draft',
            'to_state' => 'ready_for_review',
            'transitioned_by_user_id' => $manager->getKey(),
            'occurred_at' => now(),
        ]);

        FormationAuthorityEstablishment::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'established_by_membership_id' => $managerMembership->getKey(),
            'establishment_hash' => $version->content_hash,
            'established_at' => now(),
        ]);
    }

    private function seedFrozenScenario(
        Business $business,
        Membership $managerMembership,
    ): string {
        $scenarioId = (string) Str::uuid7();
        $classId = (string) Str::uuid7();

        DB::table('ownership_scenarios')->insert([
            'id' => $scenarioId,
            'business_id' => $business->getKey(),
            'name' => 'Governed Ownership Scenario',
            'currency' => 'THB',
            'share_value_minor_units' => 10_000,
            'authorized_shares' => '100',
            'reserved_unissued_shares' => '0',
            'status' => 'draft',
            'revision' => 1,
            'frozen_at' => null,
            'created_by_membership_id' => $managerMembership->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table(
            'ownership_scenario_share_classes',
        )->insert([
            'id' => $classId,
            'business_id' => $business->getKey(),
            'ownership_scenario_id' => $scenarioId,
            'name' => 'Ordinary',
            'voting_right_per_share' => '1',
            'profit_right_per_share' => '1',
            'transfer_allowed' => true,
            'restrictions' => null,
            'special_rights' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ownership_scenarios')
            ->where('id', $scenarioId)
            ->where(
                'business_id',
                $business->getKey(),
            )
            ->update([
                'status' => 'frozen',
                'revision' => 2,
                'frozen_at' => now(),
                'updated_at' => now(),
            ]);

        return $scenarioId;
    }

    private function waitForDatabaseTime(
        CarbonImmutable $target,
    ): CarbonImmutable {
        for ($attempt = 0; $attempt < 200; $attempt++) {
            $row = DB::selectOne(
                'SELECT clock_timestamp() AS current_time',
            );

            self::assertNotNull($row);

            $databaseNow = CarbonImmutable::parse(
                (string) $row->current_time,
            );

            if (
                $databaseNow->greaterThanOrEqualTo(
                    $target,
                )
            ) {
                return $databaseNow;
            }

            usleep(100_000);
        }

        self::fail(
            'PostgreSQL clock did not reach the Ownership Effective From boundary within 20 seconds.',
        );
    }

    private function latestState(
        string $formalRecordVersionId,
    ): string {
        return (string) DB::table(
            'record_version_state_transitions',
        )
            ->where(
                'formal_record_version_id',
                $formalRecordVersionId,
            )
            ->orderByDesc('sequence')
            ->value('to_state');
    }

    /**
     * @return array{User, Membership}
     */
    private function userMembership(
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

    /**
     * @param  list<class-string>  $resourceTypes
     */
    private function grant(
        Business $business,
        Membership $membership,
        string $capability,
        array $resourceTypes,
    ): void {
        $permission = Permission::query()
            ->firstOrCreate([
                'key' => $capability,
            ]);

        PermissionGrant::query()->firstOrCreate(
            [
                'business_id' => $business->getKey(),
                'membership_id' => $membership->getKey(),
                'permission_id' => $permission->getKey(),
            ],
            [
                'effect' => 'allow',
            ],
        );

        foreach ($resourceTypes as $resourceType) {
            AccessPolicy::query()->firstOrCreate(
                [
                    'business_id' => $business->getKey(),
                    'membership_id' => $membership->getKey(),
                    'permission_profile_id' => null,
                    'permission_id' => $permission->getKey(),
                    'resource_type' => $resourceType,
                ],
                [
                    'effect' => 'allow',
                ],
            );
        }
    }
}
