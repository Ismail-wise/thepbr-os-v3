<?php

declare(strict_types=1);

namespace App\Domain\Access\Enums;

enum StandardAccessProfile: string
{
    case WorkspaceOwner = 'Workspace Owner';
    case Partner = 'Partner';
    case ManagingPartnerCeo = 'Managing Partner / CEO';
    case FinanceOwner = 'Finance Owner';
    case GovernanceSecretary = 'Governance Secretary / PBR Administrator';
    case AdvisorConsultant = 'Advisor / Consultant';
    case AuditorViewer = 'Auditor / Viewer';
    case ExternalAccountantLegalAdvisor = 'External Accountant / Legal Advisor';
}
