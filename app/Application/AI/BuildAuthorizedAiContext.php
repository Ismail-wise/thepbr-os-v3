<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Application\Access\ResolveMembershipCapabilities;
use App\Application\Identity\ResolveUiLanguageMode;
use App\Application\Search\GlobalSearch;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class BuildAuthorizedAiContext
{
    public function __construct(
        private readonly ResolveMembershipCapabilities $memberships,
        private readonly AuthorizeBusinessCapability $authorize,
        private readonly ResolveUiLanguageMode $languageMode,
        private readonly GlobalSearch $search,
    ) {}

    public function canAccess(
        User $user,
        Business $business,
    ): bool {
        return $this->memberships->activeMembership(
            $user,
            $business,
        ) !== null
            && $this->authorize->decide(
                $user,
                $business,
                $business,
                new Capability(CapabilityCatalog::PBR_AI_VIEW),
            )->allowed;
    }

    /**
     * @return array{
     *   language_mode:string,
     *   business:array{name:string},
     *   authorized_sources:list<array{
     *     source_type:string,
     *     source_id:string,
     *     title:string,
     *     snippet:string|null
     *   }>
     * }|null
     */
    public function execute(
        User $user,
        Business $business,
        string $prompt,
    ): ?array {
        if (! $this->canAccess($user, $business)) {
            return null;
        }

        $limit = max(
            1,
            min((int) config('pbr_ai.retrieval_limit', 8), 20),
        );

        $query = mb_substr(trim($prompt), 0, 200);

        $results = $query === ''
            ? null
            : $this->search->execute(
                $user,
                $business,
                $query,
                min($limit * 2, 50),
            );

        $sources = [];

        foreach (($results['items'] ?? []) as $item) {
            if (
                ! is_array($item)
                || ! $this->sourceAllowedByAiPolicy(
                    $business,
                    $item,
                )
            ) {
                continue;
            }

            $sources[] = [
                'source_type' => (string) $item['source_type'],
                'source_id' => (string) $item['source_id'],
                'title' => (string) $item['title'],
                'snippet' => isset($item['snippet'])
                    && is_string($item['snippet'])
                        ? $item['snippet']
                        : null,
            ];

            if (count($sources) >= $limit) {
                break;
            }
        }

        return [
            'language_mode' => $this->languageMode
                ->handle($user)
                ->value,
            'business' => [
                'name' => (string) $business->name,
            ],
            'authorized_sources' => $sources,
        ];
    }

    /**
     * @param  array<string,mixed>  $source
     */
    private function sourceAllowedByAiPolicy(
        Business $business,
        array $source,
    ): bool {
        $sourceType = (string) ($source['source_type'] ?? '');
        $sourceId = (string) ($source['source_id'] ?? '');

        $domain = match ($sourceType) {
            'conflict_case' => 'conflict',
            'finance_payment' => 'finance',
            'risk_item',
            'risk_protection',
            'risk_incident',
            'risk_control_test' => 'risk',
            'continuity_test',
            'continuity_emergency_access_activation' => 'continuity',
            'formal_record_version' => $this->formalRecordDomain(
                $business,
                $sourceId,
            ),
            default => null,
        };

        if ($domain === null) {
            return true;
        }

        return (bool) config(
            'pbr_ai.restricted_domains.'.$domain,
            false,
        );
    }

    private function formalRecordDomain(
        Business $business,
        string $versionId,
    ): ?string {
        $recordType = DB::table('formal_record_versions as version')
            ->join(
                'formal_record_families as family',
                function ($join): void {
                    $join->on(
                        'family.id',
                        '=',
                        'version.formal_record_family_id',
                    )->on(
                        'family.business_id',
                        '=',
                        'version.business_id',
                    );
                },
            )
            ->where(
                'version.business_id',
                $business->getKey(),
            )
            ->where('version.id', $versionId)
            ->value('family.record_type');

        if (! is_string($recordType)) {
            return null;
        }

        return match ($recordType) {
            'conflict_settlement',
            'conflict_resolution_policy' => 'conflict',
            'finance_policy',
            'finance_payment' => 'finance',
            'risk_register' => 'risk',
            'continuity_plan' => 'continuity',
            default => null,
        };
    }
}
