<?php

declare(strict_types=1);

namespace Tests\Feature\Formation;

use App\Application\Businesses\CreateBusiness;
use App\Application\Formation\BusinessModelPlanning;
use App\Application\Formation\DeepFeasibilityDimensionAssessmentEngine;
use App\Application\Formation\GetDeepFeasibilityAssessmentRunHistory;
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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DeepFeasibilityAssessmentRunHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_records_exact_immutable_contract_with_null_overall_result(): void
    {
        [$user, $business] = $this->readyNewBusiness(
            'deep-run-authorized@example.test',
            'Authorized Deep Feasibility',
        );

        $run = $this->app
            ->make(RecordDeepFeasibilityAssessmentRun::class)
            ->execute($user, $business);

        self::assertNotNull($run);
        self::assertSame(
            DeepFeasibilityDimensionAssessmentEngine::CONTRACT_VERSION,
            $run['contractVersion'],
        );
        self::assertSame(
            DeepFeasibilityDimensionAssessmentEngine::ENGINE_VERSION,
            $run['engineVersion'],
        );
        self::assertNull($run['overallScore']);
        self::assertNull($run['recommendation']);
        self::assertSame(64, strlen($run['inputHash']));
        self::assertSame(64, strlen($run['resultHash']));

        self::assertFalse(
            $run['semantics']['canonicalBusinessTruth'],
        );
        self::assertFalse($run['semantics']['approvedPolicy']);
        self::assertFalse($run['semantics']['ownershipTruth']);
        self::assertFalse($run['semantics']['valuationTruth']);
        self::assertFalse($run['semantics']['signedTruth']);
        self::assertFalse(
            $run['semantics']['effectiveLifecycleTruth'],
        );

        self::assertSame(
            'new_business_default_skip',
            $run['inputSnapshot']['canonicalSources'][
                'businessValuation'
            ]['reason'],
        );
        self::assertSame(
            'aggregate_only',
            $run['sourceProvenance']['demand']['evidence']['mode'],
        );
        self::assertSame(
            'not_copied',
            $run['sourceProvenance']['partnerDynamics']['mode'],
        );

        $stored = DeepFeasibilityAssessmentRun::query()
            ->whereKey($run['id'])
            ->firstOrFail();

        self::assertNull($stored->overall_score);
        self::assertNull($stored->recommendation);

        self::assertTrue(
            DB::table('audit_events')
                ->where('business_id', $business->getKey())
                ->where(
                    'action',
                    'formation.deep_feasibility.assessment_recorded',
                )
                ->where('target_id', $run['id'])
                ->exists(),
        );

        self::assertTrue(
            DB::table('business_events')
                ->where('business_id', $business->getKey())
                ->where(
                    'event_type',
                    'formation.deep_feasibility.assessment_recorded',
                )
                ->where('aggregate_id', $run['id'])
                ->exists(),
        );
    }

    public function test_wrong_business_user_cannot_create_or_read_assessment_history(): void
    {
        [$ownerA, $businessA] = $this->readyNewBusiness(
            'deep-run-owner-a@example.test',
            'Business A',
        );
        [$ownerB, $businessB] = $this->readyNewBusiness(
            'deep-run-owner-b@example.test',
            'Business B',
        );

        $created = $this->app
            ->make(RecordDeepFeasibilityAssessmentRun::class)
            ->execute($ownerA, $businessA);

        self::assertNotNull($created);

        self::assertNull(
            $this->app
                ->make(RecordDeepFeasibilityAssessmentRun::class)
                ->execute($ownerB, $businessA),
        );

        self::assertNull(
            $this->app
                ->make(GetDeepFeasibilityAssessmentRunHistory::class)
                ->execute($ownerB, $businessA),
        );

        self::assertNull(
            $this->app
                ->make(GetDeepFeasibilityAssessmentRunHistory::class)
                ->execute($ownerA, $businessB),
        );

        self::assertSame(
            1,
            DeepFeasibilityAssessmentRun::query()
                ->where('business_id', $businessA->getKey())
                ->count(),
        );
        self::assertSame(
            0,
            DeepFeasibilityAssessmentRun::query()
                ->where('business_id', $businessB->getKey())
                ->count(),
        );
    }

    public function test_saved_run_stays_historical_when_canonical_source_changes_and_new_run_is_appended(): void
    {
        [$user, $business] = $this->readyNewBusiness(
            'deep-run-history@example.test',
            'Historical Deep Feasibility',
        );

        $recorder = $this->app
            ->make(RecordDeepFeasibilityAssessmentRun::class);

        $first = $recorder->execute($user, $business);
        self::assertNotNull($first);

        $firstProfile = $first['sourceProvenance']['operatingProfile'];
        self::assertSame(1, $firstProfile['revision']);
        self::assertSame(
            'Myanmar-owned SMEs in Myanmar and Thailand.',
            $first['inputSnapshot']['canonicalSources'][
                'businessModel'
            ]['market'],
        );

        $changed = $this->operatingProfile();
        $changed['market'] = 'A changed canonical market.';
        $changed['average_selling_price'] = '120.00';

        $saved = $this->app
            ->make(BusinessModelPlanning::class)
            ->saveOperatingProfile(
                $user,
                $business,
                1,
                $changed,
            );

        self::assertSame(2, $saved['revision']);

        $historyBeforeSecond = $this->app
            ->make(GetDeepFeasibilityAssessmentRunHistory::class)
            ->execute($user, $business);

        self::assertCount(1, $historyBeforeSecond);
        self::assertSame(
            $first['id'],
            $historyBeforeSecond[0]['id'],
        );
        self::assertSame(
            'Myanmar-owned SMEs in Myanmar and Thailand.',
            $historyBeforeSecond[0]['inputSnapshot'][
                'canonicalSources'
            ]['businessModel']['market'],
        );
        self::assertSame(
            1,
            $historyBeforeSecond[0]['sourceProvenance'][
                'operatingProfile'
            ]['revision'],
        );
        self::assertSame(
            $first['inputHash'],
            $historyBeforeSecond[0]['inputHash'],
        );
        self::assertTrue(
            $historyBeforeSecond[0]['integrity']['inputVerified'],
        );
        self::assertTrue(
            $historyBeforeSecond[0]['integrity']['resultVerified'],
        );

        $second = $recorder->execute($user, $business);

        self::assertNotNull($second);
        self::assertNotSame($first['id'], $second['id']);
        self::assertNotSame(
            $first['inputHash'],
            $second['inputHash'],
        );
        self::assertSame(
            'A changed canonical market.',
            $second['inputSnapshot']['canonicalSources'][
                'businessModel'
            ]['market'],
        );
        self::assertSame(
            2,
            $second['sourceProvenance']['operatingProfile']['revision'],
        );

        self::assertSame(
            2,
            DeepFeasibilityAssessmentRun::query()
                ->where('business_id', $business->getKey())
                ->count(),
        );
    }

    public function test_dimension_results_blockers_and_unavailable_dependencies_are_preserved_without_fake_zero(): void
    {
        [$user, $business] = $this->readyNewBusiness(
            'deep-run-blocker@example.test',
            'Blocker Deep Feasibility',
            variableCost: '120.00',
        );

        $run = $this->app
            ->make(RecordDeepFeasibilityAssessmentRun::class)
            ->execute($user, $business);

        self::assertNotNull($run);

        $assessment = $run['resultSnapshot'];
        $unitEconomics = $this->dimension(
            $assessment,
            'unit_economics',
        );

        self::assertSame('blocker', $unitEconomics['state']);
        self::assertSame(
            'non_positive_contribution_margin',
            $unitEconomics['resultCode'],
        );
        self::assertSame(1, $assessment['summary']['blocker']);

        foreach ([
            'capital',
            'partner_alignment',
            'operations_readiness',
            'legal_risk',
            'sales_readiness',
        ] as $key) {
            $dimension = $this->dimension($assessment, $key);

            self::assertSame(
                'unavailable_dependency',
                $dimension['state'],
            );
            self::assertNull($dimension['score']);
        }

        self::assertNull($run['overallScore']);
        self::assertNull($run['recommendation']);
        self::assertSame(
            'required_dependencies_unavailable',
            $assessment['overall']['reasonCode'],
        );
    }

    public function test_private_partner_dynamics_answers_are_not_copied_into_assessment_run(): void
    {
        [$user, $business] = $this->readyNewBusiness(
            'deep-run-private-pd@example.test',
            'Private PD Boundary',
        );

        PartnerDynamicsAssessment::query()->create([
            'user_id' => $user->getKey(),
            'assessment_version' => 'pd-private-test-v1',
            'status' => 'completed',
            'answers' => [
                'private_answer' => 'NEVER-COPY-PRIVATE-PD-ANSWER',
            ],
            'dimension_scores' => [
                'decision_style' => 99,
            ],
            'primary_profile' => 'visionary',
            'primary_score' => '99.00',
            'is_blended' => false,
            'result_confidence' => 'high',
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);

        $run = $this->app
            ->make(RecordDeepFeasibilityAssessmentRun::class)
            ->execute($user, $business);

        self::assertNotNull($run);

        $serialized = json_encode(
            [
                'sourceProvenance' => $run['sourceProvenance'],
                'inputSnapshot' => $run['inputSnapshot'],
                'resultSnapshot' => $run['resultSnapshot'],
            ],
            JSON_THROW_ON_ERROR,
        );

        self::assertStringNotContainsString(
            'NEVER-COPY-PRIVATE-PD-ANSWER',
            $serialized,
        );
        self::assertStringNotContainsString(
            'decision_style',
            $serialized,
        );
        self::assertSame(
            'protected_private_boundary',
            $run['sourceProvenance']['partnerDynamics']['reason'],
        );

        $partnerDimension = $this->dimension(
            $run['resultSnapshot'],
            'partner_alignment',
        );

        self::assertSame(
            'unavailable_dependency',
            $partnerDimension['state'],
        );
        self::assertSame(
            'partner_dynamics',
            $partnerDimension['dependency'],
        );
    }

    public function test_existing_business_cannot_create_deep_feasibility_run_and_business_valuation_boundary_stays_separate(): void
    {
        $user = $this->activeUser('deep-run-existing@example.test');
        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            'Existing Business',
            BusinessOriginType::ExistingBusinessImportedIntoPbr,
            BusinessStage::Operating,
            'USD',
        );

        self::assertNull(
            $this->app
                ->make(RecordDeepFeasibilityAssessmentRun::class)
                ->execute($user, $business),
        );

        self::assertNull(
            $this->app
                ->make(GetDeepFeasibilityAssessmentRunHistory::class)
                ->execute($user, $business),
        );

        self::assertSame(
            0,
            DeepFeasibilityAssessmentRun::query()
                ->where('business_id', $business->getKey())
                ->count(),
        );
    }

    /**
     * @return array{0:User,1:Business}
     */
    private function readyNewBusiness(
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
        $planning = $this->app->make(NewBusinessPlanning::class);

        $model->saveBmc(
            $user,
            $business,
            0,
            $this->bmc(),
        );

        $profile = $this->operatingProfile();
        $profile['variable_cost_per_unit'] = $variableCost;

        $model->saveOperatingProfile(
            $user,
            $business,
            0,
            $profile,
        );

        $assumptionId = $planning->addAssumption(
            $user,
            $business,
            'market',
            'SME owners will pay for guided partnership setup.',
            'validated',
        );

        self::assertNotNull($assumptionId);

        $validationId = $planning->addValidationActivity(
            $user,
            $business,
            $assumptionId,
            'Customer interviews and paid pilot',
            'completed',
            'Four interviews confirmed the problem and one pilot paid.',
            '2026-10-05',
        );

        self::assertNotNull($validationId);

        return [$user, $business];
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

    /**
     * @param  array<string,mixed>  $assessment
     * @return array<string,mixed>
     */
    private function dimension(
        array $assessment,
        string $key,
    ): array {
        foreach ($assessment['dimensions'] as $dimension) {
            if ($dimension['key'] === $key) {
                return $dimension;
            }
        }

        self::fail("Dimension [{$key}] not found.");
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
