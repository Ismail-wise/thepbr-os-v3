<?php

declare(strict_types=1);

namespace Tests\Feature\Formation;

use App\Application\Businesses\CreateBusiness;
use App\Application\Formation\BusinessModelPlanning;
use App\Application\Formation\CalculateCapitalFoundation;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CapitalCalculationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_foundation_reuses_canonical_operating_economics_for_monthly_burn(): void
    {
        [$user, $business] = $this->business(
            'capital-foundation@example.test',
            'Capital Foundation',
        );

        $this->app->make(BusinessModelPlanning::class)
            ->saveOperatingProfile(
                $user,
                $business,
                0,
                $this->operatingProfile(),
            );

        $result = $this->app
            ->make(CalculateCapitalFoundation::class)
            ->execute(
                $user,
                $business,
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

        self::assertNotNull($result);
        self::assertSame(
            'reused',
            $result['canonicalReuse']['monthlyOperatingCost']['status'],
        );
        self::assertSame(
            'business_model_operating_profile',
            $result['canonicalReuse']['monthlyOperatingCost']['source'],
        );
        self::assertSame(
            '6200.00',
            $result['canonicalReuse']['monthlyOperatingCost']['value'],
        );
        self::assertSame(
            '6200.00',
            $result['workingCapital']['monthlyBurn'],
        );
        self::assertSame(
            '18600.00',
            $result['workingCapital']['amount'],
        );
        self::assertSame(
            '1860.00',
            $result['contingency']['amount'],
        );
        self::assertSame(
            '20460.00',
            $result['totalCapitalRequirement']['amount'],
        );
        self::assertSame(
            '10460.00',
            $result['fundingPosition']['fundingGap'],
        );
        self::assertSame('USD', $result['business']['currency']);
    }

    public function test_canonical_reuse_remains_unavailable_when_operating_evidence_is_incomplete(): void
    {
        [$user, $business] = $this->business(
            'capital-incomplete@example.test',
            'Capital Incomplete',
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

        $result = $this->app
            ->make(CalculateCapitalFoundation::class)
            ->execute(
                $user,
                $business,
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

        self::assertNotNull($result);
        self::assertSame(
            'unavailable',
            $result['canonicalReuse']['monthlyOperatingCost']['status'],
        );
        self::assertSame(
            'canonical_operating_cost_incomplete',
            $result['canonicalReuse']['monthlyOperatingCost']['reasonCode'],
        );
        self::assertSame(
            'incomplete',
            $result['workingCapital']['status'],
        );
        self::assertNull(
            $result['totalCapitalRequirement']['amount'],
        );
        self::assertNull(
            $result['fundingPosition']['fundingGap'],
        );
    }

    public function test_same_business_member_without_capabilities_fails_closed(): void
    {
        [$owner, $business] = $this->business(
            'capital-capability-owner@example.test',
            'Capital Capability Boundary',
        );
        $viewer = $this->activeUser(
            'capital-capability-viewer@example.test',
        );

        Membership::query()->create([
            'user_id' => $viewer->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => MembershipAccessStatus::Active,
        ]);

        $result = $this->app
            ->make(CalculateCapitalFoundation::class)
            ->execute(
                $viewer,
                $business,
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

        self::assertNull($result);
        self::assertNotSame(
            $owner->getKey(),
            $viewer->getKey(),
        );
    }

    public function test_cross_business_user_fails_closed(): void
    {
        [$owner, $business] = $this->business(
            'capital-owner@example.test',
            'Capital Protected',
        );
        $outsider = $this->activeUser(
            'capital-outsider@example.test',
        );

        $result = $this->app
            ->make(CalculateCapitalFoundation::class)
            ->execute(
                $outsider,
                $business,
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

        self::assertNull($result);
        self::assertNotSame(
            $owner->getKey(),
            $outsider->getKey(),
        );
    }

    public function test_calculation_only_foundation_creates_no_capital_contribution_equity_or_ownership_truth(): void
    {
        [$user, $business] = $this->business(
            'capital-no-truth@example.test',
            'Capital No Formal Truth',
        );

        $before = [
            'capital_scenarios' => $this->tableCount('capital_scenarios'),
            'capital_plan_promotions' => $this->tableCount(
                'capital_plan_promotions',
            ),
            'contributions' => $this->tableCount('contributions'),
            'ownership_scenarios' => $this->tableCount(
                'ownership_scenarios',
            ),
            'ownership_registers' => $this->tableCount(
                'ownership_registers',
            ),
            'formal_record_versions' => $this->tableCount(
                'formal_record_versions',
            ),
        ];

        $result = $this->app
            ->make(CalculateCapitalFoundation::class)
            ->execute(
                $user,
                $business,
                [
                    'preOpeningItems' => [
                        [
                            'category' => 'registration_legal',
                            'amount' => '100.00',
                        ],
                    ],
                    'initialAssetsInventoryItems' => [],
                    'workingCapital' => [
                        'method' => 'fixed_amount',
                        'amount' => '200.00',
                    ],
                    'contingency' => [
                        'method' => 'fixed_amount',
                        'amount' => '50.00',
                    ],
                    'confirmedFunding' => '150.00',
                ],
            );

        self::assertNotNull($result);
        self::assertSame(
            '350.00',
            $result['totalCapitalRequirement']['amount'],
        );

        foreach ($before as $table => $count) {
            self::assertSame(
                $count,
                $this->tableCount($table),
                "Unexpected write to {$table}.",
            );
        }
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

    private function activeUser(string $email): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => 'not-a-real-hash',
            'status' => AccountStatus::Active,
            'password_changed_at' => now(),
        ]);
    }

    private function tableCount(string $table): int
    {
        return DB::table($table)->count();
    }
}
