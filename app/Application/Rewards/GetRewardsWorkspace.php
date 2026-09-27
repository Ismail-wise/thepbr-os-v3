<?php

declare(strict_types=1);

namespace App\Application\Rewards;

use App\Application\Governance\GovernanceActorContext;
use App\Domain\Access\CapabilityCatalog;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class GetRewardsWorkspace
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly ResolveRewardPolicy $policies,
    ) {}

    /** @return array<string,mixed>|null */
    public function execute(User $user, Business $business): ?array
    {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::REWARDS_VIEW,
        ) === null) {
            return null;
        }

        $policy = $this->policies->currentPolicy($business);
        $current = null;

        if ($policy !== null) {
            $versionId = $policy['formal_record_version_id'];
            $current = [
                'formal_record_version_id' => $versionId,
                'header' => $policy['header'],
                'role_compensation_rules' => DB::table('reward_role_compensation_rules')
                    ->where('business_id', $business->getKey())
                    ->where('formal_record_version_id', $versionId)->get(),
                'reimbursement_rules' => DB::table('reward_reimbursement_rules')
                    ->where('business_id', $business->getKey())
                    ->where('formal_record_version_id', $versionId)->get(),
                'bonus_rules' => DB::table('reward_bonus_rules')
                    ->where('business_id', $business->getKey())
                    ->where('formal_record_version_id', $versionId)->get(),
                'loan_repayment_rules' => DB::table('reward_loan_repayment_rules')
                    ->where('business_id', $business->getKey())
                    ->where('formal_record_version_id', $versionId)->get(),
                'distribution_rule' => $policy['distribution_rule'],
                'distribution_status_rules' => DB::table('reward_distribution_status_rules')
                    ->where('business_id', $business->getKey())
                    ->where('formal_record_version_id', $versionId)->get(),
                'distribution_class_rules' => DB::table('reward_distribution_class_rules')
                    ->where('business_id', $business->getKey())
                    ->where('formal_record_version_id', $versionId)->get(),
            ];
        }

        return [
            'business' => [
                'id' => (string) $business->getKey(),
                'name' => (string) $business->name,
            ],
            'permissions' => [
                'manage' => $this->actorContext->membership(
                    $user,
                    $business,
                    CapabilityCatalog::REWARDS_MANAGE,
                ) !== null,
                'governance_manage' => $this->actorContext->membership(
                    $user,
                    $business,
                    CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
                ) !== null,
            ],
            'current' => $current,
            'versions' => DB::table('formal_record_versions as v')
                ->join('formal_record_families as f', function ($join): void {
                    $join->on('f.id', '=', 'v.formal_record_family_id')
                        ->on('f.business_id', '=', 'v.business_id');
                })
                ->where('v.business_id', $business->getKey())
                ->where('f.record_type', 'reward_policy')
                ->orderByDesc('v.version_number')
                ->get(['v.id', 'v.version_number', 'v.revision', 'v.frozen_at', 'v.effective_from'])
                ->map(function (object $version) use ($business): object {
                    $version->state = DB::table('record_version_state_transitions')
                        ->where('business_id', $business->getKey())
                        ->where('formal_record_version_id', $version->id)
                        ->orderByDesc('sequence')
                        ->value('to_state');

                    return $version;
                }),
            'distribution_runs' => DB::table('distribution_runs')
                ->where('business_id', $business->getKey())
                ->orderByDesc('period_end')->limit(100)->get(),
            'distribution_lines' => DB::table('distribution_run_lines')
                ->where('business_id', $business->getKey())
                ->orderBy('distribution_run_id')
                ->orderBy('partner_id')
                ->get(),
            'distribution_payment_links' => DB::table('distribution_run_payment_links')
                ->where('business_id', $business->getKey())
                ->get(),
            'reward_payments' => DB::table('reward_payment_links as link')
                ->join('finance_payments as payment', function ($join): void {
                    $join->on('payment.id', '=', 'link.finance_payment_id')
                        ->on('payment.business_id', '=', 'link.business_id');
                })
                ->where('link.business_id', $business->getKey())
                ->orderByDesc('payment.created_at')
                ->get([
                    'link.*',
                    'payment.status',
                    'payment.amount_minor_units',
                    'payment.currency',
                    'payment.payee_reference',
                    'payment.paid_at',
                ]),
            'reconciliations' => DB::table('finance_reconciliation_reviews')
                ->where('business_id', $business->getKey())
                ->where('status', 'completed')
                ->orderByDesc('period_end')->limit(50)->get(),
            'partners' => DB::table('partners as p')
                ->leftJoin('partner_membership_links as link', function ($join): void {
                    $join->on('link.partner_id', '=', 'p.id')
                        ->on('link.business_id', '=', 'p.business_id');
                })
                ->where('p.business_id', $business->getKey())
                ->orderBy('p.display_name')
                ->get(['p.id', 'p.display_name', 'p.status', 'link.membership_id']),
            'memberships' => DB::table('memberships as m')
                ->join('users as u', 'u.id', '=', 'm.user_id')
                ->where('m.business_id', $business->getKey())
                ->where('m.access_status', 'active')
                ->orderBy('u.email')
                ->get(['m.id', 'u.email']),
            'operations_roles' => $this->operationsRoles($business, $policy),
            'operations_kpis' => $this->operationsKpis($business, $policy),
            'finance_expense_rules' => $this->financeExpenseRules($business, $policy),
            'bank_accounts' => $this->bankAccounts($business, $policy),
        ];
    }

    private function operationsRoles(Business $business, ?array $policy)
    {
        if ($policy === null) {
            return collect();
        }

        return DB::table('operations_roles')
            ->where('business_id', $business->getKey())
            ->where(
                'formal_record_version_id',
                $policy['header']->operations_formal_record_version_id,
            )
            ->orderBy('role_key')
            ->get(['id', 'role_key', 'name']);
    }

    private function operationsKpis(Business $business, ?array $policy)
    {
        if ($policy === null) {
            return collect();
        }

        return DB::table('operations_kpis')
            ->where('business_id', $business->getKey())
            ->where(
                'formal_record_version_id',
                $policy['header']->operations_formal_record_version_id,
            )
            ->orderBy('name')
            ->get(['id', 'operations_role_id', 'name', 'current_status']);
    }

    private function financeExpenseRules(Business $business, ?array $policy)
    {
        if ($policy === null) {
            return collect();
        }

        return DB::table('finance_expense_procurement_rules')
            ->where('business_id', $business->getKey())
            ->where(
                'formal_record_version_id',
                $policy['header']->finance_policy_formal_record_version_id,
            )
            ->where('control_type', 'reimbursement')
            ->where('prohibited', false)
            ->orderBy('sequence')
            ->get(['id', 'rule_key', 'category']);
    }

    private function bankAccounts(Business $business, ?array $policy)
    {
        if ($policy === null) {
            return collect();
        }

        return DB::table('finance_bank_account_references')
            ->where('business_id', $business->getKey())
            ->where(
                'formal_record_version_id',
                $policy['header']->finance_policy_formal_record_version_id,
            )
            ->where('status', 'active')
            ->orderBy('bank_name')
            ->get(['id', 'bank_name', 'account_name', 'account_reference', 'currency']);
    }
}
