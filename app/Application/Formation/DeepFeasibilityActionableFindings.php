<?php

declare(strict_types=1);

namespace App\Application\Formation;

use UnexpectedValueException;

final class DeepFeasibilityActionableFindings
{
    public const string CONTRACT_VERSION = 'deep-feasibility-findings-v1';

    /**
     * @param  array<string,mixed>  $assessment
     * @return array<string,mixed>
     */
    public function derive(array $assessment): array
    {
        $this->assertAcceptedAssessmentContract($assessment);

        $strengths = [];
        $risks = [];
        $blockers = [];
        $requiredActions = [];
        $readinessGaps = [];
        $goRequirements = [];

        foreach ($assessment['dimensions'] ?? [] as $dimension) {
            if (! is_array($dimension)) {
                continue;
            }

            $key = (string) ($dimension['key'] ?? '');
            $state = (string) ($dimension['state'] ?? '');
            $resultCode = (string) ($dimension['resultCode'] ?? '');

            if ($state === DeepFeasibilityDimensionAssessmentEngine::ASSESSED) {
                $strength = $this->strength(
                    $key,
                    $resultCode,
                );

                if ($strength !== null) {
                    $strengths[] = $strength;
                }

                $risk = $this->risk(
                    $key,
                    $resultCode,
                );

                if ($risk !== null) {
                    $risks[] = $risk;
                    $requiredActions[] = $risk['requiredAction'];
                    $goRequirements[] = $this->goRequirementFromAction(
                        $risk['requiredAction'],
                    );
                }

                continue;
            }

            if ($state === DeepFeasibilityDimensionAssessmentEngine::BLOCKER) {
                $blocker = $this->blocker(
                    $key,
                    $resultCode,
                );

                if ($blocker !== null) {
                    $blockers[] = $blocker;
                    $requiredActions[] = $blocker['requiredAction'];
                    $goRequirements[] = $this->goRequirementFromAction(
                        $blocker['requiredAction'],
                    );
                }

                continue;
            }

            if (
                $state
                === DeepFeasibilityDimensionAssessmentEngine::INSUFFICIENT_EVIDENCE
            ) {
                $missing = $this->stringList(
                    $dimension['missingEvidence'] ?? [],
                );

                $readinessGaps[] = [
                    'code' => 'gap.insufficient_evidence',
                    'dimension' => $key,
                    'dimensionState' => $state,
                    'resultCode' => $resultCode,
                    'gapType' => 'evidence',
                    'requirements' => $missing,
                ];

                foreach ($this->evidenceActions(
                    $key,
                    $resultCode,
                    $missing,
                ) as $action) {
                    $requiredActions[] = $action;
                    $goRequirements[] = $this->goRequirementFromAction(
                        $action,
                    );
                }

                continue;
            }

            if (
                $state
                === DeepFeasibilityDimensionAssessmentEngine::UNAVAILABLE_DEPENDENCY
            ) {
                $dependency = (string) (
                    $dimension['dependency'] ?? ''
                );

                $gap = [
                    'code' => 'gap.unavailable_dependency',
                    'dimension' => $key,
                    'dimensionState' => $state,
                    'resultCode' => $resultCode,
                    'gapType' => 'dependency',
                    'dependency' => $dependency,
                ];

                $readinessGaps[] = $gap;

                $action = $this->dependencyAction(
                    $key,
                    $resultCode,
                    $dependency,
                );

                $requiredActions[] = $action;
                $goRequirements[] = $this->goRequirementFromAction(
                    $action,
                );
            }
        }

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'sourceAssessmentContractVersion' => (string) (
                $assessment['contractVersion'] ?? ''
            ),
            'sourceEngineVersion' => (string) (
                $assessment['engineVersion'] ?? ''
            ),
            'strengths' => $this->withoutEmbeddedActions($strengths),
            'risks' => $this->withoutEmbeddedActions($risks),
            'blockers' => $this->withoutEmbeddedActions($blockers),
            'requiredActions' => $requiredActions,
            'readinessGaps' => $readinessGaps,
            'goReadiness' => [
                'recommendation' => null,
                'evaluationAvailable' => false,
                'reasonCode' => (string) (
                    $assessment['overall']['reasonCode']
                    ?? 'overall_recommendation_unavailable'
                ),
                'requirements' => $goRequirements,
                'requirementsDoNotGuaranteeGo' => true,
            ],
            'semantics' => [
                'deterministic' => true,
                'derivedFromAssessmentOnly' => true,
                'officialAiJudgment' => false,
                'missingEvidenceIsNegativeJudgment' => false,
                'unavailableDependencyIsRisk' => false,
                'unavailableDependencyIsBlocker' => false,
                'successProbability' => false,
                'guaranteedBusinessSuccess' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function strength(
        string $dimension,
        string $resultCode,
    ): ?array {
        return match ($resultCode) {
            'validated_demand_supported' => $this->finding(
                'strength.validated_demand_supported',
                'strength',
                $dimension,
                $resultCode,
                [
                    'validatedAssumptions',
                    'completedValidations',
                ],
            ),
            'projected_operating_profit' => $this->finding(
                'strength.projected_operating_profit',
                'strength',
                $dimension,
                $resultCode,
                [
                    'contributionMarginPerUnit',
                    'breakEvenRevenue',
                    'expectedMonthlyOperatingProfit',
                ],
            ),
            'positive_unit_margin_break_even_available' => $this->finding(
                'strength.positive_unit_margin_break_even_available',
                'strength',
                $dimension,
                $resultCode,
                [
                    'contributionMarginPerUnit',
                    'breakEvenRevenue',
                ],
            ),
            'core_model_and_competition_documented' => $this->finding(
                'strength.core_model_competition_documented',
                'strength',
                $dimension,
                $resultCode,
                [
                    'businessPurpose',
                    'market',
                    'valueProposition',
                    'revenueStreams',
                    'costStructure',
                    'competitionAlternatives',
                ],
            ),
            'scalability_strategy_and_constraints_documented' => $this->finding(
                'strength.scalability_documented',
                'strength',
                $dimension,
                $resultCode,
                [
                    'scalabilityStrategy',
                    'scalabilityConstraints',
                ],
            ),
            default => null,
        };
    }

    /**
     * @return array<string,mixed>|null
     */
    private function risk(
        string $dimension,
        string $resultCode,
    ): ?array {
        return match ($resultCode) {
            'projected_operating_loss' => [
                ...$this->finding(
                    'risk.projected_operating_loss',
                    'risk',
                    $dimension,
                    $resultCode,
                    [
                        'expectedMonthlyOperatingProfit',
                        'breakEvenRevenue',
                    ],
                ),
                'requiredAction' => $this->action(
                    'action.resolve_projected_operating_loss',
                    $dimension,
                    DeepFeasibilityDimensionAssessmentEngine::ASSESSED,
                    $resultCode,
                    'corrective',
                    conditionCode: 'expected_monthly_operating_profit_gte_zero',
                    evidenceRefs: [
                        'expectedMonthlyOperatingProfit',
                        'breakEvenRevenue',
                    ],
                ),
            ],
            'projected_operating_break_even' => [
                ...$this->finding(
                    'risk.projected_operating_break_even',
                    'risk',
                    $dimension,
                    $resultCode,
                    [
                        'expectedMonthlyOperatingProfit',
                        'breakEvenRevenue',
                    ],
                ),
                'requiredAction' => $this->action(
                    'action.create_positive_operating_margin',
                    $dimension,
                    DeepFeasibilityDimensionAssessmentEngine::ASSESSED,
                    $resultCode,
                    'corrective',
                    conditionCode: 'expected_monthly_operating_profit_gt_zero',
                    evidenceRefs: [
                        'expectedMonthlyOperatingProfit',
                        'breakEvenRevenue',
                    ],
                ),
            ],
            default => null,
        };
    }

    /**
     * @return array<string,mixed>|null
     */
    private function blocker(
        string $dimension,
        string $resultCode,
    ): ?array {
        return match ($resultCode) {
            'non_positive_contribution_margin' => [
                ...$this->finding(
                    'blocker.non_positive_contribution_margin',
                    'blocker',
                    $dimension,
                    $resultCode,
                    ['contributionMarginPerUnit'],
                ),
                'requiredAction' => $this->action(
                    'action.restore_positive_contribution_margin',
                    $dimension,
                    DeepFeasibilityDimensionAssessmentEngine::BLOCKER,
                    $resultCode,
                    'corrective',
                    conditionCode: 'contribution_margin_per_unit_gt_zero',
                    evidenceRefs: ['contributionMarginPerUnit'],
                ),
            ],
            default => null,
        };
    }

    /**
     * @param  list<string>  $missing
     * @return list<array<string,mixed>>
     */
    private function evidenceActions(
        string $dimension,
        string $resultCode,
        array $missing,
    ): array {
        if ($missing === []) {
            return [
                $this->action(
                    'action.provide_required_evidence',
                    $dimension,
                    DeepFeasibilityDimensionAssessmentEngine::INSUFFICIENT_EVIDENCE,
                    $resultCode,
                    'evidence',
                    requirementCode: 'dimension_minimum_evidence',
                ),
            ];
        }

        return array_map(
            fn (string $requirement): array => $this->action(
                $this->evidenceActionCode($requirement),
                $dimension,
                DeepFeasibilityDimensionAssessmentEngine::INSUFFICIENT_EVIDENCE,
                $resultCode,
                'evidence',
                requirementCode: $requirement,
            ),
            $missing,
        );
    }

    private function evidenceActionCode(string $requirement): string
    {
        return match ($requirement) {
            'validated_demand_assumption' => 'action.obtain_validated_demand_assumption',
            'completed_customer_validation' => 'action.complete_customer_validation',
            'valid_price_variable_cost_and_fixed_cost' => 'action.complete_unit_economics_inputs',
            'businessPurpose',
            'market',
            'valueProposition',
            'revenueStreams',
            'costStructure',
            'competitionAlternatives' => 'action.complete_business_model_evidence',
            'scalabilityStrategy',
            'scalabilityConstraints' => 'action.complete_scalability_evidence',
            default => 'action.provide_required_evidence',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function dependencyAction(
        string $dimension,
        string $resultCode,
        string $dependency,
    ): array {
        $code = match ($dependency) {
            'capital' => 'action.make_capital_readiness_available',
            'partner_dynamics' => 'action.make_partner_alignment_available',
            'operations' => 'action.make_operations_readiness_available',
            'legal_risk' => 'action.make_legal_risk_readiness_available',
            'sales_channels' => 'action.make_sales_readiness_available',
            default => 'action.make_dependency_available',
        };

        return $this->action(
            $code,
            $dimension,
            DeepFeasibilityDimensionAssessmentEngine::UNAVAILABLE_DEPENDENCY,
            $resultCode,
            'dependency',
            requirementCode: $dependency,
        );
    }

    /**
     * @param  list<string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function finding(
        string $code,
        string $kind,
        string $dimension,
        string $resultCode,
        array $evidenceRefs,
    ): array {
        return [
            'code' => $code,
            'kind' => $kind,
            'dimension' => $dimension,
            'dimensionState' => $kind === 'blocker'
                ? DeepFeasibilityDimensionAssessmentEngine::BLOCKER
                : DeepFeasibilityDimensionAssessmentEngine::ASSESSED,
            'resultCode' => $resultCode,
            'reasonRef' => 'dimension.result',
            'evidenceRefs' => $evidenceRefs,
        ];
    }

    /**
     * @param  list<string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function action(
        string $code,
        string $dimension,
        string $triggerState,
        string $triggerResultCode,
        string $actionType,
        ?string $conditionCode = null,
        ?string $requirementCode = null,
        array $evidenceRefs = [],
    ): array {
        return [
            'code' => $code,
            'dimension' => $dimension,
            'triggerState' => $triggerState,
            'triggerResultCode' => $triggerResultCode,
            'actionType' => $actionType,
            'conditionCode' => $conditionCode,
            'requirementCode' => $requirementCode,
            'evidenceRefs' => $evidenceRefs,
        ];
    }

    /**
     * @param  array<string,mixed>  $action
     * @return array<string,mixed>
     */
    private function goRequirementFromAction(array $action): array
    {
        return [
            'code' => 'go_requirement.'.substr(
                (string) $action['code'],
                strlen('action.'),
            ),
            'dimension' => $action['dimension'],
            'requirementType' => $action['actionType'],
            'triggerState' => $action['triggerState'],
            'triggerResultCode' => $action['triggerResultCode'],
            'conditionCode' => $action['conditionCode'],
            'requirementCode' => $action['requirementCode'],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @return list<array<string,mixed>>
     */
    private function withoutEmbeddedActions(array $findings): array
    {
        return array_map(
            static function (array $finding): array {
                unset($finding['requiredAction']);

                return $finding;
            },
            $findings,
        );
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $item): string => (string) $item,
            array_filter(
                $value,
                static fn (mixed $item): bool => is_string($item)
                    && trim($item) !== '',
            ),
        ));
    }

    /**
     * @param  array<string,mixed>  $assessment
     */
    private function assertAcceptedAssessmentContract(
        array $assessment,
    ): void {
        if (
            ($assessment['contractVersion'] ?? null)
                !== DeepFeasibilityDimensionAssessmentEngine::CONTRACT_VERSION
            || ($assessment['engineVersion'] ?? null)
                !== DeepFeasibilityDimensionAssessmentEngine::ENGINE_VERSION
        ) {
            throw new UnexpectedValueException(
                'Deep Feasibility findings require the accepted dimension assessment contract.',
            );
        }
    }
}
