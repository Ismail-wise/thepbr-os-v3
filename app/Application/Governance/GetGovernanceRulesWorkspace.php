<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Domain\Access\CapabilityCatalog;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class GetGovernanceRulesWorkspace
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

        $canManage = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
        ) !== null;

        $canBootstrapFormationAuthority =
            $this->actorContext->membership(
                $user,
                $business,
                CapabilityCatalog::FORMATION_AUTHORITY_BOOTSTRAP,
            ) !== null
            && $this->actorContext->membership(
                $user,
                $business,
                CapabilityCatalog::RECORDS_MANAGE,
            ) !== null;

        $source = $this->authority->currentSource($business);
        $currentCharter = null;

        if (
            $source !== null
            && $source['source_kind'] === 'governance_charter'
        ) {
            $versionId = (string) $source['version']->getKey();

            $header = DB::table('governance_charter_versions')
                ->where('business_id', $business->getKey())
                ->where('formal_record_version_id', $versionId)
                ->first();

            $rules = DB::table('governance_charter_rules')
                ->where('business_id', $business->getKey())
                ->where('formal_record_version_id', $versionId)
                ->orderBy('sequence')
                ->get();

            $actors = DB::table('governance_charter_rule_actors as actor')
                ->join(
                    'governance_charter_rules as rule',
                    function ($join): void {
                        $join
                            ->on(
                                'rule.id',
                                '=',
                                'actor.governance_charter_rule_id',
                            )
                            ->on(
                                'rule.business_id',
                                '=',
                                'actor.business_id',
                            );
                    },
                )
                ->where('actor.business_id', $business->getKey())
                ->where('rule.formal_record_version_id', $versionId)
                ->orderBy('rule.sequence')
                ->orderBy('actor.capacity')
                ->get(['actor.*']);

            $currentCharter = [
                'formal_record_version_id' => $versionId,
                'version_number' => (int) $source['version']->version_number,
                'effective_from' => $source['version']->effective_from,
                'header' => $header,
                'rules' => $rules,
                'actors' => $actors,
            ];
        }

        $versions = DB::table('formal_record_versions as version')
            ->join(
                'formal_record_families as family',
                function ($join): void {
                    $join
                        ->on(
                            'family.id',
                            '=',
                            'version.formal_record_family_id',
                        )
                        ->on(
                            'family.business_id',
                            '=',
                            'version.business_id',
                        );
                },
            )
            ->where('version.business_id', $business->getKey())
            ->where('family.record_type', 'governance_charter')
            ->orderByDesc('version.version_number')
            ->get([
                'version.id',
                'version.version_number',
                'version.revision',
                'version.frozen_at',
                'version.effective_from',
                'version.content_hash',
            ]);

        $formationAuthorityPolicyVersions = collect();

        if ($canBootstrapFormationAuthority) {
            $formationAuthorityPolicyVersions = DB::table(
                'formal_record_versions as version',
            )
                ->join(
                    'formal_record_families as family',
                    function ($join): void {
                        $join
                            ->on(
                                'family.id',
                                '=',
                                'version.formal_record_family_id',
                            )
                            ->on(
                                'family.business_id',
                                '=',
                                'version.business_id',
                            );
                    },
                )
                ->where(
                    'version.business_id',
                    $business->getKey(),
                )
                ->where(
                    'family.record_type',
                    'formation_authority_policy',
                )
                ->orderByDesc('version.version_number')
                ->get([
                    'version.id',
                    'version.version_number',
                    'version.revision',
                    'version.frozen_at',
                    'version.effective_from',
                    'version.content_hash',
                ])
                ->map(
                    static function (object $version) use (
                        $business,
                    ): array {
                        $latestState = DB::table(
                            'record_version_state_transitions',
                        )
                            ->where(
                                'business_id',
                                $business->getKey(),
                            )
                            ->where(
                                'formal_record_version_id',
                                $version->id,
                            )
                            ->orderByDesc('sequence')
                            ->value('to_state');

                        return [
                            'id' => (string) $version->id,
                            'version_number' => (int) $version->version_number,
                            'revision' => (int) $version->revision,
                            'frozen_at' => $version->frozen_at,
                            'effective_from' => $version->effective_from,
                            'content_hash' => (string) $version->content_hash,
                            'state' => $latestState === null
                                ? null
                                : (string) $latestState,
                        ];
                    },
                )
                ->values();
        }

        $formationAuthorityEstablished = DB::table(
            'formation_authority_establishments',
        )
            ->where('business_id', $business->getKey())
            ->exists();

        $changes = DB::table(
            'governance_authority_change_submissions as change',
        )
            ->leftJoin(
                'decisions as decision',
                function ($join): void {
                    $join
                        ->on(
                            'decision.id',
                            '=',
                            'change.authorizing_decision_id',
                        )
                        ->on(
                            'decision.business_id',
                            '=',
                            'change.business_id',
                        );
                },
            )
            ->where('change.business_id', $business->getKey())
            ->orderByDesc('change.created_at')
            ->get([
                'change.id',
                'change.subject_type',
                'change.subject_id',
                'change.action',
                'change.proposal_version_id',
                'change.authorizing_decision_id',
                'change.authorized_at',
                'change.created_at',
                'decision.decision_type',
                'decision.status as decision_status',
                'decision.outcome as decision_outcome',
            ]);

        $delegations = DB::table('governance_delegations')
            ->where('business_id', $business->getKey())
            ->orderByDesc('created_at')
            ->get();

        $emergency = DB::table('emergency_authority_grants')
            ->where('business_id', $business->getKey())
            ->orderByDesc('created_at')
            ->get();

        $memberships = DB::table('memberships as membership')
            ->join(
                'users as user',
                'user.id',
                '=',
                'membership.user_id',
            )
            ->where('membership.business_id', $business->getKey())
            ->where('membership.access_status', 'active')
            ->orderBy('user.email')
            ->get([
                'membership.id',
                'user.email',
            ]);

        return [
            'business' => [
                'id' => (string) $business->getKey(),
                'name' => (string) $business->name,
            ],
            'permissions' => [
                'manage' => $canManage,
                'bootstrap_formation_authority' => $canBootstrapFormationAuthority,
            ],
            'formation_authority' => [
                'established' => $formationAuthorityEstablished,
                'versions' => $formationAuthorityPolicyVersions,
            ],
            'current_source' => $source === null
                ? null
                : [
                    'kind' => $source['source_kind'],
                    'formal_record_version_id' => (string) $source['version']->getKey(),
                    'version_number' => (int) $source['version']->version_number,
                    'initial_bootstrap' => (bool) $source['initial_bootstrap'],
                ],
            'current_charter' => $currentCharter,
            'versions' => $versions,
            'authority_changes' => $changes,
            'delegations' => $delegations,
            'emergency_authority' => $emergency,
            'memberships' => $memberships,
        ];
    }
}
