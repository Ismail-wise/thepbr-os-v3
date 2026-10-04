<?php

declare(strict_types=1);

namespace Tests\Feature\Formation;

use App\Application\Businesses\CreateBusiness;
use App\Application\Formation\BusinessModelPlanning;
use App\Application\Formation\GetFormationWorkspace;
use App\Application\Formation\NewBusinessPlanning;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BusinessModelFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_model_foundation_reuses_bmc_demand_evidence_and_deterministic_break_even(): void
    {
        $user = $this->activeUser('business-model-foundation@example.test');
        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            'Business Model Foundation',
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

        $profile = $model->saveOperatingProfile(
            $user,
            $business,
            0,
            $this->operatingProfile(),
        );

        self::assertSame(1, $profile['revision']);

        $assumptionId = $planning->addAssumption(
            $user,
            $business,
            'market',
            'SME operators will pay for a structured operating system.',
            'validated',
        );

        self::assertNotNull($assumptionId);

        $planning->addValidationActivity(
            $user,
            $business,
            $assumptionId,
            'Five customer interviews plus paid pilot',
            'completed',
            'Four of five interviews confirmed the problem and one pilot paid.',
            '2026-10-05',
        );

        $workspace = $this->app
            ->make(GetFormationWorkspace::class)
            ->execute($user, $business);

        self::assertNotNull($workspace);
        self::assertSame(
            'Build a simple operating system for SME partnerships.',
            $workspace['business_model_foundation']['operating_profile']['business_purpose'],
        );
        self::assertSame(
            'ready',
            $workspace['business_model_foundation']['economics']['status'],
        );
        self::assertSame(
            '60.00',
            $workspace['business_model_foundation']['economics']['contributionMarginPerUnit'],
        );
        self::assertSame(
            50.0,
            $workspace['business_model_foundation']['economics']['breakEvenUnits'],
        );
        self::assertSame(
            '5000.00',
            $workspace['business_model_foundation']['economics']['breakEvenRevenue'],
        );
        self::assertSame(
            'validated',
            $workspace['business_model_foundation']['demand']['status'],
        );
        self::assertSame(
            1,
            $workspace['business_model_foundation']['demand']['validated_assumptions'],
        );
        self::assertSame(
            1,
            $workspace['business_model_foundation']['demand']['completed_validations'],
        );

        $this->expectException(StaleRevision::class);

        $model->saveOperatingProfile(
            $user,
            $business,
            0,
            $this->operatingProfile(),
        );
    }

    public function test_existing_business_uses_the_same_current_business_model_foundation_without_forcing_valuation(): void
    {
        $user = $this->activeUser('business-model-existing@example.test');
        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            'Existing Business Model',
            BusinessOriginType::ExistingBusinessImportedIntoPbr,
            BusinessStage::Operating,
            'THB',
        );

        $this->app->make(BusinessModelPlanning::class)
            ->saveOperatingProfile(
                $user,
                $business,
                0,
                [
                    ...$this->operatingProfile(),
                    'location' => 'Chiang Mai',
                ],
            );

        $workspace = $this->app
            ->make(GetFormationWorkspace::class)
            ->execute($user, $business);

        self::assertNotNull($workspace);
        self::assertSame('existing', $workspace['journey']);
        self::assertSame(
            'Chiang Mai',
            $workspace['business_model_foundation']['operating_profile']['location'],
        );
        self::assertSame([], $workspace['existing_business']['valuations']);
    }

    public function test_http_save_allows_progressive_partial_foundation_without_hidden_field_validation(): void
    {
        $this->withoutVite();

        $user = $this->activeUser('business-model-progressive@example.test');
        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            'Progressive Business Model',
            BusinessOriginType::StartedThroughPbr,
            BusinessStage::Idea,
            'USD',
        );

        $this->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
            ])
            ->from('/formation')
            ->put('/formation/business-model/foundation', [
                'expected_revision' => 0,
                'business_purpose' => 'Make partner decisions easier to operate.',
            ])
            ->assertRedirect('/formation')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('business_model_operating_profiles', [
            'business_id' => $business->getKey(),
            'business_purpose' => 'Make partner decisions easier to operate.',
            'average_selling_price' => null,
            'variable_cost_per_unit' => null,
            'monthly_fixed_cost' => null,
        ]);
    }

    /**
     * @return array<string,string>
     */
    private function bmc(): array
    {
        return [
            'customer_segments' => 'SME operators',
            'value_propositions' => 'Clear partnership operating system',
            'channels' => 'Direct consultation and partner network',
            'customer_relationships' => 'Guided setup and periodic review',
            'revenue_streams' => 'Setup fee and recurring support',
            'key_resources' => 'Business advisors and operating platform',
            'key_activities' => 'Facilitation, setup, review and support',
            'key_partnerships' => 'Professional and implementation partners',
            'cost_structure' => 'People, platform and support costs',
        ];
    }

    /**
     * @return array<string,?string>
     */
    private function operatingProfile(): array
    {
        return [
            'business_purpose' => 'Build a simple operating system for SME partnerships.',
            'market' => 'Myanmar-owned SMEs operating in Myanmar and Thailand.',
            'location' => 'Myanmar and Thailand',
            'operating_model' => 'Guided setup plus recurring support.',
            'excluded_activities' => 'No legal representation or guaranteed business outcomes.',
            'pricing_notes' => 'Average setup package used for planning.',
            'unit_name' => 'setup package',
            'average_selling_price' => '100.00',
            'variable_cost_per_unit' => '40.00',
            'monthly_fixed_cost' => '3000.00',
            'expected_monthly_units' => '80.00',
            'scalability_strategy' => 'Standardize delivery and reuse operating templates.',
            'scalability_constraints' => 'Senior advisor capacity is the current bottleneck.',
            'first_12_month_plan' => 'Validate, launch, standardize delivery, then expand partner channels.',
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
