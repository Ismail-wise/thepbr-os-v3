<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Domain\Governance\Enums\DecisionMethod;
use App\Domain\Governance\Enums\DecisionOutcome;
use App\Domain\Governance\Enums\DecisionStatus;
use App\Domain\Governance\Enums\DelegationStatus;
use App\Domain\Governance\Enums\EmergencyAuthorityStatus;
use App\Domain\Governance\ValueObjects\DecisionType;
use App\Domain\Governance\ValueObjects\ResolvedAuthorityActor;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Governance\EmergencyAuthorityGrant;
use App\Infrastructure\Persistence\Eloquent\Governance\GovernanceDelegation;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\RecordFamilyEffectiveHead;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single authority resolver used by every governed module.
 *
 * Current Effective Governance Charter wins. Temporary Formation Authority is
 * consulted only while no Effective Governance Charter exists.
 *
 * Delegation and Emergency Authority never create System Permission. They are
 * considered only after an exact Frozen Proposal Version has been approved by
 * a governed Decision and the time/scope conditions still hold.
 */
final class ResolveGovernanceAuthority
{
    public function __construct(
        private readonly ResolveFormationAuthority $formationAuthority,
    ) {}

    /**
     * @return array{
     *   version: FormalRecordVersion,
     *   source_kind:string,
     *   initial_bootstrap:bool
     * }|null
     */
    public function currentSource(Business $business): ?array
    {
        $versionId = RecordFamilyEffectiveHead::query()
            ->join(
                'formal_record_versions as current_authority_version',
                function ($join): void {
                    $join
                        ->on(
                            'current_authority_version.id',
                            '=',
                            'record_family_effective_heads.formal_record_version_id',
                        )
                        ->on(
                            'current_authority_version.business_id',
                            '=',
                            'record_family_effective_heads.business_id',
                        );
                },
            )
            ->join(
                'formal_record_families as current_authority_family',
                function ($join): void {
                    $join
                        ->on(
                            'current_authority_family.id',
                            '=',
                            'current_authority_version.formal_record_family_id',
                        )
                        ->on(
                            'current_authority_family.business_id',
                            '=',
                            'current_authority_version.business_id',
                        );
                },
            )
            ->where(
                'record_family_effective_heads.business_id',
                $business->getKey(),
            )
            ->where(
                'current_authority_family.record_type',
                'governance_charter',
            )
            ->value(
                'record_family_effective_heads.formal_record_version_id',
            );

        if ($versionId !== null) {
            $version = FormalRecordVersion::query()
                ->where('business_id', $business->getKey())
                ->whereKey((string) $versionId)
                ->first();

            if ($version === null || $version->frozen_at === null) {
                return null;
            }

            return [
                'version' => $version,
                'source_kind' => 'governance_charter',
                'initial_bootstrap' => false,
            ];
        }

        $formation = $this->formationAuthority->currentSource($business);

        if ($formation === null) {
            return null;
        }

        return [
            'version' => $formation['version'],
            'source_kind' => 'formation_authority',
            'initial_bootstrap' => $formation['initial_bootstrap'],
        ];
    }

    /**
     * @return array{
     *   source_version: FormalRecordVersion,
     *   source_kind: string,
     *   rule: array{
     *     sequence:int,
     *     decision_method:DecisionMethod,
     *     required_approvals:int,
     *     required_votes:int,
     *     quorum_count:int,
     *     signature_required:bool,
     *     reserved_matter:bool,
     *     meeting_required:bool,
     *     record_required:bool,
     *     amount_min:?string,
     *     amount_max:?string
     *   },
     *   actors: Collection<int, ResolvedAuthorityActor>,
     *   initial_bootstrap: bool
     * }|null
     */
    public function resolve(
        Business $business,
        DecisionType $decisionType,
        ?string $decisionAmount = null,
        ?CarbonImmutable $at = null,
    ): ?array {
        $at ??= CarbonImmutable::now();

        $base = $this->resolveGovernanceCharter(
            $business,
            $decisionType,
            $decisionAmount,
        );

        if ($base === null) {
            $formation = $this->formationAuthority->resolve(
                $business,
                $decisionType,
                $decisionAmount,
            );

            if ($formation === null) {
                return null;
            }

            $rule = $formation['rule'];
            $actors = $formation['actors']->map(
                static fn ($actor): ResolvedAuthorityActor => new ResolvedAuthorityActor(
                    (string) $actor->membership_id,
                    (string) $actor->capacity,
                    (bool) $actor->can_approve,
                    (bool) $actor->can_vote,
                    (bool) $actor->can_sign,
                ),
            );

            $base = [
                'source_version' => $formation['source_version'],
                'source_kind' => 'formation_authority',
                'rule' => [
                    'sequence' => (int) $rule->sequence,
                    'decision_method' => $rule->decision_method,
                    'required_approvals' => (int) $rule->required_approvals,
                    'required_votes' => (int) $rule->required_votes,
                    'quorum_count' => (int) $rule->quorum_count,
                    'signature_required' => (bool) $rule->signature_required,
                    'reserved_matter' => (bool) $rule->reserved_matter,
                    'meeting_required' => false,
                    'record_required' => true,
                    'amount_min' => $rule->amount_min,
                    'amount_max' => $rule->amount_max,
                ],
                'actors' => $actors,
                'initial_bootstrap' => $formation['initial_bootstrap'],
            ];
        }

        $actors = $this->applyDelegations(
            $business,
            $decisionType,
            $base['actors'],
            $at,
        );

        if ($actors === null) {
            return null;
        }

        $actors = $this->applyEmergencyAuthority(
            $business,
            $decisionType,
            $actors,
            $at,
        );

        if ($actors === null || $actors->isEmpty()) {
            return null;
        }

        if (
            $base['rule']['required_approvals']
                > $actors->where('canApprove', true)->count()
            || $base['rule']['required_votes']
                > $actors->where('canVote', true)->count()
            || $base['rule']['quorum_count'] > $actors->count()
        ) {
            return null;
        }

        $base['actors'] = $actors->values();

        return $base;
    }

    /**
     * @return array{
     *   source_version: FormalRecordVersion,
     *   source_kind: string,
     *   rule: array<string,mixed>,
     *   actors: Collection<int,ResolvedAuthorityActor>,
     *   initial_bootstrap: bool
     * }|null
     */
    private function resolveGovernanceCharter(
        Business $business,
        DecisionType $decisionType,
        ?string $decisionAmount,
    ): ?array {
        $versionId = RecordFamilyEffectiveHead::query()
            ->join(
                'formal_record_versions as charter_version',
                function ($join): void {
                    $join
                        ->on(
                            'charter_version.id',
                            '=',
                            'record_family_effective_heads.formal_record_version_id',
                        )
                        ->on(
                            'charter_version.business_id',
                            '=',
                            'record_family_effective_heads.business_id',
                        );
                },
            )
            ->join(
                'formal_record_families as charter_family',
                function ($join): void {
                    $join
                        ->on(
                            'charter_family.id',
                            '=',
                            'charter_version.formal_record_family_id',
                        )
                        ->on(
                            'charter_family.business_id',
                            '=',
                            'charter_version.business_id',
                        );
                },
            )
            ->where(
                'record_family_effective_heads.business_id',
                $business->getKey(),
            )
            ->where('charter_family.record_type', 'governance_charter')
            ->value(
                'record_family_effective_heads.formal_record_version_id',
            );

        if ($versionId === null) {
            return null;
        }

        $version = FormalRecordVersion::query()
            ->where('business_id', $business->getKey())
            ->whereKey((string) $versionId)
            ->first();

        if ($version === null || $version->frozen_at === null) {
            return null;
        }

        $query = DB::table('governance_charter_rules')
            ->where('business_id', $business->getKey())
            ->where('formal_record_version_id', $version->getKey())
            ->where('decision_type', $decisionType->value());

        if ($decisionAmount === null) {
            $query->whereNull('amount_min')->whereNull('amount_max');
        } else {
            if (! preg_match(
                '/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/',
                $decisionAmount,
            )) {
                return null;
            }

            $query
                ->where(function ($amount) use ($decisionAmount): void {
                    $amount
                        ->whereNull('amount_min')
                        ->orWhere('amount_min', '<=', $decisionAmount);
                })
                ->where(function ($amount) use ($decisionAmount): void {
                    $amount
                        ->whereNull('amount_max')
                        ->orWhere('amount_max', '>=', $decisionAmount);
                });
        }

        $rules = $query->orderBy('sequence')->get();

        if ($rules->count() !== 1) {
            return null;
        }

        $rule = $rules->first();

        $allActors = DB::table('governance_charter_rule_actors')
            ->where('business_id', $business->getKey())
            ->where('governance_charter_rule_id', $rule->id)
            ->where(function ($actors): void {
                $actors
                    ->where('can_approve', true)
                    ->orWhere('can_vote', true)
                    ->orWhere('can_sign', true);
            })
            ->orderBy('membership_id')
            ->get();

        if ($allActors->isEmpty()) {
            return null;
        }

        $activeMembershipIds = Membership::query()
            ->where('business_id', $business->getKey())
            ->whereIn(
                'id',
                $allActors->pluck('membership_id')->all(),
            )
            ->where(
                'access_status',
                MembershipAccessStatus::Active->value,
            )
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        if (count($activeMembershipIds) !== $allActors->count()) {
            return null;
        }

        $actors = $allActors->map(
            static fn ($actor): ResolvedAuthorityActor => new ResolvedAuthorityActor(
                (string) $actor->membership_id,
                (string) $actor->capacity,
                (bool) $actor->can_approve,
                (bool) $actor->can_vote,
                (bool) $actor->can_sign,
            ),
        );

        return [
            'source_version' => $version,
            'source_kind' => 'governance_charter',
            'rule' => [
                'sequence' => (int) $rule->sequence,
                'decision_method' => DecisionMethod::from(
                    (string) $rule->decision_method,
                ),
                'required_approvals' => (int) $rule->required_approvals,
                'required_votes' => (int) $rule->required_votes,
                'quorum_count' => (int) $rule->quorum_count,
                'signature_required' => (bool) $rule->signature_required,
                'reserved_matter' => (bool) $rule->reserved_matter,
                'meeting_required' => (bool) $rule->meeting_required,
                'record_required' => (bool) $rule->record_required,
                'amount_min' => $rule->amount_min === null
                    ? null
                    : (string) $rule->amount_min,
                'amount_max' => $rule->amount_max === null
                    ? null
                    : (string) $rule->amount_max,
            ],
            'actors' => $actors,
            'initial_bootstrap' => false,
        ];
    }

    /**
     * @param  Collection<int,ResolvedAuthorityActor>  $actors
     * @return Collection<int,ResolvedAuthorityActor>|null
     */
    private function applyDelegations(
        Business $business,
        DecisionType $decisionType,
        Collection $actors,
        CarbonImmutable $at,
    ): ?Collection {
        $resolved = collect();

        foreach ($actors as $actor) {
            $delegations = GovernanceDelegation::query()
                ->where('business_id', $business->getKey())
                ->where(
                    'delegator_membership_id',
                    $actor->membershipId,
                )
                ->where(
                    'decision_type',
                    $decisionType->value(),
                )
                ->where('status', DelegationStatus::Active->value)
                ->where('effective_from', '<=', $at)
                ->where(function ($query) use ($at): void {
                    $query
                        ->whereNull('expires_at')
                        ->orWhere('expires_at', '>', $at);
                })
                ->whereExists(function ($query): void {
                    $query
                        ->selectRaw('1')
                        ->from(
                            'governance_authority_change_submissions as change',
                        )
                        ->join(
                            'decisions as authorizing_decision',
                            function ($join): void {
                                $join
                                    ->on(
                                        'authorizing_decision.id',
                                        '=',
                                        'change.authorizing_decision_id',
                                    )
                                    ->on(
                                        'authorizing_decision.business_id',
                                        '=',
                                        'change.business_id',
                                    )
                                    ->on(
                                        'authorizing_decision.proposal_version_id',
                                        '=',
                                        'change.proposal_version_id',
                                    );
                            },
                        )
                        ->whereColumn(
                            'change.business_id',
                            'governance_delegations.business_id',
                        )
                        ->whereColumn(
                            'change.subject_id',
                            'governance_delegations.id',
                        )
                        ->where(
                            'change.subject_type',
                            'delegation',
                        )
                        ->where('change.action', 'grant')
                        ->whereNotNull('change.authorized_at')
                        ->where(
                            'authorizing_decision.status',
                            DecisionStatus::Decided->value,
                        )
                        ->where(
                            'authorizing_decision.outcome',
                            DecisionOutcome::Approved->value,
                        );
                })
                ->get();

            if ($delegations->count() > 1) {
                return null;
            }

            if ($delegations->isEmpty()) {
                $resolved->push($actor);

                continue;
            }

            $delegation = $delegations->first();

            $delegateActive = Membership::query()
                ->where('business_id', $business->getKey())
                ->whereKey($delegation->delegate_membership_id)
                ->where(
                    'access_status',
                    MembershipAccessStatus::Active->value,
                )
                ->exists();

            if (! $delegateActive) {
                return null;
            }

            $resolved->push(
                $actor->delegatedTo(
                    (string) $delegation->delegate_membership_id,
                ),
            );
        }

        if (
            $resolved->pluck('membershipId')->unique()->count()
            !== $resolved->count()
        ) {
            return null;
        }

        return $resolved;
    }

    /**
     * @param  Collection<int,ResolvedAuthorityActor>  $actors
     * @return Collection<int,ResolvedAuthorityActor>|null
     */
    private function applyEmergencyAuthority(
        Business $business,
        DecisionType $decisionType,
        Collection $actors,
        CarbonImmutable $at,
    ): ?Collection {
        $grants = EmergencyAuthorityGrant::query()
            ->where('business_id', $business->getKey())
            ->where(
                'decision_type',
                $decisionType->value(),
            )
            ->where(
                'status',
                EmergencyAuthorityStatus::Active->value,
            )
            ->where('effective_from', '<=', $at)
            ->where('expires_at', '>', $at)
            ->whereNotNull('capacity')
            ->whereNotNull('can_approve')
            ->whereNotNull('can_vote')
            ->whereNotNull('can_sign')
            ->whereExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from(
                        'governance_authority_change_submissions as change',
                    )
                    ->join(
                        'decisions as authorizing_decision',
                        function ($join): void {
                            $join
                                ->on(
                                    'authorizing_decision.id',
                                    '=',
                                    'change.authorizing_decision_id',
                                )
                                ->on(
                                    'authorizing_decision.business_id',
                                    '=',
                                    'change.business_id',
                                )
                                ->on(
                                    'authorizing_decision.proposal_version_id',
                                    '=',
                                    'change.proposal_version_id',
                                );
                        },
                    )
                    ->whereColumn(
                        'change.business_id',
                        'emergency_authority_grants.business_id',
                    )
                    ->whereColumn(
                        'change.subject_id',
                        'emergency_authority_grants.id',
                    )
                    ->where(
                        'change.subject_type',
                        'emergency_authority',
                    )
                    ->where('change.action', 'grant')
                    ->whereNotNull('change.authorized_at')
                    ->where(
                        'authorizing_decision.status',
                        DecisionStatus::Decided->value,
                    )
                    ->where(
                        'authorizing_decision.outcome',
                        DecisionOutcome::Approved->value,
                    );
            })
            ->orderBy('grantee_membership_id')
            ->get();

        foreach ($grants as $grant) {
            if (
                $actors->contains(
                    fn (ResolvedAuthorityActor $actor): bool => $actor->membershipId
                            === (string) $grant->grantee_membership_id,
                )
            ) {
                return null;
            }

            $active = Membership::query()
                ->where('business_id', $business->getKey())
                ->whereKey($grant->grantee_membership_id)
                ->where(
                    'access_status',
                    MembershipAccessStatus::Active->value,
                )
                ->exists();

            if (! $active) {
                return null;
            }

            $actors->push(
                new ResolvedAuthorityActor(
                    (string) $grant->grantee_membership_id,
                    (string) $grant->capacity,
                    (bool) $grant->can_approve,
                    (bool) $grant->can_vote,
                    (bool) $grant->can_sign,
                    'emergency',
                ),
            );
        }

        return $actors;
    }
}
