<?php

declare(strict_types=1);

namespace App\Application\Formation;

use App\Domain\Capital\CapitalApprovalContract;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class GetApprovedCapitalDecisionSource
{
    /**
     * @return array<string,mixed>|null
     */
    public function execute(Business $business): ?array
    {
        $rows = DB::table('capital_approval_snapshots as snapshot')
            ->join('decisions as decision', function ($join): void {
                $join
                    ->on('decision.business_id', '=', 'snapshot.business_id')
                    ->on(
                        'decision.proposal_version_id',
                        '=',
                        'snapshot.proposal_version_id',
                    );
            })
            ->join('authority_snapshots as authority', function ($join): void {
                $join
                    ->on('authority.business_id', '=', 'decision.business_id')
                    ->on('authority.id', '=', 'decision.authority_snapshot_id');
            })
            ->where('snapshot.business_id', $business->getKey())
            ->where(
                'decision.decision_type',
                CapitalApprovalContract::DECISION_TYPE,
            )
            ->where('decision.status', 'decided')
            ->where('decision.outcome', 'approved')
            ->whereNotNull('decision.resolved_at')
            ->orderByDesc('decision.resolved_at')
            ->orderByDesc('snapshot.prepared_at')
            ->orderByDesc('snapshot.id')
            ->select([
                'snapshot.*',
                'decision.id as governance_decision_id',
                'decision.resolved_at as approval_date',
                'authority.decision_method',
                'authority.signature_required',
                'authority.source_kind as authority_source_kind',
            ])
            ->get();

        foreach ($rows as $row) {
            $formal = DB::table('formal_record_versions')
                ->where('business_id', $business->getKey())
                ->where('id', $row->formal_record_version_id)
                ->first();

            $proposal = DB::table('proposal_versions')
                ->where('business_id', $business->getKey())
                ->where('id', $row->proposal_version_id)
                ->first();

            if (
                $formal === null
                || $proposal === null
                || $formal->frozen_at === null
                || $proposal->frozen_at === null
                || (string) $formal->content_hash !== (string) $row->content_hash
                || (string) $proposal->proposal_content_hash !== (string) $row->content_hash
            ) {
                continue;
            }

            $bindingHash = DB::table('proposal_version_records')
                ->where('business_id', $business->getKey())
                ->where('proposal_version_id', $row->proposal_version_id)
                ->where(
                    'formal_record_version_id',
                    $row->formal_record_version_id,
                )
                ->value('captured_content_hash');

            if ((string) $bindingHash !== (string) $row->content_hash) {
                continue;
            }

            $formalState = DB::table('record_version_state_transitions')
                ->where('business_id', $business->getKey())
                ->where(
                    'formal_record_version_id',
                    $row->formal_record_version_id,
                )
                ->orderByDesc('sequence')
                ->value('to_state');

            if (! is_string($formalState)) {
                continue;
            }

            $payload = json_decode(
                (string) $row->snapshot_payload,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            if (! is_array($payload)) {
                continue;
            }

            $approvedByEvidence = $this->approvedByEvidence(
                $business,
                (string) $row->governance_decision_id,
                (string) $row->decision_method,
            );

            if ($approvedByEvidence === []) {
                continue;
            }

            return [
                'snapshotId' => (string) $row->id,
                'formalRecordVersionId' => (string) $row->formal_record_version_id,
                'formalRecordVersionNumber' => (int) $formal->version_number,
                'proposalVersionId' => (string) $row->proposal_version_id,
                'proposalVersionNumber' => (int) $proposal->version_number,
                'governanceDecisionId' => (string) $row->governance_decision_id,
                'contentHash' => (string) $row->content_hash,
                'preferredPlan' => (string) $row->preferred_plan,
                'capitalPlanningRevision' => (int) $row->capital_planning_revision,
                'capitalRuleRevision' => (int) $row->capital_rule_revision,
                'capitalComparisonRevision' => (int) $row->capital_comparison_revision,
                'preparedAt' => (string) $row->prepared_at,
                'approvalDate' => $this->timestamp($row->approval_date),
                'decisionMethod' => (string) $row->decision_method,
                'signatureRequired' => (bool) $row->signature_required,
                'authoritySourceKind' => (string) $row->authority_source_kind,
                'formalState' => $formalState,
                'payload' => $payload,
                'approvedByEvidence' => $approvedByEvidence,
            ];
        }

        return null;
    }

    /**
     * @return list<array{membershipId:string,evidenceKind:string,recordedAt:string}>
     */
    private function approvedByEvidence(
        Business $business,
        string $decisionId,
        string $method,
    ): array {
        $evidence = [];

        if (in_array($method, ['approval', 'approval_and_vote'], true)) {
            $approvals = DB::table('approvals as approval')
                ->join(
                    'decision_participants as participant',
                    function ($join): void {
                        $join
                            ->on(
                                'participant.id',
                                '=',
                                'approval.decision_participant_id',
                            )
                            ->on(
                                'participant.business_id',
                                '=',
                                'approval.business_id',
                            )
                            ->on(
                                'participant.decision_id',
                                '=',
                                'approval.decision_id',
                            );
                    },
                )
                ->where('approval.business_id', $business->getKey())
                ->where('approval.decision_id', $decisionId)
                ->where('approval.outcome', 'approved')
                ->where('participant.status', 'eligible')
                ->orderBy('approval.recorded_at')
                ->get([
                    'approval.membership_id',
                    'approval.recorded_at',
                ]);

            foreach ($approvals as $approval) {
                $evidence[] = [
                    'membershipId' => (string) $approval->membership_id,
                    'evidenceKind' => 'approval',
                    'recordedAt' => $this->timestamp($approval->recorded_at),
                ];
            }
        }

        if (in_array($method, ['vote', 'approval_and_vote'], true)) {
            $votes = DB::table('votes as vote')
                ->join(
                    'decision_participants as participant',
                    function ($join): void {
                        $join
                            ->on(
                                'participant.id',
                                '=',
                                'vote.decision_participant_id',
                            )
                            ->on(
                                'participant.business_id',
                                '=',
                                'vote.business_id',
                            )
                            ->on(
                                'participant.decision_id',
                                '=',
                                'vote.decision_id',
                            );
                    },
                )
                ->where('vote.business_id', $business->getKey())
                ->where('vote.decision_id', $decisionId)
                ->where('vote.choice', 'for')
                ->where('participant.status', 'eligible')
                ->orderBy('vote.cast_at')
                ->get([
                    'vote.membership_id',
                    'vote.cast_at',
                ]);

            foreach ($votes as $vote) {
                $evidence[] = [
                    'membershipId' => (string) $vote->membership_id,
                    'evidenceKind' => 'supporting_vote',
                    'recordedAt' => $this->timestamp($vote->cast_at),
                ];
            }
        }

        return $evidence;
    }

    private function timestamp(mixed $value): string
    {
        return CarbonImmutable::parse((string) $value)
            ->toIso8601String();
    }
}
