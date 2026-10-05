<?php

declare(strict_types=1);

namespace App\Application\Formation;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Formation\DeepFeasibilityAssessmentRun;
use App\Infrastructure\Persistence\Eloquent\Identity\User;

final class GetDeepFeasibilityAssessmentRunHistory
{
    public function __construct(
        private readonly FormationActorContext $actor,
        private readonly DeepFeasibilitySnapshotHasher $hasher,
    ) {}

    /**
     * @return list<array<string,mixed>>|null
     */
    public function execute(User $user, Business $business): ?array
    {
        if (
            $business->origin_type
                !== BusinessOriginType::StartedThroughPbr
            || ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::FORMATION_VIEW,
            )
            || ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::BUSINESS_MODEL_VIEW,
            )
        ) {
            return null;
        }

        return DeepFeasibilityAssessmentRun::query()
            ->where('business_id', $business->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (DeepFeasibilityAssessmentRun $run): array => [
                'id' => (string) $run->getKey(),
                'businessId' => (string) $run->business_id,
                'contractVersion' => (string) $run->contract_version,
                'engineVersion' => (string) $run->engine_version,
                'sourceProvenance' => $run->source_provenance ?? [],
                'inputSnapshot' => $run->input_snapshot ?? [],
                'inputHash' => (string) $run->input_hash,
                'resultSnapshot' => $run->result_snapshot ?? [],
                'resultHash' => (string) $run->result_hash,
                'confidenceLevel' => (string) $run->confidence_level,
                'evidenceQuality' => (string) $run->evidence_quality,
                'overallScore' => $run->overall_score,
                'recommendation' => $run->recommendation,
                'createdByMembershipId' => (string) $run->created_by_membership_id,
                'createdAt' => $run->created_at?->toIso8601String(),
                'integrity' => [
                    'inputVerified' => hash_equals(
                        (string) $run->input_hash,
                        $this->hasher->hash([
                            'snapshot' => $run->input_snapshot ?? [],
                            'provenance' => $run->source_provenance ?? [],
                        ]),
                    ),
                    'resultVerified' => hash_equals(
                        (string) $run->result_hash,
                        $this->hasher->hash(
                            $run->result_snapshot ?? [],
                        ),
                    ),
                ],
                'semantics' => [
                    'historicalSnapshot' => true,
                    'canonicalBusinessTruth' => false,
                    'approvedPolicy' => false,
                    'ownershipTruth' => false,
                    'valuationTruth' => false,
                    'signedTruth' => false,
                    'effectiveLifecycleTruth' => false,
                ],
            ])
            ->all();
    }
}
