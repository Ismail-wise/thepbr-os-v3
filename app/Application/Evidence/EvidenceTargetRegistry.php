<?php

declare(strict_types=1);

namespace App\Application\Evidence;

use App\Domain\Access\CapabilityCatalog;
use App\Infrastructure\Persistence\Eloquent\Conflict\ConflictCase;
use App\Infrastructure\Persistence\Eloquent\Continuity\ContinuityEmergencyAccessActivation;
use App\Infrastructure\Persistence\Eloquent\Continuity\ContinuityTest;
use App\Infrastructure\Persistence\Eloquent\Finance\FinanceException;
use App\Infrastructure\Persistence\Eloquent\Finance\FinancePayment;
use App\Infrastructure\Persistence\Eloquent\Finance\FinanceReconciliationReview;
use App\Infrastructure\Persistence\Eloquent\Partnership\Contribution;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\ProposalVersion;
use App\Infrastructure\Persistence\Eloquent\Rewards\DistributionRun;
use App\Infrastructure\Persistence\Eloquent\Risk\RiskControlTest;
use App\Infrastructure\Persistence\Eloquent\Risk\RiskIncident;
use App\Infrastructure\Persistence\Eloquent\Risk\RiskProtectionRecord;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class EvidenceTargetRegistry
{
    /**
     * @var array<string, class-string<Model>>
     */
    private const array TARGETS = [
        'formal_record_version' => FormalRecordVersion::class,
        'proposal_version' => ProposalVersion::class,
        'contribution' => Contribution::class,
        'finance_payment' => FinancePayment::class,
        'finance_reconciliation' => FinanceReconciliationReview::class,
        'finance_exception' => FinanceException::class,
        'distribution_run' => DistributionRun::class,
        'risk_protection' => RiskProtectionRecord::class,
        'risk_incident' => RiskIncident::class,
        'risk_control_test' => RiskControlTest::class,
        'continuity_test' => ContinuityTest::class,
        'continuity_emergency_access_activation' => ContinuityEmergencyAccessActivation::class,
        'conflict_case' => ConflictCase::class,
    ];

    /**
     * @return list<string>
     */
    public function supportedTypes(): array
    {
        return array_keys(self::TARGETS);
    }

    public function requiredManageCapability(
        string $targetType,
    ): string {
        if (! array_key_exists($targetType, self::TARGETS)) {
            throw new InvalidArgumentException(
                'Unsupported evidence target type.',
            );
        }

        return match ($targetType) {
            'contribution' => CapabilityCatalog::CONTRIBUTIONS_MANAGE,
            'finance_payment',
            'finance_reconciliation',
            'finance_exception' => CapabilityCatalog::FINANCE_MANAGE,
            'distribution_run' => CapabilityCatalog::REWARDS_MANAGE,
            'risk_protection',
            'risk_incident',
            'risk_control_test' => CapabilityCatalog::RISK_MANAGE,
            'continuity_test',
            'continuity_emergency_access_activation' => CapabilityCatalog::CONTINUITY_MANAGE,
            'conflict_case' => CapabilityCatalog::CONFLICT_MANAGE,
            default => CapabilityCatalog::RECORDS_MANAGE,
        };
    }

    public function resolve(
        string $targetType,
        string $targetId,
        string $businessId,
    ): ?Model {
        $modelClass = self::TARGETS[$targetType] ?? null;

        if ($modelClass === null) {
            throw new InvalidArgumentException(
                'Unsupported evidence target type.',
            );
        }

        return $modelClass::query()
            ->where('business_id', $businessId)
            ->whereKey($targetId)
            ->first();
    }
}
