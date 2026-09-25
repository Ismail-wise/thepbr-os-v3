<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Domain\Records\Enums\FormalRecordState;
use App\Domain\Governance\ValueObjects\DecisionType;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityEstablishment;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityPolicyActor;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityPolicyRule;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\RecordFamilyEffectiveHead;
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionStateTransition;
use Illuminate\Database\Eloquent\Collection;

/**
 * Internal governance-authority resolver.
 *
 * System permission does not establish governance authority.
 * This resolver returns only the exact current authority source/rule/actors.
 *
 * @phpstan-type ResolvedFormationAuthority array{
 *     source_version: FormalRecordVersion,
 *     rule: FormationAuthorityPolicyRule,
 *     actors: Collection<int, FormationAuthorityPolicyActor>,
 *     initial_bootstrap: bool
 * }
 */
final class ResolveFormationAuthority
{
    /**
     * @return array{
     *     source_version: FormalRecordVersion,
     *     rule: FormationAuthorityPolicyRule,
     *     actors: Collection<int, FormationAuthorityPolicyActor>,
     *     initial_bootstrap: bool
     * }|null
     */
    public function resolve(
        Business $business,
        DecisionType $decisionType,
        ?string $decisionAmount = null,
    ): ?array {
        $source = $this->resolveSource($business);

        if ($source === null) {
            return null;
        }

        /** @var FormalRecordVersion $sourceVersion */
        $sourceVersion = $source['version'];
        $initialBootstrap = $source['initial_bootstrap'];

        $latestState = RecordVersionStateTransition::query()
            ->where(
                'formal_record_version_id',
                $sourceVersion->getKey(),
            )
            ->orderByDesc('sequence')
            ->first();

        if ($latestState === null) {
            return null;
        }

        if ($initialBootstrap) {
            $allowedBootstrapStates = [
                FormalRecordState::ReadyForReview,
                FormalRecordState::UnderReview,
                FormalRecordState::Approved,
                FormalRecordState::ReadyForEffect,
                FormalRecordState::Effective,
            ];

            if (! in_array(
                $latestState->to_state,
                $allowedBootstrapStates,
                true,
            )) {
                return null;
            }
        } elseif (
            $latestState->to_state !== FormalRecordState::Effective
        ) {
            return null;
        }

        $ruleQuery = FormationAuthorityPolicyRule::query()
            ->where('business_id', $business->getKey())
            ->where(
                'formal_record_version_id',
                $sourceVersion->getKey(),
            )
            ->where(
                'decision_type',
                $decisionType->value(),
            );

        if ($decisionAmount === null) {
            $ruleQuery
                ->whereNull('amount_min')
                ->whereNull('amount_max');
        } else {
            if (! preg_match(
                '/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/',
                $decisionAmount,
            )) {
                return null;
            }

            $ruleQuery
                ->where(
                    function ($query) use ($decisionAmount): void {
                        $query
                            ->whereNull('amount_min')
                            ->orWhere(
                                'amount_min',
                                '<=',
                                $decisionAmount,
                            );
                    },
                )
                ->where(
                    function ($query) use ($decisionAmount): void {
                        $query
                            ->whereNull('amount_max')
                            ->orWhere(
                                'amount_max',
                                '>=',
                                $decisionAmount,
                            );
                    },
                );
        }

        $rules = $ruleQuery
            ->orderBy('sequence')
            ->get();

        if ($rules->count() !== 1) {
            return null;
        }

        /** @var FormationAuthorityPolicyRule $rule */
        $rule = $rules->first();

        $allActors = FormationAuthorityPolicyActor::query()
            ->where(
                'business_id',
                $business->getKey(),
            )
            ->where(
                'formation_authority_policy_rule_id',
                $rule->getKey(),
            )
            ->orderBy('membership_id')
            ->get();

        if ($allActors->isEmpty()) {
            return null;
        }

        $activeActors = FormationAuthorityPolicyActor::query()
            ->join(
                'memberships as authority_memberships',
                function ($join): void {
                    $join
                        ->on(
                            'authority_memberships.id',
                            '=',
                            'formation_authority_policy_actors.membership_id',
                        )
                        ->on(
                            'authority_memberships.business_id',
                            '=',
                            'formation_authority_policy_actors.business_id',
                        );
                },
            )
            ->where(
                'formation_authority_policy_actors.business_id',
                $business->getKey(),
            )
            ->where(
                'formation_authority_policy_actors.formation_authority_policy_rule_id',
                $rule->getKey(),
            )
            ->where(
                'authority_memberships.access_status',
                MembershipAccessStatus::Active->value,
            )
            ->select('formation_authority_policy_actors.*')
            ->orderBy(
                'formation_authority_policy_actors.membership_id',
            )
            ->get();

        /*
         * Fail closed if any explicitly recorded authority actor no longer has
         * an active Membership. Do not silently shrink the captured authority
         * electorate/approver set.
         */
        if ($activeActors->count() !== $allActors->count()) {
            return null;
        }

        return [
            'source_version' => $sourceVersion,
            'rule' => $rule,
            'actors' => $activeActors,
            'initial_bootstrap' => $initialBootstrap,
        ];
    }

    /**
     * @return array{
     *     version: FormalRecordVersion,
     *     initial_bootstrap: bool
     * }|null
     */
    private function resolveSource(Business $business): ?array
    {
        $effectiveVersionId =
            RecordFamilyEffectiveHead::query()
                ->join(
                    'formal_record_versions as authority_version',
                    function ($join): void {
                        $join
                            ->on(
                                'authority_version.id',
                                '=',
                                'record_family_effective_heads.formal_record_version_id',
                            )
                            ->on(
                                'authority_version.business_id',
                                '=',
                                'record_family_effective_heads.business_id',
                            );
                    },
                )
                ->join(
                    'formal_record_families as authority_family',
                    function ($join): void {
                        $join
                            ->on(
                                'authority_family.id',
                                '=',
                                'authority_version.formal_record_family_id',
                            )
                            ->on(
                                'authority_family.business_id',
                                '=',
                                'authority_version.business_id',
                            );
                    },
                )
                ->where(
                    'record_family_effective_heads.business_id',
                    $business->getKey(),
                )
                ->where(
                    'authority_family.record_type',
                    'formation_authority_policy',
                )
                ->value(
                    'record_family_effective_heads.formal_record_version_id',
                );

        if ($effectiveVersionId !== null) {
            $version = FormalRecordVersion::query()
                ->where('business_id', $business->getKey())
                ->whereKey((string) $effectiveVersionId)
                ->first();

            if ($version === null || $version->frozen_at === null) {
                return null;
            }

            return [
                'version' => $version,
                'initial_bootstrap' => false,
            ];
        }

        $establishment =
            FormationAuthorityEstablishment::query()
                ->where('business_id', $business->getKey())
                ->first();

        if ($establishment === null) {
            return null;
        }

        $version = FormalRecordVersion::query()
            ->where('business_id', $business->getKey())
            ->whereKey($establishment->formal_record_version_id)
            ->first();

        if (
            $version === null
            || $version->frozen_at === null
            || $establishment->establishment_hash
                !== $version->content_hash
        ) {
            return null;
        }

        return [
            'version' => $version,
            'initial_bootstrap' => true,
        ];
    }
}
