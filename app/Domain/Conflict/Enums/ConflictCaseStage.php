<?php

declare(strict_types=1);

namespace App\Domain\Conflict\Enums;

enum ConflictCaseStage: string
{
    case Intake = 'intake';
    case DirectDiscussion = 'direct_discussion';
    case Mediation = 'mediation';
    case FormalDecision = 'formal_decision';
    case Escalation = 'escalation';
    case Deadlock = 'deadlock';
    case MisconductInvestigation = 'misconduct_investigation';
    case UrgentRisk = 'urgent_risk';
    case Settlement = 'settlement';
    case ExitLegal = 'exit_legal';
    case Resolved = 'resolved';
}
