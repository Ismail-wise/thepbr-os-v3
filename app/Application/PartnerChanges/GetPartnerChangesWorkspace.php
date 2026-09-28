<?php

declare(strict_types=1);

namespace App\Application\PartnerChanges;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Application\Partnership\PartnershipActorContext;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\PartnerChanges\PartnerChangeCase;
use Illuminate\Support\Facades\DB;

final class GetPartnerChangesWorkspace
{
    public function __construct(
        private readonly PartnershipActorContext $actor,
        private readonly AuthorizeBusinessCapability $authorize,
    ) {}

    /** @return array<string,mixed>|null */
    public function execute(User $user, Business $business): ?array
    {
        $canView = $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::PARTNER_CHANGES_VIEW,
        );

        if (! $canView) {
            return null;
        }

        $canManage = $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::PARTNER_CHANGES_MANAGE,
        );

        $businessId = (string) $business->getKey();

        $cases = PartnerChangeCase::query()
            ->where('business_id', $businessId)
            ->orderByDesc('created_at')
            ->get()
            ->filter(function (PartnerChangeCase $case) use (
                $user,
                $business,
            ): bool {
                return $this->authorize->decide(
                    $user,
                    $business,
                    $business,
                    new Capability(CapabilityCatalog::PARTNER_CHANGES_VIEW),
                    PartnerChangeCase::class,
                    (string) $case->getKey(),
                )->allowed;
            })
            ->map(
                fn (PartnerChangeCase $case): array => $this->caseProjection($businessId, $case),
            )
            ->values()
            ->all();

        $partners = DB::table('partners')
            ->where('business_id', $businessId)
            ->orderBy('display_name')
            ->get([
                'id',
                'display_name',
                'status',
            ])
            ->map(static fn (object $row): array => (array) $row)
            ->all();
        $register = DB::table('ownership_register_versions')
            ->where('business_id', $businessId)
            ->where('status', 'effective')
            ->where('effective_from', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('effective_until')
                    ->orWhere('effective_until', '>', now());
            })
            ->orderByDesc('effective_from')
            ->first();

        $ownership = null;

        if ($register !== null) {
            $ownership = [
                'id' => (string) $register->id,
                'version_number' => (int) $register->version_number,
                'currency' => (string) $register->currency,
                'effective_from' => $register->effective_from,
                'share_classes' => DB::table(
                    'ownership_register_share_classes',
                )
                    ->where('business_id', $businessId)
                    ->where(
                        'ownership_register_version_id',
                        $register->id,
                    )
                    ->orderBy('name')
                    ->get([
                        'id',
                        'name',
                        'voting_right_per_share',
                        'profit_right_per_share',
                        'transfer_allowed',
                        'restrictions',
                    ])
                    ->map(static fn (object $row): array => (array) $row)
                    ->all(),
                'positions' => DB::table('ownership_register_positions')
                    ->where('business_id', $businessId)
                    ->where(
                        'ownership_register_version_id',
                        $register->id,
                    )
                    ->orderBy('partner_id')
                    ->get([
                        'partner_id',
                        'share_class_id',
                        'shares_issued',
                        'shares_vested',
                        'voting_rights',
                        'profit_rights',
                    ])
                    ->map(static fn (object $row): array => (array) $row)
                    ->all(),
            ];
        }

        return [
            'business' => [
                'id' => $businessId,
                'name' => (string) $business->name,
            ],
            'permissions' => [
                'view' => true,
                'manage' => $canManage,
            ],
            'partners' => $partners,
            'current_ownership_register' => $ownership,
            'cases' => $cases,
        ];
    }

    /** @return array<string,mixed> */
    private function caseProjection(
        string $businessId,
        PartnerChangeCase $case,
    ): array {
        $caseId = (string) $case->getKey();

        $submission = DB::table('partner_change_governance_submissions')
            ->where('business_id', $businessId)
            ->where('partner_change_case_id', $caseId)
            ->orderByDesc('created_at')
            ->first();
        $formalState = null;

        if ($submission !== null) {
            $formalState = DB::table('record_version_state_transitions')
                ->where('business_id', $businessId)
                ->where(
                    'formal_record_version_id',
                    $submission->formal_record_version_id,
                )
                ->orderByDesc('sequence')
                ->value('to_state');
        }

        $rounds = DB::table('partner_change_rofr_rounds')
            ->where('business_id', $businessId)
            ->where('partner_change_case_id', $caseId)
            ->orderBy('sequence')
            ->get()
            ->map(function (object $round) use ($businessId): array {
                return [
                    ...(array) $round,
                    'responses' => DB::table('partner_change_rofr_responses')
                        ->where('business_id', $businessId)
                        ->where('rofr_round_id', $round->id)
                        ->orderBy('responded_at')
                        ->get()
                        ->map(static fn (object $row): array => (array) $row)
                        ->all(),
                ];
            })
            ->all();

        return [
            'id' => $caseId,
            'case_number' => (string) $case->case_number,
            'transaction_type' => $case->transaction_type->value,
            'status' => $case->status->value,
            'source_ownership_register_version_id' => $case->source_ownership_register_version_id,
            'seller_partner_id' => $case->seller_partner_id,
            'buyer_partner_id' => (string) $case->buyer_partner_id,
            'source_share_class_id' => $case->source_share_class_id,
            'shares' => $case->shares,
            'currency' => $case->currency,
            'consideration_minor_units' => $case->consideration_minor_units,
            'valuation_method' => $case->valuation_method,
            'rights_impact_summary' => $case->rights_impact_summary,
            'governance_decision_type' => $case->governance_decision_type,
            'rofr_required' => (bool) $case->rofr_required,
            'effective_from' => $case->effective_from?->toAtomString(),
            'revision' => (int) $case->revision,
            'created_at' => $case->created_at?->toAtomString(),
            'eligibility' => DB::table('partner_change_eligibility_checks')
                ->where('business_id', $businessId)
                ->where('partner_change_case_id', $caseId)
                ->orderBy('checked_at')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
            'requirements' => DB::table('partner_change_requirements')
                ->where('business_id', $businessId)
                ->where('partner_change_case_id', $caseId)
                ->orderBy('recorded_at')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
            'rofr_rounds' => $rounds,
            'governance_submission' => $submission === null
                ? null
                : [
                    'formal_record_version_id' => (string) $submission->formal_record_version_id,
                    'proposal_version_id' => (string) $submission->proposal_version_id,
                    'decision_type' => (string) $submission->decision_type,
                    'decision_id' => $submission->decision_id,
                    'effective_register_version_id' => $submission->effective_register_version_id,
                    'formal_record_state' => $formalState,
                ],
        ];
    }
}
