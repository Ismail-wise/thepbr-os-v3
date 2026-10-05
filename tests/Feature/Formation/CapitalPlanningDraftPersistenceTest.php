<?php

declare(strict_types=1);

namespace Tests\Feature\Formation;

use App\Application\Businesses\CreateBusiness;
use App\Application\Formation\BusinessModelPlanning;
use App\Application\Formation\GetCapitalPlanningDraft;
use App\Application\Formation\GetCapitalPlanningDraftCalculation;
use App\Application\Formation\SaveCapitalPlanningDraft;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Capital\CapitalPlanningDraftContract;
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

final class CapitalPlanningDraftPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_scoped_draft_round_trips_detailed_input_and_emits_occurrences(): void
    {
        [$user, $business] = $this->business(
            'capital-draft@example.test',
            'Capital Draft',
        );

        $saved = $this->save()->execute(
            $user,
            $business,
            0,
            [
                'openingDate' => '2026-12-01',
                'preOpeningItems' => [
                    [
                        'category' => 'registration_legal',
                        'label' => 'Registration',
                        'amount' => null,
                    ],
                    [
                        'category' => 'deposit',
                        'label' => 'Premises deposit',
                        'amount' => '0.00',
                    ],
                    [
                        'category' => 'launch_marketing',
                        'label' => 'Launch campaign',
                        'amount' => '1250.50',
                    ],
                ],
                'initialAssetsInventoryItems' => [
                    [
                        'category' => 'equipment',
                        'label' => 'Production equipment',
                        'amount' => '8000.00',
                    ],
                    [
                        'category' => 'opening_stock',
                        'label' => 'Opening stock',
                        'amount' => '2200.00',
                    ],
                ],
                'workingCapital' => [
                    'method' => 'monthly_costs',
                    'months' => 3,
                    'items' => [
                        [
                            'category' => 'salary',
                            'label' => 'Salary',
                            'amount' => '3000.00',
                        ],
                        [
                            'category' => 'rent',
                            'label' => 'Rent',
                            'amount' => '1000.00',
                        ],
                        [
                            'category' => 'software',
                            'label' => 'Software',
                            'amount' => '0.00',
                        ],
                    ],
                ],
                'contingency' => [
                    'method' => 'percentage',
                    'percentage' => '10.00',
                ],
                'confirmedFunding' => null,
            ],
        );

        self::assertNotNull($saved);
        self::assertSame(
            CapitalPlanningDraftContract::CONTRACT_VERSION,
            $saved['contractVersion'],
        );
        self::assertSame(1, $saved['revision']);
        self::assertNull(
            $saved['input']['preOpeningItems'][0]['amount'],
        );
        self::assertSame(
            '0.00',
            $saved['input']['preOpeningItems'][1]['amount'],
        );
        self::assertSame(
            '1250.50',
            $saved['input']['preOpeningItems'][2]['amount'],
        );
        self::assertNull($saved['input']['confirmedFunding']);
        self::assertFalse($saved['semantics']['approvedTruth']);
        self::assertFalse($saved['semantics']['ownershipTruth']);

        $read = $this->reader()->execute($user, $business);

        self::assertNotNull($read);
        self::assertSame(1, $read['revision']);
        self::assertSame($saved['input'], $read['input']);
        self::assertSame('USD', $read['baseCurrency']);

        $this->assertDatabaseHas('audit_events', [
            'business_id' => $business->getKey(),
            'action' => 'capital.planning_draft.created',
            'target_type' => 'capital_planning_draft',
        ]);
        $this->assertDatabaseHas('business_events', [
            'business_id' => $business->getKey(),
            'event_type' => 'capital.planning_draft.created',
            'aggregate_type' => 'capital_planning_draft',
        ]);
    }

    public function test_missing_section_and_explicit_zero_section_round_trip_without_collapsing_state(): void
    {
        [$user, $business] = $this->business(
            'capital-section-state@example.test',
            'Capital Section State',
        );

        $missing = $this->save()->execute(
            $user,
            $business,
            0,
            [
                'preOpeningItems' => null,
                'initialAssetsInventoryItems' => null,
                'workingCapital' => null,
                'contingency' => null,
                'confirmedFunding' => null,
            ],
        );

        self::assertNotNull($missing);
        self::assertNull($missing['input']['preOpeningItems']);

        $missingRead = $this->reader()->execute($user, $business);

        self::assertNotNull($missingRead);
        self::assertNull($missingRead['input']['preOpeningItems']);

        $explicitZero = $this->save()->execute(
            $user,
            $business,
            1,
            [
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
            ],
        );

        self::assertNotNull($explicitZero);
        self::assertSame([], $explicitZero['input']['preOpeningItems']);

        $zeroRead = $this->reader()->execute($user, $business);

        self::assertNotNull($zeroRead);
        self::assertSame([], $zeroRead['input']['preOpeningItems']);
        self::assertSame(
            '0.00',
            $zeroRead['input']['workingCapital']['amount'],
        );
        self::assertSame(
            '0.00',
            $zeroRead['input']['confirmedFunding'],
        );

        $calculation = $this->calculation()->execute(
            $user,
            $business,
        );

        self::assertNotNull($calculation);
        self::assertSame(
            '0.00',
            $calculation['calculation']['preOpening']['subtotal'],
        );
        self::assertSame(
            '0.00',
            $calculation['calculation']['totalCapitalRequirement']['amount'],
        );
    }

    public function test_edit_increments_revision_and_stale_revision_cannot_overwrite_current_draft(): void
    {
        [$user, $business] = $this->business(
            'capital-revision@example.test',
            'Capital Revision',
        );

        $first = $this->save()->execute(
            $user,
            $business,
            0,
            $this->fixedDraft('100.00'),
        );

        self::assertNotNull($first);
        self::assertSame(1, $first['revision']);

        $second = $this->save()->execute(
            $user,
            $business,
            1,
            $this->fixedDraft('250.00'),
        );

        self::assertNotNull($second);
        self::assertSame(2, $second['revision']);
        self::assertSame(
            '250.00',
            $second['input']['workingCapital']['amount'],
        );

        try {
            $this->save()->execute(
                $user,
                $business,
                1,
                $this->fixedDraft('999.00'),
            );

            self::fail('Expected stale draft revision to be rejected.');
        } catch (StaleRevision $exception) {
            self::assertSame(1, $exception->expectedRevision);
            self::assertSame(2, $exception->actualRevision);
        }

        $read = $this->reader()->execute($user, $business);

        self::assertNotNull($read);
        self::assertSame(2, $read['revision']);
        self::assertSame(
            '250.00',
            $read['input']['workingCapital']['amount'],
        );
        $this->assertDatabaseCount('capital_planning_drafts', 1);
        $this->assertDatabaseHas('audit_events', [
            'business_id' => $business->getKey(),
            'action' => 'capital.planning_draft.updated',
        ]);
    }

    public function test_all_working_capital_methods_and_contingency_methods_round_trip_without_method_leakage(): void
    {
        [$user, $business] = $this->business(
            'capital-methods@example.test',
            'Capital Methods',
        );

        $drafts = [
            [
                'workingCapital' => [
                    'method' => 'monthly_burn',
                    'months' => 3,
                    'monthlyBurn' => '5000.00',
                ],
                'contingency' => [
                    'method' => 'percentage',
                    'percentage' => '12.50',
                ],
            ],
            [
                'workingCapital' => [
                    'method' => 'monthly_costs',
                    'months' => 2,
                    'items' => [
                        [
                            'category' => 'admin',
                            'label' => 'Admin',
                            'amount' => '400.00',
                        ],
                    ],
                ],
                'contingency' => [
                    'method' => 'fixed_amount',
                    'amount' => '900.00',
                ],
            ],
            [
                'workingCapital' => [
                    'method' => 'fixed_amount',
                    'amount' => '7000.00',
                ],
                'contingency' => null,
            ],
            [
                'workingCapital' => [
                    'method' => 'canonical_operating_profile',
                    'months' => 4,
                ],
                'contingency' => [
                    'method' => 'percentage',
                    'percentage' => null,
                ],
            ],
        ];

        $revision = 0;

        foreach ($drafts as $expected) {
            $saved = $this->save()->execute(
                $user,
                $business,
                $revision,
                [
                    'workingCapital' => $expected['workingCapital'],
                    'contingency' => $expected['contingency'],
                ],
            );

            self::assertNotNull($saved);
            $revision++;
            self::assertSame($revision, $saved['revision']);
            self::assertSame(
                $expected['workingCapital'],
                $saved['input']['workingCapital'],
            );
            self::assertSame(
                $expected['contingency'],
                $saved['input']['contingency'],
            );
        }
    }

    public function test_canonical_operating_profile_is_referenced_not_copied_and_current_economics_are_resolved_each_time(): void
    {
        [$user, $business] = $this->business(
            'capital-canonical@example.test',
            'Capital Canonical Reuse',
        );

        $profile = $this->operatingProfile();

        $this->app->make(BusinessModelPlanning::class)
            ->saveOperatingProfile(
                $user,
                $business,
                0,
                $profile,
            );

        $this->save()->execute(
            $user,
            $business,
            0,
            [
                'preOpeningItems' => [],
                'initialAssetsInventoryItems' => [],
                'workingCapital' => [
                    'method' => 'canonical_operating_profile',
                    'months' => 3,
                ],
                'contingency' => [
                    'method' => 'percentage',
                    'percentage' => '10.00',
                ],
                'confirmedFunding' => '10000.00',
            ],
        );

        $rawPayload = (string) DB::table('capital_planning_drafts')
            ->where('business_id', $business->getKey())
            ->value('input_payload');

        self::assertStringContainsString(
            'canonical_operating_profile',
            $rawPayload,
        );
        self::assertStringNotContainsString('6200.00', $rawPayload);
        self::assertStringNotContainsString('average_selling_price', $rawPayload);
        self::assertStringNotContainsString('monthly_fixed_cost', $rawPayload);

        $first = $this->calculation()->execute($user, $business);

        self::assertNotNull($first);
        self::assertSame(
            '6200.00',
            $first['calculation']['canonicalReuse'][
                'monthlyOperatingCost'
            ]['value'],
        );
        self::assertSame(
            '18600.00',
            $first['calculation']['workingCapital']['amount'],
        );

        $profile['monthly_fixed_cost'] = '4000.00';

        $this->app->make(BusinessModelPlanning::class)
            ->saveOperatingProfile(
                $user,
                $business,
                1,
                $profile,
            );

        $second = $this->calculation()->execute($user, $business);

        self::assertNotNull($second);
        self::assertSame(
            '7200.00',
            $second['calculation']['canonicalReuse'][
                'monthlyOperatingCost'
            ]['value'],
        );
        self::assertSame(
            '21600.00',
            $second['calculation']['workingCapital']['amount'],
        );

        self::assertSame(1, $second['revision']);
    }

    public function test_incomplete_current_canonical_economics_make_calculation_incomplete_instead_of_using_stale_or_zero_value(): void
    {
        [$user, $business] = $this->business(
            'capital-canonical-incomplete@example.test',
            'Capital Canonical Incomplete',
        );

        $profile = $this->operatingProfile();
        $profile['expected_monthly_units'] = null;

        $this->app->make(BusinessModelPlanning::class)
            ->saveOperatingProfile(
                $user,
                $business,
                0,
                $profile,
            );

        $this->save()->execute(
            $user,
            $business,
            0,
            [
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

        $result = $this->calculation()->execute($user, $business);

        self::assertNotNull($result);
        self::assertSame(
            'unavailable',
            $result['calculation']['canonicalReuse'][
                'monthlyOperatingCost'
            ]['status'],
        );
        self::assertSame(
            'canonical_operating_cost_incomplete',
            $result['calculation']['canonicalReuse'][
                'monthlyOperatingCost'
            ]['reasonCode'],
        );
        self::assertSame(
            'incomplete',
            $result['calculation']['workingCapital']['status'],
        );
        self::assertNull(
            $result['calculation']['totalCapitalRequirement']['amount'],
        );
    }

    public function test_confirmed_funding_missing_and_explicit_zero_round_trip_distinctly(): void
    {
        [$user, $business] = $this->business(
            'capital-funding-zero@example.test',
            'Capital Funding Zero',
        );

        $missing = $this->save()->execute(
            $user,
            $business,
            0,
            [
                'confirmedFunding' => null,
            ],
        );

        self::assertNotNull($missing);
        self::assertNull($missing['input']['confirmedFunding']);

        $zero = $this->save()->execute(
            $user,
            $business,
            1,
            [
                'confirmedFunding' => '0',
            ],
        );

        self::assertNotNull($zero);
        self::assertSame(
            '0.00',
            $zero['input']['confirmedFunding'],
        );
    }

    public function test_negative_money_fails_before_any_draft_is_persisted(): void
    {
        [$user, $business] = $this->business(
            'capital-negative@example.test',
            'Capital Negative',
        );

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->save()->execute(
                $user,
                $business,
                0,
                [
                    'preOpeningItems' => [
                        [
                            'category' => 'deposit',
                            'label' => 'Deposit',
                            'amount' => '-1.00',
                        ],
                    ],
                ],
            );
        } finally {
            $this->assertDatabaseCount('capital_planning_drafts', 0);
        }
    }

    public function test_cross_tenant_access_and_missing_capabilities_fail_closed(): void
    {
        [$owner, $businessA] = $this->business(
            'capital-tenant-owner@example.test',
            'Capital Tenant A',
        );
        [$otherOwner, $businessB] = $this->business(
            'capital-tenant-other@example.test',
            'Capital Tenant B',
        );

        $this->save()->execute(
            $owner,
            $businessA,
            0,
            $this->fixedDraft('500.00'),
        );

        self::assertNull(
            $this->reader()->execute($otherOwner, $businessA),
        );
        self::assertNull(
            $this->save()->execute(
                $otherOwner,
                $businessA,
                1,
                $this->fixedDraft('999.00'),
            ),
        );

        $viewer = $this->activeUser(
            'capital-view-only@example.test',
        );
        $viewerMembership = Membership::query()->create([
            'user_id' => $viewer->getKey(),
            'business_id' => $businessA->getKey(),
            'access_status' => 'active',
        ]);
        $this->grant(
            $businessA,
            $viewerMembership,
            CapabilityCatalog::CAPITAL_VIEW,
        );

        self::assertNotNull(
            $this->reader()->execute($viewer, $businessA),
        );
        self::assertNull(
            $this->save()->execute(
                $viewer,
                $businessA,
                1,
                $this->fixedDraft('700.00'),
            ),
        );

        $noView = $this->activeUser(
            'capital-no-view@example.test',
        );
        Membership::query()->create([
            'user_id' => $noView->getKey(),
            'business_id' => $businessA->getKey(),
            'access_status' => 'active',
        ]);

        self::assertNull(
            $this->reader()->execute($noView, $businessA),
        );

        self::assertSame(
            '500.00',
            $this->reader()->execute(
                $owner,
                $businessA,
            )['input']['workingCapital']['amount'],
        );
        self::assertSame(
            0,
            DB::table('capital_planning_drafts')
                ->where('business_id', $businessB->getKey())
                ->count(),
        );
    }

    public function test_canonical_operating_profile_calculation_respects_business_model_view_capability(): void
    {
        [$owner, $business] = $this->business(
            'capital-bm-owner@example.test',
            'Capital BM Boundary',
        );

        $this->app->make(BusinessModelPlanning::class)
            ->saveOperatingProfile(
                $owner,
                $business,
                0,
                $this->operatingProfile(),
            );

        $this->save()->execute(
            $owner,
            $business,
            0,
            [
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

        $viewer = $this->activeUser(
            'capital-no-business-model@example.test',
        );
        $membership = Membership::query()->create([
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
                $membership,
                $capability,
            );
        }

        $result = $this->calculation()->execute(
            $viewer,
            $business,
        );

        self::assertNotNull($result);
        self::assertSame(
            'unavailable',
            $result['calculation']['canonicalReuse'][
                'monthlyOperatingCost'
            ]['status'],
        );
        self::assertSame(
            'business_model_view_not_authorized',
            $result['calculation']['canonicalReuse'][
                'monthlyOperatingCost'
            ]['reasonCode'],
        );
        self::assertNull(
            $result['calculation']['totalCapitalRequirement']['amount'],
        );
    }

    public function test_saving_draft_does_not_write_legacy_scenario_promotion_or_formal_partnership_truth(): void
    {
        [$user, $business] = $this->business(
            'capital-boundary@example.test',
            'Capital Boundary',
        );

        $tables = [
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

        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->count();
        }

        $saved = $this->save()->execute(
            $user,
            $business,
            0,
            $this->fixedDraft('2000.00'),
        );

        self::assertNotNull($saved);
        $this->assertDatabaseCount('capital_planning_drafts', 1);

        foreach ($before as $table => $count) {
            self::assertSame(
                $count,
                DB::table($table)->count(),
                "Unexpected write to {$table}.",
            );
        }
    }

    private function save(): SaveCapitalPlanningDraft
    {
        return $this->app->make(SaveCapitalPlanningDraft::class);
    }

    private function reader(): GetCapitalPlanningDraft
    {
        return $this->app->make(GetCapitalPlanningDraft::class);
    }

    private function calculation(): GetCapitalPlanningDraftCalculation
    {
        return $this->app->make(
            GetCapitalPlanningDraftCalculation::class,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function fixedDraft(string $workingCapital): array
    {
        return [
            'openingDate' => null,
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
            'confirmedFunding' => '0.00',
        ];
    }

    /**
     * @return array<string,?string>
     */
    private function operatingProfile(): array
    {
        return [
            'business_purpose' => 'Capital planning source business.',
            'market' => 'SME market.',
            'location' => 'Myanmar and Thailand',
            'competition_alternatives' => 'Manual spreadsheets.',
            'operating_model' => 'Guided service.',
            'excluded_activities' => 'No success guarantee.',
            'pricing_notes' => 'Current planning assumptions.',
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
