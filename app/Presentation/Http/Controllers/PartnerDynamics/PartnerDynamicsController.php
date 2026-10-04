<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\PartnerDynamics;

use App\Application\PartnerDynamics\PartnerDynamicsScoringService;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\PartnerDynamics\PartnerDynamicsAssessment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PartnerDynamicsController
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $version = $this->assessmentVersion();

        $latestCompleted = PartnerDynamicsAssessment::query()
            ->where('user_id', $user->getKey())
            ->where('assessment_version', $version)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->orderByDesc('created_at')
            ->first();

        $draft = PartnerDynamicsAssessment::query()
            ->where('user_id', $user->getKey())
            ->where('assessment_version', $version)
            ->where('status', 'draft')
            ->latest('created_at')
            ->first();

        return Inertia::render('PartnerDynamics/Index', [
            'assessmentVersion' => $version,
            'latestCompleted' => $latestCompleted === null
                ? null
                : $this->summary($latestCompleted),
            'draft' => $draft === null
                ? null
                : [
                    'id' => (string) $draft->getKey(),
                    'nextStep' => $this->firstIncompleteStep(
                        $draft->answers ?? [],
                    ) ?? 5,
                    'startedAt' => $draft->started_at?->toIso8601String(),
                ],
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $version = $this->assessmentVersion();

        $assessment = PartnerDynamicsAssessment::query()
            ->where('user_id', $user->getKey())
            ->where('assessment_version', $version)
            ->where('status', 'draft')
            ->latest('created_at')
            ->first();

        if ($assessment === null) {
            $assessment = PartnerDynamicsAssessment::query()->create([
                'user_id' => $user->getKey(),
                'assessment_version' => $version,
                'status' => 'draft',
                'answers' => [],
                'started_at' => now(),
            ]);
        }

        $step = $this->firstIncompleteStep($assessment->answers ?? []) ?? 5;

        return redirect()->route(
            'partner-dynamics.assessment.step',
            [$assessment->getKey(), $step],
        );
    }

    public function retake(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $version = $this->assessmentVersion();

        $assessment = PartnerDynamicsAssessment::query()
            ->where('user_id', $user->getKey())
            ->where('assessment_version', $version)
            ->where('status', 'draft')
            ->latest('created_at')
            ->first();

        if ($assessment === null) {
            $assessment = PartnerDynamicsAssessment::query()->create([
                'user_id' => $user->getKey(),
                'assessment_version' => $version,
                'status' => 'draft',
                'answers' => [],
                'started_at' => now(),
            ]);
        }

        return redirect()->route(
            'partner-dynamics.assessment.step',
            [$assessment->getKey(), 1],
        );
    }

    public function step(
        Request $request,
        string $assessment,
        int $step,
    ): Response|RedirectResponse {
        $user = $this->user($request);
        $owned = $this->ownedAssessment($user, $assessment);

        if ($owned->isCompleted()) {
            return redirect()->route(
                'partner-dynamics.result',
                $owned->getKey(),
            );
        }

        abort_unless($step >= 1 && $step <= 5, 404);

        $answers = $owned->answers ?? [];
        $firstIncomplete = $this->firstIncompleteStep($answers);

        if ($firstIncomplete !== null && $step > $firstIncomplete) {
            return redirect()->route(
                'partner-dynamics.assessment.step',
                [$owned->getKey(), $firstIncomplete],
            );
        }

        $questions = $this->questionsForStep($step);

        return Inertia::render('PartnerDynamics/Assessment', [
            'assessment' => [
                'id' => (string) $owned->getKey(),
                'version' => (string) $owned->assessment_version,
            ],
            'step' => $step,
            'totalSteps' => 5,
            'questions' => $questions,
            'answers' => collect($questions)
                ->mapWithKeys(function (array $question) use ($answers): array {
                    $number = (int) $question['number'];

                    return [
                        (string) $number => $answers[$number]
                            ?? $answers[(string) $number]
                            ?? null,
                    ];
                })
                ->all(),
        ]);
    }

    public function saveStep(
        Request $request,
        string $assessment,
        int $step,
        PartnerDynamicsScoringService $scoring,
    ): RedirectResponse {
        $user = $this->user($request);
        $owned = $this->ownedAssessment($user, $assessment);

        abort_if($owned->isCompleted(), 409);
        abort_unless($step >= 1 && $step <= 5, 404);

        $questions = $this->questionsForStep($step);
        $rules = [];

        foreach ($questions as $question) {
            $number = (int) $question['number'];

            $rules["answers.{$number}"] = $step <= 4
                ? ['required', 'integer', 'between:1,5']
                : ['required', 'string', 'in:A,B,C,D'];
        }

        $validated = $request->validate($rules);
        $answers = $owned->answers ?? [];

        foreach ($validated['answers'] as $number => $answer) {
            $answers[(int) $number] = $step <= 4
                ? (int) $answer
                : strtoupper((string) $answer);
        }

        ksort($answers);

        $firstIncomplete = $this->firstIncompleteStep($answers);

        if ($firstIncomplete !== null) {
            $owned->update(['answers' => $answers]);

            return redirect()->route(
                'partner-dynamics.assessment.step',
                [$owned->getKey(), $firstIncomplete],
            );
        }

        $result = $scoring->calculate($answers);

        $owned->update([
            'status' => 'completed',
            'answers' => $answers,
            'dimension_scores' => $result['dimension_scores'],
            'behaviour_profile_scores' => $result['behaviour_profile_scores'],
            'scenario_scores' => $result['scenario_scores'],
            'scenario_counts' => $result['scenario_counts'],
            'profile_scores' => $result['profile_scores'],
            'primary_profile' => $result['primary_profile'],
            'primary_score' => $result['primary_score'],
            'secondary_profile' => $result['secondary_profile'],
            'secondary_score' => $result['secondary_score'],
            'is_blended' => $result['is_blended'],
            'result_confidence' => $result['result_confidence'],
            'consistency_data' => $result['consistency_data'],
            'completed_at' => now(),
        ]);

        return redirect()->route(
            'partner-dynamics.result',
            $owned->getKey(),
        );
    }

    public function result(
        Request $request,
        string $assessment,
    ): Response|RedirectResponse {
        $user = $this->user($request);
        $owned = $this->ownedAssessment($user, $assessment);

        if (! $owned->isCompleted()) {
            return redirect()->route(
                'partner-dynamics.assessment.step',
                [
                    $owned->getKey(),
                    $this->firstIncompleteStep($owned->answers ?? []) ?? 5,
                ],
            );
        }

        return Inertia::render('PartnerDynamics/Result', [
            'result' => $this->summary($owned),
        ]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    private function ownedAssessment(
        User $user,
        string $assessment,
    ): PartnerDynamicsAssessment {
        return PartnerDynamicsAssessment::query()
            ->whereKey($assessment)
            ->where('user_id', $user->getKey())
            ->firstOrFail();
    }

    private function assessmentVersion(): string
    {
        return (string) config('partner_dynamics.version', 'v1');
    }

    /**
     * @return list<array{
     *   number:int,
     *   title:string|null,
     *   text:string,
     *   options:list<array{value:string,text:string}>
     * }>
     */
    private function questionsForStep(int $step): array
    {
        if ($step === 5) {
            return collect(config('partner_dynamics.scenario_questions', []))
                ->map(function (array $question, int|string $number): array {
                    return [
                        'number' => (int) $number,
                        'title' => (string) ($question['title'] ?? ''),
                        'text' => (string) ($question['text'] ?? ''),
                        'options' => collect($question['options'] ?? [])
                            ->map(
                                static fn (array $option, string $value): array => [
                                    'value' => $value,
                                    'text' => (string) ($option['text'] ?? ''),
                                ],
                            )
                            ->values()
                            ->all(),
                    ];
                })
                ->values()
                ->all();
        }

        $start = (($step - 1) * 8) + 1;
        $end = $start + 7;

        return collect(config('partner_dynamics.behaviour_questions', []))
            ->filter(
                static fn (array $question, int|string $number): bool => (int) $number >= $start
                    && (int) $number <= $end,
            )
            ->map(
                static fn (array $question, int|string $number): array => [
                    'number' => (int) $number,
                    'title' => null,
                    'text' => (string) ($question['text'] ?? ''),
                    'options' => [],
                ],
            )
            ->values()
            ->all();
    }

    private function firstIncompleteStep(array $answers): ?int
    {
        for ($step = 1; $step <= 5; $step++) {
            foreach ($this->questionsForStep($step) as $question) {
                $number = (int) $question['number'];

                if (
                    ! array_key_exists($number, $answers)
                    && ! array_key_exists((string) $number, $answers)
                ) {
                    return $step;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function summary(
        PartnerDynamicsAssessment $assessment,
    ): array {
        return [
            'id' => (string) $assessment->getKey(),
            'assessmentVersion' => (string) $assessment->assessment_version,
            'primaryProfile' => (string) $assessment->primary_profile,
            'primaryScore' => (float) $assessment->primary_score,
            'secondaryProfile' => $assessment->secondary_profile === null
                ? null
                : (string) $assessment->secondary_profile,
            'secondaryScore' => $assessment->secondary_score === null
                ? null
                : (float) $assessment->secondary_score,
            'isBlended' => (bool) $assessment->is_blended,
            'resultConfidence' => (string) $assessment->result_confidence,
            'dimensionScores' => $assessment->dimension_scores ?? [],
            'profileScores' => $assessment->profile_scores ?? [],
            'completedAt' => $assessment->completed_at?->toIso8601String(),
        ];
    }
}
