<?php

namespace App\Domain\Documents\Enums;

enum DocumentCategory: string
{
    case CorporateLegal = 'corporate_legal';
    case PartnersOwnership = 'partners_ownership';
    case AgreementsContracts = 'agreements_contracts';
    case FinanceTax = 'finance_tax';
    case GovernanceDecisions = 'governance_decisions';
    case RiskInsurance = 'risk_insurance';
    case Operations = 'operations';
    case PbrGenerated = 'pbr_generated';
}
