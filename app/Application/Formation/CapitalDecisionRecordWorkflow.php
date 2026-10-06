<?php

declare(strict_types=1);

namespace App\Application\Formation;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Capital\CapitalDecisionRecordContract;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class CapitalDecisionRecordWorkflow
{
    public function __construct(
        private readonly FormationActorContext $actor,
        private readonly FormationOccurrence $occurrence,
        private readonly GetApprovedCapitalDecisionSource $source,
        private readonly CapitalDecisionRecordContract $contract,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>|null
     */
    public function create(
        User $user,
        Business $business,
        array $input,
    ): ?array {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::CAPITAL_MANAGE,
        );

        if (
            $membership === null
            || ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::RECORDS_MANAGE,
            )
            || ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
            )
        ) {
            return null;
        }

        $normalized = $this->contract->normalize($input);

        return DB::transaction(function () use (
            $user,
            $business,
            $membership,
            $normalized,
        ): array {
            $source = $this->source->execute($business);

            if ($source === null) {
                throw new RuntimeException(
                    'An approved Capital Plan is required before recording the decision.',
                );
            }

            if ($source['formalState'] !== 'approved') {
                throw new RuntimeException(
                    'The Capital Plan must remain in the Approved lifecycle state before the Decision Record is created.',
                );
            }

            $existing = DB::table('capital_decision_records')
                ->where('business_id', $business->getKey())
                ->where(
                    'capital_approval_snapshot_id',
                    $source['snapshotId'],
                )
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $this->envelope($existing, false);
            }

            $owner = Membership::query()
                ->where('business_id', $business->getKey())
                ->whereKey($normalized['decisionOwnerMembershipId'])
                ->where('access_status', 'active')
                ->lockForUpdate()
                ->first();

            if ($owner === null) {
                throw new InvalidArgumentException(
                    'Decision Owner must be an active member of this Business.',
                );
            }

            $id = (string) Str::uuid7();
            $createdAt = now();

            DB::table('capital_decision_records')->insert([
                'id' => $id,
                'business_id' => $business->getKey(),
                'contract_version' => CapitalDecisionRecordContract::CONTRACT_VERSION,
                'capital_approval_snapshot_id' => $source['snapshotId'],
                'formal_record_version_id' => $source['formalRecordVersionId'],
                'proposal_version_id' => $source['proposalVersionId'],
                'governance_decision_id' => $source['governanceDecisionId'],
                'approved_content_hash' => $source['contentHash'],
                'decision_owner_membership_id' => $owner->getKey(),
                'effective_date' => $normalized['effectiveDate'],
                'review_date' => $normalized['reviewDate'],
                'decision_summary' => $normalized['decisionSummary'],
                'evidence_references' => json_encode(
                    $normalized['evidenceReferences'],
                    JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
                ),
                'created_by_membership_id' => $membership->getKey(),
                'created_at' => $createdAt,
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'capital.decision_record.created',
                'capital_decision_record',
                $id,
                [
                    'contract_version' => CapitalDecisionRecordContract::CONTRACT_VERSION,
                    'capital_approval_snapshot_id' => $source['snapshotId'],
                    'formal_record_version_id' => $source['formalRecordVersionId'],
                    'governance_decision_id' => $source['governanceDecisionId'],
                    'decision_owner_membership_id' => (string) $owner->getKey(),
                    'capital_planning_revision' => $source['capitalPlanningRevision'],
                    'capital_rule_revision' => $source['capitalRuleRevision'],
                    'capital_comparison_revision' => $source['capitalComparisonRevision'],
                ],
            );

            $row = DB::table('capital_decision_records')
                ->where('business_id', $business->getKey())
                ->where('id', $id)
                ->sole();

            return $this->envelope($row, true);
        });
    }

    /**
     * @return array<string,mixed>
     */
    private function envelope(object $row, bool $created): array
    {
        return [
            'id' => (string) $row->id,
            'created' => $created,
            'contractVersion' => (string) $row->contract_version,
            'capitalApprovalSnapshotId' => (string) $row->capital_approval_snapshot_id,
            'formalRecordVersionId' => (string) $row->formal_record_version_id,
            'proposalVersionId' => (string) $row->proposal_version_id,
            'governanceDecisionId' => (string) $row->governance_decision_id,
            'approvedContentHash' => (string) $row->approved_content_hash,
            'createdAt' => (string) $row->created_at,
        ];
    }
}
