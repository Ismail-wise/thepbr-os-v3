<?php

declare(strict_types=1);

namespace App\Application\Legal;

use App\Application\Governance\GovernanceActorContext;
use App\Domain\Access\CapabilityCatalog;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class GetLegalWorkspace
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly LegalRecordVisibility $visibility,
    ) {}

    /** @return array<string,mixed>|null */
    public function execute(User $user, Business $business): ?array
    {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::LEGAL_VIEW,
        ) === null) {
            return null;
        }

        $canManage = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::LEGAL_MANAGE,
        ) !== null;

        $head = DB::table('record_family_effective_heads as h')
            ->join(
                'formal_record_versions as v',
                'v.id',
                '=',
                'h.formal_record_version_id',
            )
            ->join(
                'formal_record_families as f',
                function ($join): void {
                    $join->on(
                        'f.id',
                        '=',
                        'v.formal_record_family_id',
                    )->on(
                        'f.business_id',
                        '=',
                        'v.business_id',
                    );
                },
            )
            ->join(
                'legal_structure_versions as legal',
                function ($join): void {
                    $join->on(
                        'legal.formal_record_version_id',
                        '=',
                        'v.id',
                    )->on(
                        'legal.business_id',
                        '=',
                        'v.business_id',
                    );
                },
            )
            ->where('h.business_id', $business->getKey())
            ->where('f.business_id', $business->getKey())
            ->where('f.record_type', 'legal_architecture')
            ->first([
                'v.id',
                'v.version_number',
                'v.revision',
                'v.effective_from',
                'v.review_due_at',
                'v.content_hash',
                'legal.legal_form',
                'legal.entity_name',
                'legal.primary_jurisdiction_code',
                'legal.governing_law_reference',
                'legal.registered_address',
                'legal.confidentiality',
                'legal.notes',
            ]);

        $current = null;

        if (
            $head !== null
            && $this->visibility->canViewVersion(
                $user,
                $business,
                (string) $head->id,
                (string) $head->confidentiality,
            )
        ) {
            $current = $this->snapshot(
                $business,
                (string) $head->id,
                $head,
            );
        }

        $versions = DB::table('formal_record_versions as v')
            ->join(
                'formal_record_families as f',
                function ($join): void {
                    $join->on(
                        'f.id',
                        '=',
                        'v.formal_record_family_id',
                    )->on(
                        'f.business_id',
                        '=',
                        'v.business_id',
                    );
                },
            )
            ->join(
                'legal_structure_versions as legal',
                function ($join): void {
                    $join->on(
                        'legal.formal_record_version_id',
                        '=',
                        'v.id',
                    )->on(
                        'legal.business_id',
                        '=',
                        'v.business_id',
                    );
                },
            )
            ->where('v.business_id', $business->getKey())
            ->where('f.record_type', 'legal_architecture')
            ->orderByDesc('v.version_number')
            ->get([
                'v.id',
                'v.version_number',
                'v.revision',
                'v.frozen_at',
                'v.effective_from',
                'v.review_due_at',
                'v.content_hash',
                'legal.legal_form',
                'legal.primary_jurisdiction_code',
                'legal.confidentiality',
            ])
            ->filter(fn (object $version): bool => $this->visibility->canViewVersion(
                $user,
                $business,
                (string) $version->id,
                (string) $version->confidentiality,
            )
            )
            ->map(function (object $version) use ($business): object {
                $version->state = DB::table(
                    'record_version_state_transitions',
                )
                    ->where('business_id', $business->getKey())
                    ->where('formal_record_version_id', $version->id)
                    ->orderByDesc('sequence')
                    ->value('to_state');

                $version->is_current_effective = DB::table(
                    'record_family_effective_heads',
                )
                    ->where('business_id', $business->getKey())
                    ->where(
                        'formal_record_version_id',
                        $version->id,
                    )
                    ->exists();

                return $version;
            })
            ->values();

        return [
            'business' => [
                'id' => (string) $business->getKey(),
                'name' => (string) $business->name,
            ],
            'permissions' => [
                'manage' => $canManage,
            ],
            'current' => $current,
            'versions' => $versions,
            'attention' => $this->attention($current),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(
        Business $business,
        string $versionId,
        object $header,
    ): array {
        return [
            'formal_record_version_id' => $versionId,
            'version_number' => (int) $header->version_number,
            'revision' => (int) $header->revision,
            'effective_from' => $header->effective_from,
            'review_due_at' => $header->review_due_at,
            'content_hash' => (string) $header->content_hash,
            'legal_form' => (string) $header->legal_form,
            'entity_name' => $header->entity_name,
            'primary_jurisdiction_code' => (string) $header->primary_jurisdiction_code,
            'governing_law_reference' => $header->governing_law_reference,
            'registered_address' => $header->registered_address,
            'confidentiality' => (string) $header->confidentiality,
            'notes' => $header->notes,
            'jurisdictions' => DB::table(
                'legal_jurisdiction_applicabilities',
            )
                ->where('business_id', $business->getKey())
                ->where('formal_record_version_id', $versionId)
                ->orderBy('scope_type')
                ->get(),
            'registrations' => DB::table('legal_registrations')
                ->where('business_id', $business->getKey())
                ->where('formal_record_version_id', $versionId)
                ->orderBy('registration_type')
                ->get(),
            'licenses' => DB::table('legal_license_permits')
                ->where('business_id', $business->getKey())
                ->where('formal_record_version_id', $versionId)
                ->orderBy('name')
                ->get(),
            'requirements' => DB::table('legal_requirements')
                ->where('business_id', $business->getKey())
                ->where('formal_record_version_id', $versionId)
                ->orderBy('requirement_key')
                ->get(),
            'reviews' => DB::table('legal_reviews')
                ->where('business_id', $business->getKey())
                ->where('formal_record_version_id', $versionId)
                ->orderByDesc('review_date')
                ->get(),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $current
     * @return array<string,int>
     */
    private function attention(?array $current): array
    {
        if ($current === null) {
            return [
                'blocked_requirements' => 0,
                'review_required' => 0,
                'licenses_due_soon' => 0,
            ];
        }

        $requirements = collect($current['requirements']);
        $licenses = collect($current['licenses']);

        return [
            'blocked_requirements' => $requirements
                ->where('status', 'blocked')
                ->count(),
            'review_required' => $requirements
                ->where('legal_review_required', true)
                ->whereNotIn('status', ['met', 'not_applicable'])
                ->count(),
            'licenses_due_soon' => $licenses
                ->filter(
                    fn (object $license): bool => $license->expiry_date !== null
                        && (string) $license->expiry_date
                            <= now()->addDays(60)->toDateString()
                        && in_array(
                            $license->status,
                            ['active', 'pending'],
                            true,
                        ),
                )
                ->count(),
        ];
    }
}
