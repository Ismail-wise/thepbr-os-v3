<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Application\Access\ResolveMembershipCapabilities;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Records\Enums\FormalRecordState;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityEstablishment;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityPolicyActor;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityPolicyRule;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\RecordFamilyEffectiveHead;
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionStateTransition;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class EstablishInitialFormationAuthority
{
    public function __construct(
        private readonly AuthorizeBusinessCapability $authorizeBusinessCapability,
        private readonly ResolveMembershipCapabilities $membershipCapabilities,
    ) {}

    public function execute(
        User $user,
        Business $currentBusiness,
        string $formalRecordVersionId,
    ): ?FormationAuthorityEstablishment {
        $capability = new Capability(
            CapabilityCatalog::FORMATION_AUTHORITY_BOOTSTRAP,
        );

        $authorization = $this->authorizeBusinessCapability->decide(
            $user,
            $currentBusiness,
            $currentBusiness,
            $capability,
        );

        if (! $authorization->allowed) {
            return null;
        }

        $membership = $this->membershipCapabilities->activeMembership(
            $user,
            $currentBusiness,
        );

        if ($membership === null) {
            return null;
        }

        return DB::transaction(function () use (
            $currentBusiness,
            $formalRecordVersionId,
            $membership,
        ): ?FormationAuthorityEstablishment {
            $lockedBusiness = Business::query()
                ->whereKey($currentBusiness->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedBusiness === null) {
                return null;
            }

            $existingEstablishment =
                FormationAuthorityEstablishment::query()
                    ->where(
                        'business_id',
                        $currentBusiness->getKey(),
                    )
                    ->lockForUpdate()
                    ->first();

            if ($existingEstablishment !== null) {
                throw new RuntimeException(
                    'Initial Formation Authority has already been established for this Business.',
                );
            }

            $effectiveAuthorityExists =
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
                    ->exists();

            if ($effectiveAuthorityExists) {
                throw new RuntimeException(
                    'Initial Formation Authority cannot be established after Current Effective Formation Authority exists.',
                );
            }

            $version = FormalRecordVersion::query()
                ->where(
                    'business_id',
                    $currentBusiness->getKey(),
                )
                ->whereKey($formalRecordVersionId)
                ->lockForUpdate()
                ->first();

            if ($version === null) {
                return null;
            }

            $family = FormalRecordFamily::query()
                ->where(
                    'business_id',
                    $currentBusiness->getKey(),
                )
                ->whereKey($version->formal_record_family_id)
                ->lockForUpdate()
                ->first();

            if (
                $family === null
                || $family->record_type !== 'formation_authority_policy'
            ) {
                throw new RuntimeException(
                    'Initial Formation Authority must bind a Formation Authority Policy record.',
                );
            }

            if ($version->frozen_at === null) {
                throw new RuntimeException(
                    'Initial Formation Authority policy must be frozen.',
                );
            }

            if ($version->effective_from === null) {
                throw new RuntimeException(
                    'Initial Formation Authority policy requires effective_from.',
                );
            }

            $latestState = RecordVersionStateTransition::query()
                ->where(
                    'formal_record_version_id',
                    $version->getKey(),
                )
                ->orderByDesc('sequence')
                ->lockForUpdate()
                ->first();

            if (
                $latestState === null
                || $latestState->to_state
                    !== FormalRecordState::ReadyForReview
            ) {
                throw new RuntimeException(
                    'Initial Formation Authority must bind the frozen Ready for Review policy version.',
                );
            }

            $rules = FormationAuthorityPolicyRule::query()
                ->where(
                    'business_id',
                    $currentBusiness->getKey(),
                )
                ->where(
                    'formal_record_version_id',
                    $version->getKey(),
                )
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get();

            if ($rules->isEmpty()) {
                throw new RuntimeException(
                    'Initial Formation Authority requires at least one authority rule.',
                );
            }

            foreach ($rules as $rule) {
                $actorCount =
                    FormationAuthorityPolicyActor::query()
                        ->where(
                            'business_id',
                            $currentBusiness->getKey(),
                        )
                        ->where(
                            'formation_authority_policy_rule_id',
                            $rule->getKey(),
                        )
                        ->count();

                if ($actorCount < 1) {
                    throw new RuntimeException(
                        'Every Formation Authority rule requires at least one explicit eligible actor.',
                    );
                }
            }

            return FormationAuthorityEstablishment::query()->create([
                'business_id' => $currentBusiness->getKey(),
                'formal_record_version_id' => $version->getKey(),
                'established_by_membership_id' => $membership->getKey(),
                'establishment_hash' => $version->content_hash,
                'established_at' => now(),
            ]);
        });
    }
}
