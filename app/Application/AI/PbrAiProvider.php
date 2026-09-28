<?php

declare(strict_types=1);

namespace App\Application\AI;

interface PbrAiProvider
{
    public function available(): bool;

    /**
     * Advisory text generation only.
     *
     * Providers receive an already-authorized, minimized context. This
     * interface intentionally exposes no mutation, tool, SQL or workflow
     * execution surface.
     *
     * @param array{
     *   prompt:string,
     *   context:array<string,mixed>,
     *   constraints:array<string,mixed>
     * } $request
     */
    public function respond(array $request): string;
}
