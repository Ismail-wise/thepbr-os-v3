<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityEstablishment;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityPolicyActor;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityPolicyRule;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\RecordFamilyEffectiveHead;
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionStateTransition;

final class GetFormationAuthorityWorkspace
{
    public function __construct(
        private readonly AuthorizeBusinessCapability $authorizeBusinessCapability,
    ) {}

    /**
     * @return array{
     *     authority_mode: 'none'|'bootstrap'|'effective',
     *     source_version_id: string|null,
     *     source_content_hash: string|null,
     *     source_state: string|null,
     *     rules: list<array{
     *         id: string,
     *         sequence: int,
     *         decision_type: string,
     *         decision_method: string,
     *         required_approvals: int,
     *         required_votes: int,
     *         quorum_count: int,
     *         signature_required: bool,
     *         reserved_matter: bool,
     *         amount_min: string|null,
     *         amount_max: string|null,
     *         actors: list<array{
     *             membership_id: string,
     *             capacity: string,
     *             can_approve: bool,
     *             can_vote: bool,
     *             can_sign: bool
     *         }>
     *     }>
     * }|null
     */
    public function execute(
        User $user,
        Business $currentBusiness,
    ): ?array {
        $authorization =
            $this->authorizeBusinessCapability->decide(
                $user,
                $currentBusiness,
                $currentBusiness,
                new Capability(
                    CapabilityCatalog::FORMATION_VIEW,
                ),
            );

        if (! $authorization->allowed) {
            return null;
        }

        $mode = 'none';
        $sourceVersionId = null;

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
                    $currentBusiness->getKey(),
                )
                ->where(
                    'authority_family.record_type',
                    'formation_authority_policy',
                )
                ->value(
                    'record_family_effective_heads.formal_record_version_id',
                );

        if ($effectiveVersionId !== null) {
            $mode = 'effective';
            $sourceVersionId = (string) $effectiveVersionId;
        } else {
            $establishment =
                FormationAuthorityEstablishment::query()
                    ->where(
                        'business_id',
                        $currentBusiness->getKey(),
                    )
                    ->first();

            if ($establishment !== null) {
                $mode = 'bootstrap';
                $sourceVersionId =
                    (string) $establishment->formal_record_version_id;
            }
        }

        if ($sourceVersionId === null) {
            return [
                'authority_mode' => 'none',
                'source_version_id' => null,
                'source_content_hash' => null,
                'source_state' => null,
                'rules' => [],
            ];
        }

        $sourceVersion = FormalRecordVersion::query()
            ->where(
                'business_id',
                $currentBusiness->getKey(),
            )
            ->whereKey($sourceVersionId)
            ->first();

        if ($sourceVersion === null) {
            return null;
        }

        $latestState = RecordVersionStateTransition::query()
            ->where(
                'formal_record_version_id',
                $sourceVersion->getKey(),
            )
            ->orderByDesc('sequence')
            ->first();

        $rules = FormationAuthorityPolicyRule::query()
            ->where(
                'business_id',
                $currentBusiness->getKey(),
            )
            ->where(
                'formal_record_version_id',
                $sourceVersion->getKey(),
            )
            ->orderBy('sequence')
            ->get()
            ->map(
                function (
                    FormationAuthorityPolicyRule $rule,
                ) use ($currentBusiness): array {
                    $actors =
                        FormationAuthorityPolicyActor::query()
                            ->where(
                                'business_id',
                                $currentBusiness->getKey(),
                            )
                            ->where(
                                'formation_authority_policy_rule_id',
                                $rule->getKey(),
                            )
                            ->orderBy('membership_id')
                            ->get()
                            ->map(
                                static function (
                                    FormationAuthorityPolicyActor $actor,
                                ): array {
                                    return [
                                        'membership_id' =>
                                            (string) $actor->membership_id,
                                        'capacity' =>
                                            (string) $actor->capacity,
                                        'can_approve' =>
                                            (bool) $actor->can_approve,
                                        'can_vote' =>
                                            (bool) $actor->can_vote,
                                        'can_sign' =>
                                            (bool) $actor->can_sign,
                                    ];
                                },
                            )
                            ->values()
                            ->all();

                    return [
                        'id' => (string) $rule->getKey(),
                        'sequence' => (int) $rule->sequence,
                        'decision_type' =>
                            (string) $rule->decision_type,
                        'decision_method' =>
                            $rule->decision_method->value,
                        'required_approvals' =>
                            (int) $rule->required_approvals,
                        'required_votes' =>
                            (int) $rule->required_votes,
                        'quorum_count' =>
                            (int) $rule->quorum_count,
                        'signature_required' =>
                            (bool) $rule->signature_required,
                        'reserved_matter' =>
                            (bool) $rule->reserved_matter,
                        'amount_min' => $rule->amount_min,
                        'amount_max' => $rule->amount_max,
                        'actors' => $actors,
                    ];
                },
            )
            ->values()
            ->all();

        return [
            'authority_mode' => $mode,
            'source_version_id' =>
                (string) $sourceVersion->getKey(),
            'source_content_hash' =>
                (string) $sourceVersion->content_hash,
            'source_state' =>
                $latestState?->to_state->value,
            'rules' => $rules,
        ];
    }
}
