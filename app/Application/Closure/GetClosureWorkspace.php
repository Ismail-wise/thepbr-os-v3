<?php

declare(strict_types=1);

namespace App\Application\Closure;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Application\Partnership\PartnershipActorContext;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Closure\ClosureCase;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class GetClosureWorkspace
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
            CapabilityCatalog::CLOSURE_VIEW,
        )) {
            return null;
        }

        $canManage = $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::CLOSURE_MANAGE,
        );
        $canViewFinance = $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::FINANCE_VIEW,
        );
        $businessId = (string) $business->getKey();

        $cases = ClosureCase::query()
            ->where('business_id', $businessId)
            ->orderByDesc('created_at')
            ->get()
            ->filter(function (ClosureCase $case) use (
                $user,
                $business,
            ): bool {
                return $this->authorize->decide(
                    $user,
                    $business,
                    $business,
                    new Capability(CapabilityCatalog::CLOSURE_VIEW),
                    ClosureCase::class,
                    (string) $case->getKey(),
                )->allowed;
            })
            ->map(
                fn (ClosureCase $case): array => $this->caseProjection(
                    $businessId,
                    $case,
                    $canViewFinance,
                ),
            )
            ->values()
            ->all();

        return [
            'business' => [
                'id' => $businessId,
                'name' => (string) $business->name,
                'workspace_status' => $business->workspace_status->value,
            ],
            'permissions' => [
                'view' => true,
                'manage' => $canManage,
                'finance_view' => $canViewFinance,
            ],
            'cases' => $cases,
        ];
    }

    /** @return array<string,mixed> */
    private function caseProjection(
        string $businessId,
        ClosureCase $case,
        bool $canViewFinance,
    ): array {
        $caseId = (string) $case->getKey();

        $submission = DB::table('closure_governance_submissions')
            ->where('business_id', $businessId)
            ->where('closure_case_id', $caseId)
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

        $financeLinks = [];

        if ($canViewFinance) {
            $financeLinks = DB::table('closure_finance_links as link')
                ->join(
                    'finance_payments as payment',
                    function ($join): void {
                        $join->on(
                            'payment.id',
                            '=',
                            'link.finance_payment_id',
                        )->on(
                            'payment.business_id',
                            '=',
                            'link.business_id',
                        );
                    },
                )
                ->where('link.business_id', $businessId)
                ->where('link.closure_case_id', $caseId)
                ->orderBy('link.linked_at')
                ->get([
                    'link.id',
                    'link.closure_claim_id',
                    'link.finance_payment_id',
                    'link.purpose',
                    'link.linked_at',
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
            'trigger' => (string) $case->trigger,
            'trigger_detail' => $case->trigger_detail,
            'jurisdiction_reference' => (string) $case->jurisdiction_reference,
            'legal_entity_reference' => $case->legal_entity_reference,
            'governance_decision_type' => (string) $case->governance_decision_type,
            'intended_legal_closure_at' => $case->intended_legal_closure_at?->toAtomString(),
            'residual_distribution_minor_units' => $case->residual_distribution_minor_units,
            'currency' => $case->currency,
            'residual_distribution_basis' => $case->residual_distribution_basis,
            'residual_distribution_status' => (string) $case->residual_distribution_status,
            'legal_closed_at' => $case->legal_closed_at?->toAtomString(),
            'workspace_closed_at' => $case->workspace_closed_at?->toAtomString(),
            'status' => $case->status->value,
            'revision' => (int) $case->revision,
            'created_at' => $case->created_at?->toAtomString(),
            'claims' => DB::table('closure_claims')
                ->where('business_id', $businessId)
                ->where('closure_case_id', $caseId)
                ->orderBy('claim_reference')
                ->get([
                    'id',
                    'claim_reference',
                    'claim_type',
                    'claimant_reference',
                    'description',
                    'amount_minor_units',
                    'currency',
                    'required',
                    'legal_priority_reference',
                    'source_type',
                    'source_id',
                    'external_source_reference',
                    'status',
                    'revision',
                    'created_at',
                ])
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
            'requirements' => DB::table('closure_requirements')
                ->where('business_id', $businessId)
                ->where('closure_case_id', $caseId)
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
                    'authorized_at' => $submission->authorized_at,
                    'effected_at' => $submission->effected_at,
                ],
        ];
    }
}
