<?php

declare(strict_types=1);

namespace Tests\Feature\Formation;

use App\Application\Businesses\CreateBusiness;
use App\Application\Formation\BusinessModelPlanning;
use App\Application\Formation\GetDeepFeasibilityFoundation;
use App\Application\Formation\GetFormationWorkspace;
use App\Application\Formation\NewBusinessPlanning;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Identity\Enums\AccountStatus;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DeepFeasibilityFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_business_foundation_reuses_canonical_business_model_demand_and_economics(): void
    {
        $user = $this->activeUser('deep-feasibility@example.test');
        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            'Deep Feasibility Business',
            BusinessOriginType::StartedThroughPbr,
            BusinessStage::Planning,
            'USD',
        );

        $model = $this->app->make(BusinessModelPlanning::class);
        $planning = $this->app->make(NewBusinessPlanning::class);

        $model->saveBmc(
            $user,
            $business,
            0,
            $this->bmc(),
        );

        $model->saveOperatingProfile(
            $user,
            $business,
            0,
            $this->operatingProfile(),
        );

        $assumptionId = $planning->addAssumption(
            $user,
            $business,
            'market',
            'SME owners will pay for guided partnership setup.',
            'validated',
        );

        self::assertNotNull($assumptionId);

        $planning->addValidationActivity(
            $user,
            $business,
            $assumptionId,
            'Customer interviews and paid pilot',
            'completed',
            'Four interviews confirmed the problem and one pilot paid.',
            '2026-10-05',
        );

        $foundation = $this->app
            ->make(GetDeepFeasibilityFoundation::class)
            ->execute($user, $business);

        self::assertNotNull($foundation);
        self::assertSame('core_evidence_ready', $foundation['status']);
        self::assertSame('medium', $foundation['confidence']['level']);
        self::assertFalse(
            $foundation['confidence']['highConfidenceAvailable'],
        );
        self::assertSame(
            'traceable',
            $foundation['evidenceQuality']['level'],
        );

        self::assertSame(
            'Myanmar-owned SMEs in Myanmar and Thailand.',
            $foundation['canonicalSources']['businessModel']['market'],
        );
        self::assertSame(
            'Spreadsheet coordination and large ERP products.',
            $foundation['canonicalSources']['businessModel']['competitionAlternatives'],
        );
        self::assertSame(
            'Standardize delivery and reuse operating templates.',
            $foundation['canonicalSources']['businessModel']['scalabilityStrategy'],
        );

        self::assertSame(
            '60.00',
            $foundation['canonicalSources']['economics']['contributionMarginPerUnit'],
        );
        self::assertSame(
            '5000.00',
            $foundation['canonicalSources']['economics']['breakEvenRevenue'],
        );
        self::assertSame(
            '4800.00',
            $foundation['canonicalSources']['economics']['expectedMonthlyGrossProfit'],
        );
        self::assertSame(
            '1800.00',
            $foundation['canonicalSources']['economics']['expectedMonthlyOperatingProfit'],
        );

        self::assertSame(
            'validated',
            $foundation['canonicalSources']['demand']['status'],
        );
        self::assertSame(
            1,
            $foundation['canonicalSources']['demand']['validated_assumptions'],
        );
        self::assertSame(
            1,
            $foundation['canonicalSources']['demand']['completed_validations'],
        );

        self::assertFalse(
            $foundation['canonicalSources']['businessValuation']['applicable'],
        );
        self::assertSame(
            'new_business_default_skip',
            $foundation['canonicalSources']['businessValuation']['reason'],
        );

        self::assertNull($foundation['score']);
        self::assertNull($foundation['decision']);
        self::assertTrue(
            $foundation['semantics']['livingFeasibility'],
        );
        self::assertFalse(
            $foundation['semantics']['decisionRecommendationAvailable'],
        );
        self::assertFalse(
            $foundation['semantics']['guaranteedBusinessSuccess'],
        );
        self::assertFalse(
            $foundation['semantics']['successProbability'],
        );
        self::assertFalse(
            $foundation['semantics']['canonicalTruthDuplicated'],
        );
    }

    public function test_early_stage_foundation_stays_low_confidence_and_never_invents_missing_data(): void
    {
        $user = $this->activeUser('deep-feasibility-early@example.test');
        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            'Early Deep Feasibility',
            BusinessOriginType::StartedThroughPbr,
            BusinessStage::Idea,
            'THB',
        );

        $foundation = $this->app
            ->make(GetDeepFeasibilityFoundation::class)
            ->execute($user, $business);

        self::assertNotNull($foundation);
        self::assertSame('not_started', $foundation['status']);
        self::assertSame('low', $foundation['confidence']['level']);
        self::assertSame(
            'limited',
            $foundation['evidenceQuality']['level'],
        );
        self::assertSame(
            'incomplete',
            $foundation['canonicalSources']['economics']['status'],
        );
        self::assertNull(
            $foundation['canonicalSources']['economics']['breakEvenRevenue'],
        );
        self::assertSame(
            'not_started',
            $foundation['canonicalSources']['demand']['status'],
        );
        self::assertSame(
            [
                'business_model',
                'demand_validation',
                'unit_economics',
                'competition_alternatives',
                'scalability',
            ],
            $foundation['gaps'],
        );
        self::assertNull($foundation['score']);
        self::assertNull($foundation['decision']);
    }

    public function test_foundation_is_new_business_only_and_does_not_reopen_business_valuation(): void
    {
        $user = $this->activeUser('deep-feasibility-existing@example.test');
        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            'Existing Business',
            BusinessOriginType::ExistingBusinessImportedIntoPbr,
            BusinessStage::Operating,
            'USD',
        );

        self::assertNull(
            $this->app
                ->make(GetDeepFeasibilityFoundation::class)
                ->execute($user, $business),
        );

        $workspace = $this->app
            ->make(GetFormationWorkspace::class)
            ->execute($user, $business);

        self::assertNotNull($workspace);
        self::assertSame('existing', $workspace['journey']);
        self::assertNull($workspace['new_business']);
    }

    public function test_foundation_fails_closed_for_user_without_business_access(): void
    {
        $owner = $this->activeUser('deep-feasibility-owner@example.test');
        $outsider = $this->activeUser('deep-feasibility-outsider@example.test');
        $business = $this->app->make(CreateBusiness::class)->handle(
            $owner,
            'Protected Feasibility Business',
            BusinessOriginType::StartedThroughPbr,
            BusinessStage::Planning,
            'USD',
        );

        self::assertNull(
            $this->app
                ->make(GetDeepFeasibilityFoundation::class)
                ->execute($outsider, $business),
        );
    }

    public function test_formation_workspace_exposes_the_same_living_foundation_without_replacing_legacy_scenarios(): void
    {
        $user = $this->activeUser('deep-feasibility-workspace@example.test');
        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            'Workspace Deep Feasibility',
            BusinessOriginType::StartedThroughPbr,
            BusinessStage::Planning,
            'USD',
        );

        $this->app->make(BusinessModelPlanning::class)
            ->saveOperatingProfile(
                $user,
                $business,
                0,
                $this->operatingProfile(),
            );

        $workspace = $this->app
            ->make(GetFormationWorkspace::class)
            ->execute($user, $business);

        self::assertNotNull($workspace);
        self::assertSame([], $workspace['new_business']['feasibility']);
        self::assertSame(
            'building',
            $workspace['new_business']['deep_feasibility_foundation']['status'],
        );
        self::assertSame(
            $workspace['business_model_foundation']['demand'],
            $workspace['new_business']['deep_feasibility_foundation']['canonicalSources']['demand'],
        );
        self::assertArrayHasKey(
            'verified_evidence_links',
            $workspace['business_model_foundation']['demand'],
        );
    }

    /**
     * @return array<string,string>
     */
    private function bmc(): array
    {
        return [
            'customer_segments' => 'SME owners',
            'value_propositions' => 'Guided partnership setup with auditable decisions',
            'channels' => 'Direct consultation and partner referrals',
            'customer_relationships' => 'Guided onboarding and periodic review',
            'revenue_streams' => 'Setup fee and recurring support',
            'key_resources' => 'Advisors, process and software',
            'key_activities' => 'Validation, setup and review',
            'key_partnerships' => 'Professional partners',
            'cost_structure' => 'People, software and support',
        ];
    }

    /**
     * @return array<string,?string>
     */
    private function operatingProfile(): array
    {
        return [
            'business_purpose' => 'Make partnership setup easier to operate.',
            'market' => 'Myanmar-owned SMEs in Myanmar and Thailand.',
            'location' => 'Myanmar and Thailand',
            'competition_alternatives' => 'Spreadsheet coordination and large ERP products.',
            'operating_model' => 'Guided setup plus recurring support.',
            'excluded_activities' => 'No guaranteed business success.',
            'pricing_notes' => 'Average setup package used for planning.',
            'unit_name' => 'setup package',
            'average_selling_price' => '100.00',
            'variable_cost_per_unit' => '40.00',
            'monthly_fixed_cost' => '3000.00',
            'expected_monthly_units' => '80.00',
            'scalability_strategy' => 'Standardize delivery and reuse operating templates.',
            'scalability_constraints' => 'Senior advisor capacity.',
            'first_12_month_plan' => 'Validate, launch, standardize, expand.',
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
}
