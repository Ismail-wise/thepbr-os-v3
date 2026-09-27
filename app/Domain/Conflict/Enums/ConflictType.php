<?php

declare(strict_types=1);

namespace App\Domain\Conflict\Enums;

enum ConflictType: string
{
    case OrdinaryDisagreement = 'ordinary_disagreement';
    case GovernanceDispute = 'governance_dispute';
    case FinancialDispute = 'financial_dispute';
    case RolePerformance = 'role_performance';
    case ConflictOfInterest = 'conflict_of_interest';
    case Misconduct = 'misconduct';
    case AgreementBreach = 'agreement_breach';
    case UrgentRisk = 'urgent_risk';
    case Deadlock5050 = 'deadlock_50_50';
    case RelationshipBreakdown = 'relationship_breakdown';
    case Other = 'other';
}
