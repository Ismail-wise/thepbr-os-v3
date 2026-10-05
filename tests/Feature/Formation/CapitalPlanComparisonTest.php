<?php

declare(strict_types=1);

namespace Tests\Feature\Formation;

use App\Application\Businesses\CreateBusiness;
use App\Application\Formation\BusinessModelPlanning;
use App\Application\Formation\GetCapitalComparisonDraft;
use App\Application\Formation\GetCapitalComparisonReadModel;
use App\Application\Formation\RefreshCapitalComparisonDraftFromCanonical;
use App\Application\Formation\SaveCapitalComparisonDraft;
use App\Application\Formation\SaveCapitalPlanningDraft;
use App\Application\Formation\SaveCapitalRuleDraft;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class CapitalPlanComparisonTest extends TestCase
{
    use RefreshDatabase;

    public function test_comparison_initializes_once_from_canonical_and_uses_server_calculation_in_exact_order(): void
    {
        [$user, $business] = $this->business(
            'capital-comparison@example.test',
            'Capital Comparison',
        );

        $canonical = $this->planningInput(
            workingCapital: '1000.00',
            funding: '500.00',
        );

        $this->savePlanning(
            $user,
            $business,
            0,
            $canonical,
        );

        $rule = $this->app->make(SaveCapitalRuleDraft::class)
            ->execute(
                $user,
                $business,
                0,
                [
                    'shortfallResponses' => ['reduce_scope'],
                    'allocationNotes' => 'Keep the operating buffer.',
                ],
            );

        self::assertNotNull($rule);

        $canonicalBefore = DB::table('capital_planning_drafts')
            ->where('business_id', $business->getKey())
            ->sole();
        $ruleBefore = DB::table('capital_rule_drafts')
            ->where('business_id', $business->getKey())
            ->sole();

        $protectedTables = [
            'capital_scenarios',
            'capital_plan_promotions',
            'formal_record_versions',
            'proposals',
            'decisions',
            'record_family_effective_heads',
            'contributions',
            'ownership_scenarios',
            'ownership_registers',
        ];
        $before = [];

        foreach ($protectedTables as $table) {
            $before[$table] = DB::table($table)->count();
        }

        $initialized = $this->refreshComparison()->execute(
            $user,
            $business,
            0,
        );

        self::assertNotNull($initialized);
        self::assertSame(1, $initialized['revision']);
        self::assertSame(1, $initialized['capitalPlanningRevision']);
        self::assertSame(
            ['lean', 'base', 'growth'],
            array_keys($initialized['input']['scenarios']),
        );

        foreach (['lean', 'base', 'growth'] as $key) {
            self::assertSame(
                $canonical,
                $initialized['input']['scenarios'][$key],
            );
        }

        self::assertNull($initialized['input']['preferredPlan']);
        self::assertFalse($initialized['semantics']['approvedTruth']);
        self::assertFalse($initialized['semantics']['signedTruth']);
        self::assertFalse($initialized['semantics']['effectiveTruth']);
        self::assertFalse($initialized['semantics']['contributionTruth']);
        self::assertFalse($initialized['semantics']['equityTruth']);
        self::assertFalse($initialized['semantics']['ownershipTruth']);

        $input = $initialized['input'];
        $input['scenarios']['lean']['workingCapital']['amount'] = '800.00';
        $input['scenarios']['lean']['contingency'] = [
            'method' => 'fixed_amount',
            'amount' => '0.00',
        ];
        $input['scenarios']['growth']['workingCapital']['amount'] = '2000.00';
        $input['scenarios']['growth']['contingency']['percentage'] = '20.00';

        $saved = $this->saveComparison()->execute(
            $user,
            $business,
            1,
            $input,
        );

        self::assertNotNull($saved);
        self::assertSame(2, $saved['revision']);
        self::assertSame(1, $saved['capitalPlanningRevision']);

        $model = $this->readModel($user, $business);

        self::assertNotNull($model);
        self::assertSame('capital-comparison-draft-v1', $model['draftContractVersion']);
        self::assertSame('capital-calculation-v1', $model['calculationContractVersion']);
        self::assertSame(
            ['lean', 'base', 'growth'],
            array_column($model['scenarios'], 'scenarioKey'),
        );
        self::assertTrue($model['ready']);
        self::assertSame('ready_for_comparison', $model['status']);

        $lean = $model['scenarios'][0];
        $base = $model['scenarios'][1];
        $growth = $model['scenarios'][2];

        self::assertSame('1100.00', $lean['totalCapitalRequirement']);
        self::assertSame('800.00', $lean['workingCapital']);
        self::assertSame('0.00', $lean['contingencyAmount']);
        self::assertSame('500.00', $lean['confirmedFunding']);
        self::assertSame('600.00', $lean['fundingGap']);
        self::assertFalse($lean['workingCapitalMonthsApplicable']);
        self::assertNull($lean['workingCapitalMonths']);
        self::assertFalse($lean['contingencyPercentageApplicable']);
        self::assertNull($lean['contingencyPercentage']);

        self::assertSame('1430.00', $base['totalCapitalRequirement']);
        self::assertSame('1000.00', $base['workingCapital']);
        self::assertSame('130.00', $base['contingencyAmount']);
        self::assertSame('10.00', $base['contingencyPercentage']);
        self::assertSame('930.00', $base['fundingGap']);

        self::assertSame('2760.00', $growth['totalCapitalRequirement']);
        self::assertSame('2000.00', $growth['workingCapital']);
        self::assertSame('460.00', $growth['contingencyAmount']);
        self::assertSame('20.00', $growth['contingencyPercentage']);
        self::assertSame('2260.00', $growth['fundingGap']);

        $canonicalAfter = DB::table('capital_planning_drafts')
            ->where('business_id', $business->getKey())
            ->sole();
        $ruleAfter = DB::table('capital_rule_drafts')
            ->where('business_id', $business->getKey())
            ->sole();

        self::assertSame(
            (string) $canonicalBefore->input_payload,
            (string) $canonicalAfter->input_payload,
        );
        self::assertSame(
            (int) $canonicalBefore->revision,
            (int) $canonicalAfter->revision,
        );
        self::assertSame(
            (string) $ruleBefore->input_payload,
            (string) $ruleAfter->input_payload,
        );
        self::assertSame(
            (int) $ruleBefore->revision,
            (int) $ruleAfter->revision,
        );

        foreach ($before as $table => $count) {
            self::assertSame(
                $count,
                DB::table($table)->count(),
                "Unexpected comparison write to {$table}.",
            );
        }

        $this->assertDatabaseHas('audit_events', [
            'business_id' => $business->getKey(),
            'action' => 'capital.comparison.created',
            'target_type' => 'capital_comparison_draft',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'business_id' => $business->getKey(),
            'action' => 'capital.comparison.updated',
            'target_type' => 'capital_comparison_draft',
        ]);

        $comparisonAudit = DB::table('audit_events')
            ->where('business_id', $business->getKey())
            ->where('action', 'like', 'capital.comparison.%')
            ->pluck('metadata')
            ->map(static fn (mixed $value): string => (string) $value)
            ->implode("\n");

        self::assertStringNotContainsString('2760.00', $comparisonAudit);
        self::assertStringNotContainsString('2260.00', $comparisonAudit);
    }

    public function test_partial_zero_revision_stale_and_upstream_review_semantics_are_safe(): void
    {
        [$user, $business] = $this->business(
            'capital-comparison-state@example.test',
            'Capital Comparison State',
        );

        $zero = [
            'openingDate' => null,
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
        ];

        $this->savePlanning($user, $business, 0, $zero);

        $first = $this->refreshComparison()->execute(
            $user,
            $business,
            0,
        );

        self::assertNotNull($first);
        self::assertSame(1, $first['revision']);

        $partial = $first['input'];
        $partial['scenarios']['base'] = null;
        $partial['scenarios']['growth']['workingCapital'] = null;
        $partial['preferredPlan'] = 'lean';

        $second = $this->saveComparison()->execute(
            $user,
            $business,
            1,
            $partial,
        );

        self::assertNotNull($second);
        self::assertSame(2, $second['revision']);

        $partialModel = $this->readModel($user, $business);

        self::assertNotNull($partialModel);
        self::assertFalse($partialModel['ready']);
        self::assertSame('incomplete', $partialModel['status']);
        self::assertSame('0.00', $partialModel['scenarios'][0]['totalCapitalRequirement']);
        self::assertSame('0.00', $partialModel['scenarios'][0]['fundingGap']);
        self::assertFalse($partialModel['scenarios'][0]['workingCapitalMonthsApplicable']);
        self::assertNull($partialModel['scenarios'][0]['workingCapitalMonths']);
        self::assertFalse($partialModel['scenarios'][0]['contingencyPercentageApplicable']);
        self::assertNull($partialModel['scenarios'][0]['contingencyPercentage']);

        self::assertSame('not_started', $partialModel['scenarios'][1]['readiness']);
        self::assertNull($partialModel['scenarios'][1]['totalCapitalRequirement']);
        self::assertSame('incomplete', $partialModel['scenarios'][2]['readiness']);
        self::assertNull($partialModel['scenarios'][2]['totalCapitalRequirement']);
        self::assertSame('lean', $partialModel['preferredPlan']);
        self::assertFalse($partialModel['preferredReadyForNextStage']);

        $complete = $second['input'];

        foreach (['base', 'growth'] as $key) {
            $complete['scenarios'][$key] = $zero;
        }

        $complete['preferredPlan'] = 'base';

        $third = $this->saveComparison()->execute(
            $user,
            $business,
            2,
            $complete,
        );

        self::assertNotNull($third);
        self::assertSame(3, $third['revision']);

        $ready = $this->readModel($user, $business);

        self::assertNotNull($ready);
        self::assertTrue($ready['ready']);
        self::assertTrue($ready['preferredReadyForNextStage']);
        self::assertSame('base', $ready['preferredPlan']);
        self::assertFalse($ready['semantics']['approvedTruth']);
        self::assertFalse($ready['semantics']['signedTruth']);
        self::assertFalse($ready['semantics']['effectiveTruth']);
        self::assertFalse($ready['semantics']['fundingCommitmentTruth']);
        self::assertFalse($ready['semantics']['contributionTruth']);
        self::assertFalse($ready['semantics']['acceptedContributionTruth']);
        self::assertFalse($ready['semantics']['equityTruth']);
        self::assertFalse($ready['semantics']['ownershipTruth']);

        try {
            $this->saveComparison()->execute(
                $user,
                $business,
                2,
                $complete,
            );

            self::fail('Expected stale comparison revision to fail.');
        } catch (StaleRevision $exception) {
            self::assertSame(2, $exception->expectedRevision);
            self::assertSame(3, $exception->actualRevision);
        }

        self::assertSame(
            3,
            (int) DB::table('capital_comparison_drafts')
                ->where('business_id', $business->getKey())
                ->value('revision'),
        );

        $changedCanonical = $zero;
        $changedCanonical['workingCapital']['amount'] = '100.00';

        $this->savePlanning(
            $user,
            $business,
            1,
            $changedCanonical,
        );

        $stale = $this->readModel($user, $business);

        self::assertNotNull($stale);
        self::assertTrue($stale['needsReview']);
        self::assertSame('needs_review', $stale['status']);
        self::assertSame(1, $stale['preparedAgainstCapitalRevision']);
        self::assertSame(2, $stale['capitalPlanningRevision']);
        self::assertFalse($stale['preferredReadyForNextStage']);

        $refreshed = $this->refreshComparison()->execute(
            $user,
            $business,
            3,
        );

        self::assertNotNull($refreshed);
        self::assertSame(4, $refreshed['revision']);
        self::assertSame(2, $refreshed['capitalPlanningRevision']);
        self::assertNull($refreshed['input']['preferredPlan']);

        foreach (['lean', 'base', 'growth'] as $key) {
            self::assertSame(
                '100.00',
                $refreshed['input']['scenarios'][$key]['workingCapital']['amount'],
            );
        }

        $current = $this->readModel($user, $business);

        self::assertNotNull($current);
        self::assertFalse($current['needsReview']);
        self::assertSame('ready_for_comparison', $current['status']);
        self::assertFalse($current['preferredReadyForNextStage']);

        $this->assertDatabaseHas('audit_events', [
            'business_id' => $business->getKey(),
            'action' => 'capital.comparison.preferred_selected',
            'target_type' => 'capital_comparison_draft',
        ]);
    }

    public function test_capital_view_can_read_manage_can_write_and_business_model_values_do_not_leak(): void
    {
        [$owner, $business] = $this->business(
            'capital-comparison-owner@example.test',
            'Capital Comparison Private',
        );
        [, $otherBusiness] = $this->business(
            'capital-comparison-other-owner@example.test',
            'Capital Comparison Other',
        );

        $this->app->make(BusinessModelPlanning::class)
            ->saveOperatingProfile(
                $owner,
                $business,
                0,
                $this->operatingProfile(),
            );

        $this->savePlanning(
            $owner,
            $business,
            0,
            [
                'openingDate' => null,
                'preOpeningItems' => [],
                'initialAssetsInventoryItems' => [],
                'workingCapital' => [
                    'method' => 'canonical_operating_profile',
                    'months' => 3,
                ],
                'contingency' => [
                    'method' => 'fixed_amount',
                    'amount' => '0.00',
                ],
                'confirmedFunding' => '0.00',
            ],
        );

        $this->refreshComparison()->execute(
            $owner,
            $business,
            0,
        );

        $viewer = $this->activeUser(
            'capital-comparison-viewer@example.test',
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

        $draft = $this->comparisonReader()->execute(
            $viewer,
            $business,
        );

        self::assertNotNull($draft);
        self::assertSame(
            'canonical_operating_profile',
            $draft['input']['scenarios']['base']['workingCapital']['method'],
        );

        $model = $this->readModel($viewer, $business);

        self::assertNotNull($model);
        self::assertFalse($model['ready']);
        self::assertSame('incomplete', $model['status']);

        foreach ($model['scenarios'] as $scenario) {
            self::assertNull($scenario['workingCapital']);
            self::assertSame('incomplete', $scenario['readiness']);
            self::assertSame(
                'unavailable',
                $scenario['calculation']['canonicalReuse'][
                    'monthlyOperatingCost'
                ]['status'],
            );
            self::assertNull(
                $scenario['calculation']['canonicalReuse'][
                    'monthlyOperatingCost'
                ]['value'],
            );
        }

        self::assertNull(
            $this->refreshComparison()->execute(
                $viewer,
                $business,
                1,
            ),
        );
        self::assertNull(
            $this->saveComparison()->execute(
                $viewer,
                $business,
                1,
                $draft['input'],
            ),
        );

        $outsider = $this->activeUser(
            'capital-comparison-outsider@example.test',
        );

        self::assertNull(
            $this->comparisonReader()->execute(
                $outsider,
                $business,
            ),
        );
        self::assertNull(
            $this->readModel($outsider, $business),
        );
        self::assertNull(
            $this->refreshComparison()->execute(
                $outsider,
                $business,
                1,
            ),
        );

        self::assertSame(
            0,
            DB::table('capital_comparison_drafts')
                ->where('business_id', $otherBusiness->getKey())
                ->count(),
        );
    }

    public function test_http_routes_expose_comparison_without_creating_formal_truth(): void
    {
        $this->withoutVite();

        [$user, $business] = $this->business(
            'capital-comparison-http@example.test',
            'Capital Comparison HTTP',
        );

        $this->savePlanning(
            $user,
            $business,
            0,
            $this->planningInput(
                workingCapital: '1200.00',
                funding: '600.00',
            ),
        );

        $session = [
            EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
        ];

        $this->actingAs($user)
            ->withSession($session)
            ->post('/formation/capital/comparison-draft/refresh', [
                'expected_revision' => 0,
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->withSession($session)
            ->get('/formation')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->where(
                        'formation.capital.comparison_draft.revision',
                        1,
                    )
                    ->where(
                        'formation.capital.comparison_read_model.status',
                        'ready_for_comparison',
                    )
                    ->where(
                        'formation.capital.comparison_read_model.scenarios.0.scenarioKey',
                        'lean',
                    )
                    ->where(
                        'formation.capital.comparison_read_model.scenarios.1.scenarioKey',
                        'base',
                    )
                    ->where(
                        'formation.capital.comparison_read_model.scenarios.2.scenarioKey',
                        'growth',
                    ),
            );

        $draft = $this->comparisonReader()->execute(
            $user,
            $business,
        );

        self::assertNotNull($draft);
        $input = $draft['input'];
        $input['preferredPlan'] = 'base';

        $this->actingAs($user)
            ->withSession($session)
            ->put('/formation/capital/comparison-draft', [
                'expected_revision' => 1,
                'input' => $input,
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('formal_record_versions', 0);
        $this->assertDatabaseCount('proposals', 0);
        $this->assertDatabaseCount('decisions', 0);
        $this->assertDatabaseCount('record_family_effective_heads', 0);
        $this->assertDatabaseCount('contributions', 0);
        $this->assertDatabaseCount('ownership_registers', 0);
    }

    private function refreshComparison(): RefreshCapitalComparisonDraftFromCanonical
    {
        return $this->app->make(
            RefreshCapitalComparisonDraftFromCanonical::class,
        );
    }

    private function saveComparison(): SaveCapitalComparisonDraft
    {
        return $this->app->make(SaveCapitalComparisonDraft::class);
    }

    private function comparisonReader(): GetCapitalComparisonDraft
    {
        return $this->app->make(GetCapitalComparisonDraft::class);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readModel(
        User $user,
        Business $business,
    ): ?array {
        return $this->app
            ->make(GetCapitalComparisonReadModel::class)
            ->execute($user, $business);
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
            'openingDate' => '2026-12-01',
            'preOpeningItems' => [
                [
                    'category' => 'registration_legal',
                    'label' => 'Registration',
                    'amount' => '100.00',
                ],
            ],
            'initialAssetsInventoryItems' => [
                [
                    'category' => 'equipment',
                    'label' => 'Equipment',
                    'amount' => '200.00',
                ],
            ],
            'workingCapital' => [
                'method' => 'fixed_amount',
                'amount' => $workingCapital,
            ],
            'contingency' => [
                'method' => 'percentage',
                'percentage' => '10.00',
            ],
            'confirmedFunding' => $funding,
        ];
    }

    /**
     * @return array<string,?string>
     */
    private function operatingProfile(): array
    {
        return [
            'business_purpose' => 'Private comparison source.',
            'market' => 'SME market.',
            'location' => 'Myanmar and Thailand',
            'competition_alternatives' => 'Manual spreadsheets.',
            'operating_model' => 'Guided service.',
            'excluded_activities' => 'No success guarantee.',
            'pricing_notes' => 'Current assumptions.',
            'unit_name' => 'service unit',
            'average_selling_price' => '100.00',
            'variable_cost_per_unit' => '40.00',
            'monthly_fixed_cost' => '3000.00',
            'expected_monthly_units' => '80.00',
            'scalability_strategy' => 'Standardize delivery.',
            'scalability_constraints' => 'Advisor capacity.',
            'first_12_month_plan' => 'Launch and iterate.',
        ];
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
