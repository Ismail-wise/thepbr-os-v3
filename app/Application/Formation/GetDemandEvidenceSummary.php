<?php

declare(strict_types=1);

namespace App\Application\Formation;

use Illuminate\Support\Facades\DB;

final class GetDemandEvidenceSummary
{
    /**
     * @return array{
     *   status:string,
     *   assumptions:int,
     *   validated_assumptions:int,
     *   invalidated_assumptions:int,
     *   validation_activities:int,
     *   completed_validations:int,
     *   evidence_links:int,
     *   verified_evidence_links:int
     * }
     */
    public function execute(string $businessId): array
    {
        $assumptions = DB::table('formation_assumptions')
            ->where('business_id', $businessId);

        $validations = DB::table('validation_activities')
            ->where('business_id', $businessId);

        $assumptionCount = (clone $assumptions)->count();
        $validatedCount = (clone $assumptions)
            ->where('status', 'validated')
            ->count();
        $invalidatedCount = (clone $assumptions)
            ->where('status', 'invalidated')
            ->count();
        $validationCount = (clone $validations)->count();
        $completedValidations = (clone $validations)
            ->where('status', 'completed')
            ->count();
        $evidenceLinks = DB::table('validation_evidence_links')
            ->where('business_id', $businessId)
            ->count();
        $verifiedEvidenceLinks = DB::table(
            'validation_evidence_links as link',
        )
            ->join('evidence as evidence', function ($join): void {
                $join->on('evidence.id', '=', 'link.evidence_id')
                    ->on('evidence.business_id', '=', 'link.business_id');
            })
            ->where('link.business_id', $businessId)
            ->whereNotNull('evidence.verified_at')
            ->count();

        $status = 'not_started';

        if ($validatedCount > 0 && $completedValidations > 0) {
            $status = 'validated';
        } elseif ($assumptionCount > 0 || $validationCount > 0) {
            $status = 'testing';
        }

        return [
            'status' => $status,
            'assumptions' => $assumptionCount,
            'validated_assumptions' => $validatedCount,
            'invalidated_assumptions' => $invalidatedCount,
            'validation_activities' => $validationCount,
            'completed_validations' => $completedValidations,
            'evidence_links' => $evidenceLinks,
            'verified_evidence_links' => $verifiedEvidenceLinks,
        ];
    }
}
