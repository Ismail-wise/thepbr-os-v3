<?php

namespace Tests\Unit\Domain\Businesses;

use App\Domain\Businesses\Enums\WorkspaceStatus;
use PHPUnit\Framework\TestCase;

final class WorkspaceStatusTest extends TestCase
{
    public function test_workspace_statuses_are_exact(): void
    {
        $this->assertSame([
            'active',
            'restricted',
            'archived',
            'closed',
        ], array_map(
            static fn (WorkspaceStatus $status): string => $status->value,
            WorkspaceStatus::cases(),
        ));
    }
}
