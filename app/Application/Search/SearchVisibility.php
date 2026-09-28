<?php

declare(strict_types=1);

namespace App\Application\Search;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Application\Conflict\ConflictRecordVisibility;
use App\Application\Continuity\ContinuityRecordVisibility;
use App\Application\Documents\AuthorizeDocumentAccess;
use App\Application\Risk\RiskRecordVisibility;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Documents\Enums\DocumentAccessRight;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Closure\ClosureCase;
use App\Infrastructure\Persistence\Eloquent\Conflict\ConflictCase;
use App\Infrastructure\Persistence\Eloquent\Continuity\ContinuityEmergencyAccessActivation;
use App\Infrastructure\Persistence\Eloquent\Continuity\ContinuityTest;
use App\Infrastructure\Persistence\Eloquent\Documents\Document;
use App\Infrastructure\Persistence\Eloquent\Exit\ExitCase;
use App\Infrastructure\Persistence\Eloquent\Finance\FinancePayment;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\PartnerChanges\PartnerChangeCase;
use App\Infrastructure\Persistence\Eloquent\Partnership\Contribution;
use App\Infrastructure\Persistence\Eloquent\Partnership\OwnershipRegisterVersion;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Rewards\DistributionRun;
use App\Infrastructure\Persistence\Eloquent\Risk\RiskControlTest;
use App\Infrastructure\Persistence\Eloquent\Risk\RiskIncident;
use App\Infrastructure\Persistence\Eloquent\Risk\RiskItem;
use App\Infrastructure\Persistence\Eloquent\Risk\RiskProtectionRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class SearchVisibility
{
    public function __construct(
        private readonly AuthorizeBusinessCapability $authorize,
        private readonly AuthorizeDocumentAccess $documentAccess,
        private readonly ConflictRecordVisibility $conflictVisibility,
        private readonly RiskRecordVisibility $riskVisibility,
        private readonly ContinuityRecordVisibility $continuityVisibility,
    ) {}

    public function allows(
        User $user,
        Business $business,
        string $sourceType,
        string $sourceId,
    ): bool {
        if (! $this->baseAllows(
            $user,
            $business,
            CapabilityCatalog::SEARCH_VIEW,
        )) {
            return false;
        }

        return match ($sourceType) {
            'formal_record_version' => $this->formalRecordAllows(
                $user,
                $business,
                $sourceId,
            ),
            'document' => $this->documentAllows(
                $user,
                $business,
                $sourceId,
            ),
            'partner' => $this->tableAllows(
                $user,
                $business,
                'partners',
                $sourceId,
                CapabilityCatalog::PARTNERS_VIEW,
            ),
            'contribution' => $this->modelBaseAllows(
                $user,
                $business,
                Contribution::class,
                $sourceId,
                CapabilityCatalog::CONTRIBUTIONS_VIEW,
            ),
            'ownership_register_version' => $this->modelBaseAllows(
                $user,
                $business,
                OwnershipRegisterVersion::class,
                $sourceId,
                CapabilityCatalog::OWNERSHIP_VIEW,
            ),
            'partner_change_case' => $this->resourceAllows(
                $user,
                $business,
                PartnerChangeCase::class,
                $sourceId,
                CapabilityCatalog::PARTNER_CHANGES_VIEW,
            ),
            'exit_case' => $this->resourceAllows(
                $user,
                $business,
                ExitCase::class,
                $sourceId,
                CapabilityCatalog::EXIT_VIEW,
            ),
            'closure_case' => $this->resourceAllows(
                $user,
                $business,
                ClosureCase::class,
                $sourceId,
                CapabilityCatalog::CLOSURE_VIEW,
            ),
            'governance_decision' => $this->resourceAllows(
                $user,
                $business,
                Decision::class,
                $sourceId,
                CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
            ),
            'finance_payment' => $this->modelBaseAllows(
                $user,
                $business,
                FinancePayment::class,
                $sourceId,
                CapabilityCatalog::FINANCE_VIEW,
            ),
            'distribution_run' => $this->modelBaseAllows(
                $user,
                $business,
                DistributionRun::class,
                $sourceId,
                CapabilityCatalog::REWARDS_VIEW,
            ),
            'risk_item' => $this->riskAllows(
                $user,
                $business,
                RiskItem::class,
                $sourceId,
            ),
            'risk_protection' => $this->riskAllows(
                $user,
                $business,
                RiskProtectionRecord::class,
                $sourceId,
            ),
            'risk_incident' => $this->riskAllows(
                $user,
                $business,
                RiskIncident::class,
                $sourceId,
            ),
            'risk_control_test' => $this->riskAllows(
                $user,
                $business,
                RiskControlTest::class,
                $sourceId,
            ),
            'continuity_test' => $this->modelBaseAllows(
                $user,
                $business,
                ContinuityTest::class,
                $sourceId,
                CapabilityCatalog::CONTINUITY_VIEW,
            ),
            'continuity_emergency_access_activation' => $this->continuityActivationAllows(
                $user,
                $business,
                $sourceId,
            ),
            'conflict_case' => $this->conflictAllows(
                $user,
                $business,
                $sourceId,
            ),
            default => false,
        };
    }

    private function formalRecordAllows(
        User $user,
        Business $business,
        string $sourceId,
    ): bool {
        $record = DB::table('formal_record_versions as version')
            ->join(
                'formal_record_families as family',
                function ($join): void {
                    $join->on(
                        'family.id',
                        '=',
                        'version.formal_record_family_id',
                    )->on(
                        'family.business_id',
                        '=',
                        'version.business_id',
                    );
                },
            )
            ->where('version.business_id', $business->getKey())
            ->where('version.id', $sourceId)
            ->first([
                'family.record_type',
                'family.subject_type',
                'family.subject_id',
            ]);

        if ($record === null) {
            return false;
        }

        if (! $this->authorize->decide(
            $user,
            $business,
            $business,
            new Capability(CapabilityCatalog::RECORDS_VIEW),
            FormalRecordVersion::class,
            $sourceId,
        )->allowed) {
            return false;
        }

        $recordType = (string) $record->record_type;
        $subjectType = (string) $record->subject_type;
        $subjectId = (string) $record->subject_id;

        return match ($recordType) {
            'conflict_settlement' => (
                $subjectType === 'conflict_case'
                && $this->conflictVisibility->canView(
                    $user,
                    $business,
                    $subjectId,
                )
            ),
            'conflict_resolution_policy' => $this->baseAllows(
                $user,
                $business,
                CapabilityCatalog::CONFLICT_VIEW,
            ),
            'risk_register' => $this->baseAllows(
                $user,
                $business,
                CapabilityCatalog::RISK_VIEW,
            ),
            'continuity_plan' => $this->baseAllows(
                $user,
                $business,
                CapabilityCatalog::CONTINUITY_VIEW,
            ),
            'finance_policy',
            'finance_payment' => $this->baseAllows(
                $user,
                $business,
                CapabilityCatalog::FINANCE_VIEW,
            ),
            'reward_policy',
            'distribution_run' => $this->baseAllows(
                $user,
                $business,
                CapabilityCatalog::REWARDS_VIEW,
            ),
            'operations_register' => $this->baseAllows(
                $user,
                $business,
                CapabilityCatalog::OPERATIONS_VIEW,
            ),
            'governance_charter',
            'formation_authority_policy' => $this->baseAllows(
                $user,
                $business,
                CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
            ),
            'ownership_register' => $this->baseAllows(
                $user,
                $business,
                CapabilityCatalog::OWNERSHIP_VIEW,
            ),
            'partner_contribution' => $this->baseAllows(
                $user,
                $business,
                CapabilityCatalog::CONTRIBUTIONS_VIEW,
            ),
            'partner_change' => (
                $subjectType === 'partner_change_case'
                && $this->resourceAllows(
                    $user,
                    $business,
                    PartnerChangeCase::class,
                    $subjectId,
                    CapabilityCatalog::PARTNER_CHANGES_VIEW,
                )
            ),
            'exit_case' => (
                $subjectType === 'exit_case'
                && $this->resourceAllows(
                    $user,
                    $business,
                    ExitCase::class,
                    $subjectId,
                    CapabilityCatalog::EXIT_VIEW,
                )
            ),
            'closure_case' => (
                $subjectType === 'closure_case'
                && $this->resourceAllows(
                    $user,
                    $business,
                    ClosureCase::class,
                    $subjectId,
                    CapabilityCatalog::CLOSURE_VIEW,
                )
            ),
            'capital_plan' => $this->baseAllows(
                $user,
                $business,
                CapabilityCatalog::CAPITAL_VIEW,
            ),
            default => true,
        };
    }

    private function documentAllows(
        User $user,
        Business $business,
        string $sourceId,
    ): bool {
        $document = Document::query()
            ->where('business_id', $business->getKey())
            ->whereKey($sourceId)
            ->first();

        if ($document === null) {
            return false;
        }

        return $this->documentAccess->allows(
            $user,
            $business,
            $document,
            DocumentAccessRight::View,
            CapabilityCatalog::RECORDS_VIEW,
        ) !== null;
    }

    /**
     * Conflict never falls back to generic Search visibility.
     */
    private function conflictAllows(
        User $user,
        Business $business,
        string $sourceId,
    ): bool {
        if (! ConflictCase::query()
            ->where('business_id', $business->getKey())
            ->whereKey($sourceId)
            ->exists()) {
            return false;
        }

        return $this->conflictVisibility->canView(
            $user,
            $business,
            $sourceId,
        );
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function riskAllows(
        User $user,
        Business $business,
        string $modelClass,
        string $sourceId,
    ): bool {
        $row = $modelClass::query()
            ->where('business_id', $business->getKey())
            ->whereKey($sourceId)
            ->first();

        if ($row === null) {
            return false;
        }

        return $this->riskVisibility->canView(
            $user,
            $business,
            $modelClass,
            $sourceId,
            (string) $row->getAttribute('confidentiality'),
        );
    }

    private function continuityActivationAllows(
        User $user,
        Business $business,
        string $sourceId,
    ): bool {
        if (! ContinuityEmergencyAccessActivation::query()
            ->where('business_id', $business->getKey())
            ->whereKey($sourceId)
            ->exists()) {
            return false;
        }

        return $this->continuityVisibility->canView(
            $user,
            $business,
            ContinuityEmergencyAccessActivation::class,
            $sourceId,
            true,
        );
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function resourceAllows(
        User $user,
        Business $business,
        string $modelClass,
        string $sourceId,
        string $capability,
    ): bool {
        if (! $modelClass::query()
            ->where('business_id', $business->getKey())
            ->whereKey($sourceId)
            ->exists()) {
            return false;
        }

        return $this->authorize->decide(
            $user,
            $business,
            $business,
            new Capability($capability),
            $modelClass,
            $sourceId,
        )->allowed;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function modelBaseAllows(
        User $user,
        Business $business,
        string $modelClass,
        string $sourceId,
        string $capability,
    ): bool {
        if (! $modelClass::query()
            ->where('business_id', $business->getKey())
            ->whereKey($sourceId)
            ->exists()) {
            return false;
        }

        return $this->baseAllows($user, $business, $capability);
    }

    private function tableAllows(
        User $user,
        Business $business,
        string $table,
        string $sourceId,
        string $capability,
    ): bool {
        if (! DB::table($table)
            ->where('business_id', $business->getKey())
            ->where('id', $sourceId)
            ->exists()) {
            return false;
        }

        return $this->baseAllows($user, $business, $capability);
    }

    private function baseAllows(
        User $user,
        Business $business,
        string $capability,
    ): bool {
        return $this->authorize->decide(
            $user,
            $business,
            $business,
            new Capability($capability),
        )->allowed;
    }
}
