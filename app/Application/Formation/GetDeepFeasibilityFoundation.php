<?php

declare(strict_types=1);

namespace App\Application\Formation;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class GetDeepFeasibilityFoundation
{
    public function __construct(
        private readonly FormationActorContext $actor,
        private readonly BusinessModelEconomicsCalculator $economics,
        private readonly GetDemandEvidenceSummary $demandEvidence,
        private readonly DeepFeasibilityDimensionAssessmentEngine $assessment,
        private readonly DeepFeasibilityActionableFindings $findings,
    ) {}

    /**
     * First Deep Feasibility foundation only.
     *
     * This read model intentionally does not calculate a final feasibility
     * score or GO/HOLD/NO-GO recommendation yet. It proves canonical source
     * reuse and living confidence without inventing missing Capital,
     * Partner Dynamics, Operations, Legal/Risk or Sales readiness data.
     *
     * @return array<string,mixed>|null
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

        $businessId = (string) $business->getKey();

        $bmc = DB::table('business_model_canvases')
            ->where('business_id', $businessId)
            ->first();

        $profile = DB::table('business_model_operating_profiles')
            ->where('business_id', $businessId)
            ->first();

        $profileArray = $profile === null ? null : (array) $profile;
        $economics = $this->economics->calculate($profileArray);
        $demand = $this->demandEvidence->execute($businessId);

        $businessModelAvailable = $bmc !== null || $profile !== null;
        $competitionAvailable = $this->hasText(
            $profile?->competition_alternatives ?? null,
        );
        $scalabilityAvailable = $this->hasText(
            $profile?->scalability_strategy ?? null,
        ) || $this->hasText(
            $profile?->scalability_constraints ?? null,
        );
        $economicsReady = $economics['status'] === 'ready';
        $demandValidated = $demand['status'] === 'validated';

        $gaps = [];

        if (! $businessModelAvailable) {
            $gaps[] = 'business_model';
        }

        if (! $demandValidated) {
            $gaps[] = 'demand_validation';
        }

        if (! $economicsReady) {
            $gaps[] = 'unit_economics';
        }

        if (! $competitionAvailable) {
            $gaps[] = 'competition_alternatives';
        }

        if (! $scalabilityAvailable) {
            $gaps[] = 'scalability';
        }

        $confidence = $demandValidated && $economicsReady
            ? 'medium'
            : 'low';

        $evidenceQuality = match (true) {
            $demand['verified_evidence_links'] > 0 => 'documented',
            $demand['evidence_links'] > 0
                || $demand['completed_validations'] > 0 => 'traceable',
            default => 'limited',
        };

        $status = match (true) {
            $demandValidated && $economicsReady => 'core_evidence_ready',
            $businessModelAvailable
                || $demand['assumptions'] > 0
                || $demand['validation_activities'] > 0 => 'building',
            default => 'not_started',
        };

        $foundation = [
            'status' => $status,
            'businessId' => $businessId,
            'currency' => (string) $business->base_currency,
            'canonicalSources' => [
                'businessModel' => [
                    'businessPurpose' => $profile?->business_purpose,
                    'market' => $profile?->market,
                    'location' => $profile?->location,
                    'competitionAlternatives' => $profile?->competition_alternatives,
                    'operatingModel' => $profile?->operating_model,
                    'pricingNotes' => $profile?->pricing_notes,
                    'valueProposition' => $bmc?->value_propositions,
                    'channels' => $bmc?->channels,
                    'revenueStreams' => $bmc?->revenue_streams,
                    'costStructure' => $bmc?->cost_structure,
                    'scalabilityStrategy' => $profile?->scalability_strategy,
                    'scalabilityConstraints' => $profile?->scalability_constraints,
                    'first12MonthPlan' => $profile?->first_12_month_plan,
                ],
                'economics' => $economics,
                'demand' => $demand,
                'businessValuation' => [
                    'applicable' => false,
                    'status' => 'skipped',
                    'reason' => 'new_business_default_skip',
                ],
            ],
            'coverage' => [
                'businessModel' => $businessModelAvailable,
                'demandValidated' => $demandValidated,
                'unitEconomicsReady' => $economicsReady,
                'competitionAvailable' => $competitionAvailable,
                'scalabilityAvailable' => $scalabilityAvailable,
            ],
            'confidence' => [
                'level' => $confidence,
                'highConfidenceAvailable' => false,
                'reason' => $confidence === 'medium'
                    ? 'validated_demand_and_ready_unit_economics'
                    : 'core_evidence_still_maturing',
            ],
            'evidenceQuality' => [
                'level' => $evidenceQuality,
                'linkedDemandEvidence' => $demand['evidence_links'],
                'verifiedDemandEvidence' => $demand['verified_evidence_links'],
            ],
            'gaps' => $gaps,
            'pendingDomains' => [
                'capital',
                'partner_dynamics',
                'operations_readiness',
                'legal_risk',
                'sales_channel_readiness',
            ],
            'semantics' => [
                'livingFeasibility' => true,
                'decisionRecommendationAvailable' => false,
                'guaranteedBusinessSuccess' => false,
                'successProbability' => false,
                'canonicalTruthDuplicated' => false,
            ],
        ];

        $assessment = $this->assessment->assess($foundation);

        return [
            ...$foundation,
            'assessment' => $assessment,
            'findings' => $this->findings->derive($assessment),
            'score' => $assessment['overall']['score'],
            'decision' => $assessment['overall']['recommendation'],
        ];
    }

    private function hasText(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
