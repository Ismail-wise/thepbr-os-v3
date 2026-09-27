<?php

declare(strict_types=1);

namespace App\Application\Finance;

use App\Application\Governance\GovernanceActorContext;
use App\Domain\Access\CapabilityCatalog;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class GetFinanceWorkspace
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly ResolveFinanceControl $controls,
    ) {}

    /** @return array<string,mixed>|null */
    public function execute(User $user, Business $business): ?array
    {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::FINANCE_VIEW,
        ) === null) {
            return null;
        }

        $policy = $this->controls->currentPolicy($business);
        $current = null;

        if ($policy !== null) {
            $versionId = $policy['formal_record_version_id'];
            $current = [
                'formal_record_version_id' => $versionId,
                'header' => $policy['header'],
                'bank_accounts' => DB::table('finance_bank_account_references')
                    ->where('business_id', $business->getKey())
                    ->where('formal_record_version_id', $versionId)
                    ->orderBy('bank_name')->get(),
                'bank_access' => DB::table('finance_bank_access_assignments')
                    ->where('business_id', $business->getKey())
                    ->where('formal_record_version_id', $versionId)
                    ->orderBy('bank_account_reference_id')->get(),
                'payment_rules' => DB::table('finance_payment_authority_rules')
                    ->where('business_id', $business->getKey())
                    ->where('formal_record_version_id', $versionId)
                    ->orderBy('sequence')->get(),
                'expense_rules' => DB::table('finance_expense_procurement_rules')
                    ->where('business_id', $business->getKey())
                    ->where('formal_record_version_id', $versionId)
                    ->orderBy('sequence')->get(),
            ];
        }

        $versions = DB::table('formal_record_versions as v')
            ->join('formal_record_families as f', function ($join): void {
                $join->on('f.id', '=', 'v.formal_record_family_id')
                    ->on('f.business_id', '=', 'v.business_id');
            })
            ->where('v.business_id', $business->getKey())
            ->where('f.record_type', 'finance_policy')
            ->orderByDesc('v.version_number')
            ->get([
                'v.id',
                'v.version_number',
                'v.revision',
                'v.frozen_at',
                'v.effective_from',
            ])
            ->map(function (object $version) use ($business): object {
                $version->state = DB::table('record_version_state_transitions')
                    ->where('business_id', $business->getKey())
                    ->where('formal_record_version_id', $version->id)
                    ->orderByDesc('sequence')
                    ->value('to_state');

                return $version;
            });

        return [
            'business' => [
                'id' => (string) $business->getKey(),
                'name' => (string) $business->name,
            ],
            'permissions' => [
                'manage' => $this->actorContext->membership(
                    $user,
                    $business,
                    CapabilityCatalog::FINANCE_MANAGE,
                ) !== null,
                'pay' => $this->actorContext->membership(
                    $user,
                    $business,
                    CapabilityCatalog::FINANCE_PAY,
                ) !== null,
            ],
            'current' => $current,
            'versions' => $versions,
            'payments' => DB::table('finance_payments')
                ->where('business_id', $business->getKey())
                ->orderByDesc('created_at')->limit(100)->get(),
            'reconciliations' => DB::table('finance_reconciliation_reviews')
                ->where('business_id', $business->getKey())
                ->orderByDesc('period_end')->limit(50)->get(),
            'exceptions' => DB::table('finance_exceptions')
                ->where('business_id', $business->getKey())
                ->orderByDesc('opened_at')->limit(100)->get(),
            'exception_reviews' => DB::table('finance_exception_reviews')
                ->where('business_id', $business->getKey())
                ->orderByDesc('reviewed_at')->limit(100)->get(),
            'memberships' => DB::table('memberships as m')
                ->join('users as u', 'u.id', '=', 'm.user_id')
                ->where('m.business_id', $business->getKey())
                ->where('m.access_status', 'active')
                ->orderBy('u.email')
                ->get(['m.id', 'u.email']),
            'operations_roles' => $this->operationsRoles($business),
        ];
    }

    private function operationsRoles(Business $business)
    {
        $versionId = DB::table('record_family_effective_heads as h')
            ->join('formal_record_versions as v', 'v.id', '=', 'h.formal_record_version_id')
            ->join('formal_record_families as f', 'f.id', '=', 'v.formal_record_family_id')
            ->where('h.business_id', $business->getKey())
            ->where('f.business_id', $business->getKey())
            ->where('f.record_type', 'operations_register')
            ->value('v.id');

        return $versionId === null
            ? collect()
            : DB::table('operations_roles')
                ->where('business_id', $business->getKey())
                ->where('formal_record_version_id', $versionId)
                ->orderBy('role_key')
                ->get(['id', 'role_key', 'name']);
    }
}
