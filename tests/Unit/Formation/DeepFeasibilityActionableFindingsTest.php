<?php

declare(strict_types=1);

namespace Tests\Unit\Formation;

use App\Application\Formation\DeepFeasibilityActionableFindings;
use App\Application\Formation\DeepFeasibilityDimensionAssessmentEngine;
use PHPUnit\Framework\TestCase;

final class DeepFeasibilityActionableFindingsTest extends TestCase
{
    public function test_same_assessment_produces_the_same_versioned_findings(): void
    {
        $findings = new DeepFeasibilityActionableFindings;
        $assessment = $this->completeAssessment();

        $first = $findings->derive($assessment);
        $second = $findings->derive($assessment);

        self::assertSame($first, $second);
        self::assertSame(
            'deep-feasibility-findings-v1',
            $first['contractVersion'],
        );
        self::assertSame(
            DeepFeasibilityDimensionAssessmentEngine::CONTRACT_VERSION,
            $first['sourceAssessmentContractVersion'],
        );
        self::assertSame(
            DeepFeasibilityDimensionAssessmentEngine::ENGINE_VERSION,
            $first['sourceEngineVersion'],
        );
        self::assertNull($first['goReadiness']['recommendation']);
        self::assertFalse($first['goReadiness']['evaluationAvailable']);
        self::assertTrue(
            $first['goReadiness']['requirementsDoNotGuaranteeGo'],
        );
    }

    public function test_positive_assessed_dimensions_produce_only_supported_strengths(): void
    {
        $result = (new DeepFeasibilityActionableFindings)
            ->derive($this->completeAssessment());

        self::assertSame(
            [
                'strength.validated_demand_supported',
                'strength.projected_operating_profit',
                'strength.core_model_competition_documented',
                'strength.scalability_documented',
            ],
            array_column($result['strengths'], 'code'),
        );

        self::assertSame([], $result['risks']);
        self::assertSame([], $result['blockers']);

        foreach ($result['strengths'] as $strength) {
            self::assertSame('assessed', $strength['dimensionState']);
            self::assertNotSame([], $strength['evidenceRefs']);
            self::assertSame(
                'dimension.result',
                $strength['reasonRef'],
            );
        }
    }

    public function test_objective_blocker_produces_blocker_corrective_action_and_clearance_condition(): void
    {
        $assessment = $this->completeAssessment();
        $assessment['dimensions'][1] = $this->dimension(
            key: 'unit_economics',
            state: 'blocker',
            resultCode: 'non_positive_contribution_margin',
            evidence: [
                'contributionMarginPerUnit' => '-20.00',
            ],
        );
        $assessment['summary']['assessed'] = 3;
        $assessment['summary']['blocker'] = 1;

        $result = (new DeepFeasibilityActionableFindings)
            ->derive($assessment);

        self::assertCount(1, $result['blockers']);
        self::assertSame(
            'blocker.non_positive_contribution_margin',
            $result['blockers'][0]['code'],
        );
        self::assertSame(
            'unit_economics',
            $result['blockers'][0]['dimension'],
        );
        self::assertSame(
            'non_positive_contribution_margin',
            $result['blockers'][0]['resultCode'],
        );

        $action = $this->action(
            $result,
            'action.restore_positive_contribution_margin',
        );

        self::assertSame('corrective', $action['actionType']);
        self::assertSame(
            'contribution_margin_per_unit_gt_zero',
            $action['conditionCode'],
        );
        self::assertSame(
            'non_positive_contribution_margin',
            $action['triggerResultCode'],
        );

        $goRequirement = $this->goRequirement(
            $result,
            'go_requirement.restore_positive_contribution_margin',
        );

        self::assertSame(
            'contribution_margin_per_unit_gt_zero',
            $goRequirement['conditionCode'],
        );
        self::assertNull($result['goReadiness']['recommendation']);
    }

    public function test_insufficient_evidence_creates_evidence_gap_not_negative_business_judgment(): void
    {
        $assessment = $this->completeAssessment();
        $assessment['dimensions'][0] = $this->dimension(
            key: 'market_demand',
            state: 'insufficient_evidence',
            resultCode: 'validated_demand_required',
            missingEvidence: [
                'validated_demand_assumption',
                'completed_customer_validation',
            ],
        );
        $assessment['summary']['assessed'] = 3;
        $assessment['summary']['insufficient_evidence'] = 1;

        $result = (new DeepFeasibilityActionableFindings)
            ->derive($assessment);

        self::assertSame([], $result['blockers']);
        self::assertSame([], $result['risks']);

        $gap = $result['readinessGaps'][0];
        self::assertSame('gap.insufficient_evidence', $gap['code']);
        self::assertSame('evidence', $gap['gapType']);
        self::assertSame('market_demand', $gap['dimension']);
        self::assertSame(
            [
                'validated_demand_assumption',
                'completed_customer_validation',
            ],
            $gap['requirements'],
        );

        self::assertNotNull(
            $this->action(
                $result,
                'action.obtain_validated_demand_assumption',
            ),
        );
        self::assertNotNull(
            $this->action(
                $result,
                'action.complete_customer_validation',
            ),
        );
        self::assertFalse(
            $result['semantics']['missingEvidenceIsNegativeJudgment'],
        );
    }

    public function test_unavailable_dependency_creates_readiness_requirement_not_risk_blocker_or_zero_score(): void
    {
        $result = (new DeepFeasibilityActionableFindings)
            ->derive($this->completeAssessment());

        self::assertSame([], $result['risks']);
        self::assertSame([], $result['blockers']);

        $capitalGap = $this->gap($result, 'capital');

        self::assertSame(
            'unavailable_dependency',
            $capitalGap['dimensionState'],
        );
        self::assertSame('dependency', $capitalGap['gapType']);
        self::assertSame('capital', $capitalGap['dependency']);

        $capitalAction = $this->action(
            $result,
            'action.make_capital_readiness_available',
        );

        self::assertSame('dependency', $capitalAction['actionType']);
        self::assertSame(
            'capital',
            $capitalAction['requirementCode'],
        );
        self::assertFalse(
            $result['semantics']['unavailableDependencyIsRisk'],
        );
        self::assertFalse(
            $result['semantics']['unavailableDependencyIsBlocker'],
        );

        foreach ($result['requiredActions'] as $action) {
            self::assertArrayNotHasKey('score', $action);
        }

        self::assertNull($result['goReadiness']['recommendation']);
    }

    public function test_projected_operating_loss_is_a_traceable_risk_not_a_forced_no_go(): void
    {
        $assessment = $this->completeAssessment();
        $assessment['dimensions'][1] = $this->dimension(
            key: 'unit_economics',
            state: 'assessed',
            resultCode: 'projected_operating_loss',
            evidence: [
                'expectedMonthlyOperatingProfit' => '-500.00',
                'breakEvenRevenue' => '9000.00',
            ],
        );

        $result = (new DeepFeasibilityActionableFindings)
            ->derive($assessment);

        self::assertCount(1, $result['risks']);
        self::assertSame(
            'risk.projected_operating_loss',
            $result['risks'][0]['code'],
        );
        self::assertSame(
            'projected_operating_loss',
            $result['risks'][0]['resultCode'],
        );
        self::assertSame(
            [
                'expectedMonthlyOperatingProfit',
                'breakEvenRevenue',
            ],
            $result['risks'][0]['evidenceRefs'],
        );

        $action = $this->action(
            $result,
            'action.resolve_projected_operating_loss',
        );
        self::assertSame(
            'expected_monthly_operating_profit_gte_zero',
            $action['conditionCode'],
        );

        self::assertNull($result['goReadiness']['recommendation']);
        self::assertNotContains(
            'NO-GO',
            array_column($result['requiredActions'], 'code'),
        );
    }

    public function test_findings_never_copy_raw_dimension_evidence_or_private_payload_values(): void
    {
        $assessment = $this->completeAssessment();
        $assessment['dimensions'][0]['evidence'] = [
            'privateDocumentContent' => 'NEVER-LEAK-DOCUMENT-CONTENT',
            'privatePartnerAnswer' => 'NEVER-LEAK-PARTNER-ANSWER',
            'internalEvidenceId' => '019db-private-id',
        ];

        $result = (new DeepFeasibilityActionableFindings)
            ->derive($assessment);

        $serialized = json_encode(
            $result,
            JSON_THROW_ON_ERROR,
        );

        self::assertStringNotContainsString(
            'NEVER-LEAK-DOCUMENT-CONTENT',
            $serialized,
        );
        self::assertStringNotContainsString(
            'NEVER-LEAK-PARTNER-ANSWER',
            $serialized,
        );
        self::assertStringNotContainsString(
            '019db-private-id',
            $serialized,
        );
        self::assertStringNotContainsString(
            'privateDocumentContent',
            $serialized,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function completeAssessment(): array
    {
        return [
            'contractVersion' => DeepFeasibilityDimensionAssessmentEngine::CONTRACT_VERSION,
            'engineVersion' => DeepFeasibilityDimensionAssessmentEngine::ENGINE_VERSION,
            'dimensions' => [
                $this->dimension(
                    'market_demand',
                    'assessed',
                    'validated_demand_supported',
                ),
                $this->dimension(
                    'unit_economics',
                    'assessed',
                    'projected_operating_profit',
                ),
                $this->dimension(
                    'business_model_competition',
                    'assessed',
                    'core_model_and_competition_documented',
                ),
                $this->dimension(
                    'scalability',
                    'assessed',
                    'scalability_strategy_and_constraints_documented',
                ),
                $this->dimension(
                    'capital',
                    'unavailable_dependency',
                    'dependency_not_integrated',
                    dependency: 'capital',
                ),
                $this->dimension(
                    'partner_alignment',
                    'unavailable_dependency',
                    'dependency_not_integrated',
                    dependency: 'partner_dynamics',
                ),
                $this->dimension(
                    'operations_readiness',
                    'unavailable_dependency',
                    'dependency_not_integrated',
                    dependency: 'operations',
                ),
                $this->dimension(
                    'legal_risk',
                    'unavailable_dependency',
                    'dependency_not_integrated',
                    dependency: 'legal_risk',
                ),
                $this->dimension(
                    'sales_readiness',
                    'unavailable_dependency',
                    'dependency_not_integrated',
                    dependency: 'sales_channels',
                ),
            ],
            'summary' => [
                'assessed' => 4,
                'insufficient_evidence' => 0,
                'unavailable_dependency' => 5,
                'blocker' => 0,
            ],
            'blockers' => [],
            'missingEvidence' => [],
            'unavailableDependencies' => [],
            'overall' => [
                'score' => null,
                'recommendation' => null,
                'reasonCode' => 'required_dependencies_unavailable',
            ],
        ];
    }

    /**
     * @param  list<string>  $missingEvidence
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function dimension(
        string $key,
        string $state,
        string $resultCode,
        array $missingEvidence = [],
        ?string $dependency = null,
        array $evidence = [],
    ): array {
        return [
            'key' => $key,
            'label' => $key,
            'state' => $state,
            'score' => null,
            'resultCode' => $resultCode,
            'evidence' => $evidence,
            'missingEvidence' => $missingEvidence,
            'dependency' => $dependency,
        ];
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function action(
        array $result,
        string $code,
    ): array {
        foreach ($result['requiredActions'] as $action) {
            if ($action['code'] === $code) {
                return $action;
            }
        }

        self::fail("Action [{$code}] not found.");
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function gap(
        array $result,
        string $dimension,
    ): array {
        foreach ($result['readinessGaps'] as $gap) {
            if ($gap['dimension'] === $dimension) {
                return $gap;
            }
        }

        self::fail("Gap [{$dimension}] not found.");
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function goRequirement(
        array $result,
        string $code,
    ): array {
        foreach ($result['goReadiness']['requirements'] as $requirement) {
            if ($requirement['code'] === $code) {
                return $requirement;
            }
        }

        self::fail("GO requirement [{$code}] not found.");
    }
}
