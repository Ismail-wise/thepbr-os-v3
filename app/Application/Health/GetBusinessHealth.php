<?php

declare(strict_types=1);

namespace App\Application\Health;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Application\Partnership\OwnershipWorkflow;
use App\Application\Records\ResolveCurrentEffectiveRecordVersion;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Health\Enums\HealthRequirementState;
use App\Domain\Health\ValueObjects\HealthRequirement;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use Illuminate\Support\Facades\DB;

final class GetBusinessHealth
{
    public function __construct(
        private readonly AuthorizeBusinessCapability $authorize,
        private readonly ResolveCurrentEffectiveRecordVersion $effectiveRecords,
        private readonly OwnershipWorkflow $ownership,
        private readonly HealthRuleCatalog $catalog,
    ) {}

    /**
     * @return array{
     *   business:array{id:string,name:string,workspace_status:string},
     *   generated_at:string,
     *   summary:array{met:int,warning:int,blocked:int,unknown:int},
     *   requirements:list<array<string,mixed>>
     * }|null
     */
    public function execute(
        User $user,
        Business $business,
    ): ?array {
        if (! $this->allows(
            $user,
            $business,
            CapabilityCatalog::BUSINESS_HEALTH_VIEW,
        )) {
            return null;
        }

        $requirements = [
            $this->workspaceRequirement($business),
        ];

        $ownership = $this->ownershipRequirement($user, $business);

        if ($ownership !== null) {
            $requirements[] = $ownership;
        }

        foreach ($this->catalog->formalRecordRules() as $rule) {
            $requirement = $this->formalRecordRequirement(
                $user,
                $business,
                $rule,
            );

            if ($requirement !== null) {
                $requirements[] = $requirement;
            }
        }

        $payload = array_map(
            static fn (HealthRequirement $requirement): array => $requirement->toArray(),
            $requirements,
        );

        $summary = [
            'met' => 0,
            'warning' => 0,
            'blocked' => 0,
            'unknown' => 0,
        ];

        foreach ($payload as $requirement) {
            $state = $requirement['state'];
            $summary[$state]++;
        }

        return [
            'business' => [
                'id' => (string) $business->getKey(),
                'name' => (string) $business->name,
                'workspace_status' => $business->workspace_status->value,
            ],
            'generated_at' => now()->toIso8601String(),
            'summary' => $summary,
            'requirements' => $payload,
        ];
    }

    private function workspaceRequirement(
        Business $business,
    ): HealthRequirement {
        [$state, $reason] = match ($business->workspace_status) {
            WorkspaceStatus::Active => [
                HealthRequirementState::Met,
                'workspace_active',
            ],
            WorkspaceStatus::Restricted => [
                HealthRequirementState::Warning,
                'workspace_restricted',
            ],
            WorkspaceStatus::Archived => [
                HealthRequirementState::Blocked,
                'workspace_archived',
            ],
            WorkspaceStatus::Closed => [
                HealthRequirementState::Blocked,
                'workspace_closed',
            ],
        };

        return new HealthRequirement(
            key: 'workspace',
            state: $state,
            reasonCode: $reason,
            nextActionCode: 'open_workspace',
            sourceType: 'business',
            sourceId: (string) $business->getKey(),
            lastVerifiedAt: $business->updated_at?->toIso8601String(),
            route: '/',
        );
    }

    private function ownershipRequirement(
        User $user,
        Business $business,
    ): ?HealthRequirement {
        if (! $this->allows(
            $user,
            $business,
            CapabilityCatalog::OWNERSHIP_VIEW,
        )) {
            return null;
        }

        $current = $this->ownership->currentEffectiveRegisterVersion(
            $user,
            $business,
        );

        if ($current === null) {
            return new HealthRequirement(
                key: 'ownership',
                state: HealthRequirementState::Unknown,
                reasonCode: 'authorized_source_unavailable',
                nextActionCode: 'open_ownership',
                route: '/partnership',
            );
        }

        return new HealthRequirement(
            key: 'ownership',
            state: HealthRequirementState::Met,
            reasonCode: 'current_effective_source',
            nextActionCode: 'open_ownership',
            sourceType: 'ownership_register_version',
            sourceId: (string) $current->id,
            sourceVersion: (int) $current->version_number,
            lastVerifiedAt: $this->isoDateTime($current->effective_from),
            route: '/partnership',
        );
    }

    /**
     * @param array{
     *   key:string,
     *   record_type:string,
     *   capability:string,
     *   route:string,
     *   next_action_code:string
     * } $rule
     */
    private function formalRecordRequirement(
        User $user,
        Business $business,
        array $rule,
    ): ?HealthRequirement {
        if (
            ! $this->allows($user, $business, $rule['capability'])
            || ! $this->allows(
                $user,
                $business,
                CapabilityCatalog::RECORDS_VIEW,
            )
        ) {
            return null;
        }

        $family = FormalRecordFamily::query()
            ->where('business_id', $business->getKey())
            ->where('record_type', $rule['record_type'])
            ->where('subject_type', 'business')
            ->where('subject_id', $business->getKey())
            ->first();

        if ($family === null) {
            return $this->unknownFormalRecordRequirement($rule);
        }

        $familyDecision = $this->authorize->decide(
            $user,
            $business,
            $business,
            new Capability(CapabilityCatalog::RECORDS_VIEW),
            FormalRecordFamily::class,
            (string) $family->getKey(),
        );

        if (! $familyDecision->allowed) {
            return $this->unknownFormalRecordRequirement($rule);
        }

        $version = $this->effectiveRecords->execute(
            $user,
            $business,
            new Capability(CapabilityCatalog::RECORDS_VIEW),
            (string) $family->getKey(),
        );

        if ($version === null) {
            return $this->unknownFormalRecordRequirement($rule);
        }

        $state = HealthRequirementState::Met;
        $reasonCode = 'current_effective_source';

        if ($version->review_due_at !== null) {
            if (! $version->review_due_at->isFuture()) {
                $state = HealthRequirementState::Warning;
                $reasonCode = 'review_due';
            } elseif ($version->review_due_at->lte(now()->addDays(30))) {
                $state = HealthRequirementState::Warning;
                $reasonCode = 'review_due_soon';
            }
        }

        $verifiedAt = DB::table('record_version_state_transitions')
            ->where('business_id', $business->getKey())
            ->where('formal_record_version_id', $version->getKey())
            ->where('to_state', 'effective')
            ->orderByDesc('sequence')
            ->value('occurred_at');

        return new HealthRequirement(
            key: $rule['key'],
            state: $state,
            reasonCode: $reasonCode,
            nextActionCode: $rule['next_action_code'],
            sourceType: 'formal_record_version',
            sourceId: (string) $version->getKey(),
            sourceVersion: (int) $version->version_number,
            sourceHash: strtolower((string) $version->content_hash),
            lastVerifiedAt: $this->isoDateTime(
                $verifiedAt ?? $version->effective_from,
            ),
            route: $rule['route'],
        );
    }

    /**
     * @param array{
     *   key:string,
     *   record_type:string,
     *   capability:string,
     *   route:string,
     *   next_action_code:string
     * } $rule
     */
    private function unknownFormalRecordRequirement(
        array $rule,
    ): HealthRequirement {
        return new HealthRequirement(
            key: $rule['key'],
            state: HealthRequirementState::Unknown,
            reasonCode: 'authorized_source_unavailable',
            nextActionCode: $rule['next_action_code'],
            route: $rule['route'],
        );
    }

    private function allows(
        User $user,
        Business $business,
        string $capability,
    ): bool {
        return $this->authorize->decide(
            $user,
            $business,
            $business,
            new Capability($capability),
        )->allowed;
    }

    private function isoDateTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return (string) $value;
    }
}
