<?php

declare(strict_types=1);

namespace App\Application\Formation;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Formation\BusinessValuationRun;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class GetBusinessValuationReadModel
{
    public function __construct(
        private readonly FormationActorContext $actor,
    ) {}

    /**
     * @return array<string,mixed>|null
     */
    public function latest(
        User $user,
        Business $business,
    ): ?array {
        if (! $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::FORMATION_VIEW,
        )) {
            return null;
        }

        if (
            $business->origin_type
            !== BusinessOriginType::ExistingBusinessImportedIntoPbr
        ) {
            throw new InvalidArgumentException(
                'Business Valuation belongs to the Existing Business journey.',
            );
        }

        $run = BusinessValuationRun::query()
            ->where('business_id', $business->getKey())
            ->orderByDesc('as_of_date')
            ->orderByDesc('created_at')
            ->first();

        if ($run === null) {
            return null;
        }

        $links = DB::table('evidence_links as link')
            ->join('evidence as evidence', function ($join): void {
                $join->on('evidence.id', '=', 'link.evidence_id')
                    ->on('evidence.business_id', '=', 'link.business_id');
            })
            ->where('link.business_id', $business->getKey())
            ->where('link.target_type', 'business_valuation_run')
            ->where('link.target_id', $run->getKey())
            ->orderBy('link.created_at')
            ->get([
                'link.evidence_id',
                'evidence.verified_at',
            ]);

        $provenance = $run->source_provenance ?? [];
        $hasSystemSources = ($provenance['financialSnapshot'] ?? null) !== null
            || ($provenance['assets'] ?? []) !== []
            || ($provenance['liabilities'] ?? []) !== [];

        $evidenceQuality = match (true) {
            $links->isNotEmpty() => 'documented',
            $hasSystemSources => 'traceable',
            default => 'limited',
        };

        return [
            'id' => (string) $run->getKey(),
            'businessId' => (string) $run->business_id,
            'baselineValuationId' => (string) $run->valuation_id,
            'asOfDate' => $run->as_of_date?->format('Y-m-d'),
            'formulaVersion' => (string) $run->formula_version,
            'reviewState' => (string) $run->review_state,
            'historical' => $run->historical_inputs ?? [],
            'assumptions' => $run->assumptions ?? [],
            'provenance' => $provenance,
            'methods' => $run->method_results ?? [],
            'range' => [
                'low' => (string) $run->range_low,
                'base' => (string) $run->base_value,
                'high' => (string) $run->range_high,
                'basis' => 'available_method_spread',
            ],
            'confidence' => [
                'level' => (string) $run->confidence_level,
                'usableMethodCount' => count($run->method_results ?? []),
            ],
            'evidenceQuality' => [
                'level' => $evidenceQuality,
                'linkedEvidenceCount' => $links->count(),
                'verifiedEvidenceCount' => $links
                    ->filter(
                        static fn (object $row): bool => $row->verified_at !== null,
                    )
                    ->count(),
                'evidenceIds' => $links
                    ->pluck('evidence_id')
                    ->map(static fn (mixed $id): string => (string) $id)
                    ->values()
                    ->all(),
            ],
            'warnings' => $run->warnings ?? [],
            'semantics' => $run->semantics ?? [],
            'createdAt' => $run->created_at?->toIso8601String(),
        ];
    }
}
