<?php

declare(strict_types=1);

namespace Tests\Feature\Formation;

use App\Application\Businesses\CreateBusiness;
use App\Application\Formation\BusinessModelPlanning;
use App\Application\Formation\SaveCapitalPlanningDraft;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Identity\Enums\AccountStatus;
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

final class CapitalGuidedCalculateUxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('session.driver', 'array');
    }

    public function test_authorized_user_opens_true_missing_guided_capital_state_without_fake_zero(): void
    {
        $this->withoutVite();

        [$user, $business] = $this->business(
            'capital-guided-missing@example.test',
            'Capital Guided Missing',
        );

        $this->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
            ])
            ->get('/formation')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->component('Formation/Index')
                    ->where(
                        'formation.capital.planning_draft.revision',
                        0,
                    )
                    ->where(
                        'formation.capital.planning_draft.input',
                        null,
                    )
                    ->where(
                        'formation.capital.planning_calculation.revision',
                        0,
                    )
                    ->where(
                        'formation.capital.planning_calculation.calculation',
                        null,
                    )
                    ->where(
                        'formation.capital.planning_calculation.reasonCode',
                        'capital_planning_draft_not_started',
                    )
                    ->where(
                        'formation.permissions.can_manage_capital',
                        true,
                    ),
            );
    }

    public function test_guided_save_route_persists_input_and_refreshes_server_derived_calculation(): void
    {
        $this->withoutVite();

        [$user, $business] = $this->business(
            'capital-guided-save@example.test',
            'Capital Guided Save',
        );

        $session = [
            EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
        ];

        $this->actingAs($user)
            ->withSession($session)
            ->put('/formation/capital/planning-draft', [
                'expected_revision' => 0,
                'input' => [
                    'openingDate' => '2026-12-01',
                    'preOpeningItems' => [],
                    'initialAssetsInventoryItems' => [
                        [
                            'category' => 'equipment',
                            'label' => 'Equipment',
                            'amount' => '2000.00',
                        ],
                    ],
                    'workingCapital' => [
                        'method' => 'fixed_amount',
                        'amount' => '3000.00',
                    ],
                    'contingency' => [
                        'method' => 'percentage',
                        'percentage' => '10.00',
                    ],
                    'confirmedFunding' => '2500.00',
                ],
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->withSession($session)
            ->get('/formation')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->where(
                        'formation.capital.planning_draft.revision',
                        1,
                    )
                    ->where(
                        'formation.capital.planning_draft.input.preOpeningItems',
                        [],
                    )
                    ->where(
                        'formation.capital.planning_draft.input.initialAssetsInventoryItems.0.label',
                        'Equipment',
                    )
                    ->where(
                        'formation.capital.planning_calculation.calculation.preOpening.subtotal',
                        '0.00',
                    )
                    ->where(
                        'formation.capital.planning_calculation.calculation.initialAssetsInventory.subtotal',
                        '2000.00',
                    )
                    ->where(
                        'formation.capital.planning_calculation.calculation.workingCapital.amount',
                        '3000.00',
                    )
                    ->where(
                        'formation.capital.planning_calculation.calculation.contingency.amount',
                        '500.00',
                    )
                    ->where(
                        'formation.capital.planning_calculation.calculation.totalCapitalRequirement.amount',
                        '5500.00',
                    )
                    ->where(
                        'formation.capital.planning_calculation.calculation.fundingPosition.fundingGap',
                        '3000.00',
                    )
                    ->where(
                        'formation.capital.planning_calculation.calculation.fundingPosition.fundedPercentage',
                        '45.45',
                    ),
            );

        $this->assertDatabaseCount('capital_scenarios', 0);
        $this->assertDatabaseCount('capital_plan_promotions', 0);
        $this->assertDatabaseCount('formal_record_versions', 0);
        $this->assertDatabaseCount('contributions', 0);
        $this->assertDatabaseCount('ownership_registers', 0);
    }

    public function test_stale_guided_save_returns_human_error_and_does_not_silently_overwrite(): void
    {
        [$user, $business] = $this->business(
            'capital-guided-stale@example.test',
            'Capital Guided Stale',
        );

        $session = [
            EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
        ];

        $this->app->make(SaveCapitalPlanningDraft::class)
            ->execute(
                $user,
                $business,
                0,
                $this->fixedDraft('100.00'),
            );

        $this->app->make(SaveCapitalPlanningDraft::class)
            ->execute(
                $user,
                $business,
                1,
                $this->fixedDraft('250.00'),
            );

        $this->actingAs($user)
            ->withSession($session)
            ->put('/formation/capital/planning-draft', [
                'expected_revision' => 1,
                'input' => $this->fixedDraft('999.00'),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors([
                'capital_draft',
            ]);

        $row = DB::table('capital_planning_drafts')
            ->where('business_id', $business->getKey())
            ->sole();

        self::assertSame(2, (int) $row->revision);

        $payload = json_decode(
            (string) $row->input_payload,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            '250.00',
            $payload['workingCapital']['amount'],
        );
    }

    public function test_read_only_capital_viewer_can_inspect_but_cannot_save(): void
    {
        $this->withoutVite();

        [$owner, $business] = $this->business(
            'capital-guided-owner@example.test',
            'Capital Guided Read Only',
        );

        $this->app->make(SaveCapitalPlanningDraft::class)
            ->execute(
                $owner,
                $business,
                0,
                $this->fixedDraft('500.00'),
            );

        $viewer = $this->activeUser(
            'capital-guided-viewer@example.test',
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
            $this->grant($business, $membership, $capability);
        }

        $session = [
            EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
        ];

        $this->actingAs($viewer)
            ->withSession($session)
            ->get('/formation')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->where(
                        'formation.permissions.can_manage_capital',
                        false,
                    )
                    ->where(
                        'formation.capital.planning_draft.revision',
                        1,
                    )
                    ->where(
                        'formation.capital.planning_calculation.calculation.workingCapital.amount',
                        '500.00',
                    ),
            );

        $this->actingAs($viewer)
            ->withSession($session)
            ->put('/formation/capital/planning-draft', [
                'expected_revision' => 1,
                'input' => $this->fixedDraft('900.00'),
            ])
            ->assertNotFound();

        $row = DB::table('capital_planning_drafts')
            ->where('business_id', $business->getKey())
            ->sole();

        $payload = json_decode(
            (string) $row->input_payload,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            '500.00',
            $payload['workingCapital']['amount'],
        );
    }

    public function test_canonical_business_model_values_do_not_leak_without_business_model_view(): void
    {
        $this->withoutVite();

        [$owner, $business] = $this->business(
            'capital-guided-canonical-owner@example.test',
            'Capital Canonical Privacy',
        );

        $this->app->make(BusinessModelPlanning::class)
            ->saveOperatingProfile(
                $owner,
                $business,
                0,
                $this->operatingProfile(),
            );

        $this->app->make(SaveCapitalPlanningDraft::class)
            ->execute(
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
            'capital-guided-canonical-viewer@example.test',
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
            $this->grant($business, $membership, $capability);
        }

        $response = $this->actingAs($viewer)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
            ])
            ->get('/formation');

        $response
            ->assertOk()
            ->assertDontSee('6200.00')
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->where(
                        'formation.business_model_foundation',
                        null,
                    )
                    ->where(
                        'formation.capital.planning_calculation.calculation.canonicalReuse.monthlyOperatingCost.status',
                        'unavailable',
                    )
                    ->where(
                        'formation.capital.planning_calculation.calculation.canonicalReuse.monthlyOperatingCost.value',
                        null,
                    ),
            );
    }

    public function test_cross_tenant_capital_route_fails_closed(): void
    {
        [$owner, $businessA] = $this->business(
            'capital-guided-tenant-a@example.test',
            'Capital Guided Tenant A',
        );
        [$otherOwner, $businessB] = $this->business(
            'capital-guided-tenant-b@example.test',
            'Capital Guided Tenant B',
        );

        $this->app->make(SaveCapitalPlanningDraft::class)
            ->execute(
                $owner,
                $businessA,
                0,
                $this->fixedDraft('400.00'),
            );

        $this->actingAs($otherOwner)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => (string) $businessA->getKey(),
            ])
            ->put('/formation/capital/planning-draft', [
                'expected_revision' => 1,
                'input' => $this->fixedDraft('999.00'),
            ])
            ->assertForbidden();

        self::assertSame(
            0,
            DB::table('capital_planning_drafts')
                ->where('business_id', $businessB->getKey())
                ->count(),
        );

        $row = DB::table('capital_planning_drafts')
            ->where('business_id', $businessA->getKey())
            ->sole();

        $payload = json_decode(
            (string) $row->input_payload,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            '400.00',
            $payload['workingCapital']['amount'],
        );
    }

    public function test_normal_capital_source_uses_guided_steps_one_to_six_and_excludes_legacy_primary_controls(): void
    {
        $formation = $this->source(
            'resources/js/pages/Formation/Index.vue',
        );
        $guided = $this->source(
            'resources/js/components/capital/CapitalGuidedJourney.vue',
        );
        $rule = $this->source(
            'resources/js/components/capital/CapitalRuleAllocationStep.vue',
        );
        $comparison = $this->source(
            'resources/js/components/capital/CapitalPlanComparison.vue',
        );
        $approval = $this->source(
            'resources/js/components/capital/CapitalApprovalStage.vue',
        );

        self::assertStringContainsString(
            '<CapitalGuidedJourney',
            $formation,
        );

        foreach ([
            'scenarioKinds',
            'selectedCapitalKind',
            'capitalDraftPosition',
            'formation.capital.promotions',
        ] as $legacyPrimary) {
            self::assertStringNotContainsString(
                $legacyPrimary,
                $formation,
            );
        }

        foreach ([
            'Startup Cost Plan',
            'Initial Assets & Opening Inventory',
            'Working Capital Forecast',
            'Contingency Reserve',
            'Funding Position & Gap',
            'Capital Rule & Allocation',
            'capital-guided-journey',
            '/formation/capital/planning-draft',
            'Use existing Business Model numbers',
            'Not entered yet',
            'There are no Startup Costs / zero',
        ] as $guidedContract) {
            self::assertStringContainsString(
                $guidedContract,
                $guided,
            );
        }

        foreach ([
            'CapitalPlanComparison',
            ':draft="comparisonDraft"',
            ':read-model="comparisonReadModel"',
        ] as $comparisonIntegration) {
            self::assertStringContainsString(
                $comparisonIntegration,
                $guided,
            );
        }

        foreach ([
            'Capital Plan Comparison',
            'Lean',
            'Base',
            'Growth',
            '/formation/capital/comparison-draft',
            '/formation/capital/comparison-draft/refresh',
            'Planning comparison only.',
            'Preferred Plan is not Approval',
            'capital-plan-comparison',
        ] as $comparisonContract) {
            self::assertStringContainsString(
                $comparisonContract,
                $comparison,
            );
        }

        foreach ([
            'Promote to frozen Proposal',
            'capital_plan_promotions',
            'approvedTruth =',
            'totalCapitalRequirement =',
            'fundingGap =',
        ] as $forbiddenComparisonUx) {
            self::assertStringNotContainsString(
                $forbiddenComparisonUx,
                $comparison,
            );
        }

        foreach ([
            'CapitalApprovalStage',
            ':read-model="approvalReadModel"',
            ':can-manage-records="canManageRecords"',
            ':can-manage-governance="canManageGovernance"',
        ] as $approvalIntegration) {
            self::assertStringContainsString(
                $approvalIntegration,
                $guided,
            );
        }

        foreach ([
            'Capital Approval',
            'Final Plan for Approval',
            '/formation/capital/approval/prepare',
            '/formation/capital/approval/review',
            '/formation/capital/approval/open',
            '/formation/capital/approval/approve',
            '/formation/capital/approval/vote',
            '/formation/capital/approval/resolve',
            'Approval is not Signature',
            'Approved, but not Signed and not Effective.',
            'capital-approval-stage',
        ] as $approvalContract) {
            self::assertStringContainsString(
                $approvalContract,
                $approval,
            );
        }

        foreach ([
            'totalCapitalRequirement +',
            'fundingGap =',
            'confirmedFunding -',
            'record_family_effective_heads',
            'SignatureRequest',
            'proposal_version_id',
            'formal_record_version_id',
            'authority_snapshot_id',
        ] as $forbiddenApprovalUx) {
            self::assertStringNotContainsString(
                $forbiddenApprovalUx,
                $approval,
            );
        }

        foreach ([
            '/formation/capital/rule-draft',
            'Capital Allocation Summary',
            'Reduce the startup scope',
            'Delay the launch or selected spending',
            'Consider borrowing',
            'Consider a Capital Call later',
            'This is a planning rule only.',
            'capital-rule-step',
        ] as $ruleContract) {
            self::assertStringContainsString(
                $ruleContract,
                $rule,
            );
        }

        self::assertStringNotContainsString(
            'preOpening +',
            $rule,
        );
        self::assertStringNotContainsString(
            'totalCapitalRequirement =',
            $rule,
        );

        self::assertStringNotContainsString(
            'total =',
            $guided,
        );
        self::assertStringNotContainsString(
            'funding *',
            $guided,
        );
    }

    public function test_guided_capital_source_is_multilingual_and_wrap_safe(): void
    {
        $guided = $this->source(
            'resources/js/components/capital/CapitalGuidedJourney.vue',
        );
        $rule = $this->source(
            'resources/js/components/capital/CapitalRuleAllocationStep.vue',
        );
        $comparison = $this->source(
            'resources/js/components/capital/CapitalPlanComparison.vue',
        );
        $approval = $this->source(
            'resources/js/components/capital/CapitalApprovalStage.vue',
        );

        foreach ([
            'uiLanguageMode',
            'ဒီလုပ်ငန်းစဖို့ Capital ဘယ်လောက်လိုမလဲ',
            'ဒီ Business စဖို့ Capital ဘယ်လောက်လိုမလဲ',
            'pbr-safe-copy',
            'min-w-0',
            'flex-wrap',
            'sm:grid-cols-2',
        ] as $contract) {
            self::assertStringContainsString(
                $contract,
                $guided,
            );
        }

        foreach ([
            'Capital Rule & Allocation ကို သတ်မှတ်ပါ',
            'Capital Rule & Allocation ကို set လုပ်ပါ',
            'pbr-safe-copy',
            'min-w-0',
            'flex-wrap',
            'sm:grid-cols-2',
        ] as $ruleContract) {
            self::assertStringContainsString(
                $ruleContract,
                $rule,
            );
        }

        foreach ([
            'uiLanguageMode',
            'Capital Approval မတိုင်မီ Lean, Base, Growth ကို နှိုင်းယှဉ်ပါ',
            'Capital Approval မတိုင်မီ Lean / Base / Growth ကို compare လုပ်ပါ',
            'pbr-safe-copy',
            'min-w-0',
            'flex-wrap',
            'sm:grid-cols-3',
            'xl:grid-cols-3',
        ] as $comparisonContract) {
            self::assertStringContainsString(
                $comparisonContract,
                $comparison,
            );
        }

        self::assertStringNotContainsString(
            'w-[320px]',
            $comparison,
        );
        self::assertStringNotContainsString(
            'reasonCode',
            $comparison,
        );

        foreach ([
            'uiLanguageMode',
            'Approval အတွက် နောက်ဆုံး Capital Plan',
            'Approval != Signature != Effective',
            'pbr-safe-copy',
            'min-w-0',
            'flex-wrap',
            'sm:grid-cols-2',
            'Capital Approval လုပ်မယ့် Authority ကို မသတ်မှတ်ရသေးပါ',
        ] as $approvalContract) {
            self::assertStringContainsString(
                $approvalContract,
                $approval,
            );
        }

        self::assertStringNotContainsString(
            'w-[320px]',
            $approval,
        );
        self::assertStringNotContainsString(
            'reasonCode',
            $approval,
        );

        self::assertStringNotContainsString(
            'w-[320px]',
            $guided,
        );
        self::assertStringNotContainsString(
            '{{ calculation.reasonCode }}',
            $guided,
        );
        self::assertStringNotContainsString(
            '{{ draft.contractVersion }}',
            $guided,
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
            'business_purpose' => 'Capital guided source business.',
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

    private function source(string $path): string
    {
        $source = file_get_contents(base_path($path));

        self::assertIsString($source);

        return $source;
    }
}
