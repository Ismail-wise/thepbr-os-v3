<?php

declare(strict_types=1);

namespace App\Domain\Access;

final class CapabilityCatalog
{
    public const string PERMISSION_PROFILES_VIEW = 'permission_profiles.view';

    public const string RECORDS_VIEW = 'records.view';

    public const string RECORDS_MANAGE = 'records.manage';

    public const string RECORDS_ACTIVITY_VIEW = 'records.activity.view';

    public const string ACCESS_ADMIN_VIEW = 'access.admin.view';

    public const string ACCESS_ADMIN_MANAGE = 'access.admin.manage';

    public const string FORMATION_VIEW = 'formation.view';

    public const string FORMATION_MANAGE = 'formation.manage';

    public const string FORMATION_AUTHORITY_BOOTSTRAP = 'formation.authority.bootstrap';

    public const string GOVERNANCE_RECORDS_VIEW = 'governance.records.view';

    public const string GOVERNANCE_RECORDS_MANAGE = 'governance.records.manage';

    public const string GOVERNANCE_SIGNATURE_ACT = 'governance.signature.act';

    public const string GOVERNANCE_ACTION_MANAGE = 'governance.action.manage';

    public const string BUSINESS_MODEL_VIEW = 'business.model.view';

    public const string BUSINESS_MODEL_MANAGE = 'business.model.manage';

    public const string LEGAL_VIEW = 'legal.view';

    public const string LEGAL_MANAGE = 'legal.manage';

    public const string CAPITAL_VIEW = 'capital.view';

    public const string CAPITAL_MANAGE = 'capital.manage';

    public const string PARTNERS_VIEW = 'partners.view';

    public const string PARTNERS_MANAGE = 'partners.manage';

    public const string DUE_DILIGENCE_VIEW = 'due_diligence.view';

    public const string DUE_DILIGENCE_MANAGE = 'due_diligence.manage';

    public const string CONTRIBUTIONS_VIEW = 'contributions.view';

    public const string CONTRIBUTIONS_MANAGE = 'contributions.manage';

    /**
     * System capabilities only.
     *
     * These values never establish Ownership Rights, Governance Rights,
     * Partner status, voting entitlement, profit entitlement or document
     * visibility by themselves.
     *
     * @return list<string>
     */
    public const OWNERSHIP_VIEW = 'ownership.view';

    public const OWNERSHIP_MANAGE = 'ownership.manage';

    public const PARTNER_CHANGES_VIEW = 'partner_changes.view';

    public const PARTNER_CHANGES_MANAGE = 'partner_changes.manage';

    public const OPERATIONS_VIEW = 'operations.view';

    public const OPERATIONS_MANAGE = 'operations.manage';

    public const FINANCE_VIEW = 'finance.view';

    public const FINANCE_MANAGE = 'finance.manage';

    public const FINANCE_PAY = 'finance.pay';

    public const REWARDS_VIEW = 'rewards.view';

    public const REWARDS_MANAGE = 'rewards.manage';

    public const RISK_VIEW = 'risk.view';

    public const RISK_MANAGE = 'risk.manage';

    public const CONTINUITY_VIEW = 'continuity.view';

    public const CONTINUITY_MANAGE = 'continuity.manage';

    public const CONFLICT_VIEW = 'conflict.view';

    public const CONFLICT_MANAGE = 'conflict.manage';

    public static function all(): array
    {
        return [
            self::PARTNER_CHANGES_VIEW,
            self::PARTNER_CHANGES_MANAGE,
            self::CONFLICT_VIEW,
            self::CONFLICT_MANAGE,
            self::RISK_VIEW,
            self::RISK_MANAGE,
            self::CONTINUITY_VIEW,
            self::CONTINUITY_MANAGE,
            self::FINANCE_VIEW,
            self::FINANCE_MANAGE,
            self::FINANCE_PAY,
            self::REWARDS_VIEW,
            self::REWARDS_MANAGE,
            self::OPERATIONS_VIEW,
            self::OPERATIONS_MANAGE,
            self::OWNERSHIP_VIEW,
            self::OWNERSHIP_MANAGE,
            self::PERMISSION_PROFILES_VIEW,
            self::RECORDS_VIEW,
            self::RECORDS_MANAGE,
            self::RECORDS_ACTIVITY_VIEW,
            self::ACCESS_ADMIN_VIEW,
            self::ACCESS_ADMIN_MANAGE,
            self::FORMATION_VIEW,
            self::FORMATION_MANAGE,
            self::FORMATION_AUTHORITY_BOOTSTRAP,
            self::GOVERNANCE_RECORDS_VIEW,
            self::GOVERNANCE_RECORDS_MANAGE,
            self::GOVERNANCE_SIGNATURE_ACT,
            self::GOVERNANCE_ACTION_MANAGE,
            self::BUSINESS_MODEL_VIEW,
            self::BUSINESS_MODEL_MANAGE,
            self::LEGAL_VIEW,
            self::LEGAL_MANAGE,
            self::CAPITAL_VIEW,
            self::CAPITAL_MANAGE,
            self::PARTNERS_VIEW,
            self::PARTNERS_MANAGE,
            self::DUE_DILIGENCE_VIEW,
            self::DUE_DILIGENCE_MANAGE,
            self::CONTRIBUTIONS_VIEW,
            self::CONTRIBUTIONS_MANAGE,
        ];
    }
}
