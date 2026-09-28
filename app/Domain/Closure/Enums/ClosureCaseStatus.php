<?php

declare(strict_types=1);

namespace App\Domain\Closure\Enums;

enum ClosureCaseStatus: string
{
    case Draft = 'draft';
    case UnderGovernance = 'under_governance';
    case Approved = 'approved';
    case WindDownActive = 'wind_down_active';
    case ResidualReady = 'residual_ready';
    case LegalClosureReady = 'legal_closure_ready';
    case LegallyClosed = 'legally_closed';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
    case Cancelled = 'cancelled';
}
