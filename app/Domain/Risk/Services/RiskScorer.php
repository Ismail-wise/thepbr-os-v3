<?php

declare(strict_types=1);

namespace App\Domain\Risk\Services;

use InvalidArgumentException;

final class RiskScorer
{
    /**
     * @return array{score:int,level:string}
     */
    public function score(
        int $likelihood,
        int $impact,
        int $lowMax,
        int $mediumMax,
        int $highMax,
    ): array {
        if (
            $likelihood < 1 || $likelihood > 5
            || $impact < 1 || $impact > 5
            || $lowMax < 1
            || $mediumMax <= $lowMax
            || $highMax <= $mediumMax
            || $highMax >= 25
        ) {
            throw new InvalidArgumentException(
                'Risk score inputs or configurable thresholds are invalid.',
            );
        }

        $score = $likelihood * $impact;

        return [
            'score' => $score,
            'level' => match (true) {
                $score <= $lowMax => 'low',
                $score <= $mediumMax => 'medium',
                $score <= $highMax => 'high',
                default => 'critical',
            },
        ];
    }
}
