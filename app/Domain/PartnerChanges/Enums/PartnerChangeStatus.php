<?php

declare(strict_types=1);

namespace App\Domain\PartnerChanges\Enums;

enum PartnerChangeStatus: string
{
    case Draft = 'draft';
    case EligibilityReview = 'eligibility_review';
    case Blocked = 'blocked';
    case Eligible = 'eligible';
    case Rofr = 'rofr';
    case TermsReady = 'terms_ready';
    case UnderGovernance = 'under_governance';
    case Approved = 'approved';
    case ReadyForEffect = 'ready_for_effect';
    case Effective = 'effective';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
}
