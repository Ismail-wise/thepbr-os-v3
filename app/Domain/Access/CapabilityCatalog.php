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

    /**
     * System capabilities only.
     *
     * These values never establish Ownership Rights, Governance Rights,
     * Partner status, voting entitlement, profit entitlement or document
     * visibility by themselves.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
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
        ];
    }
}
