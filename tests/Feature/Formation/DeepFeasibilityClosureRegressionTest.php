<?php

declare(strict_types=1);

namespace Tests\Feature\Formation;

use App\Application\Businesses\CreateBusiness;
use App\Application\Formation\BusinessModelPlanning;
use App\Application\Formation\NewBusinessPlanning;
use App\Application\Formation\RecordDeepFeasibilityAssessmentRun;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Identity\Enums\AccountStatus;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DeepFeasibilityClosureRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('session.driver', 'array');
    }

    public function test_master_journey_places_deep_feasibility_after_reused_planning_foundation_for_new_business(): void
    {
        $this->withoutVite();

        [$user, $business] = $this->readyNewBusiness(
            'deep-closure-master@example.test',
            'Deep Closure Master Journey',
        );

        $journey = $this->overviewJourney($user, $business);
        $keys = array_column($journey['steps'], 'key');

        self::assertSame('new', $journey['variant']);
        self::assertSame('business_model', $keys[0]);
        self::assertSame('deep_feasibility', $keys[1]);
        self::assertSame('partner_dynamics', $keys[2]);
        self::assertNotContains('business_valuation', $keys);

        $businessModel = collect($journey['steps'])
            ->firstWhere('key', 'business_model');
        $deepFeasibility = collect($journey['steps'])
            ->firstWhere('key', 'deep_feasibility');

        self::assertIsArray($businessModel);
        self::assertIsArray($deepFeasibility);
        self::assertSame('recorded', $businessModel['state']);
        self::assertSame('current', $deepFeasibility['state']);
        self::assertFalse($deepFeasibility['disabled']);
        self::assertSame(
            '/formation?step=feasibility',
            $deepFeasibility['route'],
        );
    }

    public function test_immutable_snapshot_marks_information_recorded_without_inventing_decision_completion(): void
    {
        $this->withoutVite();

        [$user, $business] = $this->readyNewBusiness(
            'deep-closure-recorded@example.test',
            'Deep Closure Recorded State',
        );

        $run = $this->app
            ->make(RecordDeepFeasibilityAssessmentRun::class)
            ->execute($user, $business);

        self::assertNotNull($run);
        self::assertNull($run['overallScore']);
        self::assertNull($run['recommendation']);

        $assessment = $run['resultSnapshot'];

        self::assertSame(
            5,
            $assessment['summary']['unavailable_dependency'],
        );
        self::assertNull($assessment['overall']['recommendation']);

        $journey = $this->overviewJourney($user, $business);
        $deepFeasibility = collect($journey['steps'])
            ->firstWhere('key', 'deep_feasibility');
        $partnerDynamics = collect($journey['steps'])
            ->firstWhere('key', 'partner_dynamics');

        self::assertIsArray($deepFeasibility);
        self::assertIsArray($partnerDynamics);
        self::assertSame('recorded', $deepFeasibility['state']);
        self::assertSame('current', $partnerDynamics['state']);

        self::assertSame(
            'Completed — information is recorded',
            $this->englishJourneyRecordedCopy(),
        );
    }

    public function test_existing_business_keeps_business_valuation_and_never_receives_new_business_deep_feasibility_step(): void
    {
        $this->withoutVite();

        $user = $this->activeUser(
            'deep-closure-existing@example.test',
        );

        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            'Deep Closure Existing Business',
            BusinessOriginType::ExistingBusinessImportedIntoPbr,
            BusinessStage::Operating,
            'USD',
        );

        $journey = $this->overviewJourney($user, $business);
        $keys = array_column($journey['steps'], 'key');

        self::assertSame('existing', $journey['variant']);
        self::assertSame('business_model', $keys[0]);
        self::assertSame('business_valuation', $keys[1]);
        self::assertSame('partner_dynamics', $keys[2]);
        self::assertNotContains('deep_feasibility', $keys);
    }

    public function test_closure_source_keeps_guided_experience_primary_supports_direct_entry_and_excludes_legacy_manual_form(): void
    {
        $formation = $this->source(
            'resources/js/pages/Formation/Index.vue',
        );
        $master = $this->source(
            'resources/js/components/journey/MasterBusinessJourney.vue',
        );
        $guided = $this->source(
            'resources/js/components/deep-feasibility/DeepFeasibilityGuidedJourney.vue',
        );

        self::assertStringContainsString(
            '<DeepFeasibilityGuidedJourney',
            $formation,
        );
        self::assertStringContainsString(
            'new URLSearchParams(',
            $formation,
        );
        self::assertStringContainsString(
            "'feasibility'",
            $formation,
        );
        self::assertStringContainsString(
            "deep_feasibility: 'journey.step.deepFeasibility'",
            $master,
        );

        self::assertStringNotContainsString(
            '@submit.prevent="post(\'/formation/new/feasibility\', feasibility)"',
            $formation,
        );
        self::assertStringNotContainsString(
            'projected_monthly_revenue',
            $formation,
        );
        self::assertStringNotContainsString(
            'projected_monthly_cost',
            $formation,
        );

        self::assertStringContainsString(
            'You do not need to enter them again here.',
            $guided,
        );
        self::assertStringContainsString(
            'ဒီနေရာမှာ ပြန်ဖြည့်စရာမလိုပါ',
            $guided,
        );
    }

    public function test_multilingual_closure_source_has_english_myanmar_mixed_copy_and_wrap_safe_layout_contract(): void
    {
        $guided = $this->source(
            'resources/js/components/deep-feasibility/DeepFeasibilityGuidedJourney.vue',
        );
        $catalog = $this->source(
            'resources/js/i18n/catalog.ts',
        );

        foreach ([
            "uiLanguageMode.value === 'my'",
            "uiLanguageMode.value === 'mixed'",
            'Understand how ready this Business is to start',
            'ဒီလုပ်ငန်းကို စဖို့ အခုဘယ်လောက်အဆင်သင့်ဖြစ်နေပြီလဲ',
            'ဒီ Business ကို အခု start လုပ်ဖို့ ဘယ်လောက် ready ဖြစ်နေပြီလဲ',
            'pbr-safe-copy',
            'min-w-0',
            'flex-wrap',
            'sm:grid-cols-2',
        ] as $contract) {
            self::assertStringContainsString($contract, $guided);
        }

        self::assertSame(
            3,
            substr_count(
                $catalog,
                "'journey.step.deepFeasibility': 'Deep Feasibility'",
            ),
        );

        self::assertStringNotContainsString(
            'w-[320px]',
            $guided,
        );
        self::assertStringNotContainsString(
            '{{ dimension.resultCode }}',
            $guided,
        );
        self::assertStringNotContainsString(
            '{{ action.code }}',
            $guided,
        );
    }

    /**
     * @return array{0:User,1:Business}
     */
    private function readyNewBusiness(
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

        $model = $this->app->make(BusinessModelPlanning::class);

        $model->saveBmc(
            $user,
            $business,
            0,
            [
                'customer_segments' => 'SME owners',
                'value_propositions' => 'Guided partnership setup',
                'channels' => 'Direct consultation',
                'customer_relationships' => 'Guided onboarding',
                'revenue_streams' => 'Setup and support fees',
                'key_resources' => 'Advisors and software',
                'key_activities' => 'Validation and setup',
                'key_partnerships' => 'Professional partners',
                'cost_structure' => 'People and software',
            ],
        );

        $model->saveOperatingProfile(
            $user,
            $business,
            0,
            [
                'business_purpose' => 'Make partnership setup easier.',
                'market' => 'Myanmar-owned SMEs.',
                'location' => 'Myanmar and Thailand',
                'competition_alternatives' => 'Spreadsheets and ERP.',
                'operating_model' => 'Guided setup plus support.',
                'excluded_activities' => 'No guaranteed business success.',
                'pricing_notes' => 'Planning price.',
                'unit_name' => 'setup package',
                'average_selling_price' => '100.00',
                'variable_cost_per_unit' => '40.00',
                'monthly_fixed_cost' => '3000.00',
                'expected_monthly_units' => '80.00',
                'scalability_strategy' => 'Standardize delivery.',
                'scalability_constraints' => 'Advisor capacity.',
                'first_12_month_plan' => 'Validate, launch, standardize.',
            ],
        );

        $planning = $this->app->make(NewBusinessPlanning::class);

        $assumptionId = $planning->addAssumption(
            $user,
            $business,
            'market',
            'SME owners will pay for guided setup.',
            'validated',
        );

        self::assertNotNull($assumptionId);

        self::assertNotNull(
            $planning->addValidationActivity(
                $user,
                $business,
                $assumptionId,
                'Customer interviews',
                'completed',
                'Validated through interviews.',
                '2026-10-05',
            ),
        );

        return [$user, $business];
    }

    /**
     * @return array<string,mixed>
     */
    private function overviewJourney(
        User $user,
        Business $business,
    ): array {
        $response = $this->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
            ])
            ->get('/overview');

        $response->assertOk();

        return $response->viewData('page')['props']['controlCenter'][
            'journey'
        ];
    }

    private function englishJourneyRecordedCopy(): string
    {
        $catalog = $this->source('resources/js/i18n/catalog.ts');

        preg_match(
            "/'journey\.state\.recorded': '([^']+)'/",
            $catalog,
            $matches,
        );

        self::assertArrayHasKey(1, $matches);

        return $matches[1];
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

    private function source(string $path): string
    {
        $source = file_get_contents(base_path($path));

        self::assertIsString($source);

        return $source;
    }
}
