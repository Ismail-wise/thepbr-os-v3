<?php

declare(strict_types=1);

namespace App\Domain\Partnership;

use InvalidArgumentException;

final class EquityScenarioSimulator
{
    /**
     * @param  array{capital:mixed,work:mixed,expertise:mixed,risk:mixed}  $weights
     * @param  list<array{name:mixed,capital:mixed,work:mixed,expertise:mixed,risk:mixed}>  $partners
     * @return array<string,mixed>
     */
    public function calculate(array $weights, array $partners): array
    {
        if ($partners === []) {
            throw new InvalidArgumentException('Add at least one Partner to the scenario.');
        }

        $keys = ['capital', 'work', 'expertise', 'risk'];
        $normalizedWeights = [];
        foreach ($keys as $key) {
            $normalizedWeights[$key] = $this->number($weights[$key] ?? null, ucfirst($key).' weight');
        }

        $weightTotal = array_sum($normalizedWeights);
        if ($weightTotal <= 0.0) {
            throw new InvalidArgumentException('Scenario weights must total more than zero.');
        }

        $normalizedPartners = [];
        foreach ($partners as $index => $partner) {
            $name = trim((string) ($partner['name'] ?? ''));
            if ($name === '') {
                throw new InvalidArgumentException('Every scenario Partner needs a name.');
            }
            $row = ['name' => $name];
            foreach ($keys as $key) {
                $row[$key] = $this->number(
                    $partner[$key] ?? null,
                    sprintf('Partner %d %s value', $index + 1, $key),
                );
            }
            $normalizedPartners[] = $row;
        }

        $totals = [];
        foreach ($keys as $key) {
            $totals[$key] = array_sum(array_column($normalizedPartners, $key));
        }

        $scores = [];
        foreach ($normalizedPartners as $partner) {
            $score = 0.0;
            foreach ($keys as $key) {
                if ($totals[$key] > 0.0) {
                    $score += ($partner[$key] / $totals[$key]) * $normalizedWeights[$key];
                }
            }
            $scores[] = $score;
        }

        $scoreTotal = array_sum($scores);
        $results = [];
        foreach ($normalizedPartners as $index => $partner) {
            $percent = $scoreTotal > 0.0
                ? ($scores[$index] / $scoreTotal) * 100.0
                : 0.0;

            $results[] = [
                'partner' => $partner['name'],
                'scenarioPercent' => number_format($percent, 2, '.', ''),
                'weightedScore' => number_format($scores[$index], 6, '.', ''),
            ];
        }

        return [
            'weights' => array_map(
                static fn (float $value): string => number_format($value, 2, '.', ''),
                $normalizedWeights,
            ),
            'weightTotal' => number_format($weightTotal, 2, '.', ''),
            'weightsTotalOneHundred' => abs($weightTotal - 100.0) < 0.000001,
            'results' => $results,
            'semantics' => [
                'scenarioOnly' => true,
                'writesOfficialOwnership' => false,
                'writesContribution' => false,
                'writesGovernance' => false,
                'writesShareRegister' => false,
            ],
        ];
    }

    private function number(mixed $value, string $label): float
    {
        $value = trim((string) $value);
        if ($value === '' || ! preg_match('/\A\d+(?:\.\d{1,6})?\z/', $value)) {
            throw new InvalidArgumentException($label.' must be a non-negative number.');
        }

        $number = (float) $value;
        if (! is_finite($number) || $number < 0.0) {
            throw new InvalidArgumentException($label.' must be a non-negative number.');
        }

        return $number;
    }
}
