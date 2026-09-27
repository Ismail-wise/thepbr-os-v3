<?php

declare(strict_types=1);

namespace App\Domain\Conflict\Enums;

enum DirectDiscussionOutcome: string
{
    case Resolved = 'resolved';
    case ContinueMediation = 'continue_mediation';
    case ContinueFormalDecision = 'continue_formal_decision';
}
