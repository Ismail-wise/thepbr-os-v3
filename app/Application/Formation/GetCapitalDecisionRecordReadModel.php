<?php

declare(strict_types=1);

namespace App\Application\Formation;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Capital\CapitalDecisionRecordContract;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Support\Facades\DB;

final class GetCapitalDecisionRecordReadModel
{
    public function __construct(
        private readonly FormationActorContext $actor,
        private readonly GetApprovedCapitalDecisionSource $source,
    ) {}

    /**
     * @return array<string,mixed>|null
     */
    public function execute(
        User $user,
        Business $business,
    ): ?array {
        if (
            ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::CAPITAL_VIEW,
            )
            || ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::RECORDS_VIEW,
            )
        ) {
            return null;
        }

        $source = $this->source->execute($business);

        if ($source === null) {
            return [
                'contractVersion' => CapitalDecisionRecordContract::READ_MODEL_VERSION,
                'available' => false,
                'recorded' => false,
                'status' => 'not_approved',
                'canCreate' => false,
            ];
        }

        $record = DB::table('capital_decision_records')
            ->where('business_id', $business->getKey())
            ->where(
                'capital_approval_snapshot_id',
                $source['snapshotId'],
            )
            ->first();

        $planningChanged = $this->planningChanged(
            $business,
            $source,
        );

        $canCreate = $record === null
            && $source['formalState'] === 'approved'
            && $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::CAPITAL_MANAGE,
            )
            && $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::RECORDS_MANAGE,
            )
            && $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
            );

        $payload = $source['payload'];
        $calculation = is_array($payload['calculation'] ?? null)
            ? $payload['calculation']
            : [];
        $rule = is_array($payload['capitalRule'] ?? null)
            ? $payload['capitalRule']
            : [];

        $approvedBy = collect($source['approvedByEvidence'])
            ->map(function (array $evidence) use ($business): array {
                return [
                    'name' => $this->membershipLabel(
                        $business,
                        $evidence['membershipId'],
                    ),
                    'evidenceKind' => $evidence['evidenceKind'],
                    'recordedAt' => $evidence['recordedAt'],
                ];
            })
            ->values()
            ->all();

        $recordView = $record === null
            ? null
            : [
                'recordId' => (string) $record->id,
                'contractVersion' => (string) $record->contract_version,
                'decisionOwner' => $this->membershipLabel(
                    $business,
                    (string) $record->decision_owner_membership_id,
                ),
                'effectiveDate' => (string) $record->effective_date,
                'reviewDate' => (string) $record->review_date,
                'decisionSummary' => (string) $record->decision_summary,
                'evidenceReferences' => $this->decodeReferences(
                    (string) $record->evidence_references,
                ),
                'lastUpdated' => (string) $record->created_at,
                'internalReferences' => [
                    'capitalApprovalSnapshotId' => (string) $record->capital_approval_snapshot_id,
                    'formalRecordVersionId' => (string) $record->formal_record_version_id,
                    'proposalVersionId' => (string) $record->proposal_version_id,
                    'governanceDecisionId' => (string) $record->governance_decision_id,
                ],
            ];

        return [
            'contractVersion' => CapitalDecisionRecordContract::READ_MODEL_VERSION,
            'recordContractVersion' => CapitalDecisionRecordContract::CONTRACT_VERSION,
            'available' => true,
            'recorded' => $record !== null,
            'status' => $record === null
                ? 'approved_ready_to_record'
                : ($source['signatureRequired']
                    ? 'approved_signature_required'
                    : 'approved_awaiting_effectivity'),
            'canCreate' => $canCreate,
            'planningChangedSinceApproval' => $planningChanged,
            'historicalWarningRequired' => $planningChanged,
            'decisionOwnerOptions' => $record === null && $canCreate
                ? $this->decisionOwnerOptions($business)
                : [],
            'record' => $recordView,
            'approvedPlan' => [
                'preferredPlan' => $source['preferredPlan'],
                'baseCurrency' => $payload['business']['baseCurrency'] ?? null,
                'totalCapitalRequirement' => $calculation[
                    'totalCapitalRequirement'
                ]['amount'] ?? null,
                'workingCapital' => $calculation[
                    'workingCapital'
                ]['amount'] ?? null,
                'workingCapitalMonthsApplicable' => in_array(
                    $calculation['workingCapital']['method'] ?? null,
                    ['monthly_burn', 'canonical_operating_profile'],
                    true,
                ),
                'workingCapitalMonths' => $calculation[
                    'workingCapital'
                ]['months'] ?? null,
                'contingency' => $calculation[
                    'contingency'
                ]['amount'] ?? null,
                'contingencyPercentageApplicable' => (
                    $calculation['contingency']['method'] ?? null
                ) === 'percentage',
                'contingencyPercentage' => $calculation[
                    'contingency'
                ]['percentage'] ?? null,
                'confirmedFunding' => $calculation[
                    'fundingPosition'
                ]['confirmedFunding'] ?? null,
                'fundingGap' => $calculation[
                    'fundingPosition'
                ]['fundingGap'] ?? null,
                'fundingSurplus' => $calculation[
                    'fundingPosition'
                ]['fundingSurplus'] ?? null,
                'fundedPercentage' => $calculation[
                    'fundingPosition'
                ]['fundedPercentage'] ?? null,
                'capitalRule' => [
                    'shortfallResponses' => $rule['shortfallResponses'] ?? [],
                    'allocationNotes' => $rule['allocationNotes'] ?? null,
                    'shortfallRuleNotes' => $rule['shortfallRuleNotes'] ?? null,
                    'capitalCallRuleNote' => $rule['capitalCallRuleNote'] ?? null,
                ],
                'sourceRevisions' => [
                    'capitalPlanning' => $source['capitalPlanningRevision'],
                    'capitalRule' => $source['capitalRuleRevision'],
                    'capitalComparison' => $source['capitalComparisonRevision'],
                ],
                'approvedContentHash' => $source['contentHash'],
            ],
            'governedApproval' => [
                'approvedBy' => $approvedBy,
                'approvalDate' => $source['approvalDate'],
                'decisionMethod' => $source['decisionMethod'],
                'signatureRequired' => $source['signatureRequired'],
                'formalState' => $source['formalState'],
            ],
            'systemReferences' => [
                [
                    'label' => 'Approved Capital snapshot',
                    'detail' => 'capital-approval-v1',
                ],
                [
                    'label' => 'Frozen Capital Plan',
                    'detail' => 'Version '.$source['formalRecordVersionNumber'],
                ],
                [
                    'label' => 'Frozen Proposal',
                    'detail' => 'Version '.$source['proposalVersionNumber'],
                ],
                [
                    'label' => 'Governance Decision',
                    'detail' => 'Approved',
                ],
            ],
            'suggestedDecisionSummary' => $this->suggestedSummary(
                $source,
            ),
            'semantics' => [
                'approvalTruth' => true,
                'signedTruth' => false,
                'effectiveTruth' => false,
                'decisionRecordTruth' => $record !== null,
                'actionPlanTruth' => false,
                'capitalCallExecuted' => false,
                'contributionTruth' => false,
                'acceptedContributionTruth' => false,
                'equityTruth' => false,
                'ownershipTruth' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $source
     */
    private function planningChanged(
        Business $business,
        array $source,
    ): bool {
        $planning = DB::table('capital_planning_drafts')
            ->where('business_id', $business->getKey())
            ->first();
        $rule = DB::table('capital_rule_drafts')
            ->where('business_id', $business->getKey())
            ->first();
        $comparison = DB::table('capital_comparison_drafts')
            ->where('business_id', $business->getKey())
            ->first();

        if (
            $planning === null
            || $rule === null
            || $comparison === null
        ) {
            return true;
        }

        $comparisonPayload = json_decode(
            (string) $comparison->input_payload,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return (int) $planning->revision
                !== (int) $source['capitalPlanningRevision']
            || (int) $rule->revision
                !== (int) $source['capitalRuleRevision']
            || (int) $comparison->revision
                !== (int) $source['capitalComparisonRevision']
            || ! is_array($comparisonPayload)
            || ($comparisonPayload['preferredPlan'] ?? null)
                !== $source['preferredPlan'];
    }

    /**
     * @return list<array{id:string,name:string}>
     */
    private function decisionOwnerOptions(Business $business): array
    {
        return Membership::query()
            ->with(['user.profile'])
            ->where('business_id', $business->getKey())
            ->where('access_status', 'active')
            ->get()
            ->map(fn (Membership $membership): array => [
                'id' => (string) $membership->getKey(),
                'name' => $this->label($membership),
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    private function membershipLabel(
        Business $business,
        string $membershipId,
    ): string {
        $membership = Membership::query()
            ->with(['user.profile'])
            ->where('business_id', $business->getKey())
            ->whereKey($membershipId)
            ->first();

        return $membership === null
            ? 'Unavailable member'
            : $this->label($membership);
    }

    private function label(Membership $membership): string
    {
        $displayName = trim((string) (
            $membership->user?->profile?->display_name ?? ''
        ));

        if ($displayName !== '') {
            return $displayName;
        }

        return (string) (
            $membership->user?->email ?? 'Business member'
        );
    }

    /**
     * @return list<string>
     */
    private function decodeReferences(string $json): array
    {
        $values = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            $values,
            static fn (mixed $value): bool => is_string($value),
        ));
    }

    /**
     * @param  array<string,mixed>  $source
     */
    private function suggestedSummary(array $source): string
    {
        $payload = $source['payload'];
        $currency = (string) (
            $payload['business']['baseCurrency'] ?? ''
        );
        $amount = (string) (
            $payload['calculation']['totalCapitalRequirement']['amount']
            ?? ''
        );
        $plan = ucfirst((string) $source['preferredPlan']);

        return trim(sprintf(
            'Approved %s Capital Plan with %s %s total Capital requirement.',
            $plan,
            $currency,
            $amount,
        ));
    }
}
