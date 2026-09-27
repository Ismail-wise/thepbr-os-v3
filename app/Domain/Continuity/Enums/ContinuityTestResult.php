<?php

declare(strict_types=1);

namespace App\Domain\Continuity\Enums;

enum ContinuityTestResult: string
{
    case Planned = 'planned';
    case Running = 'running';
    case Passed = 'passed';
    case Partial = 'partial';
    case Failed = 'failed';

    public function completed(): bool
    {
        return in_array($this, [self::Passed, self::Partial, self::Failed], true);
    }
}
