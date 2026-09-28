<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

interface ImportParser
{
    public function identity(): string;

    public function version(): string;

    /**
     * @return list<array<string,mixed>>
     */
    public function parse(string $source): array;
}
