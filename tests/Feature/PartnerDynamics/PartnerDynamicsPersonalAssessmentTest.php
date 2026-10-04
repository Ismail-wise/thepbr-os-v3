<?php

declare(strict_types=1);

namespace Tests\Feature\PartnerDynamics;

use App\Application\Businesses\CreateBusiness;
use App\Application\PartnerDynamics\PartnerDynamicsScoringService;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Identity\Enums\AccountStatus;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\PartnerDynamics\PartnerDynamicsAssessment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class PartnerDynamicsPersonalAssessmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_active_account_can_start_and_complete_personal_assessment_without_business_creation_entitlement(): void
    {
        $user = $this->activeUser('partner-dynamics@example.test');

        $this->actingAs($user)
            ->get('/partner-dynamics')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->component('PartnerDynamics/Index')
                    ->where('assessmentVersion', 'v1')
                    ->where('latestCompleted', null)
                    ->where('draft', null),
            );

        $start = $this->actingAs($user)
            ->post('/partner-dynamics/start');

        $assessment = PartnerDynamicsAssessment::query()->sole();

        self::assertSame((string) $user->getKey(), (string) $assessment->user_id);
        self::assertSame('draft', $assessment->status);

        $start->assertRedirect(
            route(
                'partner-dynamics.assessment.step',
                [$assessment->getKey(), 1],
            ),
        );

        $this->actingAs($user)
            ->get(
                route(
                    'partner-dynamics.assessment.step',
                    [$assessment->getKey(), 1],
                ),
            )
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->component('PartnerDynamics/Assessment')
                    ->where('step', 1)
                    ->where('totalSteps', 5)
                    ->has('questions', 8),
            );

        for ($step = 1; $step <= 4; $step++) {
            $answers = [];

            for (
                $question = (($step - 1) * 8) + 1;
                $question <= $step * 8;
                $question++
            ) {
                $answers[$question] = 3;
            }

            $this->actingAs($user)
                ->put(
                    route(
                        'partner-dynamics.assessment.step.save',
                        [$assessment->getKey(), $step],
                    ),
                    ['answers' => $answers],
                )
                ->assertRedirect(
                    route(
                        'partner-dynamics.assessment.step',
                        [$assessment->getKey(), $step + 1],
                    ),
                );
        }

        $scenarioAnswers = [];

        for ($question = 33; $question <= 40; $question++) {
            $scenarioAnswers[$question] = 'A';
        }

        $this->actingAs($user)
            ->put(
                route(
                    'partner-dynamics.assessment.step.save',
                    [$assessment->getKey(), 5],
                ),
                ['answers' => $scenarioAnswers],
            )
            ->assertRedirect(
                route('partner-dynamics.result', $assessment->getKey()),
            );

        $assessment->refresh();

        self::assertSame('completed', $assessment->status);
        self::assertCount(40, $assessment->answers ?? []);
        self::assertCount(8, $assessment->dimension_scores ?? []);
        self::assertCount(8, $assessment->profile_scores ?? []);
        self::assertNotNull($assessment->completed_at);

        $this->actingAs($user)
            ->get(route('partner-dynamics.result', $assessment->getKey()))
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->component('PartnerDynamics/Result')
                    ->where('result.id', (string) $assessment->getKey())
                    ->where(
                        'result.primaryProfile',
                        (string) $assessment->primary_profile,
                    )
                    ->has('result.dimensionScores', 8)
                    ->missing('result.answers'),
            );
    }

    public function test_raw_answers_are_self_only_and_other_accounts_cannot_probe_assessments(): void
    {
        $owner = $this->activeUser('pd-owner@example.test');
        $other = $this->activeUser('pd-other@example.test');

        $assessment = PartnerDynamicsAssessment::query()->create([
            'user_id' => $owner->getKey(),
            'assessment_version' => 'v1',
            'status' => 'draft',
            'answers' => [1 => 4],
            'started_at' => now(),
        ]);

        $this->actingAs($owner)
            ->get(
                route(
                    'partner-dynamics.assessment.step',
                    [$assessment->getKey(), 1],
                ),
            )
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->component('PartnerDynamics/Assessment')
                    ->where('answers.1', 4),
            );

        $this->actingAs($other)
            ->get(
                route(
                    'partner-dynamics.assessment.step',
                    [$assessment->getKey(), 1],
                ),
            )
            ->assertNotFound();

        $this->actingAs($other)
            ->put(
                route(
                    'partner-dynamics.assessment.step.save',
                    [$assessment->getKey(), 1],
                ),
                ['answers' => array_fill(1, 8, 3)],
            )
            ->assertNotFound();
    }

    public function test_completed_personal_assessment_is_reused_by_master_journey_without_business_copy(): void
    {
        $user = $this->activeUser('pd-journey@example.test');
        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            'Partner Dynamics Journey',
            BusinessOriginType::StartedThroughPbr,
            BusinessStage::Planning,
            'USD',
        );

        $result = $this->app
            ->make(PartnerDynamicsScoringService::class)
            ->calculate($this->neutralAnswers());

        PartnerDynamicsAssessment::query()->create([
            'user_id' => $user->getKey(),
            'assessment_version' => 'v1',
            'status' => 'completed',
            'answers' => $this->neutralAnswers(),
            'dimension_scores' => $result['dimension_scores'],
            'behaviour_profile_scores' => $result['behaviour_profile_scores'],
            'scenario_scores' => $result['scenario_scores'],
            'scenario_counts' => $result['scenario_counts'],
            'profile_scores' => $result['profile_scores'],
            'primary_profile' => $result['primary_profile'],
            'primary_score' => $result['primary_score'],
            'secondary_profile' => $result['secondary_profile'],
            'secondary_score' => $result['secondary_score'],
            'is_blended' => $result['is_blended'],
            'result_confidence' => $result['result_confidence'],
            'consistency_data' => $result['consistency_data'],
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
            ])
            ->get('/overview');

        $response->assertOk();

        $journey = $response->viewData('page')['props']['controlCenter']['journey'];
        $partnerDynamics = collect($journey['steps'])
            ->firstWhere('key', 'partner_dynamics');

        self::assertIsArray($partnerDynamics);
        self::assertSame('/partner-dynamics', $partnerDynamics['route']);
        self::assertFalse($partnerDynamics['disabled']);
        self::assertSame('recorded', $partnerDynamics['state']);
        self::assertDatabaseCount('partner_dynamics_assessment_references', 0);
    }

    /**
     * @return array<int,int|string>
     */
    private function neutralAnswers(): array
    {
        $answers = [];

        for ($question = 1; $question <= 32; $question++) {
            $answers[$question] = 3;
        }

        for ($question = 33; $question <= 40; $question++) {
            $answers[$question] = 'A';
        }

        return $answers;
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
