<?php

declare(strict_types=1);

namespace App\Application\Formation;

use App\Domain\Access\CapabilityCatalog;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Formation\DeepFeasibilityAssessmentRun;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UnexpectedValueException;

final class RecordDeepFeasibilityAssessmentRun
{
    private const array INPUT_SNAPSHOT_KEYS = [
        'status',
        'businessId',
        'currency',
        'canonicalSources',
        'coverage',
        'confidence',
        'evidenceQuality',
        'gaps',
        'pendingDomains',
        'semantics',
    ];

    public function __construct(
        private readonly FormationActorContext $actor,
        private readonly GetDeepFeasibilityFoundation $foundation,
        private readonly DeepFeasibilitySnapshotHasher $hasher,
        private readonly FormationOccurrence $occurrence,
    ) {}

    /**
     * Create one immutable historical assessment snapshot.
     *
     * The snapshot is derived from canonical sources at record time and is
     * never promoted into Current Effective or other canonical Business truth.
     *
     * @return array<string,mixed>|null
     */
    public function execute(User $user, Business $business): ?array
    {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::FORMATION_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        $foundation = $this->foundation->execute($user, $business);

        if ($foundation === null) {
            return null;
        }

        $assessment = is_array($foundation['assessment'] ?? null)
            ? $foundation['assessment']
            : [];

        $this->assertAcceptedEngineContract($assessment);

        $inputSnapshot = array_intersect_key(
            $foundation,
            array_flip(self::INPUT_SNAPSHOT_KEYS),
        );

        $sourceProvenance = $this->sourceProvenance(
            $business,
            $foundation,
        );

        $inputHash = $this->hasher->hash([
            'snapshot' => $inputSnapshot,
            'provenance' => $sourceProvenance,
        ]);
        $resultHash = $this->hasher->hash($assessment);

        $id = (string) Str::uuid7();
        $createdAt = now();

        $run = DeepFeasibilityAssessmentRun::query()->create([
            'id' => $id,
            'business_id' => $business->getKey(),
            'contract_version' => DeepFeasibilityDimensionAssessmentEngine::CONTRACT_VERSION,
            'engine_version' => DeepFeasibilityDimensionAssessmentEngine::ENGINE_VERSION,
            'source_provenance' => $sourceProvenance,
            'input_snapshot' => $inputSnapshot,
            'input_hash' => $inputHash,
            'result_snapshot' => $assessment,
            'result_hash' => $resultHash,
            'confidence_level' => (string) (
                $foundation['confidence']['level'] ?? 'low'
            ),
            'evidence_quality' => (string) (
                $foundation['evidenceQuality']['level'] ?? 'limited'
            ),
            'overall_score' => $assessment['overall']['score'] ?? null,
            'recommendation' => $assessment['overall']['recommendation']
                ?? null,
            'created_by_membership_id' => $membership->getKey(),
            'created_at' => $createdAt,
        ]);

        $this->occurrence->record(
            $user,
            $business,
            'formation.deep_feasibility.assessment_recorded',
            'deep_feasibility_assessment_run',
            $id,
            [
                'contract_version' => (string) $run->contract_version,
                'engine_version' => (string) $run->engine_version,
                'input_hash' => (string) $run->input_hash,
                'result_hash' => (string) $run->result_hash,
                'confidence_level' => (string) $run->confidence_level,
                'evidence_quality' => (string) $run->evidence_quality,
                'blocker_count' => (int) (
                    $assessment['summary']['blocker'] ?? 0
                ),
                'unavailable_dependency_count' => (int) (
                    $assessment['summary']['unavailable_dependency'] ?? 0
                ),
                'overall_score' => $assessment['overall']['score'] ?? null,
                'recommendation' => $assessment['overall']['recommendation']
                    ?? null,
            ],
        );

        return $this->payload($run);
    }

    /**
     * @param  array<string,mixed>  $foundation
     * @return array<string,mixed>
     */
    private function sourceProvenance(
        Business $business,
        array $foundation,
    ): array {
        $businessId = (string) $business->getKey();

        $bmc = DB::table('business_model_canvases')
            ->where('business_id', $businessId)
            ->first(['id', 'revision', 'updated_at']);

        $profile = DB::table('business_model_operating_profiles')
            ->where('business_id', $businessId)
            ->first(['id', 'revision', 'updated_at']);

        $assumptions = DB::table('formation_assumptions')
            ->where('business_id', $businessId)
            ->orderBy('id')
            ->get(['id', 'revision', 'status', 'updated_at'])
            ->map(
                static fn (object $row): array => [
                    'id' => (string) $row->id,
                    'revision' => (int) $row->revision,
                    'status' => (string) $row->status,
                    'updatedAt' => (string) $row->updated_at,
                ],
            )
            ->values()
            ->all();

        $validations = DB::table('validation_activities')
            ->where('business_id', $businessId)
            ->orderBy('id')
            ->get([
                'id',
                'assumption_id',
                'revision',
                'status',
                'updated_at',
            ])
            ->map(
                static fn (object $row): array => [
                    'id' => (string) $row->id,
                    'assumptionId' => $row->assumption_id === null
                        ? null
                        : (string) $row->assumption_id,
                    'revision' => (int) $row->revision,
                    'status' => (string) $row->status,
                    'updatedAt' => (string) $row->updated_at,
                ],
            )
            ->values()
            ->all();

        return [
            'business' => [
                'id' => $businessId,
                'originType' => $business->origin_type->value,
            ],
            'businessModelCanvas' => $this->versionReference($bmc),
            'operatingProfile' => $this->versionReference($profile),
            'demand' => [
                'assumptions' => $assumptions,
                'validations' => $validations,
                'evidence' => [
                    'mode' => 'aggregate_only',
                    'linkedCount' => (int) (
                        $foundation['evidenceQuality'][
                            'linkedDemandEvidence'
                        ] ?? 0
                    ),
                    'verifiedCount' => (int) (
                        $foundation['evidenceQuality'][
                            'verifiedDemandEvidence'
                        ] ?? 0
                    ),
                ],
            ],
            'businessValuation' => $foundation[
                'canonicalSources'
            ]['businessValuation'] ?? [
                'applicable' => false,
                'status' => 'skipped',
                'reason' => 'new_business_default_skip',
            ],
            'partnerDynamics' => [
                'mode' => 'not_copied',
                'reason' => 'protected_private_boundary',
            ],
        ];
    }

    /**
     * @return array{id:string,revision:int,updatedAt:string}|null
     */
    private function versionReference(?object $row): ?array
    {
        if ($row === null) {
            return null;
        }

        return [
            'id' => (string) $row->id,
            'revision' => (int) $row->revision,
            'updatedAt' => (string) $row->updated_at,
        ];
    }

    /**
     * @param  array<string,mixed>  $assessment
     */
    private function assertAcceptedEngineContract(array $assessment): void
    {
        if (
            ($assessment['contractVersion'] ?? null)
                !== DeepFeasibilityDimensionAssessmentEngine::CONTRACT_VERSION
            || ($assessment['engineVersion'] ?? null)
                !== DeepFeasibilityDimensionAssessmentEngine::ENGINE_VERSION
        ) {
            throw new UnexpectedValueException(
                'Deep Feasibility assessment engine contract mismatch.',
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(DeepFeasibilityAssessmentRun $run): array
    {
        return [
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
            'semantics' => [
                'historicalSnapshot' => true,
                'canonicalBusinessTruth' => false,
                'approvedPolicy' => false,
                'ownershipTruth' => false,
                'valuationTruth' => false,
                'signedTruth' => false,
                'effectiveLifecycleTruth' => false,
            ],
        ];
    }
}
