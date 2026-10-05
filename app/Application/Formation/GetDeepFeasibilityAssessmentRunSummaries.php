<?php

declare(strict_types=1);

namespace App\Application\Formation;

use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;

final class GetDeepFeasibilityAssessmentRunSummaries
{
    public function __construct(
        private readonly GetDeepFeasibilityAssessmentRunHistory $history,
        private readonly DeepFeasibilityActionableFindings $findings,
    ) {}

    /**
     * User-facing read model for immutable history.
     *
     * Deliberately excludes run IDs, hashes, provenance, membership IDs and
     * raw canonical snapshots. The full immutable record remains server-side.
     *
     * @return list<array<string,mixed>>|null
     */
    public function execute(User $user, Business $business): ?array
    {
        $history = $this->history->execute($user, $business);

        if ($history === null) {
            return null;
        }

        return array_values(array_map(
            function (array $run, int $index): array {
                $assessment = is_array($run['resultSnapshot'] ?? null)
                    ? $run['resultSnapshot']
                    : [];

                $findings = $this->findings->derive($assessment);
                $summary = is_array($assessment['summary'] ?? null)
                    ? $assessment['summary']
                    : [];

                return [
                    'sequence' => $index + 1,
                    'createdAt' => $run['createdAt'] ?? null,
                    'confidenceLevel' => (string) (
                        $run['confidenceLevel'] ?? 'low'
                    ),
                    'evidenceQuality' => (string) (
                        $run['evidenceQuality'] ?? 'limited'
                    ),
                    'assessedDimensions' => (int) (
                        $summary['assessed'] ?? 0
                    ),
                    'evidenceGaps' => (int) (
                        $summary['insufficient_evidence'] ?? 0
                    ),
                    'unavailableDependencies' => (int) (
                        $summary['unavailable_dependency'] ?? 0
                    ),
                    'blockers' => (int) (
                        $summary['blocker'] ?? 0
                    ),
                    'strengths' => count(
                        $findings['strengths'] ?? [],
                    ),
                    'risks' => count(
                        $findings['risks'] ?? [],
                    ),
                    'requiredActions' => count(
                        $findings['requiredActions'] ?? [],
                    ),
                    'recommendationAvailable' => (
                        $assessment['overall']['recommendation'] ?? null
                    ) !== null,
                    'recommendation' => $assessment['overall'][
                        'recommendation'
                    ] ?? null,
                    'historicalSnapshot' => true,
                    'readOnly' => true,
                ];
            },
            $history,
            array_keys($history),
        ));
    }
}
