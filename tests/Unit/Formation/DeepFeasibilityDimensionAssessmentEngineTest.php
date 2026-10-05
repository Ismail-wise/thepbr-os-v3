<?php

declare(strict_types=1);

namespace Tests\Unit\Formation;

use App\Application\Formation\DeepFeasibilityDimensionAssessmentEngine;
use PHPUnit\Framework\TestCase;

final class DeepFeasibilityDimensionAssessmentEngineTest extends TestCase
{
    public function test_same_canonical_evidence_produces_the_same_deterministic_assessment(): void
    {
        $engine = new DeepFeasibilityDimensionAssessmentEngine;
        $foundation = $this->completeFoundation();

        $first = $engine->assess($foundation);
        $second = $engine->assess($foundation);

        self::assertSame($first, $second);
        self::assertSame(
            'deep-feasibility-dimensions-v1',
            $first['contractVersion'],
        );
        self::assertSame(4, $first['summary']['assessed']);
        self::assertSame(0, $first['summary']['insufficient_evidence']);
        self::assertSame(5, $first['summary']['unavailable_dependency']);
        self::assertSame(0, $first['summary']['blocker']);

        self::assertSame(
            'assessed',
            $this->dimension($first, 'market_demand')['state'],
        );
        self::assertSame(
            'validated_demand_supported',
            $this->dimension($first, 'market_demand')['resultCode'],
        );
        self::assertSame(
            'projected_operating_profit',
            $this->dimension($first, 'unit_economics')['resultCode'],
        );
        self::assertSame(
            'core_model_and_competition_documented',
            $this->dimension(
                $first,
                'business_model_competition',
            )['resultCode'],
        );
        self::assertSame(
            'scalability_strategy_and_constraints_documented',
            $this->dimension($first, 'scalability')['resultCode'],
        );

        self::assertNull($first['overall']['score']);
        self::assertNull($first['overall']['recommendation']);
        self::assertFalse($first['overall']['minimumEvidenceReady']);
        self::assertSame(
            'required_dependencies_unavailable',
            $first['overall']['reasonCode'],
        );
    }

    public function test_missing_evidence_is_not_treated_as_failure_or_fake_low_score(): void
    {
        $engine = new DeepFeasibilityDimensionAssessmentEngine;

        $assessment = $engine->assess([
            'canonicalSources' => [
                'businessModel' => [],
                'economics' => [
                    'status' => 'incomplete',
                ],
                'demand' => [
                    'status' => 'not_started',
                    'assumptions' => 0,
                    'validated_assumptions' => 0,
                    'invalidated_assumptions' => 0,
                    'validation_activities' => 0,
                    'completed_validations' => 0,
                    'evidence_links' => 0,
                    'verified_evidence_links' => 0,
                ],
            ],
        ]);

        self::assertSame(
            'insufficient_evidence',
            $this->dimension($assessment, 'market_demand')['state'],
        );
        self::assertSame(
            'insufficient_evidence',
            $this->dimension($assessment, 'unit_economics')['state'],
        );
        self::assertSame(
            'insufficient_evidence',
            $this->dimension(
                $assessment,
                'business_model_competition',
            )['state'],
        );
        self::assertSame(
            'insufficient_evidence',
            $this->dimension($assessment, 'scalability')['state'],
        );

        foreach ($assessment['dimensions'] as $dimension) {
            self::assertNull($dimension['score']);
        }

        self::assertSame(0, $assessment['summary']['blocker']);
        self::assertNull($assessment['overall']['score']);
        self::assertNull($assessment['overall']['recommendation']);
        self::assertFalse(
            $assessment['semantics']['missingEvidenceIsFailure'],
        );
    }

    public function test_unavailable_future_dependencies_never_create_fake_low_scores_or_recommendations(): void
    {
        $engine = new DeepFeasibilityDimensionAssessmentEngine;

        $assessment = $engine->assess($this->completeFoundation());

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
            self::assertSame(
                'dependency_not_integrated',
                $dimension['resultCode'],
            );
        }

        self::assertNull($assessment['overall']['score']);
        self::assertNull($assessment['overall']['recommendation']);
        self::assertFalse(
            $assessment['semantics']['unavailableDependencyIsLowScore'],
        );
        self::assertSame(
            ['GO', 'CONDITIONAL GO', 'HOLD', 'NO-GO'],
            $assessment['overall']['recommendationModel'],
        );
    }

    public function test_non_positive_contribution_margin_is_a_deterministic_blocker_without_forcing_no_go(): void
    {
        $engine = new DeepFeasibilityDimensionAssessmentEngine;
        $foundation = $this->completeFoundation();
        $foundation['canonicalSources']['economics'] = [
            'status' => 'non_positive_margin',
            'contributionMarginPerUnit' => '-20.00',
            'grossMarginPercent' => -20.0,
            'breakEvenUnits' => null,
            'breakEvenRevenue' => null,
            'expectedMonthlyRevenue' => null,
            'expectedMonthlyGrossProfit' => null,
            'expectedMonthlyOperatingProfit' => null,
        ];

        $assessment = $engine->assess($foundation);
        $unitEconomics = $this->dimension(
            $assessment,
            'unit_economics',
        );

        self::assertSame('blocker', $unitEconomics['state']);
        self::assertSame(
            'non_positive_contribution_margin',
            $unitEconomics['resultCode'],
        );
        self::assertSame('-20.00', $unitEconomics['evidence'][
            'contributionMarginPerUnit'
        ]);
        self::assertSame(1, $assessment['summary']['blocker']);
        self::assertSame(
            'unit_economics',
            $assessment['blockers'][0]['dimension'],
        );

        self::assertNull($assessment['overall']['score']);
        self::assertNull($assessment['overall']['recommendation']);
    }

    public function test_invalid_or_incomplete_price_data_remains_insufficient_evidence_not_a_blocker(): void
    {
        $engine = new DeepFeasibilityDimensionAssessmentEngine;
        $foundation = $this->completeFoundation();
        $foundation['canonicalSources']['economics'] = [
            'status' => 'invalid_price',
            'contributionMarginPerUnit' => '-40.00',
            'grossMarginPercent' => null,
            'breakEvenUnits' => null,
            'breakEvenRevenue' => null,
            'expectedMonthlyRevenue' => null,
            'expectedMonthlyGrossProfit' => null,
            'expectedMonthlyOperatingProfit' => null,
        ];

        $assessment = $engine->assess($foundation);
        $unitEconomics = $this->dimension(
            $assessment,
            'unit_economics',
        );

        self::assertSame(
            'insufficient_evidence',
            $unitEconomics['state'],
        );
        self::assertSame(0, $assessment['summary']['blocker']);
        self::assertNull($assessment['overall']['recommendation']);
    }

    /**
     * @return array<string,mixed>
     */
    private function completeFoundation(): array
    {
        return [
            'canonicalSources' => [
                'businessModel' => [
                    'businessPurpose' => 'Structured SME partnership setup.',
                    'market' => 'Myanmar-owned SMEs.',
                    'location' => 'Myanmar and Thailand',
                    'competitionAlternatives' => 'Spreadsheets and ERP.',
                    'operatingModel' => 'Guided setup and support.',
                    'pricingNotes' => 'Planning price.',
                    'valueProposition' => 'Auditable partnership decisions.',
                    'channels' => 'Direct consultation.',
                    'revenueStreams' => 'Setup and support fees.',
                    'costStructure' => 'People and software.',
                    'scalabilityStrategy' => 'Standardize delivery.',
                    'scalabilityConstraints' => 'Advisor capacity.',
                    'first12MonthPlan' => 'Validate, launch, standardize.',
                ],
                'economics' => [
                    'status' => 'ready',
                    'contributionMarginPerUnit' => '60.00',
                    'grossMarginPercent' => 60.0,
                    'breakEvenUnits' => 50.0,
                    'breakEvenRevenue' => '5000.00',
                    'expectedMonthlyRevenue' => '8000.00',
                    'expectedMonthlyGrossProfit' => '4800.00',
                    'expectedMonthlyOperatingProfit' => '1800.00',
                ],
                'demand' => [
                    'status' => 'validated',
                    'assumptions' => 1,
                    'validated_assumptions' => 1,
                    'invalidated_assumptions' => 0,
                    'validation_activities' => 1,
                    'completed_validations' => 1,
                    'evidence_links' => 1,
                    'verified_evidence_links' => 1,
                ],
                'businessValuation' => [
                    'applicable' => false,
                    'status' => 'skipped',
                    'reason' => 'new_business_default_skip',
                ],
            ],
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
}
