<?php

declare(strict_types=1);

namespace App\Domain\Exit\Enums;

enum ExitTrigger: string
{
    case Voluntary = 'voluntary';
    case Retirement = 'retirement';
    case PoorPerformance = 'poor_performance';
    case Misconduct = 'misconduct';
    case Incapacity = 'incapacity';
    case Death = 'death';
    case BankruptcyInsolvency = 'bankruptcy_insolvency';
    case RelationshipBreakdown = 'relationship_breakdown';
    case AgreementBreach = 'agreement_breach';
    case Other = 'other';
}
