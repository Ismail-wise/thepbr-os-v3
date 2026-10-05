<?php

declare(strict_types=1);

namespace App\Application\Formation;

final class DeepFeasibilityDimensionAssessmentEngine
{
    public const string CONTRACT_VERSION = 'deep-feasibility-dimensions-v1';

    public const string ENGINE_VERSION = 'deep-feasibility-dimension-engine-v1';

    public const string ASSESSED = 'assessed';

    public const string INSUFFICIENT_EVIDENCE = 'insufficient_evidence';

    public const string UNAVAILABLE_DEPENDENCY = 'unavailable_dependency';

    public const string BLOCKER = 'blocker';

    /**
     * Deterministic dimension assessment over the accepted Deep Feasibility
     * canonical-evidence foundation.
     *
     * This cycle intentionally does not calibrate a final feasibility score
     * or recommendation. Missing evidence and future dependencies never
     * become synthetic zero/low scores.
     *
     * @param  array<string,mixed>  $foundation
     * @return array<string,mixed>
     */
    public function assess(array $foundation): array
    {
        $sources = is_array($foundation['canonicalSources'] ?? null)
            ? $foundation['canonicalSources']
            : [];

        $dimensions = [
            $this->marketDemand(
                $this->arrayValue($sources, 'demand'),
            ),
            $this->unitEconomics(
                $this->arrayValue($sources, 'economics'),
            ),
            $this->businessModelCompetition(
                $this->arrayValue($sources, 'businessModel'),
            ),
            $this->scalability(
                $this->arrayValue($sources, 'businessModel'),
            ),
            $this->futureDependency(
                'capital',
                'Capital / Funding Readiness',
                'capital',
            ),
            $this->futureDependency(
                'partner_alignment',
                'Partner Alignment',
                'partner_dynamics',
            ),
            $this->futureDependency(
                'operations_readiness',
                'Operations Readiness',
                'operations',
            ),
            $this->futureDependency(
                'legal_risk',
                'Legal / Risk Readiness',
                'legal_risk',
            ),
            $this->futureDependency(
                'sales_readiness',
                'Sales Readiness',
                'sales_channels',
            ),
        ];

        $summary = [
            self::ASSESSED => 0,
            self::INSUFFICIENT_EVIDENCE => 0,
            self::UNAVAILABLE_DEPENDENCY => 0,
            self::BLOCKER => 0,
        ];

        foreach ($dimensions as $dimension) {
            $summary[$dimension['state']]++;
        }

        $blockers = array_values(array_map(
            static fn (array $dimension): array => [
                'dimension' => $dimension['key'],
                'resultCode' => $dimension['resultCode'],
                'evidence' => $dimension['evidence'],
            ],
            array_filter(
                $dimensions,
                static fn (array $dimension): bool => $dimension['state']
                    === self::BLOCKER,
            ),
        ));

        $missingEvidence = array_values(array_map(
            static fn (array $dimension): array => [
                'dimension' => $dimension['key'],
                'missing' => $dimension['missingEvidence'],
            ],
            array_filter(
                $dimensions,
                static fn (array $dimension): bool => $dimension['state']
                    === self::INSUFFICIENT_EVIDENCE,
            ),
        ));

        $unavailableDependencies = array_values(array_map(
            static fn (array $dimension): array => [
                'dimension' => $dimension['key'],
                'dependency' => $dimension['dependency'],
            ],
            array_filter(
                $dimensions,
                static fn (array $dimension): bool => $dimension['state']
                    === self::UNAVAILABLE_DEPENDENCY,
            ),
        ));

        $minimumEvidenceReady = $summary[self::INSUFFICIENT_EVIDENCE] === 0
            && $summary[self::UNAVAILABLE_DEPENDENCY] === 0;

        $overallReason = match (true) {
            $summary[self::UNAVAILABLE_DEPENDENCY] > 0 => 'required_dependencies_unavailable',
            $summary[self::INSUFFICIENT_EVIDENCE] > 0 => 'minimum_evidence_insufficient',
            default => 'overall_scoring_not_calibrated',
        };

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'engineVersion' => self::ENGINE_VERSION,
            'dimensions' => $dimensions,
            'summary' => $summary,
            'blockers' => $blockers,
            'missingEvidence' => $missingEvidence,
            'unavailableDependencies' => $unavailableDependencies,
            'overall' => [
                'score' => null,
                'recommendation' => null,
                'recommendationModel' => [
                    'GO',
                    'CONDITIONAL GO',
                    'HOLD',
                    'NO-GO',
                ],
                'minimumEvidenceReady' => $minimumEvidenceReady,
                'reasonCode' => $overallReason,
            ],
            'semantics' => [
                'deterministic' => true,
                'manualRatingQuestionnaire' => false,
                'missingEvidenceIsFailure' => false,
                'unavailableDependencyIsLowScore' => false,
                'successProbability' => false,
                'guaranteedBusinessSuccess' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $demand
     * @return array<string,mixed>
     */
    private function marketDemand(array $demand): array
    {
        $validated = (int) ($demand['validated_assumptions'] ?? 0);
        $completed = (int) ($demand['completed_validations'] ?? 0);

        $evidence = [
            'status' => (string) ($demand['status'] ?? 'not_started'),
            'assumptions' => (int) ($demand['assumptions'] ?? 0),
            'validatedAssumptions' => $validated,
            'invalidatedAssumptions' => (int) (
                $demand['invalidated_assumptions'] ?? 0
            ),
            'validationActivities' => (int) (
                $demand['validation_activities'] ?? 0
            ),
            'completedValidations' => $completed,
            'linkedEvidence' => (int) ($demand['evidence_links'] ?? 0),
            'verifiedEvidence' => (int) (
                $demand['verified_evidence_links'] ?? 0
            ),
        ];

        if (
            ($demand['status'] ?? null) !== 'validated'
            || $validated < 1
            || $completed < 1
        ) {
            return $this->dimension(
                key: 'market_demand',
                label: 'Market / Demand',
                state: self::INSUFFICIENT_EVIDENCE,
                resultCode: 'validated_demand_required',
                evidence: $evidence,
                missingEvidence: [
                    ...($validated < 1 ? ['validated_demand_assumption'] : []),
                    ...($completed < 1 ? ['completed_customer_validation'] : []),
                ],
            );
        }

        return $this->dimension(
            key: 'market_demand',
            label: 'Market / Demand',
            state: self::ASSESSED,
            resultCode: 'validated_demand_supported',
            evidence: $evidence,
        );
    }

    /**
     * @param  array<string,mixed>  $economics
     * @return array<string,mixed>
     */
    private function unitEconomics(array $economics): array
    {
        $status = (string) ($economics['status'] ?? 'incomplete');

        $evidence = [
            'status' => $status,
            'contributionMarginPerUnit' => $economics[
                'contributionMarginPerUnit'
            ] ?? null,
            'grossMarginPercent' => $economics['grossMarginPercent'] ?? null,
            'breakEvenUnits' => $economics['breakEvenUnits'] ?? null,
            'breakEvenRevenue' => $economics['breakEvenRevenue'] ?? null,
            'expectedMonthlyRevenue' => $economics[
                'expectedMonthlyRevenue'
            ] ?? null,
            'expectedMonthlyGrossProfit' => $economics[
                'expectedMonthlyGrossProfit'
            ] ?? null,
            'expectedMonthlyOperatingProfit' => $economics[
                'expectedMonthlyOperatingProfit'
            ] ?? null,
        ];

        if ($status === 'non_positive_margin') {
            return $this->dimension(
                key: 'unit_economics',
                label: 'Unit Economics / Financial Viability',
                state: self::BLOCKER,
                resultCode: 'non_positive_contribution_margin',
                evidence: $evidence,
            );
        }

        if ($status !== 'ready') {
            return $this->dimension(
                key: 'unit_economics',
                label: 'Unit Economics / Financial Viability',
                state: self::INSUFFICIENT_EVIDENCE,
                resultCode: 'complete_unit_economics_required',
                evidence: $evidence,
                missingEvidence: [
                    'valid_price_variable_cost_and_fixed_cost',
                ],
            );
        }

        $operatingProfit = $economics['expectedMonthlyOperatingProfit']
            ?? null;

        $resultCode = match (true) {
            $operatingProfit === null => 'positive_unit_margin_break_even_available',
            $this->isNegativeNumber($operatingProfit) => 'projected_operating_loss',
            $this->isZeroNumber($operatingProfit) => 'projected_operating_break_even',
            default => 'projected_operating_profit',
        };

        return $this->dimension(
            key: 'unit_economics',
            label: 'Unit Economics / Financial Viability',
            state: self::ASSESSED,
            resultCode: $resultCode,
            evidence: $evidence,
        );
    }

    /**
     * @param  array<string,mixed>  $model
     * @return array<string,mixed>
     */
    private function businessModelCompetition(array $model): array
    {
        $required = [
            'businessPurpose',
            'market',
            'valueProposition',
            'revenueStreams',
            'costStructure',
            'competitionAlternatives',
        ];

        $missing = $this->missingTextFields($model, $required);

        $evidence = [
            'businessPurpose' => $this->present(
                $model['businessPurpose'] ?? null,
            ),
            'market' => $this->present($model['market'] ?? null),
            'valueProposition' => $this->present(
                $model['valueProposition'] ?? null,
            ),
            'revenueStreams' => $this->present(
                $model['revenueStreams'] ?? null,
            ),
            'costStructure' => $this->present(
                $model['costStructure'] ?? null,
            ),
            'competitionAlternatives' => $this->present(
                $model['competitionAlternatives'] ?? null,
            ),
        ];

        if ($missing !== []) {
            return $this->dimension(
                key: 'business_model_competition',
                label: 'Business Model / Competition',
                state: self::INSUFFICIENT_EVIDENCE,
                resultCode: 'core_model_evidence_required',
                evidence: $evidence,
                missingEvidence: $missing,
            );
        }

        return $this->dimension(
            key: 'business_model_competition',
            label: 'Business Model / Competition',
            state: self::ASSESSED,
            resultCode: 'core_model_and_competition_documented',
            evidence: $evidence,
        );
    }

    /**
     * @param  array<string,mixed>  $model
     * @return array<string,mixed>
     */
    private function scalability(array $model): array
    {
        $required = [
            'scalabilityStrategy',
            'scalabilityConstraints',
        ];

        $missing = $this->missingTextFields($model, $required);

        $evidence = [
            'scalabilityStrategy' => $this->present(
                $model['scalabilityStrategy'] ?? null,
            ),
            'scalabilityConstraints' => $this->present(
                $model['scalabilityConstraints'] ?? null,
            ),
            'first12MonthPlan' => $this->present(
                $model['first12MonthPlan'] ?? null,
            ),
        ];

        if ($missing !== []) {
            return $this->dimension(
                key: 'scalability',
                label: 'Scalability',
                state: self::INSUFFICIENT_EVIDENCE,
                resultCode: 'scalability_evidence_required',
                evidence: $evidence,
                missingEvidence: $missing,
            );
        }

        return $this->dimension(
            key: 'scalability',
            label: 'Scalability',
            state: self::ASSESSED,
            resultCode: 'scalability_strategy_and_constraints_documented',
            evidence: $evidence,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function futureDependency(
        string $key,
        string $label,
        string $dependency,
    ): array {
        return $this->dimension(
            key: $key,
            label: $label,
            state: self::UNAVAILABLE_DEPENDENCY,
            resultCode: 'dependency_not_integrated',
            dependency: $dependency,
        );
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @param  list<string>  $missingEvidence
     * @return array<string,mixed>
     */
    private function dimension(
        string $key,
        string $label,
        string $state,
        string $resultCode,
        array $evidence = [],
        array $missingEvidence = [],
        ?string $dependency = null,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'state' => $state,
            'score' => null,
            'resultCode' => $resultCode,
            'evidence' => $evidence,
            'missingEvidence' => $missingEvidence,
            'dependency' => $dependency,
        ];
    }

    /**
     * @param  array<string,mixed>  $values
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function missingTextFields(
        array $values,
        array $fields,
    ): array {
        return array_values(array_filter(
            $fields,
            fn (string $field): bool => ! $this->present(
                $values[$field] ?? null,
            ),
        ));
    }

    private function present(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    private function arrayValue(array $values, string $key): array
    {
        return is_array($values[$key] ?? null)
            ? $values[$key]
            : [];
    }

    private function isNegativeNumber(mixed $value): bool
    {
        return is_numeric($value) && (float) $value < 0;
    }

    private function isZeroNumber(mixed $value): bool
    {
        return is_numeric($value) && (float) $value === 0.0;
    }
}
