<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Application\AI\PbrAiProvider;
use App\Infrastructure\AI\DisabledPbrAiProvider;
use App\Infrastructure\AI\InternalPbrAiProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class F7InternalAiProviderTest extends TestCase
{
    public function test_internal_provider_uses_existing_service_and_minimizes_authorized_context(): void
    {
        config()->set('pbr_ai.provider', 'internal');
        config()->set(
            'pbr_ai.providers.internal.base_url',
            'http://pbr-ai.test:3107',
        );
        config()->set(
            'pbr_ai.providers.internal.internal_secret',
            'test-internal-secret',
        );
        config()->set(
            'pbr_ai.providers.internal.timeout',
            30,
        );
        config()->set(
            'pbr_ai.providers.internal.connect_timeout',
            2,
        );

        Http::fake([
            'http://pbr-ai.test:3107/internal/pbr/chat' => Http::response(
                implode("\n\n", [
                    'data: {"type":"meta","mode":"rag"}',
                    'data: {"type":"delta","text":"Authorized "}',
                    'data: {"type":"delta","text":"answer."}',
                    'data: {"type":"done"}',
                    '',
                ]),
                200,
                ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $provider = $this->app->make(PbrAiProvider::class);

        self::assertInstanceOf(
            InternalPbrAiProvider::class,
            $provider,
        );
        self::assertTrue($provider->available());

        $answer = $provider->respond([
            'prompt' => 'Explain the current Partner status.',
            'context' => [
                'language_mode' => 'mixed',
                'business' => [
                    'name' => 'Authorized Business',
                ],
                'authorized_sources' => [[
                    'source_type' => 'partner',
                    'source_id' => '11111111-1111-1111-1111-111111111111',
                    'title' => 'Authorized Partner',
                    'snippet' => 'Current status is active.',
                ]],
                'unapproved_internal_context' => 'must-not-leave-v3',
            ],
            'constraints' => [
                'advisory_only' => true,
                'prohibited_actions' => [
                    'approve',
                    'change_ownership',
                ],
                'no_unrestricted_database_access' => true,
            ],
        ]);

        self::assertSame('Authorized answer.', $answer);

        Http::assertSent(function (Request $request): bool {
            if (
                $request->url()
                !== 'http://pbr-ai.test:3107/internal/pbr/chat'
                || ! $request->hasHeader(
                    'X-PBR-Internal-Secret',
                    'test-internal-secret',
                )
            ) {
                return false;
            }

            $payload = $request->data();
            $serialized = json_encode(
                $payload,
                JSON_THROW_ON_ERROR,
            );

            self::assertSame(
                'Explain the current Partner status.',
                $payload['message'] ?? null,
            );
            self::assertSame([], $payload['history'] ?? null);
            self::assertArrayNotHasKey('actor', $payload);
            self::assertSame(
                'Authorized Business',
                $payload['workspaceContext']['business']['name']
                    ?? null,
            );
            self::assertSame(
                'Authorized Partner',
                $payload['workspaceContext']['authorized_sources'][0]['title']
                    ?? null,
            );
            self::assertTrue(
                $payload['workspaceContext']['ai_constraints']['advisory_only']
                    ?? false,
            );
            self::assertStringNotContainsString(
                '11111111-1111-1111-1111-111111111111',
                $serialized,
            );
            self::assertStringNotContainsString(
                'must-not-leave-v3',
                $serialized,
            );

            return true;
        });
    }

    public function test_internal_provider_requires_external_secret_and_unknown_provider_falls_back_disabled(): void
    {
        config()->set('pbr_ai.provider', 'internal');
        config()->set(
            'pbr_ai.providers.internal.base_url',
            'http://127.0.0.1:3107',
        );
        config()->set(
            'pbr_ai.providers.internal.internal_secret',
            null,
        );

        $internal = $this->app->make(PbrAiProvider::class);

        self::assertInstanceOf(
            InternalPbrAiProvider::class,
            $internal,
        );
        self::assertFalse($internal->available());

        config()->set('pbr_ai.provider', 'unrecognized');

        $disabled = $this->app->make(PbrAiProvider::class);

        self::assertInstanceOf(
            DisabledPbrAiProvider::class,
            $disabled,
        );
        self::assertFalse($disabled->available());
    }

    public function test_internal_provider_rejects_incomplete_sse_without_done_event(): void
    {
        config()->set('pbr_ai.provider', 'internal');
        config()->set(
            'pbr_ai.providers.internal.base_url',
            'http://pbr-ai.test:3107',
        );
        config()->set(
            'pbr_ai.providers.internal.internal_secret',
            'test-internal-secret',
        );

        Http::fake([
            'http://pbr-ai.test:3107/internal/pbr/chat' => Http::response(
                'data: {"type":"delta","text":"Partial answer"}'."\n\n",
                200,
                ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $provider = $this->app->make(PbrAiProvider::class);

        try {
            $provider->respond([
                'prompt' => 'Explain.',
                'context' => [],
                'constraints' => [],
            ]);

            self::fail(
                'Incomplete SSE responses must not be accepted.',
            );
        } catch (RuntimeException $exception) {
            self::assertSame(
                'PBR AI internal provider response did not complete.',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString(
                'Partial answer',
                $exception->getMessage(),
            );
        }
    }

    public function test_internal_provider_does_not_expose_sse_error_text(): void
    {
        config()->set('pbr_ai.provider', 'internal');
        config()->set(
            'pbr_ai.providers.internal.base_url',
            'http://pbr-ai.test:3107',
        );
        config()->set(
            'pbr_ai.providers.internal.internal_secret',
            'test-internal-secret',
        );

        Http::fake([
            'http://pbr-ai.test:3107/internal/pbr/chat' => Http::response(
                implode("\n\n", [
                    'data: {"type":"error","text":"SensitiveStreamDetail"}',
                    'data: {"type":"done"}',
                    '',
                ]),
                200,
                ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $provider = $this->app->make(PbrAiProvider::class);

        try {
            $provider->respond([
                'prompt' => 'Explain.',
                'context' => [],
                'constraints' => [],
            ]);

            self::fail(
                'SSE provider errors must not be returned as answers.',
            );
        } catch (RuntimeException $exception) {
            self::assertSame(
                'PBR AI internal provider returned an error.',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString(
                'SensitiveStreamDetail',
                $exception->getMessage(),
            );
        }
    }

    public function test_internal_provider_does_not_expose_upstream_error_body(): void
    {
        config()->set('pbr_ai.provider', 'internal');
        config()->set(
            'pbr_ai.providers.internal.base_url',
            'http://pbr-ai.test:3107',
        );
        config()->set(
            'pbr_ai.providers.internal.internal_secret',
            'test-internal-secret',
        );

        Http::fake([
            'http://pbr-ai.test:3107/internal/pbr/chat' => Http::response(
                '{"error":"SensitiveUpstreamDetail"}',
                503,
            ),
        ]);

        $provider = $this->app->make(PbrAiProvider::class);

        try {
            $provider->respond([
                'prompt' => 'Explain.',
                'context' => [],
                'constraints' => [],
            ]);

            self::fail(
                'Provider failure must not be returned as an answer.',
            );
        } catch (RuntimeException $exception) {
            self::assertSame(
                'PBR AI internal provider request failed.',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString(
                'SensitiveUpstreamDetail',
                $exception->getMessage(),
            );
        }
    }
}
