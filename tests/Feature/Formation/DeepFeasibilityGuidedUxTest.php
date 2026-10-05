<?php

declare(strict_types=1);

namespace Tests\Feature\Formation;

use App\Application\Businesses\CreateBusiness;
use App\Application\Formation\BusinessModelPlanning;
use App\Application\Formation\NewBusinessPlanning;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Identity\Enums\AccountStatus;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Formation\DeepFeasibilityAssessmentRun;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\PartnerDynamics\PartnerDynamicsAssessment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class DeepFeasibilityGuidedUxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('session.driver', 'array');
    }

    public function test_authorized_new_business_can_open_guided_deep_feasibility_without_fake_recommendation(): void
    {
        $this->withoutVite();

        [$user, $business] = $this->readyBusiness(
            'guided-feasibility@example.test',
            'Guided Feasibility Business',
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
                    ->where('formation.journey', 'new')
                    ->where(
                        'formation.new_business.deep_feasibility_foundation.status',
                        'core_evidence_ready',
                    )
                    ->where(
                        'formation.new_business.deep_feasibility_foundation.assessment.overall.recommendation',
                        null,
                    )
                    ->where(
                        'formation.new_business.deep_feasibility_foundation.findings.contractVersion',
                        'deep-feasibility-findings-v1',
                    )
                    ->has(
                        'formation.new_business.deep_feasibility_foundation.assessment.dimensions',
                        9,
                    )
                    ->has(
                        'formation.new_business.deep_feasibility_foundation.findings.strengths',
                        4,
                    )
                    ->has(
                        'formation.new_business.deep_feasibility_foundation.findings.readinessGaps',
                        5,
                    )
                    ->has(
                        'formation.new_business.deep_feasibility_history',
                        0,
                    )
                    ->where(
                        'formation.new_business.deep_feasibility_foundation.canonicalSources.businessValuation.reason',
                        'new_business_default_skip',
                    ),
            );
    }

    public function test_record_action_appends_new_immutable_run_and_exposes_only_safe_history_summary(): void
    {
        $this->withoutVite();

        [$user, $business] = $this->readyBusiness(
            'guided-history@example.test',
            'Guided History Business',
        );

        $session = [
            EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
        ];

        $this->actingAs($user)
            ->withSession($session)
            ->post('/formation/new/deep-feasibility/assessments')
            ->assertRedirect();

        $first = DeepFeasibilityAssessmentRun::query()
            ->where('business_id', $business->getKey())
            ->firstOrFail();

        $firstId = (string) $first->getKey();
        $firstResultHash = (string) $first->result_hash;

        $this->actingAs($user)
            ->withSession($session)
            ->post('/formation/new/deep-feasibility/assessments')
            ->assertRedirect();

        self::assertSame(
            2,
            DeepFeasibilityAssessmentRun::query()
                ->where('business_id', $business->getKey())
                ->count(),
        );

        $first->refresh();

        self::assertSame($firstId, (string) $first->getKey());
        self::assertSame($firstResultHash, (string) $first->result_hash);

        $this->actingAs($user)
            ->withSession($session)
            ->get('/formation')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->has(
                        'formation.new_business.deep_feasibility_history',
                        2,
                    )
                    ->where(
                        'formation.new_business.deep_feasibility_history.0.readOnly',
                        true,
                    )
                    ->where(
                        'formation.new_business.deep_feasibility_history.0.historicalSnapshot',
                        true,
                    )
                    ->where(
                        'formation.new_business.deep_feasibility_history.0.recommendationAvailable',
                        false,
                    )
                    ->missing(
                        'formation.new_business.deep_feasibility_history.0.id',
                    )
                    ->missing(
                        'formation.new_business.deep_feasibility_history.0.inputHash',
                    )
                    ->missing(
                        'formation.new_business.deep_feasibility_history.0.resultHash',
                    )
                    ->missing(
                        'formation.new_business.deep_feasibility_history.0.sourceProvenance',
                    ),
            );
    }

    public function test_cross_business_user_cannot_record_deep_feasibility_assessment(): void
    {
        [$owner, $business] = $this->readyBusiness(
            'guided-owner@example.test',
            'Protected Guided Business',
        );
        $outsider = $this->activeUser(
            'guided-outsider@example.test',
        );

        $this->actingAs($outsider)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
            ])
            ->post('/formation/new/deep-feasibility/assessments')
            ->assertForbidden();

        self::assertSame(
            0,
            DeepFeasibilityAssessmentRun::query()
                ->where('business_id', $business->getKey())
                ->count(),
        );

        self::assertNotSame(
            $owner->getKey(),
            $outsider->getKey(),
        );
    }

    public function test_blocker_and_private_partner_dynamics_data_are_separated_in_guided_payload(): void
    {
        $this->withoutVite();

        [$user, $business] = $this->readyBusiness(
            'guided-blocker@example.test',
            'Guided Blocker Business',
            variableCost: '120.00',
        );

        PartnerDynamicsAssessment::query()->create([
            'user_id' => $user->getKey(),
            'assessment_version' => 'pd-guided-private-v1',
            'status' => 'completed',
            'answers' => [
                'private_answer' => 'NEVER-EXPOSE-PD-ANSWER-IN-GUIDED-FEASIBILITY',
            ],
            'dimension_scores' => [
                'decision_style' => 91,
            ],
            'primary_profile' => 'visionary',
            'primary_score' => '91.00',
            'is_blended' => false,
            'result_confidence' => 'high',
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
            ])
            ->get('/formation');

        $response
            ->assertOk()
            ->assertDontSee(
                'NEVER-EXPOSE-PD-ANSWER-IN-GUIDED-FEASIBILITY',
            )
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->where(
                        'formation.new_business.deep_feasibility_foundation.assessment.dimensions.1.state',
                        'blocker',
                    )
                    ->where(
                        'formation.new_business.deep_feasibility_foundation.assessment.dimensions.1.resultCode',
                        'non_positive_contribution_margin',
                    )
                    ->has(
                        'formation.new_business.deep_feasibility_foundation.findings.blockers',
                        1,
                    )
                    ->where(
                        'formation.new_business.deep_feasibility_foundation.assessment.dimensions.5.state',
                        'unavailable_dependency',
                    )
                    ->where(
                        'formation.new_business.deep_feasibility_foundation.assessment.dimensions.5.score',
                        null,
                    ),
            );
    }

    public function test_guided_source_contract_reuses_primitives_and_does_not_render_internal_codes_or_legacy_manual_feasibility_form(): void
    {
        $component = $this->source(
            'resources/js/components/deep-feasibility/DeepFeasibilityGuidedJourney.vue',
        );
        $formation = $this->source(
            'resources/js/pages/Formation/Index.vue',
        );

        foreach ([
            '<GuidedJourneyStepper',
            '<ProgressiveReveal',
            '<PbrFormSection',
            'deep-feasibility-guided-journey',
            '/formation/new/deep-feasibility/assessments',
            'Record current assessment',
            'Read-only historical snapshot',
            'More evidence needed',
            'Available later',
            'Blocker',
            'requirementsDoNotGuaranteeGo',
        ] as $contract) {
            if ($contract === 'requirementsDoNotGuaranteeGo') {
                self::assertStringContainsString(
                    'does not guarantee business success or a GO result',
                    $component,
                );

                continue;
            }

            self::assertStringContainsString(
                $contract,
                $component,
            );
        }

        self::assertStringContainsString(
            '<DeepFeasibilityGuidedJourney',
            $formation,
        );
        self::assertStringContainsString(
            'feasibilityUnavailable',
            $formation,
        );

        self::assertStringNotContainsString(
            '@submit.prevent="post(\'/formation/new/feasibility\', feasibility)"',
            $formation,
        );

        foreach ([
            '{{ dimension.resultCode }}',
            '{{ action.code }}',
            '{{ gap.resultCode }}',
            'inputHash',
            'resultHash',
            'sourceProvenance',
        ] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $component,
            );
        }
    }

    /**
     * @return array{0:User,1:Business}
     */
    private function readyBusiness(
        string $email,
        string $name,
        string $variableCost = '40.00',
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
                'value_propositions' => 'Auditable partnership setup',
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
                'variable_cost_per_unit' => $variableCost,
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
