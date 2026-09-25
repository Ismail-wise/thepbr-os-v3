<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Access\Enums\StandardAccessProfile;

final class StandardAccessProfileMatrix
{
    /**
     * System-access template only.
     *
     * Governance authority, ownership rights and document rights are evaluated
     * independently. A capability listed here never makes a Membership an
     * eligible approver, voter or signer.
     *
     * @return list<string>
     */
    public static function capabilities(StandardAccessProfile $profile): array
    {
        $view = [
            CapabilityCatalog::RECORDS_VIEW,
            CapabilityCatalog::RECORDS_ACTIVITY_VIEW,
            CapabilityCatalog::FORMATION_VIEW,
            CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
            CapabilityCatalog::BUSINESS_MODEL_VIEW,
            CapabilityCatalog::LEGAL_VIEW,
            CapabilityCatalog::CAPITAL_VIEW,
        ];

        return match ($profile) {
            StandardAccessProfile::WorkspaceOwner => [
                CapabilityCatalog::PERMISSION_PROFILES_VIEW,
                CapabilityCatalog::ACCESS_ADMIN_VIEW,
                CapabilityCatalog::ACCESS_ADMIN_MANAGE,
                CapabilityCatalog::RECORDS_VIEW,
                CapabilityCatalog::RECORDS_MANAGE,
                CapabilityCatalog::RECORDS_ACTIVITY_VIEW,
                CapabilityCatalog::FORMATION_VIEW,
                CapabilityCatalog::FORMATION_MANAGE,
                CapabilityCatalog::FORMATION_AUTHORITY_BOOTSTRAP,
                CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
                CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
                CapabilityCatalog::GOVERNANCE_SIGNATURE_ACT,
                CapabilityCatalog::GOVERNANCE_ACTION_MANAGE,
                CapabilityCatalog::BUSINESS_MODEL_VIEW,
                CapabilityCatalog::BUSINESS_MODEL_MANAGE,
                CapabilityCatalog::LEGAL_VIEW,
                CapabilityCatalog::LEGAL_MANAGE,
                CapabilityCatalog::CAPITAL_VIEW,
                CapabilityCatalog::CAPITAL_MANAGE,
            ],
            StandardAccessProfile::Partner => [
                ...$view,
                CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
                CapabilityCatalog::GOVERNANCE_SIGNATURE_ACT,
            ],
            StandardAccessProfile::ManagingPartnerCeo => [
                CapabilityCatalog::PERMISSION_PROFILES_VIEW,
                CapabilityCatalog::RECORDS_VIEW,
                CapabilityCatalog::RECORDS_MANAGE,
                CapabilityCatalog::RECORDS_ACTIVITY_VIEW,
                CapabilityCatalog::FORMATION_VIEW,
                CapabilityCatalog::FORMATION_MANAGE,
                CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
                CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
                CapabilityCatalog::GOVERNANCE_SIGNATURE_ACT,
                CapabilityCatalog::GOVERNANCE_ACTION_MANAGE,
                CapabilityCatalog::BUSINESS_MODEL_VIEW,
                CapabilityCatalog::BUSINESS_MODEL_MANAGE,
                CapabilityCatalog::LEGAL_VIEW,
                CapabilityCatalog::LEGAL_MANAGE,
                CapabilityCatalog::CAPITAL_VIEW,
                CapabilityCatalog::CAPITAL_MANAGE,
            ],
            StandardAccessProfile::FinanceOwner => [
                CapabilityCatalog::RECORDS_VIEW,
                CapabilityCatalog::RECORDS_MANAGE,
                CapabilityCatalog::RECORDS_ACTIVITY_VIEW,
                CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
                CapabilityCatalog::LEGAL_VIEW,
                CapabilityCatalog::CAPITAL_VIEW,
                CapabilityCatalog::CAPITAL_MANAGE,
            ],
            StandardAccessProfile::GovernanceSecretary => [
                CapabilityCatalog::PERMISSION_PROFILES_VIEW,
                CapabilityCatalog::RECORDS_VIEW,
                CapabilityCatalog::RECORDS_MANAGE,
                CapabilityCatalog::RECORDS_ACTIVITY_VIEW,
                CapabilityCatalog::FORMATION_VIEW,
                CapabilityCatalog::FORMATION_MANAGE,
                CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
                CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
                CapabilityCatalog::GOVERNANCE_SIGNATURE_ACT,
                CapabilityCatalog::GOVERNANCE_ACTION_MANAGE,
                CapabilityCatalog::LEGAL_VIEW,
            ],
            StandardAccessProfile::AdvisorConsultant,
            StandardAccessProfile::AuditorViewer,
            StandardAccessProfile::ExternalAccountantLegalAdvisor => $view,
        };
    }
}
