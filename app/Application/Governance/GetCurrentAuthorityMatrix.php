<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Domain\Access\CapabilityCatalog;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionStateTransition;
use Illuminate\Support\Facades\DB;

final class GetCurrentAuthorityMatrix
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly ResolveGovernanceAuthority $authority,
    ) {}

    /** @return array<string,mixed>|null */
    public function execute(User $user, Business $business): ?array
    {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
        ) === null) {
            return null;
        }

        $source = $this->authority->currentSource($business);

        if ($source === null) {
            return [
                'authority_mode' => 'none',
                'source_kind' => null,
                'source_version_id' => null,
                'source_content_hash' => null,
                'source_state' => null,
                'rules' => [],
            ];
        }

        $version = $source['version'];
        $versionId = (string) $version->getKey();

        $latestState = RecordVersionStateTransition::query()
            ->where('business_id', $business->getKey())
            ->where('formal_record_version_id', $versionId)
            ->orderByDesc('sequence')
            ->first();

        if ($source['source_kind'] === 'governance_charter') {
            $rules = DB::table('governance_charter_rules')
                ->where('business_id', $business->getKey())
                ->where('formal_record_version_id', $versionId)
                ->orderBy('sequence')
                ->get()
                ->map(function ($rule) use ($business): array {
                    $actors = DB::table(
                        'governance_charter_rule_actors',
                    )
                        ->where('business_id', $business->getKey())
                        ->where(
                            'governance_charter_rule_id',
                            $rule->id,
                        )
                        ->orderBy('membership_id')
                        ->get()
                        ->map(static fn ($actor): array => [
                            'membership_id' => (string) $actor->membership_id,
                            'capacity' => (string) $actor->capacity,
                            'is_decision_owner' => (bool) $actor->is_decision_owner,
                            'is_consulted' => (bool) $actor->is_consulted,
                            'can_approve' => (bool) $actor->can_approve,
                            'can_vote' => (bool) $actor->can_vote,
                            'can_sign' => (bool) $actor->can_sign,
                        ])
                        ->values()
                        ->all();

                    return [
                        'id' => (string) $rule->id,
                        'sequence' => (int) $rule->sequence,
                        'decision_type' => (string) $rule->decision_type,
                        'category' => (string) $rule->category,
                        'decision_method' => (string) $rule->decision_method,
                        'required_approvals' => (int) $rule->required_approvals,
                        'required_votes' => (int) $rule->required_votes,
                        'quorum_count' => (int) $rule->quorum_count,
                        'signature_required' => (bool) $rule->signature_required,
                        'reserved_matter' => (bool) $rule->reserved_matter,
                        'meeting_required' => (bool) $rule->meeting_required,
                        'record_required' => (bool) $rule->record_required,
                        'amount_min' => $rule->amount_min,
                        'amount_max' => $rule->amount_max,
                        'actors' => $actors,
                    ];
                })
                ->values()
                ->all();

            $mode = 'charter';
        } else {
            $rules = DB::table(
                'formation_authority_policy_rules',
            )
                ->where('business_id', $business->getKey())
                ->where('formal_record_version_id', $versionId)
                ->orderBy('sequence')
                ->get()
                ->map(function ($rule) use ($business): array {
                    $actors = DB::table(
                        'formation_authority_policy_actors',
                    )
                        ->where('business_id', $business->getKey())
                        ->where(
                            'formation_authority_policy_rule_id',
                            $rule->id,
                        )
                        ->orderBy('membership_id')
                        ->get()
                        ->map(static fn ($actor): array => [
                            'membership_id' => (string) $actor->membership_id,
                            'capacity' => (string) $actor->capacity,
                            'is_decision_owner' => false,
                            'is_consulted' => false,
                            'can_approve' => (bool) $actor->can_approve,
                            'can_vote' => (bool) $actor->can_vote,
                            'can_sign' => (bool) $actor->can_sign,
                        ])
                        ->values()
                        ->all();

                    return [
                        'id' => (string) $rule->id,
                        'sequence' => (int) $rule->sequence,
                        'decision_type' => (string) $rule->decision_type,
                        'category' => 'formation',
                        'decision_method' => (string) $rule->decision_method,
                        'required_approvals' => (int) $rule->required_approvals,
                        'required_votes' => (int) $rule->required_votes,
                        'quorum_count' => (int) $rule->quorum_count,
                        'signature_required' => (bool) $rule->signature_required,
                        'reserved_matter' => (bool) $rule->reserved_matter,
                        'meeting_required' => false,
                        'record_required' => true,
                        'amount_min' => $rule->amount_min,
                        'amount_max' => $rule->amount_max,
                        'actors' => $actors,
                    ];
                })
                ->values()
                ->all();

            $mode = $source['initial_bootstrap']
                ? 'bootstrap'
                : 'effective';
        }

        return [
            'authority_mode' => $mode,
            'source_kind' => $source['source_kind'],
            'source_version_id' => $versionId,
            'source_content_hash' => (string) $version->content_hash,
            'source_state' => $latestState?->to_state->value,
            'rules' => $rules,
        ];
    }
}
