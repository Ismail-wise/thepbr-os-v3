<?php

declare(strict_types=1);

namespace Tests\Feature\Formation;

use App\Application\Businesses\CreateBusiness;
use App\Application\Formation\GetCapitalRuleDraft;
use App\Application\Formation\GetCapitalRuleReadModel;
use App\Application\Formation\SaveCapitalPlanningDraft;
use App\Application\Formation\SaveCapitalRuleDraft;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

final class CapitalRuleAllocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_derived_allocation_summary_preserves_zero_and_missing_without_duplicate_formula_truth(): void
    {
        [$user, $business] = $this->business(
            'capital-rule-zero@example.test',
            'Capital Rule Zero',
        );

        $this->savePlanning($user, $business, 0, [
            'preOpeningItems' => [],
            'initialAssetsInventoryItems' => [],
            'workingCapital' => [
                'method' => 'fixed_amount',
                'amount' => '0.00',
            ],
            'contingency' => [
                'method' => 'fixed_amount',
                'amount' => '0.00',
            ],
            'confirmedFunding' => '0.00',
        ]);

        $model = $this->readModel($user, $business);

        self::assertNotNull($model);
        self::assertTrue($model['calculationComplete']);
        self::assertSame(
            '0.00',
            $model['allocationSummary']['preOpening'],
        );
        self::assertSame(
            '0.00',
            $model['allocationSummary']['workingCapital'],
        );
        self::assertSame(
            '0.00',
            $model['allocationSummary']['totalCapitalRequirement'],
        );
        self::assertSame(
            '0.00',
            $model['allocationSummary']['fundingGap'],
        );
        self::assertNull(
            $model['allocationSummary']['fundedPercentage'],
        );
        self::assertFalse($model['shortfallRequired']);
        self::assertSame('funded', $model['fundingState']);
        self::assertSame('not_saved', $model['status']);

        [$missingUser, $missingBusiness] = $this->business(
            'capital-rule-missing@example.test',
            'Capital Rule Missing',
        );

        $this->savePlanning(
            $missingUser,
            $missingBusiness,
            0,
            [
                'preOpeningItems' => null,
                'initialAssetsInventoryItems' => [],
                'workingCapital' => [
                    'method' => 'fixed_amount',
                    'amount' => '0.00',
                ],
                'contingency' => [
                    'method' => 'fixed_amount',
                    'amount' => '0.00',
                ],
                'confirmedFunding' => '0.00',
            ],
        );

        $missing = $this->readModel(
            $missingUser,
            $missingBusiness,
        );

        self::assertNotNull($missing);
        self::assertFalse($missing['calculationComplete']);
        self::assertNull(
            $missing['allocationSummary']['totalCapitalRequirement'],
        );
        self::assertSame(
            'needs_prior_capital_inputs',
            $missing['status'],
        );
        self::assertContains(
            'pre_opening',
            $missing['missingRequirements'],
        );
    }

    public function test_shortfall_rule_supports_ordered_responses_and_capital_call_remains_planning_only(): void
    {
        [$user, $business] = $this->business(
            'capital-rule-gap@example.test',
            'Capital Rule Gap',
        );

        $this->savePlanning(
            $user,
            $business,
            0,
            $this->planningInput(
                workingCapital: '1000.00',
                funding: '200.00',
            ),
        );

        $protectedTables = [
            'contributions',
            'ownership_scenarios',
            'ownership_registers',
            'formal_record_versions',
            'proposals',
            'decisions',
            'record_family_effective_heads',
        ];
        $before = [];

        foreach ($protectedTables as $table) {
            $before[$table] = DB::table($table)->count();
        }

        $saved = $this->saveRule()->execute(
            $user,
            $business,
            0,
            [
                'shortfallResponses' => [
                    'capital_call',
                    'reduce_scope',
                    'borrow',
                    'delay',
                ],
                'allocationNotes' => 'Protect operating cash first.',
                'shortfallRuleNotes' => 'Review before launch.',
                'capitalCallRuleNote' => 'Consider only for the remaining gap.',
            ],
        );

        self::assertNotNull($saved);
        self::assertSame(1, $saved['revision']);
        self::assertSame(1, $saved['capitalPlanningRevision']);
        self::assertSame(
            [
                'capital_call',
                'reduce_scope',
                'borrow',
                'delay',
            ],
            $saved['input']['shortfallResponses'],
        );
        self::assertFalse($saved['semantics']['approvedTruth']);
        self::assertFalse($saved['semantics']['signedTruth']);
        self::assertFalse($saved['semantics']['effectiveTruth']);
        self::assertFalse($saved['semantics']['decisionComplete']);
        self::assertFalse($saved['semantics']['capitalCallExecuted']);
        self::assertFalse($saved['semantics']['contributionTruth']);
        self::assertFalse($saved['semantics']['equityTruth']);
        self::assertFalse($saved['semantics']['ownershipTruth']);

        $model = $this->readModel($user, $business);

        self::assertNotNull($model);
        self::assertSame(
            '800.00',
            $model['allocationSummary']['fundingGap'],
        );
        self::assertTrue($model['shortfallRequired']);
        self::assertTrue($model['shortfallResponseRecorded']);
        self::assertTrue($model['capitalCallSelected']);
        self::assertTrue($model['ready']);
        self::assertSame('ready', $model['status']);

        foreach ($before as $table => $count) {
            self::assertSame(
                $count,
                DB::table($table)->count(),
                "Unexpected write to {$table}.",
            );
        }

        $this->assertDatabaseHas('audit_events', [
            'business_id' => $business->getKey(),
            'action' => 'capital.rule_draft.created',
            'target_type' => 'capital_rule_draft',
        ]);
        $this->assertDatabaseHas('business_events', [
            'business_id' => $business->getKey(),
            'event_type' => 'capital.rule_draft.created',
            'aggregate_type' => 'capital_rule_draft',
        ]);

        $audit = (string) DB::table('audit_events')
            ->where('business_id', $business->getKey())
            ->where('action', 'capital.rule_draft.created')
            ->value('metadata');

        self::assertStringNotContainsString(
            'Consider only for the remaining gap.',
            $audit,
        );
    }

    public function test_zero_gap_or_surplus_never_forces_or_persists_a_shortfall_response(): void
    {
        [$user, $business] = $this->business(
            'capital-rule-surplus@example.test',
            'Capital Rule Surplus',
        );

        $this->savePlanning(
            $user,
            $business,
            0,
            $this->planningInput(
                workingCapital: '100.00',
                funding: '250.00',
            ),
        );

        $saved = $this->saveRule()->execute(
            $user,
            $business,
            0,
            [
                'shortfallResponses' => ['borrow'],
                'allocationNotes' => 'Keep surplus unallocated.',
                'shortfallRuleNotes' => 'Should be cleared.',
                'capitalCallRuleNote' => null,
            ],
        );

        self::assertNotNull($saved);
        self::assertSame([], $saved['input']['shortfallResponses']);
        self::assertNull($saved['input']['shortfallRuleNotes']);
        self::assertNull($saved['input']['capitalCallRuleNote']);

        $model = $this->readModel($user, $business);

        self::assertNotNull($model);
        self::assertFalse($model['shortfallRequired']);
        self::assertSame('surplus', $model['fundingState']);
        self::assertSame(
            '150.00',
            $model['allocationSummary']['fundingSurplus'],
        );
        self::assertTrue($model['ready']);
    }

    public function test_incomplete_capital_calculation_cannot_be_saved_as_a_complete_step_six_rule(): void
    {
        [$user, $business] = $this->business(
            'capital-rule-incomplete@example.test',
            'Capital Rule Incomplete',
        );

        $this->savePlanning($user, $business, 0, [
            'preOpeningItems' => null,
            'initialAssetsInventoryItems' => [],
            'workingCapital' => null,
            'contingency' => null,
            'confirmedFunding' => null,
        ]);

        $model = $this->readModel($user, $business);

        self::assertNotNull($model);
        self::assertFalse($model['ready']);
        self::assertSame(
            'needs_prior_capital_inputs',
            $model['status'],
        );

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->saveRule()->execute(
                $user,
                $business,
                0,
                [
                    'shortfallResponses' => [],
                ],
            );
        } finally {
            $this->assertDatabaseCount('capital_rule_drafts', 0);
        }
    }

    public function test_rule_revision_stale_save_and_upstream_capital_revision_need_review_are_safe(): void
    {
        [$user, $business] = $this->business(
            'capital-rule-revision@example.test',
            'Capital Rule Revision',
        );

        $this->savePlanning(
            $user,
            $business,
            0,
            $this->planningInput(
                workingCapital: '1000.00',
                funding: '200.00',
            ),
        );

        $first = $this->saveRule()->execute(
            $user,
            $business,
            0,
            [
                'shortfallResponses' => ['reduce_scope'],
            ],
        );

        self::assertNotNull($first);
        self::assertSame(1, $first['revision']);
        self::assertSame(1, $first['capitalPlanningRevision']);

        $this->savePlanning(
            $user,
            $business,
            1,
            $this->planningInput(
                workingCapital: '1000.00',
                funding: '400.00',
            ),
        );

        $staleAgainstCapital = $this->readModel($user, $business);

        self::assertNotNull($staleAgainstCapital);
        self::assertTrue($staleAgainstCapital['needsReview']);
        self::assertFalse($staleAgainstCapital['ready']);
        self::assertSame('needs_review', $staleAgainstCapital['status']);
        self::assertSame(
            1,
            $staleAgainstCapital['rulePreparedAgainstCapitalRevision'],
        );
        self::assertSame(
            2,
            $staleAgainstCapital['capitalPlanningRevision'],
        );

        $second = $this->saveRule()->execute(
            $user,
            $business,
            1,
            [
                'shortfallResponses' => [
                    'reduce_scope',
                    'delay',
                ],
            ],
        );

        self::assertNotNull($second);
        self::assertSame(2, $second['revision']);
        self::assertSame(2, $second['capitalPlanningRevision']);

        try {
            $this->saveRule()->execute(
                $user,
                $business,
                1,
                [
                    'shortfallResponses' => ['borrow'],
                ],
            );

            self::fail('Expected stale Capital Rule revision to fail.');
        } catch (StaleRevision $exception) {
            self::assertSame(1, $exception->expectedRevision);
            self::assertSame(2, $exception->actualRevision);
        }

        $current = $this->ruleReader()->execute($user, $business);

        self::assertNotNull($current);
        self::assertSame(2, $current['revision']);
        self::assertSame(
            ['reduce_scope', 'delay'],
            $current['input']['shortfallResponses'],
        );
    }

    public function test_capital_view_can_read_manage_can_save_and_cross_tenant_access_fails_closed(): void
    {
        [$owner, $business] = $this->business(
            'capital-rule-owner@example.test',
            'Capital Rule Protected',
        );
        [, $otherBusiness] = $this->business(
            'capital-rule-other-owner@example.test',
            'Capital Rule Other',
        );

        $this->savePlanning(
            $owner,
            $business,
            0,
            $this->planningInput(
                workingCapital: '1000.00',
                funding: '200.00',
            ),
        );

        $viewer = $this->activeUser(
            'capital-rule-viewer@example.test',
        );
        $viewerMembership = Membership::query()->create([
            'user_id' => $viewer->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => 'active',
        ]);

        foreach ([
            CapabilityCatalog::FORMATION_VIEW,
            CapabilityCatalog::CAPITAL_VIEW,
        ] as $capability) {
            $this->grant(
                $business,
                $viewerMembership,
                $capability,
            );
        }

        self::assertNotNull(
            $this->ruleReader()->execute($viewer, $business),
        );
        self::assertNull(
            $this->saveRule()->execute(
                $viewer,
                $business,
                0,
                ['shortfallResponses' => ['reduce_scope']],
            ),
        );

        $manager = $this->activeUser(
            'capital-rule-manager@example.test',
        );
        $managerMembership = Membership::query()->create([
            'user_id' => $manager->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => 'active',
        ]);

        foreach ([
            CapabilityCatalog::FORMATION_VIEW,
            CapabilityCatalog::CAPITAL_VIEW,
            CapabilityCatalog::CAPITAL_MANAGE,
        ] as $capability) {
            $this->grant(
                $business,
                $managerMembership,
                $capability,
            );
        }

        self::assertNotNull(
            $this->saveRule()->execute(
                $manager,
                $business,
                0,
                ['shortfallResponses' => ['reduce_scope']],
            ),
        );

        $outsider = $this->activeUser(
            'capital-rule-outsider@example.test',
        );

        self::assertNull(
            $this->ruleReader()->execute($outsider, $business),
        );
        self::assertNull(
            $this->saveRule()->execute(
                $outsider,
                $business,
                1,
                ['shortfallResponses' => ['borrow']],
            ),
        );

        self::assertSame(
            0,
            DB::table('capital_rule_drafts')
                ->where('business_id', $otherBusiness->getKey())
                ->count(),
        );
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function savePlanning(
        User $user,
        Business $business,
        int $revision,
        array $input,
    ): void {
        $saved = $this->app
            ->make(SaveCapitalPlanningDraft::class)
            ->execute(
                $user,
                $business,
                $revision,
                $input,
            );

        self::assertNotNull($saved);
    }

    /**
     * @return array<string,mixed>
     */
    private function planningInput(
        string $workingCapital,
        string $funding,
    ): array {
        return [
            'preOpeningItems' => [],
            'initialAssetsInventoryItems' => [],
            'workingCapital' => [
                'method' => 'fixed_amount',
                'amount' => $workingCapital,
            ],
            'contingency' => [
                'method' => 'fixed_amount',
                'amount' => '0.00',
            ],
            'confirmedFunding' => $funding,
        ];
    }

    private function saveRule(): SaveCapitalRuleDraft
    {
        return $this->app->make(SaveCapitalRuleDraft::class);
    }

    private function ruleReader(): GetCapitalRuleDraft
    {
        return $this->app->make(GetCapitalRuleDraft::class);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readModel(
        User $user,
        Business $business,
    ): ?array {
        return $this->app
            ->make(GetCapitalRuleReadModel::class)
            ->execute($user, $business);
    }

    /**
     * @return array{0:User,1:Business}
     */
    private function business(
        string $email,
        string $name,
    ): array {
        $user = $this->activeUser($email);

        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            $name,
            BusinessOriginType::StartedThroughPbr,
            BusinessStage::Planning,
            'USD',
        );

        return [$user, $business];
    }

    private function activeUser(string $email): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => 'not-a-real-hash',
            'status' => AccountStatus::Active,
            'password_changed_at' => now(),
        ]);
    }

    private function grant(
        Business $business,
        Membership $membership,
        string $capability,
    ): void {
        $permission = Permission::query()->firstOrCreate([
            'key' => $capability,
        ]);

        PermissionGrant::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_id' => $permission->getKey(),
            'effect' => 'allow',
        ]);
    }
}
