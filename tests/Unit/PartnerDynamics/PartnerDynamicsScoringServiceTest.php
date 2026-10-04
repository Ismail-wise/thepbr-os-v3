<?php

declare(strict_types=1);

namespace Tests\Unit\PartnerDynamics;

use App\Application\PartnerDynamics\PartnerDynamicsScoringService;
use InvalidArgumentException;
use Tests\TestCase;

final class PartnerDynamicsScoringServiceTest extends TestCase
{
    public function test_scoring_reuses_eight_dimensions_and_eight_profiles(): void
    {
        $result = $this->app
            ->make(PartnerDynamicsScoringService::class)
            ->calculate($this->neutralAnswers());

        self::assertCount(8, $result['dimension_scores']);
        self::assertCount(8, $result['profile_scores']);
        self::assertNotSame('', $result['primary_profile']);
        self::assertNotSame('', $result['secondary_profile']);

        foreach ($result['dimension_scores'] as $score) {
            self::assertGreaterThanOrEqual(0, $score);
            self::assertLessThanOrEqual(100, $score);
        }

        foreach ($result['profile_scores'] as $score) {
            self::assertGreaterThanOrEqual(0, $score);
            self::assertLessThanOrEqual(100, $score);
        }
    }

    public function test_scoring_rejects_missing_answers(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->app
            ->make(PartnerDynamicsScoringService::class)
            ->calculate([]);
    }

    public function test_scoring_rejects_invalid_behaviour_values(): void
    {
        $answers = $this->neutralAnswers();
        $answers[1] = 6;

        $this->expectException(InvalidArgumentException::class);

        $this->app
            ->make(PartnerDynamicsScoringService::class)
            ->calculate($answers);
    }

    public function test_scoring_rejects_invalid_scenario_choice(): void
    {
        $answers = $this->neutralAnswers();
        $answers[33] = 'X';

        $this->expectException(InvalidArgumentException::class);

        $this->app
            ->make(PartnerDynamicsScoringService::class)
            ->calculate($answers);
    }

    /**
     * @return array<int,int|string>
     */
    private function neutralAnswers(): array
    {
        $answers = [];

        for ($question = 1; $question <= 32; $question++) {
            $answers[$question] = 3;
        }

        for ($question = 33; $question <= 40; $question++) {
            $answers[$question] = 'A';
        }

        return $answers;
    }
}
