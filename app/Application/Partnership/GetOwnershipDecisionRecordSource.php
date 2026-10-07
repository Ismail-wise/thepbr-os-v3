<?php

declare(strict_types=1);

namespace App\Application\Partnership;

use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use Illuminate\Support\Facades\DB;

final class GetOwnershipDecisionRecordSource
{
    /** @return array<string,mixed>|null */
    public function execute(Business $business): ?array
    {
        $businessId = (string) $business->getKey();

        $register = DB::table('ownership_register_versions')
            ->where('business_id', $businessId)
            ->where('status', 'effective')
            ->where('effective_from', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('effective_until')
                    ->orWhere('effective_until', '>', now());
            })
            ->orderByDesc('effective_from')
            ->first();

        if ($register === null
            || $register->proposal_version_id === null
            || $register->governance_decision_id === null
            || $register->authority_snapshot_id === null
            || $register->source_accepted_register_hash === null
            || $register->source_contribution_decision_record_id === null) {
            return null;
        }

        $contributionDecision = DB::table('contribution_decision_records')
            ->where('business_id', $businessId)
            ->where('id', $register->source_contribution_decision_record_id)
            ->where('accepted_register_hash', $register->source_accepted_register_hash)
            ->first();

        if ($contributionDecision === null) {
            return null;
        }

        $submission = DB::table('ownership_governance_submissions')
            ->where('business_id', $businessId)
            ->where('ownership_scenario_id', $register->source_ownership_scenario_id)
            ->where('proposal_version_id', $register->proposal_version_id)
            ->where('effective_register_version_id', $register->id)
            ->first();

        if ($submission === null) {
            return null;
        }

        $decision = DB::table('decisions')
            ->where('business_id', $businessId)
            ->where('id', $register->governance_decision_id)
            ->where('proposal_version_id', $register->proposal_version_id)
            ->where('decision_type', 'ownership_approval')
            ->where('status', 'decided')
            ->where('outcome', 'approved')
            ->whereNotNull('resolved_at')
            ->first();

        if ($decision === null
            || (string) $decision->authority_snapshot_id !== (string) $register->authority_snapshot_id) {
            return null;
        }

        $authority = DB::table('authority_snapshots')
            ->where('business_id', $businessId)
            ->where('id', $register->authority_snapshot_id)
            ->where('proposal_version_id', $register->proposal_version_id)
            ->first();

        $formal = DB::table('formal_record_versions')
            ->where('business_id', $businessId)
            ->where('id', $submission->formal_record_version_id)
            ->first();

        $formalState = DB::table('record_version_state_transitions')
            ->where('business_id', $businessId)
            ->where('formal_record_version_id', $submission->formal_record_version_id)
            ->orderByDesc('sequence')
            ->value('to_state');

        if ($authority === null
            || $formal === null
            || $formalState !== 'effective'
            || (string) $formal->content_hash !== (string) $submission->content_hash) {
            return null;
        }

        return [
            'register' => $register,
            'submission' => $submission,
            'decision' => $decision,
            'authority' => $authority,
            'formal' => $formal,
            'approvedByEvidence' => $this->approvedByEvidence(
                $businessId,
                (string) $decision->id,
                (string) $authority->decision_method,
            ),
        ];
    }

    /** @return list<array{membershipId:string,evidenceKind:string,recordedAt:string}> */
    private function approvedByEvidence(
        string $businessId,
        string $decisionId,
        string $method,
    ): array {
        $evidence = [];

        if (in_array($method, ['approval', 'approval_and_vote'], true)) {
            $rows = DB::table('approvals as approval')
                ->join('decision_participants as participant', function ($join): void {
                    $join->on('participant.id', '=', 'approval.decision_participant_id')
                        ->on('participant.business_id', '=', 'approval.business_id')
                        ->on('participant.decision_id', '=', 'approval.decision_id');
                })
                ->where('approval.business_id', $businessId)
                ->where('approval.decision_id', $decisionId)
                ->where('approval.outcome', 'approved')
                ->where('participant.status', 'eligible')
                ->orderBy('approval.recorded_at')
                ->get(['approval.membership_id', 'approval.recorded_at']);

            foreach ($rows as $row) {
                $evidence[] = [
                    'membershipId' => (string) $row->membership_id,
                    'evidenceKind' => 'approval',
                    'recordedAt' => (string) $row->recorded_at,
                ];
            }
        }

        if (in_array($method, ['vote', 'approval_and_vote'], true)) {
            $rows = DB::table('votes as vote')
                ->join('decision_participants as participant', function ($join): void {
                    $join->on('participant.id', '=', 'vote.decision_participant_id')
                        ->on('participant.business_id', '=', 'vote.business_id')
                        ->on('participant.decision_id', '=', 'vote.decision_id');
                })
                ->where('vote.business_id', $businessId)
                ->where('vote.decision_id', $decisionId)
                ->where('vote.choice', 'for')
                ->where('participant.status', 'eligible')
                ->orderBy('vote.cast_at')
                ->get(['vote.membership_id', 'vote.cast_at']);

            foreach ($rows as $row) {
                $evidence[] = [
                    'membershipId' => (string) $row->membership_id,
                    'evidenceKind' => 'supporting_vote',
                    'recordedAt' => (string) $row->cast_at,
                ];
            }
        }

        return $evidence;
    }
}
