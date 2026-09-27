<?php

declare(strict_types=1);

namespace App\Application\Continuity;

use App\Application\Governance\GovernanceActorContext;
use App\Application\Operations\CreateOperationsAction;
use App\Application\Risk\RiskRecordVisibility;
use App\Domain\Access\CapabilityCatalog;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Continuity\ContinuityEmergencyAccessActivation;
use App\Infrastructure\Persistence\Eloquent\Governance\Action;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Risk\RiskControlTest;
use App\Infrastructure\Persistence\Eloquent\Risk\RiskIncident;
use App\Infrastructure\Persistence\Eloquent\Risk\RiskItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CreateRiskContinuityAction
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly CreateOperationsAction $createAction,
        private readonly RiskRecordVisibility $riskVisibility,
        private readonly ContinuityRecordVisibility $continuityVisibility,
    ) {}

    public function execute(
        User $user,
        Business $business,
        string $sourceType,
        string $sourceId,
        string $operationsRoleId,
        string $assignedMembershipId,
        string $title,
        ?string $description = null,
        ?CarbonInterface $dueAt = null,
    ): ?Action {
        $source = $this->source(
            $user,
            $business,
            $sourceType,
            $sourceId,
        );

        if ($source === null) {
            return null;
        }

        $action = $this->createAction->execute(
            $user,
            $business,
            $operationsRoleId,
            $assignedMembershipId,
            $title,
            $description,
            $dueAt,
        );

        if ($action === null) {
            return null;
        }

        DB::table('risk_continuity_action_links')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'action_id' => $action->getKey(),
            'formal_record_version_id' => $source['formal_record_version_id'],
            'operations_role_id' => $operationsRoleId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'created_at' => now(),
        ]);

        return $action;
    }

    /** @return array{formal_record_version_id:string}|null */
    private function source(
        User $user,
        Business $business,
        string $sourceType,
        string $sourceId,
    ): ?array {
        return match ($sourceType) {
            'risk_item' => $this->riskSource(
                $user,
                $business,
                RiskItem::class,
                $sourceId,
                'risk_items',
            ),
            'risk_incident' => $this->riskSource(
                $user,
                $business,
                RiskIncident::class,
                $sourceId,
                'risk_incidents',
            ),
            'risk_control_test' => $this->riskSource(
                $user,
                $business,
                RiskControlTest::class,
                $sourceId,
                'risk_control_tests',
            ),
            'continuity_critical_function' => $this->standardContinuitySource(
                $user,
                $business,
                $sourceId,
                'continuity_critical_functions',
            ),
            'continuity_test' => $this->standardContinuitySource(
                $user,
                $business,
                $sourceId,
                'continuity_tests',
            ),
            'emergency_access_activation' => $this->activationSource(
                $user,
                $business,
                $sourceId,
            ),
            default => throw new InvalidArgumentException(
                'Risk/Continuity Action source is invalid.',
            ),
        };
    }

    /** @return array{formal_record_version_id:string}|null */
    private function riskSource(
        User $user,
        Business $business,
        string $resourceType,
        string $sourceId,
        string $table,
    ): ?array {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::RISK_MANAGE,
        ) === null) {
            return null;
        }

        $row = DB::table($table)
            ->where('business_id', $business->getKey())
            ->where('id', $sourceId)
            ->first();

        if (
            $row === null
            || ! $this->riskVisibility->canView(
                $user,
                $business,
                $resourceType,
                $sourceId,
                (string) ($row->confidentiality ?? 'standard'),
            )
        ) {
            return null;
        }

        return ['formal_record_version_id' => (string) $row->formal_record_version_id];
    }

    /** @return array{formal_record_version_id:string}|null */
    private function standardContinuitySource(
        User $user,
        Business $business,
        string $sourceId,
        string $table,
    ): ?array {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::CONTINUITY_MANAGE,
        ) === null) {
            return null;
        }

        $row = DB::table($table)
            ->where('business_id', $business->getKey())
            ->where('id', $sourceId)
            ->first();

        return $row === null
            ? null
            : ['formal_record_version_id' => (string) $row->formal_record_version_id];
    }

    /** @return array{formal_record_version_id:string}|null */
    private function activationSource(
        User $user,
        Business $business,
        string $sourceId,
    ): ?array {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::CONTINUITY_MANAGE,
        ) === null) {
            return null;
        }

        $row = ContinuityEmergencyAccessActivation::query()
            ->where('business_id', $business->getKey())
            ->whereKey($sourceId)
            ->first();

        if (
            $row === null
            || ! $this->continuityVisibility->canView(
                $user,
                $business,
                ContinuityEmergencyAccessActivation::class,
                $sourceId,
                true,
            )
        ) {
            return null;
        }

        return ['formal_record_version_id' => (string) $row->formal_record_version_id];
    }
}
