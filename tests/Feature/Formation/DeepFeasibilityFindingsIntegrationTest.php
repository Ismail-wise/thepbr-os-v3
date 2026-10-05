<?php

declare(strict_types=1);

namespace Tests\Feature\Formation;

use App\Application\Businesses\CreateBusiness;
use App\Application\Formation\BusinessModelPlanning;
use App\Application\Formation\DeepFeasibilityActionableFindings;
use App\Application\Formation\GetDeepFeasibilityAssessmentRunHistory;
use App\Application\Formation\GetDeepFeasibilityFoundation;
use App\Application\Formation\NewBusinessPlanning;
use App\Application\Formation\RecordDeepFeasibilityAssessmentRun;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Identity\Enums\AccountStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Formation\DeepFeasibilityAssessmentRun;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\PartnerDynamics\PartnerDynamicsAssessment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DeepFeasibilityFindingsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_new_business_gets_findings_without_a_recommendation_and_with_valuation_skip_preserved(): void
    {
        [$user, $business] = $this->readyBusiness(
            'findings-authorized@example.test',
            'Findings Business',
        );

        $foundation = $this->app
            ->make(GetDeepFeasibilityFoundation::class)
            ->execute($user, $business);

        self::assertNotNull($foundation);
        self::assertSame(
            DeepFeasibilityActionableFindings::CONTRACT_VERSION,
            $foundation['findings']['contractVersion'],
        );
        self::assertNull(
            $foundation['findings']['goReadiness']['recommendation'],
        );
        self::assertFalse(
            $foundation['findings']['goReadiness']['evaluationAvailable'],
        );
        self::assertSame(
            'new_business_default_skip',
            $foundation['canonicalSources']['businessValuation']['reason'],
        );
        self::assertNull($foundation['score']);
        self::assertNull($foundation['decision']);
    }

    public function test_findings_and_foundation_fail_closed_across_business_boundaries(): void
    {
        [$owner, $business] = $this->readyBusiness(
            'findings-owner@example.test',
            'Protected Findings Business',
        );
        $outsider = $this->activeUser(
            'findings-outsider@example.test',
        );

        self::assertNull(
            $this->app
                ->make(GetDeepFeasibilityFoundation::class)
                ->execute($outsider, $business),
        );

        $existing = $this->app->make(CreateBusiness::class)->handle(
            $owner,
            'Existing Findings Boundary',
            BusinessOriginType::ExistingBusinessImportedIntoPbr,
            BusinessStage::Operating,
            'USD',
        );

        self::assertNull(
            $this->app
                ->make(GetDeepFeasibilityFoundation::class)
                ->execute($owner, $existing),
        );
    }

    public function test_private_partner_dynamics_values_do_not_leak_into_current_findings(): void
    {
        [$user, $business] = $this->readyBusiness(
            'findings-private-pd@example.test',
            'Private Findings Boundary',
        );

        PartnerDynamicsAssessment::query()->create([
            'user_id' => $user->getKey(),
            'assessment_version' => 'pd-findings-private-v1',
            'status' => 'completed',
            'answers' => [
                'private_answer' => 'NEVER-COPY-PD-INTO-FINDINGS',
            ],
            'dimension_scores' => [
                'decision_style' => 88,
            ],
            'primary_profile' => 'visionary',
            'primary_score' => '88.00',
            'is_blended' => false,
            'result_confidence' => 'high',
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);

        $foundation = $this->app
            ->make(GetDeepFeasibilityFoundation::class)
            ->execute($user, $business);

        self::assertNotNull($foundation);

        $serialized = json_encode(
            $foundation['findings'],
            JSON_THROW_ON_ERROR,
        );

        self::assertStringNotContainsString(
            'NEVER-COPY-PD-INTO-FINDINGS',
            $serialized,
        );
        self::assertStringNotContainsString(
            'decision_style',
            $serialized,
        );
        self::assertStringNotContainsString(
            (string) $user->getKey(),
            $serialized,
        );

        self::assertSame(
            'dependency',
            $this->action(
                $foundation['findings'],
                'action.make_partner_alignment_available',
            )['actionType'],
        );
    }

    public function test_findings_are_reproducible_from_immutable_history_without_changing_the_saved_run(): void
    {
        [$user, $business] = $this->readyBusiness(
            'findings-history@example.test',
            'Findings History',
        );

        $run = $this->app
            ->make(RecordDeepFeasibilityAssessmentRun::class)
            ->execute($user, $business);

        self::assertNotNull($run);
        self::assertArrayNotHasKey(
            'findings',
            $run['resultSnapshot'],
        );
        self::assertArrayNotHasKey(
            'findings',
            $run['inputSnapshot'],
        );

        $findings = $this->app
            ->make(DeepFeasibilityActionableFindings::class);

        $before = $findings->derive($run['resultSnapshot']);

        $changed = $this->operatingProfile();
        $changed['market'] = 'Changed market after historical run.';
        $changed['average_selling_price'] = '125.00';

        $saved = $this->app
            ->make(BusinessModelPlanning::class)
            ->saveOperatingProfile(
                $user,
                $business,
                1,
                $changed,
            );

        self::assertSame(2, $saved['revision']);

        $history = $this->app
            ->make(GetDeepFeasibilityAssessmentRunHistory::class)
            ->execute($user, $business);

        self::assertCount(1, $history);
        self::assertSame($run['id'], $history[0]['id']);
        self::assertSame(
            $run['resultHash'],
            $history[0]['resultHash'],
        );
        self::assertTrue(
            $history[0]['integrity']['resultVerified'],
        );

        $after = $findings->derive(
            $history[0]['resultSnapshot'],
        );

        self::assertSame($before, $after);
        self::assertSame(
            1,
            DeepFeasibilityAssessmentRun::query()
                ->where('business_id', $business->getKey())
                ->count(),
        );
    }

    /**
     * @return array{0:User,1:Business}
     */
    private function readyBusiness(
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

        $this->app->make(BusinessModelPlanning::class)
            ->saveBmc(
                $user,
                $business,
                0,
                $this->bmc(),
            );

        $this->app->make(BusinessModelPlanning::class)
            ->saveOperatingProfile(
                $user,
                $business,
                0,
                $this->operatingProfile(),
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
     * @return array<string,string>
     */
    private function bmc(): array
    {
        return [
            'customer_segments' => 'SME owners',
            'value_propositions' => 'Auditable partnership setup',
            'channels' => 'Direct consultation',
            'customer_relationships' => 'Guided onboarding',
            'revenue_streams' => 'Setup and support fees',
            'key_resources' => 'Advisors and software',
            'key_activities' => 'Validation and setup',
            'key_partnerships' => 'Professional partners',
            'cost_structure' => 'People and software',
        ];
    }

    /**
     * @return array<string,?string>
     */
    private function operatingProfile(): array
    {
        return [
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
        ];
    }

    /**
     * @param  array<string,mixed>  $findings
     * @return array<string,mixed>
     */
    private function action(
        array $findings,
        string $code,
    ): array {
        foreach ($findings['requiredActions'] as $action) {
            if ($action['code'] === $code) {
                return $action;
            }
        }

        self::fail("Action [{$code}] not found.");
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
