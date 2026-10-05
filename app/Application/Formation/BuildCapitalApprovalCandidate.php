<?php

declare(strict_types=1);

namespace App\Application\Formation;

use App\Domain\Capital\CapitalApprovalContract;
use App\Domain\Capital\CapitalCalculationEngine;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class BuildCapitalApprovalCandidate
{
    public function __construct(
        private readonly GetCapitalPlanningDraft $planning,
        private readonly GetCapitalRuleDraft $rules,
        private readonly GetCapitalRuleReadModel $ruleReadModel,
        private readonly GetCapitalComparisonDraft $comparisons,
        private readonly GetCapitalComparisonReadModel $comparisonReadModel,
        private readonly CapitalApprovalContract $contract,
    ) {}

    /**
     * @return array{
     *   ready:bool,
     *   reasons:list<string>,
     *   candidate:?array<string,mixed>,
     *   contentHash:?string
     * }|null
     */
    public function execute(
        User $user,
        Business $business,
    ): ?array {
        $planning = $this->planning->execute($user, $business);
        $rule = $this->rules->execute($user, $business);
        $ruleState = $this->ruleReadModel->execute($user, $business);
        $comparison = $this->comparisons->execute($user, $business);
        $comparisonState = $this->comparisonReadModel->execute(
            $user,
            $business,
        );

        if (
            $planning === null
            || $rule === null
            || $ruleState === null
            || $comparison === null
            || $comparisonState === null
        ) {
            return null;
        }

        $reasons = [];

        if (
            (int) $planning['revision'] < 1
            || ! is_array($planning['input'])
        ) {
            $reasons[] = 'capital_plan_required';
        }

        if ($ruleState['ready'] !== true) {
            $reasons[] = match ($ruleState['status'] ?? null) {
                'needs_review' => 'capital_rule_needs_review',
                'needs_shortfall_rule' => 'capital_shortfall_rule_required',
                default => 'capital_rule_not_ready',
            };
        }

        if (
            (int) $comparison['revision'] < 1
            || ! is_array($comparison['input'])
        ) {
            $reasons[] = 'capital_comparison_required';
        } elseif ($comparisonState['needsReview'] === true) {
            $reasons[] = 'capital_comparison_needs_review';
        } elseif (
            ($comparisonState['status'] ?? null)
            !== 'ready_for_comparison'
        ) {
            $reasons[] = 'capital_comparison_incomplete';
        }

        $preferred = is_array($comparison['input'])
            ? ($comparison['input']['preferredPlan'] ?? null)
            : null;

        if (
            ! is_string($preferred)
            || ! in_array($preferred, ['lean', 'base', 'growth'], true)
        ) {
            $reasons[] = 'preferred_plan_required';
        }

        $preferredScenario = is_string($preferred)
            ? collect($comparisonState['scenarios'] ?? [])
                ->firstWhere('scenarioKey', $preferred)
            : null;

        if (
            ! is_array($preferredScenario)
            || ($preferredScenario['readiness'] ?? null)
                !== 'ready_for_comparison'
            || ! is_array($preferredScenario['calculation'] ?? null)
        ) {
            $reasons[] = 'preferred_plan_incomplete';
        }

        if (
            (int) ($comparison['capitalPlanningRevision'] ?? 0)
            !== (int) $planning['revision']
        ) {
            $reasons[] = 'capital_comparison_needs_review';
        }

        if (
            (int) ($rule['capitalPlanningRevision'] ?? 0)
            !== (int) $planning['revision']
        ) {
            $reasons[] = 'capital_rule_needs_review';
        }

        $reasons = array_values(array_unique($reasons));

        if ($reasons !== []) {
            return [
                'ready' => false,
                'reasons' => $reasons,
                'candidate' => null,
                'contentHash' => null,
            ];
        }

        $planningRow = DB::table('capital_planning_drafts')
            ->where('business_id', $business->getKey())
            ->first();
        $ruleRow = DB::table('capital_rule_drafts')
            ->where('business_id', $business->getKey())
            ->first();
        $comparisonRow = DB::table('capital_comparison_drafts')
            ->where('business_id', $business->getKey())
            ->first();

        if (
            $planningRow === null
            || $ruleRow === null
            || $comparisonRow === null
        ) {
            return [
                'ready' => false,
                'reasons' => ['capital_sources_unavailable'],
                'candidate' => null,
                'contentHash' => null,
            ];
        }

        $scenarioInput = $comparison['input']['scenarios'][$preferred] ?? null;

        if (! is_array($scenarioInput)) {
            return [
                'ready' => false,
                'reasons' => ['preferred_plan_incomplete'],
                'candidate' => null,
                'contentHash' => null,
            ];
        }

        $calculation = $preferredScenario['calculation'];

        if (
            ($calculation['contractVersion'] ?? null)
            !== CapitalCalculationEngine::CONTRACT_VERSION
        ) {
            return [
                'ready' => false,
                'reasons' => ['capital_calculation_unavailable'],
                'candidate' => null,
                'contentHash' => null,
            ];
        }

        $candidate = [
            'contractVersion' => CapitalApprovalContract::CONTRACT_VERSION,
            'business' => [
                'id' => (string) $business->getKey(),
                'baseCurrency' => (string) $business->base_currency,
            ],
            'sources' => [
                'capitalPlanning' => [
                    'id' => (string) $planningRow->id,
                    'contractVersion' => (string) $planning['contractVersion'],
                    'revision' => (int) $planning['revision'],
                ],
                'capitalRule' => [
                    'id' => (string) $ruleRow->id,
                    'contractVersion' => (string) $rule['contractVersion'],
                    'revision' => (int) $rule['revision'],
                    'capitalPlanningRevision' => (int) $rule['capitalPlanningRevision'],
                ],
                'capitalComparison' => [
                    'id' => (string) $comparisonRow->id,
                    'contractVersion' => (string) $comparison['contractVersion'],
                    'revision' => (int) $comparison['revision'],
                    'capitalPlanningRevision' => (int) $comparison['capitalPlanningRevision'],
                ],
            ],
            'preferredPlan' => $preferred,
            'preferredPlanInput' => $scenarioInput,
            'calculation' => $calculation,
            'capitalRule' => [
                'shortfallResponses' => $rule['input']['shortfallResponses'] ?? [],
                'allocationNotes' => $rule['input']['allocationNotes'] ?? null,
                'shortfallRuleNotes' => $rule['input']['shortfallRuleNotes'] ?? null,
                'capitalCallRuleNote' => $rule['input']['capitalCallRuleNote'] ?? null,
            ],
        ];

        $candidate = $this->contract->normalize($candidate);

        return [
            'ready' => true,
            'reasons' => [],
            'candidate' => $candidate,
            'contentHash' => $this->contract->contentHash($candidate),
        ];
    }
}
