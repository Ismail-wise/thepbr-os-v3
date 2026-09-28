<?php

declare(strict_types=1);

namespace App\Application\Exit;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Application\Partnership\PartnershipActorContext;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Exit\ExitCase;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class GetExitWorkspace
{
    public function __construct(
        private readonly PartnershipActorContext $actor,
        private readonly AuthorizeBusinessCapability $authorize,
    ) {}

    /** @return array<string,mixed>|null */
    public function execute(User $user, Business $business): ?array
    {
        if (! $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::EXIT_VIEW,
        )) {
            return null;
        }

        $canManage = $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::EXIT_MANAGE,
        );
        $canAccessAdmin = $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::ACCESS_ADMIN_MANAGE,
        );
        $canViewFinance = $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::FINANCE_VIEW,
        );

        $businessId = (string) $business->getKey();

        $cases = ExitCase::query()
            ->where('business_id', $businessId)
            ->orderByDesc('created_at')
            ->get()
            ->filter(function (ExitCase $case) use (
                $user,
                $business,
            ): bool {
                return $this->authorize->decide(
                    $user,
                    $business,
                    $business,
                    new Capability(CapabilityCatalog::EXIT_VIEW),
                    ExitCase::class,
                    (string) $case->getKey(),
                )->allowed;
            })
            ->map(
                fn (ExitCase $case): array => $this->caseProjection(
                    $businessId,
                    $case,
                    $canViewFinance,
                ),
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

        $memberships = $canManage
            ? DB::table('memberships as m')
                ->join(
                    'users as u',
                    'u.id',
                    '=',
                    'm.user_id',
                )
                ->where('m.business_id', $businessId)
                ->orderBy('u.email')
                ->get([
                    'm.id',
                    'm.access_status',
                    'u.email',
                ])
                ->map(static fn (object $row): array => (array) $row)
                ->all()
            : [];

        return [
            'business' => [
                'id' => $businessId,
                'name' => (string) $business->name,
            ],
            'permissions' => [
                'view' => true,
                'manage' => $canManage,
                'access_admin' => $canAccessAdmin,
                'finance_view' => $canViewFinance,
            ],
            'partners' => $partners,
            'memberships' => $memberships,
            'cases' => $cases,
        ];
    }

    /** @return array<string,mixed> */
    private function caseProjection(
        string $businessId,
        ExitCase $case,
        bool $canViewFinance,
    ): array {
        $caseId = (string) $case->getKey();

        $submission = DB::table('exit_governance_submissions')
            ->where('business_id', $businessId)
            ->where('exit_case_id', $caseId)
            ->orderByDesc('created_at')
            ->first();

        $formalState = null;

        if ($submission !== null) {
            $formalState = DB::table(
                'record_version_state_transitions',
            )
                ->where('business_id', $businessId)
                ->where(
                    'formal_record_version_id',
                    $submission->formal_record_version_id,
                )
                ->orderByDesc('sequence')
                ->value('to_state');
        }

        $financeLinks = [];

        if ($canViewFinance) {
            $financeLinks = DB::table('exit_finance_links as link')
                ->join(
                    'finance_payments as payment',
                    function ($join): void {
                        $join
                            ->on(
                                'payment.id',
                                '=',
                                'link.finance_payment_id',
                            )
                            ->on(
                                'payment.business_id',
                                '=',
                                'link.business_id',
                            );
                    },
                )
                ->where('link.business_id', $businessId)
                ->where('link.exit_case_id', $caseId)
                ->orderBy('link.installment_sequence')
                ->orderBy('link.linked_at')
                ->get([
                    'link.finance_payment_id',
                    'link.purpose',
                    'link.installment_sequence',
                    'payment.amount_minor_units',
                    'payment.currency',
                    'payment.status',
                    'payment.completed_at',
                ])
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        }

        return [
            'id' => $caseId,
            'case_number' => (string) $case->case_number,
            'partner_id' => (string) $case->partner_id,
            'membership_id' => $case->membership_id,
            'trigger' => $case->trigger->value,
            'notice_date' => $case->notice_date?->toDateString(),
            'intended_exit_date' => $case->intended_exit_date?->toDateString(),
            'required_notice_days' => $case->required_notice_days,
            'trigger_detail' => $case->trigger_detail,
            'notice_summary' => $case->notice_summary,
            'source_ownership_register_version_id' => $case->source_ownership_register_version_id,
            'share_treatment' => $case->share_treatment,
            'buyer_partner_id' => $case->buyer_partner_id,
            'partner_change_case_id' => $case->partner_change_case_id,
            'ownership_scenario_id' => $case->ownership_scenario_id,
            'valuation_method' => $case->valuation_method,
            'approved_business_value_minor_units' => $case->approved_business_value_minor_units,
            'leaver_adjustment_minor_units' => $case->leaver_adjustment_minor_units,
            'final_buyout_value_minor_units' => $case->final_buyout_value_minor_units,
            'currency' => $case->currency,
            'leaver_classification' => $case->leaver_classification?->value,
            'leaver_rule_reference' => $case->leaver_rule_reference,
            'payment_total_minor_units' => $case->payment_total_minor_units,
            'payment_terms_summary' => $case->payment_terms_summary,
            'deposit_minor_units' => $case->deposit_minor_units,
            'installment_minor_units' => $case->installment_minor_units,
            'installment_count' => $case->installment_count,
            'payment_frequency' => $case->payment_frequency,
            'first_payment_date' => $case->first_payment_date?->toDateString(),
            'final_payment_date' => $case->final_payment_date?->toDateString(),
            'interest_terms' => $case->interest_terms,
            'security_terms' => $case->security_terms,
            'late_payment_rule' => $case->late_payment_rule,
            'affordability_status' => $case->affordability_status,
            'alternative_payment_structure' => $case->alternative_payment_structure,
            'governance_decision_type' => $case->governance_decision_type,
            'effective_from' => $case->effective_from?->toAtomString(),
            'settlement_status' => $case->settlement_status,
            'settled_at' => $case->settled_at?->toAtomString(),
            'completed_at' => $case->completed_at?->toAtomString(),
            'status' => $case->status->value,
            'revision' => (int) $case->revision,
            'created_at' => $case->created_at?->toAtomString(),
            'share_positions' => DB::table('exit_share_positions')
                ->where('business_id', $businessId)
                ->where('exit_case_id', $caseId)
                ->orderBy('share_class_name')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
            'requirements' => DB::table('exit_requirements')
                ->where('business_id', $businessId)
                ->where('exit_case_id', $caseId)
                ->orderBy('recorded_at')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
            'finance_links' => $financeLinks,
            'governance_submission' => $submission === null
                ? null
                : [
                    'formal_record_version_id' => (string) $submission->formal_record_version_id,
                    'proposal_version_id' => (string) $submission->proposal_version_id,
                    'decision_type' => (string) $submission->decision_type,
                    'decision_id' => $submission->decision_id,
                    'formal_record_state' => $formalState,
                    'effected_at' => $submission->effected_at,
                ],
        ];
    }
}
