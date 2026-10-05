<?php

declare(strict_types=1);

namespace App\Application\Evidence;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class ListAuthorizedEvidenceTargets
{
    public function __construct(
        private readonly AuthorizeBusinessCapability $authorize,
    ) {}

    /**
     * @return array<string, list<array{id:string,label:string}>>
     */
    public function execute(
        User $user,
        Business $business,
    ): array {
        return [
            'contribution' => $this->contributions(
                $user,
                $business,
            ),
            'business_valuation_run' => $this->businessValuations(
                $user,
                $business,
            ),
        ];
    }

    /**
     * @return list<array{id:string,label:string}>
     */
    private function businessValuations(
        User $user,
        Business $business,
    ): array {
        $decision = $this->authorize->decide(
            $user,
            $business,
            $business,
            new Capability(
                CapabilityCatalog::FORMATION_MANAGE,
            ),
        );

        if (! $decision->allowed) {
            return [];
        }

        return DB::table('business_valuation_runs')
            ->where('business_id', $business->getKey())
            ->orderByDesc('as_of_date')
            ->orderByDesc('created_at')
            ->get([
                'id',
                'as_of_date',
                'base_value',
                'confidence_level',
                'review_state',
            ])
            ->map(function (object $row) use ($business): array {
                return [
                    'id' => (string) $row->id,
                    'label' => sprintf(
                        '%s · %s %s · %s confidence · %s',
                        (string) $row->as_of_date,
                        (string) $row->base_value,
                        (string) $business->base_currency,
                        (string) $row->confidence_level,
                        (string) $row->review_state,
                    ),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{id:string,label:string}>
     */
    private function contributions(
        User $user,
        Business $business,
    ): array {
        $decision = $this->authorize->decide(
            $user,
            $business,
            $business,
            new Capability(
                CapabilityCatalog::CONTRIBUTIONS_MANAGE,
            ),
        );

        if (! $decision->allowed) {
            return [];
        }

        return DB::table('contributions as c')
            ->join('partners as p', function ($join): void {
                $join
                    ->on('p.id', '=', 'c.partner_id')
                    ->on('p.business_id', '=', 'c.business_id');
            })
            ->where('c.business_id', $business->getKey())
            ->orderByDesc('c.created_at')
            ->get([
                'c.id',
                'c.description',
                'c.contribution_type',
                'c.status',
                'p.display_name as partner_name',
            ])
            ->map(static function (object $row): array {
                return [
                    'id' => (string) $row->id,
                    'label' => sprintf(
                        '%s · %s · %s · %s',
                        (string) $row->description,
                        (string) $row->partner_name,
                        (string) $row->contribution_type,
                        (string) $row->status,
                    ),
                ];
            })
            ->values()
            ->all();
    }
}
