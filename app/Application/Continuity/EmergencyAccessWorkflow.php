<?php

declare(strict_types=1);

namespace App\Application\Continuity;

use App\Application\Governance\GovernanceActorContext;
use App\Application\Governance\RecordGovernanceOccurrence;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Continuity\Enums\EmergencyAccessActivationStatus;
use App\Domain\Governance\Enums\EmergencyAuthorityStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Continuity\ContinuityEmergencyAccessActivation;
use App\Infrastructure\Persistence\Eloquent\Governance\EmergencyAuthorityGrant;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class EmergencyAccessWorkflow
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly ContinuityRecordVisibility $visibility,
        private readonly RecordGovernanceOccurrence $occurrence,
    ) {}

    public function request(
        User $user,
        Business $business,
        string $emergencyAccessRecordId,
        string $trigger,
        string $reason,
        CarbonInterface $startsAt,
        CarbonInterface $expiresAt,
        ?string $requiredDecisionType = null,
        ?string $emergencyAuthorityGrantId = null,
    ): ?string {
        $membership = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::CONTINUITY_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        $trigger = trim($trigger);
        $reason = trim($reason);
        $requiredDecisionType = $this->nullableText($requiredDecisionType);

        if ($trigger === '' || $reason === '' || $expiresAt <= $startsAt) {
            throw new InvalidArgumentException(
                'Emergency Access activation requires trigger, reason and a bounded time window.',
            );
        }

        $planVersionId = $this->currentPlanVersion($business);

        if ($planVersionId === null) {
            return null;
        }

        $access = DB::table('continuity_emergency_access_records')
            ->where('business_id', $business->getKey())
            ->where('formal_record_version_id', $planVersionId)
            ->where('id', $emergencyAccessRecordId)
            ->where('status', 'active')
            ->first();

        if (
            $access === null
            || ! $this->visibility->canView(
                $user,
                $business,
                ContinuityRecordVisibility::EMERGENCY_ACCESS_RESOURCE,
                $emergencyAccessRecordId,
                true,
            )
        ) {
            return null;
        }

        if ($requiredDecisionType !== null) {
            if (
                $emergencyAuthorityGrantId === null
                || ! $this->validGovernedGrant(
                    $business,
                    (string) $membership->getKey(),
                    $emergencyAuthorityGrantId,
                    $requiredDecisionType,
                    $emergencyAccessRecordId,
                    $startsAt,
                    $expiresAt,
                )
            ) {
                return null;
            }
        } elseif ($emergencyAuthorityGrantId !== null) {
            throw new InvalidArgumentException(
                'Emergency Authority Grant requires an exact decision type.',
            );
        }

        $activation = ContinuityEmergencyAccessActivation::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $planVersionId,
            'emergency_access_record_id' => $emergencyAccessRecordId,
            'activated_by_membership_id' => $membership->getKey(),
            'emergency_authority_grant_id' => $emergencyAuthorityGrantId,
            'required_decision_type' => $requiredDecisionType,
            'trigger' => $trigger,
            'reason' => $reason,
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'status' => EmergencyAccessActivationStatus::Requested->value,
            'ended_at' => null,
            'revision' => 1,
        ]);

        $this->visibility->grantRestrictedAccess(
            $business,
            ContinuityEmergencyAccessActivation::class,
            (string) $activation->getKey(),
            [
                (string) $membership->getKey(),
                (string) $access->primary_access_membership_id,
                (string) $access->backup_access_membership_id,
            ],
        );

        $this->occurrence->record(
            $user,
            $business,
            'continuity.emergency_access.requested',
            'continuity_emergency_access_activation',
            (string) $activation->getKey(),
            [
                'status' => EmergencyAccessActivationStatus::Requested->value,
                'governance_authority_bound' => $requiredDecisionType !== null,
            ],
            $planVersionId,
        );

        return (string) $activation->getKey();
    }

    public function activate(
        User $user,
        Business $business,
        string $activationId,
        int $expectedRevision,
    ): bool {
        return $this->transition(
            $user,
            $business,
            $activationId,
            $expectedRevision,
            EmergencyAccessActivationStatus::Active,
        );
    }

    public function end(
        User $user,
        Business $business,
        string $activationId,
        int $expectedRevision,
        EmergencyAccessActivationStatus $target,
    ): bool {
        if (! in_array($target, [
            EmergencyAccessActivationStatus::Expired,
            EmergencyAccessActivationStatus::Revoked,
            EmergencyAccessActivationStatus::Closed,
        ], true)) {
            throw new InvalidArgumentException(
                'Emergency Access end state is invalid.',
            );
        }

        return $this->transition(
            $user,
            $business,
            $activationId,
            $expectedRevision,
            $target,
        );
    }

    private function transition(
        User $user,
        Business $business,
        string $activationId,
        int $expectedRevision,
        EmergencyAccessActivationStatus $target,
    ): bool {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::CONTINUITY_MANAGE,
        ) === null) {
            return false;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $activationId,
            $expectedRevision,
            $target,
        ): bool {
            $activation = ContinuityEmergencyAccessActivation::query()
                ->where('business_id', $business->getKey())
                ->whereKey($activationId)
                ->lockForUpdate()
                ->first();

            if (
                $activation === null
                || (int) $activation->revision !== $expectedRevision
                || ! $this->visibility->canView(
                    $user,
                    $business,
                    ContinuityEmergencyAccessActivation::class,
                    $activationId,
                    true,
                )
            ) {
                return false;
            }

            if (
                $target === EmergencyAccessActivationStatus::Active
                && $activation->status !== EmergencyAccessActivationStatus::Requested
            ) {
                return false;
            }

            if (
                $target !== EmergencyAccessActivationStatus::Active
                && $activation->status !== EmergencyAccessActivationStatus::Active
            ) {
                return false;
            }

            if (
                $target === EmergencyAccessActivationStatus::Active
                && now()->greaterThanOrEqualTo($activation->expires_at)
            ) {
                return false;
            }

            if (
                $target === EmergencyAccessActivationStatus::Active
                && $activation->required_decision_type !== null
                && ! $this->validGovernedGrant(
                    $business,
                    (string) $activation->activated_by_membership_id,
                    (string) $activation->emergency_authority_grant_id,
                    (string) $activation->required_decision_type,
                    (string) $activation->emergency_access_record_id,
                    $activation->starts_at,
                    $activation->expires_at,
                )
            ) {
                return false;
            }

            $activation->status = $target;
            $activation->revision = $expectedRevision + 1;

            if ($target !== EmergencyAccessActivationStatus::Active) {
                $activation->ended_at = now();
            }

            $activation->save();

            $this->occurrence->record(
                $user,
                $business,
                'continuity.emergency_access.status_changed',
                'continuity_emergency_access_activation',
                $activationId,
                ['status' => $target->value],
                (string) $activation->formal_record_version_id,
            );

            return true;
        });
    }

    private function validGovernedGrant(
        Business $business,
        string $membershipId,
        string $grantId,
        string $decisionType,
        string $accessRecordId,
        CarbonInterface $startsAt,
        CarbonInterface $expiresAt,
    ): bool {
        $grant = EmergencyAuthorityGrant::query()
            ->where('business_id', $business->getKey())
            ->whereKey($grantId)
            ->where('grantee_membership_id', $membershipId)
            ->where('decision_type', $decisionType)
            ->where(
                'scope',
                'continuity_emergency_access:'.$accessRecordId,
            )
            ->where('status', EmergencyAuthorityStatus::Active->value)
            ->first();

        if (
            $grant === null
            || $grant->effective_from->greaterThan($startsAt)
            || $grant->expires_at->lessThan($expiresAt)
        ) {
            return false;
        }

        return DB::table('governance_authority_change_submissions')
            ->where('business_id', $business->getKey())
            ->where('subject_type', 'emergency_authority')
            ->where('subject_id', $grantId)
            ->where('action', 'grant')
            ->whereNotNull('authorized_at')
            ->exists();
    }

    private function currentPlanVersion(Business $business): ?string
    {
        $id = DB::table('record_family_effective_heads as h')
            ->join('formal_record_versions as v', 'v.id', '=', 'h.formal_record_version_id')
            ->join('formal_record_families as f', 'f.id', '=', 'v.formal_record_family_id')
            ->where('h.business_id', $business->getKey())
            ->where('f.business_id', $business->getKey())
            ->where('f.record_type', 'continuity_plan')
            ->value('v.id');

        return $id === null ? null : (string) $id;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
