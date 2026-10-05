<?php

declare(strict_types=1);

namespace App\Application\Formation;

use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;

final class GetCapitalRuleReadModel
{
    public function __construct(
        private readonly GetCapitalPlanningDraftCalculation $calculations,
        private readonly GetCapitalRuleDraft $rules,
    ) {}

    /**
     * @return array<string,mixed>|null
     */
    public function execute(
        User $user,
        Business $business,
    ): ?array {
        $calculationEnvelope = $this->calculations->execute(
            $user,
            $business,
        );
        $rule = $this->rules->execute($user, $business);

        if ($calculationEnvelope === null || $rule === null) {
            return null;
        }

        $planningRevision = (int) (
            $calculationEnvelope['revision'] ?? 0
        );
        $calculation = $calculationEnvelope['calculation'] ?? null;

        $missing = $this->missingRequirements($calculation);
        $complete = $calculation !== null && $missing === [];

        $gapMinor = $complete
            ? $this->minor(
                $calculation['fundingPosition']['fundingGap'] ?? null,
            )
            : null;
        $surplusMinor = $complete
            ? $this->minor(
                $calculation['fundingPosition']['fundingSurplus'] ?? null,
            )
            : null;

        $shortfallRequired = $gapMinor !== null && $gapMinor > 0;
        $responses = is_array($rule['input'])
            ? ($rule['input']['shortfallResponses'] ?? [])
            : [];
        $responseRecorded = is_array($responses) && $responses !== [];

        $needsReview = (int) $rule['revision'] > 0
            && (int) $rule['capitalPlanningRevision'] !== $planningRevision;

        $ready = (int) $rule['revision'] > 0
            && $complete
            && ! $needsReview
            && (! $shortfallRequired || $responseRecorded);

        $status = match (true) {
            ! $complete => 'needs_prior_capital_inputs',
            $needsReview => 'needs_review',
            $shortfallRequired && ! $responseRecorded => 'needs_shortfall_rule',
            (int) $rule['revision'] === 0 => 'not_saved',
            default => 'ready',
        };

        $fundingState = match (true) {
            ! $complete => 'unavailable',
            $gapMinor !== null && $gapMinor > 0 => 'shortfall',
            $surplusMinor !== null && $surplusMinor > 0 => 'surplus',
            default => 'funded',
        };

        return [
            'contractVersion' => 'capital-rule-read-model-v1',
            'capitalPlanningRevision' => $planningRevision,
            'calculationContractVersion' => $calculation['contractVersion']
                ?? null,
            'allocationSummary' => $this->summary($calculation),
            'missingRequirements' => $missing,
            'calculationComplete' => $complete,
            'fundingState' => $fundingState,
            'shortfallRequired' => $shortfallRequired,
            'shortfallResponseRecorded' => $responseRecorded,
            'needsReview' => $needsReview,
            'ready' => $ready,
            'status' => $status,
            'ruleDraftRevision' => (int) $rule['revision'],
            'rulePreparedAgainstCapitalRevision' => $rule[
                'capitalPlanningRevision'
            ],
            'capitalCallSelected' => is_array($responses)
                && in_array('capital_call', $responses, true),
            'semantics' => [
                'planningTruthOnly' => true,
                'approvedTruth' => false,
                'signedTruth' => false,
                'effectiveTruth' => false,
                'decisionComplete' => false,
                'capitalCallExecuted' => false,
                'contributionTruth' => false,
                'equityTruth' => false,
                'ownershipTruth' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $calculation
     * @return list<string>
     */
    private function missingRequirements(?array $calculation): array
    {
        if ($calculation === null) {
            return [
                'pre_opening',
                'initial_assets_inventory',
                'working_capital',
                'contingency_reserve',
                'confirmed_funding',
            ];
        }

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
            ($calculation['fundingPosition']['status'] ?? null)
            !== 'calculable'
        ) {
            $missing[] = 'confirmed_funding';
        }

        return $missing;
    }

    /**
     * @param  array<string,mixed>|null  $calculation
     * @return array<string,?string>
     */
    private function summary(?array $calculation): array
    {
        return [
            'preOpening' => $calculation['preOpening']['subtotal'] ?? null,
            'initialAssetsInventory' => $calculation[
                'initialAssetsInventory'
            ]['subtotal'] ?? null,
            'workingCapital' => $calculation['workingCapital']['amount'] ?? null,
            'contingencyReserve' => $calculation['contingency']['amount'] ?? null,
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
        ];
    }

    private function minor(mixed $value): ?int
    {
        if (! is_string($value)) {
            return null;
        }

        if (
            preg_match(
                '/\A(\d+)(?:\.(\d{1,2}))?\z/',
                $value,
                $matches,
            ) !== 1
        ) {
            return null;
        }

        return ((int) $matches[1] * 100)
            + (int) str_pad($matches[2] ?? '', 2, '0');
    }
}
