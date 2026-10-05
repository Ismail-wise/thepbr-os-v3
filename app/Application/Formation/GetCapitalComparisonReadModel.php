<?php

declare(strict_types=1);

namespace App\Application\Formation;

use App\Domain\Capital\CapitalCalculationEngine;
use App\Domain\Capital\CapitalComparisonDraftContract;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;

final class GetCapitalComparisonReadModel
{
    public function __construct(
        private readonly GetCapitalComparisonDraft $comparisons,
        private readonly GetCapitalPlanningDraft $planning,
        private readonly CalculateCapitalFoundation $calculator,
    ) {}

    /**
     * @return array<string,mixed>|null
     */
    public function execute(
        User $user,
        Business $business,
    ): ?array {
        $draft = $this->comparisons->execute($user, $business);
        $canonical = $this->planning->execute($user, $business);

        if ($draft === null || $canonical === null) {
            return null;
        }

        $currentPlanningRevision = (int) $canonical['revision'];
        $canInitialize = $currentPlanningRevision > 0
            && is_array($canonical['input']);

        if ((int) $draft['revision'] === 0 || $draft['input'] === null) {
            return [
                'contractVersion' => 'capital-comparison-read-model-v1',
                'draftContractVersion' => CapitalComparisonDraftContract::CONTRACT_VERSION,
                'calculationContractVersion' => CapitalCalculationEngine::CONTRACT_VERSION,
                'revision' => 0,
                'capitalPlanningRevision' => $currentPlanningRevision,
                'preparedAgainstCapitalRevision' => null,
                'canInitialize' => $canInitialize,
                'needsReview' => false,
                'status' => 'not_started',
                'ready' => false,
                'preferredPlan' => null,
                'preferredReadyForNextStage' => false,
                'scenarios' => $this->emptyScenarios(),
                'semantics' => $this->semantics(),
            ];
        }

        $preparedAgainst = (int) $draft['capitalPlanningRevision'];
        $needsReview = $preparedAgainst !== $currentPlanningRevision;
        $scenarioInputs = $draft['input']['scenarios'] ?? [];
        $scenarios = [];

        foreach (CapitalComparisonDraftContract::SCENARIO_KEYS as $key) {
            $input = is_array($scenarioInputs)
                ? ($scenarioInputs[$key] ?? null)
                : null;

            $scenario = $this->scenario(
                $user,
                $business,
                $key,
                $input,
                $needsReview,
            );

            if ($scenario === null) {
                return null;
            }

            $scenarios[] = $scenario;
        }

        $allReady = $scenarios !== []
            && collect($scenarios)->every(
                static fn (array $scenario): bool => $scenario['readiness'] === 'ready_for_comparison',
            );

        $status = match (true) {
            $needsReview => 'needs_review',
            $allReady => 'ready_for_comparison',
            default => 'incomplete',
        };

        $preferred = $draft['input']['preferredPlan'] ?? null;
        $preferredScenario = is_string($preferred)
            ? collect($scenarios)->firstWhere('scenarioKey', $preferred)
            : null;

        $preferredReady = $status === 'ready_for_comparison'
            && is_array($preferredScenario)
            && $preferredScenario['readiness'] === 'ready_for_comparison';

        return [
            'contractVersion' => 'capital-comparison-read-model-v1',
            'draftContractVersion' => CapitalComparisonDraftContract::CONTRACT_VERSION,
            'calculationContractVersion' => CapitalCalculationEngine::CONTRACT_VERSION,
            'revision' => (int) $draft['revision'],
            'capitalPlanningRevision' => $currentPlanningRevision,
            'preparedAgainstCapitalRevision' => $preparedAgainst,
            'canInitialize' => $canInitialize,
            'needsReview' => $needsReview,
            'status' => $status,
            'ready' => $status === 'ready_for_comparison',
            'preferredPlan' => is_string($preferred) ? $preferred : null,
            'preferredReadyForNextStage' => $preferredReady,
            'scenarios' => $scenarios,
            'semantics' => $this->semantics(),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $input
     * @return array<string,mixed>|null
     */
    private function scenario(
        User $user,
        Business $business,
        string $key,
        ?array $input,
        bool $needsReview,
    ): ?array {
        if ($input === null) {
            return [
                'scenarioKey' => $key,
                'readiness' => 'not_started',
                'missingRequirements' => ['scenario_not_prepared'],
                'workingCapitalMethod' => null,
                'workingCapital' => null,
                'workingCapitalMonthsApplicable' => false,
                'workingCapitalMonths' => null,
                'contingencyMethod' => null,
                'contingencyAmount' => null,
                'contingencyPercentageApplicable' => false,
                'contingencyPercentage' => null,
                'totalCapitalRequirement' => null,
                'confirmedFunding' => null,
                'fundingGap' => null,
                'fundingSurplus' => null,
                'fundedPercentage' => null,
                'calculation' => null,
            ];
        }

        $calculation = $this->calculator->execute(
            $user,
            $business,
            $input,
        );

        if ($calculation === null) {
            return null;
        }

        $missing = $this->missingRequirements($calculation);
        $ready = $missing === [];
        $working = is_array($input['workingCapital'] ?? null)
            ? $input['workingCapital']
            : null;
        $contingency = is_array($input['contingency'] ?? null)
            ? $input['contingency']
            : null;

        $workingMethod = is_array($working)
            ? ($working['method'] ?? null)
            : null;
        $workingMonthsApplicable = in_array(
            $workingMethod,
            [
                'canonical_operating_profile',
                'monthly_costs',
                'monthly_burn',
            ],
            true,
        );

        $contingencyMethod = is_array($contingency)
            ? ($contingency['method'] ?? null)
            : null;
        $contingencyPercentageApplicable =
            $contingencyMethod === 'percentage';

        $readiness = match (true) {
            ! $ready => 'incomplete',
            $needsReview => 'needs_review',
            default => 'ready_for_comparison',
        };

        return [
            'scenarioKey' => $key,
            'readiness' => $readiness,
            'missingRequirements' => $missing,
            'workingCapitalMethod' => $workingMethod,
            'workingCapital' => $calculation['workingCapital']['amount'] ?? null,
            'workingCapitalMonthsApplicable' => $workingMonthsApplicable,
            'workingCapitalMonths' => $workingMonthsApplicable
                ? ($working['months'] ?? null)
                : null,
            'contingencyMethod' => $contingencyMethod,
            'contingencyAmount' => $calculation['contingency']['amount'] ?? null,
            'contingencyPercentageApplicable' => $contingencyPercentageApplicable,
            'contingencyPercentage' => $contingencyPercentageApplicable
                ? ($contingency['percentage'] ?? null)
                : null,
            'totalCapitalRequirement' => $calculation[
                'totalCapitalRequirement'
            ]['amount'] ?? null,
            'confirmedFunding' => $calculation[
                'fundingPosition'
            ]['confirmedFunding'] ?? null,
            'fundingGap' => $calculation[
                'fundingPosition'
            ]['fundingGap'] ?? null,
            'fundingSurplus' => $calculation[
                'fundingPosition'
            ]['fundingSurplus'] ?? null,
            'fundedPercentage' => $calculation[
                'fundingPosition'
            ]['fundedPercentage'] ?? null,
            'calculation' => $calculation,
        ];
    }

    /**
     * @param  array<string,mixed>  $calculation
     * @return list<string>
     */
    private function missingRequirements(array $calculation): array
    {
        $missing = [];

        if (($calculation['preOpening']['status'] ?? null) !== 'calculable') {
            $missing[] = 'pre_opening';
        }

        if (
            ($calculation['initialAssetsInventory']['status'] ?? null)
            !== 'calculable'
        ) {
            $missing[] = 'initial_assets_inventory';
        }

        if (
            ($calculation['workingCapital']['status'] ?? null)
            !== 'calculable'
        ) {
            $missing[] = 'working_capital';
        }

        if (
            ($calculation['contingency']['status'] ?? null)
            !== 'calculable'
        ) {
            $missing[] = 'contingency_reserve';
        }

        if (
            ($calculation['totalCapitalRequirement']['status'] ?? null)
            !== 'calculable'
        ) {
            $missing[] = 'total_capital_requirement';
        }

        if (
            ($calculation['fundingPosition']['status'] ?? null)
            !== 'calculable'
        ) {
            $missing[] = 'confirmed_funding';
        }

        return array_values(array_unique($missing));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function emptyScenarios(): array
    {
        return array_map(
            static fn (string $key): array => [
                'scenarioKey' => $key,
                'readiness' => 'not_started',
                'missingRequirements' => ['comparison_not_prepared'],
                'workingCapitalMethod' => null,
                'workingCapital' => null,
                'workingCapitalMonthsApplicable' => false,
                'workingCapitalMonths' => null,
                'contingencyMethod' => null,
                'contingencyAmount' => null,
                'contingencyPercentageApplicable' => false,
                'contingencyPercentage' => null,
                'totalCapitalRequirement' => null,
                'confirmedFunding' => null,
                'fundingGap' => null,
                'fundingSurplus' => null,
                'fundedPercentage' => null,
                'calculation' => null,
            ],
            CapitalComparisonDraftContract::SCENARIO_KEYS,
        );
    }

    /**
     * @return array<string,bool>
     */
    private function semantics(): array
    {
        return [
            'planningAlternativesOnly' => true,
            'planningPreferenceOnly' => true,
            'approvedTruth' => false,
            'signedTruth' => false,
            'effectiveTruth' => false,
            'decisionComplete' => false,
            'capitalCallExecuted' => false,
            'fundingCommitmentTruth' => false,
            'contributionTruth' => false,
            'acceptedContributionTruth' => false,
            'equityTruth' => false,
            'ownershipTruth' => false,
        ];
    }
}
