<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\AI;

use App\Application\AI\PbrAiAssistant;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class PbrAiController
{
    public function index(
        Request $request,
        PbrAiAssistant $assistant,
    ): Response {
        [$user, $business] = $this->context($request);

        $workspace = $assistant->workspace(
            $user,
            $business,
        );

        abort_if($workspace === null, 404);

        return Inertia::render('AI/Index', [
            'aiWorkspace' => $workspace,
            'aiResponse' => null,
        ]);
    }

    public function ask(
        Request $request,
        PbrAiAssistant $assistant,
    ): Response {
        [$user, $business] = $this->context($request);

        $maxPromptLength = max(
            1,
            (int) config('pbr_ai.max_prompt_length', 4000),
        );

        $data = $request->validate([
            'prompt' => [
                'required',
                'string',
                'max:'.$maxPromptLength,
            ],
        ]);

        try {
            $response = $assistant->ask(
                $user,
                $business,
                $data['prompt'],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'prompt' => $exception->getMessage(),
            ]);
        }

        abort_if($response === null, 404);

        $workspace = $assistant->workspace(
            $user,
            $business,
        );

        abort_if($workspace === null, 404);

        return Inertia::render('AI/Index', [
            'aiWorkspace' => $workspace,
            'aiResponse' => $response,
        ]);
    }

    /** @return array{User,Business} */
    private function context(Request $request): array
    {
        $user = $request->user();
        $business = $request->attributes->get(
            EnsureCurrentBusinessContext::ATTRIBUTE_KEY,
        );

        if (! $user instanceof User || ! $business instanceof Business) {
            abort(403);
        }

        return [$user, $business];
    }
}
