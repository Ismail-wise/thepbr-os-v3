<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Application\AI\PbrAiProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class InternalPbrAiProvider implements PbrAiProvider
{
    public function available(): bool
    {
        return $this->baseUrl() !== ''
            && $this->internalSecret() !== '';
    }

    public function respond(array $request): string
    {
        if (! $this->available()) {
            throw new RuntimeException(
                'PBR AI internal provider is not configured.',
            );
        }

        $response = Http::asJson()
            ->accept('text/event-stream')
            ->withHeaders([
                'X-PBR-Internal-Secret' => $this->internalSecret(),
            ])
            ->connectTimeout(
                max(
                    1,
                    (int) config(
                        'pbr_ai.providers.internal.connect_timeout',
                        5,
                    ),
                ),
            )
            ->timeout(
                max(
                    30,
                    (int) config(
                        'pbr_ai.providers.internal.timeout',
                        180,
                    ),
                ),
            )
            ->post(
                $this->baseUrl().'/internal/pbr/chat',
                [
                    'message' => (string) ($request['prompt'] ?? ''),
                    'history' => [],
                    'workspaceContext' => $this->minimizedContext(
                        $request,
                    ),
                ],
            );

        if ($response->status() !== 200) {
            throw new RuntimeException(
                'PBR AI internal provider request failed.',
            );
        }

        return $this->parseSseAnswer($response->body());
    }

    /**
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    private function minimizedContext(array $request): array
    {
        $context = is_array($request['context'] ?? null)
            ? $request['context']
            : [];

        $sources = [];

        foreach (($context['authorized_sources'] ?? []) as $source) {
            if (! is_array($source)) {
                continue;
            }

            $sources[] = [
                'source_type' => (string) (
                    $source['source_type'] ?? ''
                ),
                'title' => (string) ($source['title'] ?? ''),
                'snippet' => isset($source['snippet'])
                    && is_string($source['snippet'])
                        ? $source['snippet']
                        : null,
            ];
        }

        return [
            'language_mode' => (string) (
                $context['language_mode'] ?? 'en'
            ),
            'business' => is_array($context['business'] ?? null)
                ? [
                    'name' => (string) (
                        $context['business']['name'] ?? ''
                    ),
                ]
                : ['name' => ''],
            'authorized_sources' => $sources,
            'ai_constraints' => is_array(
                $request['constraints'] ?? null,
            )
                ? $request['constraints']
                : [],
        ];
    }

    private function parseSseAnswer(string $body): string
    {
        $answer = '';
        $receivedDone = false;

        foreach (
            preg_split('/\r?\n\r?\n/', $body) ?: [] as $event
        ) {
            foreach (preg_split('/\r?\n/', $event) ?: [] as $line) {
                if (! str_starts_with($line, 'data: ')) {
                    continue;
                }

                $data = json_decode(
                    substr($line, 6),
                    true,
                );

                if (! is_array($data)) {
                    continue;
                }

                if (($data['type'] ?? null) === 'error') {
                    throw new RuntimeException(
                        'PBR AI internal provider returned an error.',
                    );
                }

                if (
                    ($data['type'] ?? null) === 'delta'
                    && isset($data['text'])
                    && is_string($data['text'])
                ) {
                    $answer .= $data['text'];
                }

                if (($data['type'] ?? null) === 'done') {
                    $receivedDone = true;
                }
            }
        }

        if (! $receivedDone) {
            throw new RuntimeException(
                'PBR AI internal provider response did not complete.',
            );
        }

        $answer = trim($answer);

        if ($answer === '') {
            throw new RuntimeException(
                'PBR AI internal provider returned no answer.',
            );
        }

        return $answer;
    }

    private function baseUrl(): string
    {
        return rtrim(
            trim((string) config(
                'pbr_ai.providers.internal.base_url',
                '',
            )),
            '/',
        );
    }

    private function internalSecret(): string
    {
        return trim((string) config(
            'pbr_ai.providers.internal.internal_secret',
            '',
        ));
    }
}
