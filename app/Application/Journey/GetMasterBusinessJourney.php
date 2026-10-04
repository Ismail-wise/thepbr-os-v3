<?php

declare(strict_types=1);

namespace App\Application\Journey;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class GetMasterBusinessJourney
{
    public function __construct(
        private readonly AuthorizeBusinessCapability $authorize,
    ) {}

    /**
     * Presentation-only orchestration over existing authorized truth.
     *
     * The journey never writes canonical data and never grants access.
     *
     * @param  array<string,mixed>|null  $health
     * @return array{
     *   variant:string,
     *   steps:list<array{
     *     key:string,
     *     route:string|null,
     *     state:string,
     *     disabled:bool
     *   }>
     * }
     */
    public function execute(
        User $user,
        Business $business,
        ?array $health,
    ): array {
        $businessId = (string) $business->getKey();
        $userId = (string) $user->getKey();
        $existingBusiness = $business->origin_type
            === BusinessOriginType::ExistingBusinessImportedIntoPbr;
        $currentAreas = $this->currentEffectiveAreas($health);

        $definitions = [
            [
                'key' => 'business_model',
                'route' => '/formation',
                'capabilities' => [
                    CapabilityCatalog::FORMATION_VIEW,
                    CapabilityCatalog::BUSINESS_MODEL_VIEW,
                ],
                'applicable' => true,
            ],
            [
                'key' => 'business_valuation',
                'route' => '/formation',
                'capabilities' => [CapabilityCatalog::FORMATION_VIEW],
                'applicable' => $existingBusiness,
            ],
            [
                'key' => 'partner_dynamics',
                'route' => '/partner-dynamics',
                'capabilities' => [CapabilityCatalog::PARTNERS_VIEW],
                'applicable' => true,
            ],
            [
                'key' => 'capital',
                'route' => '/formation',
                'capabilities' => [
                    CapabilityCatalog::FORMATION_VIEW,
                    CapabilityCatalog::CAPITAL_VIEW,
                ],
                'applicable' => true,
            ],
            [
                'key' => 'contributions',
                'route' => '/partnership',
                'capabilities' => [CapabilityCatalog::CONTRIBUTIONS_VIEW],
                'applicable' => true,
            ],
            [
                'key' => 'equity',
                'route' => '/partnership',
                'capabilities' => [CapabilityCatalog::OWNERSHIP_VIEW],
                'applicable' => true,
            ],
            [
                'key' => 'governance',
                'route' => '/governance',
                'capabilities' => [CapabilityCatalog::GOVERNANCE_RECORDS_VIEW],
                'applicable' => true,
            ],
            [
                'key' => 'roles_operations',
                'route' => '/operations',
                'capabilities' => [CapabilityCatalog::OPERATIONS_VIEW],
                'applicable' => true,
            ],
            [
                'key' => 'finance',
                'route' => '/finance',
                'capabilities' => [CapabilityCatalog::FINANCE_VIEW],
                'applicable' => true,
            ],
            [
                'key' => 'rewards',
                'route' => '/rewards',
                'capabilities' => [CapabilityCatalog::REWARDS_VIEW],
                'applicable' => true,
            ],
            [
                'key' => 'transfer',
                'route' => '/changes/partner-changes',
                'capabilities' => [CapabilityCatalog::PARTNER_CHANGES_VIEW],
                'applicable' => true,
            ],
            [
                'key' => 'exit',
                'route' => '/changes/exit',
                'capabilities' => [CapabilityCatalog::EXIT_VIEW],
                'applicable' => true,
            ],
            [
                'key' => 'conflict',
                'route' => '/conflict',
                'capabilities' => [CapabilityCatalog::CONFLICT_VIEW],
                'applicable' => true,
            ],
            [
                'key' => 'closure',
                'route' => '/changes/closure',
                'capabilities' => [CapabilityCatalog::CLOSURE_VIEW],
                'applicable' => true,
            ],
        ];

        $steps = collect($definitions)
            ->filter(
                fn (array $step): bool => $step['applicable'] === true
                    && $this->allowsAll(
                        $user,
                        $business,
                        $step['capabilities'],
                    ),
            )
            ->map(function (array $step) use (
                $businessId,
                $userId,
                $currentAreas,
            ): array {
                return [
                    'key' => $step['key'],
                    'route' => $step['route'],
                    'recorded' => $this->hasRecordedData(
                        $step['key'],
                        $businessId,
                        $userId,
                        $currentAreas,
                    ),
                ];
            })
            ->values();

        $currentIndex = $steps->search(
            static fn (array $step): bool => $step['recorded'] === false,
        );
        $nextIndex = $currentIndex === false
            ? false
            : $steps->search(
                static fn (array $step, int $index): bool => $index > $currentIndex
                    && $step['recorded'] === false,
            );

        return [
            'variant' => $existingBusiness ? 'existing' : 'new',
            'steps' => $steps
                ->map(function (array $step, int $index) use (
                    $currentIndex,
                    $nextIndex,
                ): array {
                    $state = $step['recorded']
                        ? 'recorded'
                        : ($index === $currentIndex
                            ? 'current'
                            : ($index === $nextIndex ? 'next' : 'available'));

                    return [
                        'key' => $step['key'],
                        'route' => $step['route'],
                        'state' => $state,
                        'disabled' => $step['route'] === null,
                    ];
                })
                ->all(),
        ];
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function allowsAll(
        User $user,
        Business $business,
        array $capabilities,
    ): bool {
        foreach ($capabilities as $capability) {
            $decision = $this->authorize->decide(
                $user,
                $business,
                $business,
                new Capability($capability),
            );

            if (! $decision->allowed) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>|null  $health
     * @return array<string,true>
     */
    private function currentEffectiveAreas(?array $health): array
    {
        if ($health === null) {
            return [];
        }

        return collect($health['requirements'] ?? [])
            ->filter(
                static fn (array $row): bool => ($row['reason_code'] ?? null)
                    === 'current_effective_source',
            )
            ->mapWithKeys(
                static fn (array $row): array => [(string) $row['key'] => true],
            )
            ->all();
    }

    /**
     * @param  array<string,true>  $currentAreas
     */
    private function hasRecordedData(
        string $key,
        string $businessId,
        string $userId,
        array $currentAreas,
    ): bool {
        return match ($key) {
            'business_model' => DB::table('business_model_canvases')
                ->where('business_id', $businessId)
                ->exists()
                || DB::table('business_model_operating_profiles')
                    ->where('business_id', $businessId)
                    ->exists(),
            'business_valuation' => DB::table('valuations')
                ->where('business_id', $businessId)
                ->exists(),
            'partner_dynamics' => DB::table('partner_dynamics_personal_assessments')
                ->where('user_id', $userId)
                ->where('assessment_version', (string) config(
                    'partner_dynamics.version',
                    'v1',
                ))
                ->where('status', 'completed')
                ->exists(),
            'capital' => $this->hasEffectiveCapitalPlan($businessId),
            'contributions' => DB::table('contributions')
                ->where('business_id', $businessId)
                ->where('status', 'accepted')
                ->exists(),
            'equity' => isset($currentAreas['ownership']),
            'governance' => isset($currentAreas['governance']),
            'roles_operations' => isset($currentAreas['operations']),
            'finance' => isset($currentAreas['finance']),
            'rewards' => isset($currentAreas['rewards']),
            'transfer' => DB::table('partner_change_cases')
                ->where('business_id', $businessId)
                ->exists(),
            'exit' => DB::table('exit_cases')
                ->where('business_id', $businessId)
                ->exists(),
            'conflict' => isset($currentAreas['conflict'])
                || DB::table('conflict_cases')
                    ->where('business_id', $businessId)
                    ->exists(),
            'closure' => DB::table('closure_cases')
                ->where('business_id', $businessId)
                ->exists(),
            default => false,
        };
    }

    private function hasEffectiveCapitalPlan(string $businessId): bool
    {
        return DB::table('capital_plan_promotions as promotion')
            ->join(
                'record_family_effective_heads as head',
                function ($join): void {
                    $join
                        ->on('head.business_id', '=', 'promotion.business_id')
                        ->on(
                            'head.formal_record_family_id',
                            '=',
                            'promotion.formal_record_family_id',
                        )
                        ->on(
                            'head.formal_record_version_id',
                            '=',
                            'promotion.formal_record_version_id',
                        );
                },
            )
            ->where('promotion.business_id', $businessId)
            ->exists();
    }
}
