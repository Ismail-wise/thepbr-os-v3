<?php

declare(strict_types=1);

namespace App\Application\Risk;

use App\Application\Governance\GovernanceActorContext;
use App\Application\Governance\RecordGovernanceOccurrence;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Risk\Enums\IncidentStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Risk\RiskIncident;
use App\Infrastructure\Persistence\Eloquent\Risk\RiskItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RiskIncidentWorkflow
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly RiskRecordVisibility $visibility,
        private readonly RecordGovernanceOccurrence $occurrence,
    ) {}

    /** @param array<string,mixed> $payload */
    public function open(
        User $user,
        Business $business,
        array $payload,
    ): ?string {
        $membership = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::RISK_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        $versionId = $this->currentRiskVersion($business);

        if ($versionId === null) {
            throw new InvalidArgumentException(
                'Risk Incident requires a Current Effective Risk Register.',
            );
        }

        $riskItemId = $this->nullableText($payload['risk_item_id'] ?? null);
        $risk = null;

        if ($riskItemId !== null) {
            $risk = RiskItem::query()
                ->where('business_id', $business->getKey())
                ->where('formal_record_version_id', $versionId)
                ->whereKey($riskItemId)
                ->first();

            if (
                $risk === null
                || ! $this->visibility->canView(
                    $user,
                    $business,
                    RiskItem::class,
                    (string) $risk->getKey(),
                    (string) $risk->confidentiality,
                )
            ) {
                return null;
            }
        }

        $description = trim((string) ($payload['description'] ?? ''));
        $impact = trim((string) ($payload['business_impact'] ?? ''));
        $action = trim((string) ($payload['immediate_action'] ?? ''));
        $type = trim((string) ($payload['incident_type'] ?? ''));

        if ($description === '' || $impact === '' || $action === '' || $type === '') {
            throw new InvalidArgumentException('Risk Incident is incomplete.');
        }

        $confidentiality = ($payload['confidentiality'] ?? null) === 'restricted'
            || $risk?->confidentiality === 'restricted'
            ? 'restricted'
            : 'standard';

        $loss = $payload['loss_amount_minor_units'] ?? null;

        if ($loss !== null && $loss !== '' && (! is_numeric($loss) || (int) $loss < 0)) {
            throw new InvalidArgumentException('Incident loss amount is invalid.');
        }

        $currency = strtoupper(trim((string) ($payload['currency'] ?? '')));

        if ($currency !== '' && preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException('Incident currency is invalid.');
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $membership,
            $payload,
            $versionId,
            $risk,
            $description,
            $impact,
            $action,
            $type,
            $confidentiality,
            $loss,
            $currency,
        ): string {
            $incident = RiskIncident::query()->create([
                'business_id' => $business->getKey(),
                'formal_record_version_id' => $versionId,
                'risk_item_id' => $risk?->getKey(),
                'incident_at' => $payload['incident_at'] ?? now(),
                'incident_type' => $type,
                'description' => $description,
                'business_impact' => $impact,
                'immediate_action' => $action,
                'loss_amount_minor_units' => $loss === null || $loss === '' ? null : (int) $loss,
                'currency' => $currency === '' ? null : $currency,
                'status' => IncidentStatus::Open->value,
                'confidentiality' => $confidentiality,
                'opened_by_membership_id' => $membership->getKey(),
                'revision' => 1,
            ]);

            DB::table('risk_incident_updates')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'risk_incident_id' => $incident->getKey(),
                'status' => IncidentStatus::Open->value,
                'root_cause' => null,
                'corrective_action' => null,
                'note' => 'Incident opened.',
                'actor_membership_id' => $membership->getKey(),
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            if ($confidentiality === 'restricted') {
                $this->visibility->grantRestrictedAccess(
                    $business,
                    RiskIncident::class,
                    (string) $incident->getKey(),
                    array_filter([
                        (string) $membership->getKey(),
                        $risk?->owner_membership_id,
                    ]),
                );
            }

            $this->occurrence->record(
                $user,
                $business,
                'risk.incident.opened',
                'risk_incident',
                (string) $incident->getKey(),
                [
                    'status' => IncidentStatus::Open->value,
                    'restricted' => $confidentiality === 'restricted',
                ],
                $versionId,
            );

            return (string) $incident->getKey();
        });
    }

    public function transition(
        User $user,
        Business $business,
        string $incidentId,
        IncidentStatus $target,
        int $expectedRevision,
        ?string $rootCause = null,
        ?string $correctiveAction = null,
        ?string $note = null,
        ?CarbonInterface $occurredAt = null,
    ): bool {
        $membership = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::RISK_MANAGE,
        );

        if ($membership === null) {
            return false;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $membership,
            $incidentId,
            $target,
            $expectedRevision,
            $rootCause,
            $correctiveAction,
            $note,
            $occurredAt,
        ): bool {
            $incident = RiskIncident::query()
                ->where('business_id', $business->getKey())
                ->whereKey($incidentId)
                ->lockForUpdate()
                ->first();

            if (
                $incident === null
                || (int) $incident->revision !== $expectedRevision
                || ! $this->visibility->canView(
                    $user,
                    $business,
                    RiskIncident::class,
                    $incidentId,
                    (string) $incident->confidentiality,
                )
            ) {
                return false;
            }

            $current = $incident->status;
            $allowed = [
                IncidentStatus::Open->value => [IncidentStatus::Investigating],
                IncidentStatus::Investigating->value => [IncidentStatus::Contained, IncidentStatus::CorrectiveAction],
                IncidentStatus::Contained->value => [IncidentStatus::CorrectiveAction],
                IncidentStatus::CorrectiveAction->value => [IncidentStatus::Resolved],
                IncidentStatus::Resolved->value => [IncidentStatus::Closed],
                IncidentStatus::Closed->value => [],
            ];

            if (! in_array($target, $allowed[$current->value] ?? [], true)) {
                throw new InvalidArgumentException('Risk Incident transition is invalid.');
            }

            $incident->status = $target;
            $incident->revision = $expectedRevision + 1;
            $incident->save();

            DB::table('risk_incident_updates')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'risk_incident_id' => $incident->getKey(),
                'status' => $target->value,
                'root_cause' => $this->nullableText($rootCause),
                'corrective_action' => $this->nullableText($correctiveAction),
                'note' => $this->nullableText($note),
                'actor_membership_id' => $membership->getKey(),
                'occurred_at' => $occurredAt ?? now(),
                'created_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'risk.incident.status_changed',
                'risk_incident',
                $incidentId,
                ['status' => $target->value],
                (string) $incident->formal_record_version_id,
            );

            return true;
        });
    }

    private function currentRiskVersion(Business $business): ?string
    {
        $id = DB::table('record_family_effective_heads as h')
            ->join('formal_record_versions as v', 'v.id', '=', 'h.formal_record_version_id')
            ->join('formal_record_families as f', 'f.id', '=', 'v.formal_record_family_id')
            ->where('h.business_id', $business->getKey())
            ->where('f.business_id', $business->getKey())
            ->where('f.record_type', 'risk_register')
            ->value('v.id');

        return $id === null ? null : (string) $id;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
