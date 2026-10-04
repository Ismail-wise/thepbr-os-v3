<?php

declare(strict_types=1);

namespace Tests\Unit\PartnerDynamics;

use App\Application\PartnerDynamics\PartnerDynamicsAlignmentService;
use InvalidArgumentException;
use Tests\TestCase;

final class PartnerDynamicsAlignmentServiceTest extends TestCase
{
    public function test_alignment_returns_shared_team_insight_sections(): void
    {
        $result = $this->app
            ->make(PartnerDynamicsAlignmentService::class)
            ->analyze([
                $this->participant(1, 'Partner A', 'guardian', 80),
                $this->participant(2, 'Partner B', 'visionary', 75),
            ]);

        self::assertSame(
            2,
            $result['alignment_summary']['participant_count'],
        );
        self::assertCount(2, $result['role_suggestions']);
        self::assertArrayHasKey('shared_strengths', $result);
        self::assertArrayHasKey('complementary_areas', $result);
        self::assertArrayHasKey('important_differences', $result);
        self::assertArrayHasKey('shared_blind_spots', $result);
        self::assertArrayHasKey('decision_recommendations', $result);
        self::assertArrayHasKey('discussion_priorities', $result);
    }

    public function test_alignment_detects_meaningful_operating_difference(): void
    {
        $partnerA = $this->dimensions(80);
        $partnerA['vision'] = 90;

        $partnerB = $this->dimensions(75);
        $partnerB['vision'] = 30;

        $result = $this->app
            ->make(PartnerDynamicsAlignmentService::class)
            ->analyze([
                $this->participantWithScores(
                    1,
                    'Partner A',
                    'guardian',
                    $partnerA,
                ),
                $this->participantWithScores(
                    2,
                    'Partner B',
                    'visionary',
                    $partnerB,
                ),
            ]);

        self::assertContains(
            'vision',
            array_column($result['important_differences'], 'dimension'),
        );
    }

    public function test_alignment_requires_two_completed_participants(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->app
            ->make(PartnerDynamicsAlignmentService::class)
            ->analyze([
                $this->participant(1, 'Partner A', 'guardian', 80),
            ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function participant(
        int $userId,
        string $name,
        string $profile,
        float $score,
    ): array {
        return $this->participantWithScores(
            $userId,
            $name,
            $profile,
            $this->dimensions($score),
        );
    }

    /**
     * @param  array<string,float>  $scores
     * @return array<string,mixed>
     */
    private function participantWithScores(
        int $userId,
        string $name,
        string $profile,
        array $scores,
    ): array {
        return [
            'user_id' => $userId,
            'name' => $name,
            'primary_profile' => $profile,
            'secondary_profile' => null,
            'dimension_scores' => $scores,
        ];
    }

    /**
     * @return array<string,float>
     */
    private function dimensions(float $score): array
    {
        return [
            'vision' => $score,
            'execution' => $score,
            'people' => $score,
            'analysis' => $score,
            'structure' => $score,
            'risk' => $score,
            'decision' => $score,
            'adaptability' => $score,
        ];
    }
}
