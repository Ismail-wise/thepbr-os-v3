<?php

declare(strict_types=1);

namespace App\Domain\Exit\Enums;

enum ExitCaseStatus: string
{
    case Draft = 'draft';
    case NoticeRecorded = 'notice_recorded';
    case TreatmentReady = 'treatment_ready';
    case TermsReady = 'terms_ready';
    case UnderGovernance = 'under_governance';
    case Approved = 'approved';
    case ReadyForEffect = 'ready_for_effect';
    case Effective = 'effective';
    case SettlementPending = 'settlement_pending';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
    case Cancelled = 'cancelled';
}
