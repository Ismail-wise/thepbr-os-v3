<?php

declare(strict_types=1);

namespace App\Application\Rewards;

use App\Application\Finance\FinancePaymentWorkflow;
use App\Application\Finance\ResolveFinanceControl;
use App\Application\Governance\GovernanceActorContext;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Rewards\Enums\RewardPaymentType;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RewardPaymentWorkflow
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly ResolveRewardPolicy $rewards,
        private readonly ResolveFinanceControl $financeControls,
        private readonly FinancePaymentWorkflow $financePayments,
    ) {}

    /** @param array<string,mixed> $payload */
    public function create(
        User $user,
        Business $business,
        RewardPaymentType $type,
        string $ruleId,
        array $payload,
    ): ?string {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::REWARDS_MANAGE,
        ) === null) {
            return null;
        }

        $policy = $this->rewards->currentPolicy($business);

        if ($policy === null) {
            return null;
        }

        $resolved = match ($type) {
            RewardPaymentType::SalaryServiceFee => $this->roleCompensation(
                $business,
                $policy['formal_record_version_id'],
                $ruleId,
            ),
            RewardPaymentType::Reimbursement => $this->reimbursement(
                $business,
                $policy['formal_record_version_id'],
                $ruleId,
                $payload,
            ),
            RewardPaymentType::Bonus => $this->bonus(
                $business,
                $policy['formal_record_version_id'],
                $ruleId,
                $payload,
            ),
            RewardPaymentType::LoanRepayment => $this->loan(
                $business,
                $policy['formal_record_version_id'],
                $ruleId,
            ),
            RewardPaymentType::ProfitDistribution => null,
        };

        if ($resolved === null) {
            return null;
        }

        $financeRule = $this->financeControls->paymentRule(
            $business,
            $resolved['transaction_type'],
            $resolved['amount_minor_units'],
        );

        if (
            $financeRule === null
            || (string) $financeRule->governance_decision_type
                !== $resolved['governance_decision_type']
        ) {
            throw new InvalidArgumentException(
                'Reward rule and Finance Payment Authority Rule must use the same Governance decision type.',
            );
        }

        $paymentId = $this->financePayments->createDraft(
            $user,
            $business,
            [
                'transaction_type' => $resolved['transaction_type'],
                'amount_minor_units' => $resolved['amount_minor_units'],
                'currency' => $policy['header']->currency,
                'bank_account_reference_id' => (string) ($payload['bank_account_reference_id'] ?? ''),
                'payee_reference' => $resolved['payee_reference'],
                'description' => $resolved['description'],
                'related_party' => true,
            ],
        );

        if ($paymentId === null) {
            return null;
        }

        DB::table('reward_payment_links')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'finance_payment_id' => $paymentId,
            'reward_policy_formal_record_version_id' => $policy['formal_record_version_id'],
            'reward_payment_type' => $type->value,
            'partner_id' => $resolved['partner_id'],
            'role_compensation_rule_id' => $type === RewardPaymentType::SalaryServiceFee ? $ruleId : null,
            'reimbursement_rule_id' => $type === RewardPaymentType::Reimbursement ? $ruleId : null,
            'bonus_rule_id' => $type === RewardPaymentType::Bonus ? $ruleId : null,
            'loan_repayment_rule_id' => $type === RewardPaymentType::LoanRepayment ? $ruleId : null,
            'entitlement_source_date' => $type === RewardPaymentType::Reimbursement
                ? $resolved['entitlement_source_date']
                : null,
            'created_at' => now(),
        ]);

        return $paymentId;
    }

    private function roleCompensation(
        Business $business,
        string $policyVersionId,
        string $ruleId,
    ): ?array {
        $rule = DB::table('reward_role_compensation_rules')
            ->where('business_id', $business->getKey())
            ->where('formal_record_version_id', $policyVersionId)
            ->where('id', $ruleId)
            ->where('status', 'active')
            ->first();

        if ($rule === null || ! $this->activeOnDate(
            (string) $rule->start_date,
            $rule->end_date === null ? null : (string) $rule->end_date,
            CarbonImmutable::today(),
        )) {
            return null;
        }

        return [
            'transaction_type' => 'salary_service_fee',
            'amount_minor_units' => (int) $rule->amount_minor_units,
            'governance_decision_type' => (string) $rule->governance_decision_type,
            'partner_id' => (string) $rule->partner_id,
            'payee_reference' => $this->partnerName($business, (string) $rule->partner_id),
            'description' => ucfirst(str_replace('_', ' ', (string) $rule->compensation_type))
                .' for approved Operations role.',
        ];
    }

    /** @param array<string,mixed> $payload */
    private function reimbursement(
        Business $business,
        string $policyVersionId,
        string $ruleId,
        array $payload,
    ): ?array {
        $rule = DB::table('reward_reimbursement_rules')
            ->where('business_id', $business->getKey())
            ->where('formal_record_version_id', $policyVersionId)
            ->where('id', $ruleId)
            ->first();

        $partnerId = trim((string) ($payload['partner_id'] ?? ''));
        $amount = $this->positiveMoney($payload['amount_minor_units'] ?? null);
        $expenseDateRaw = trim((string) ($payload['expense_date'] ?? ''));

        if (
            $rule === null
            || ! $this->partnerExists($business, $partnerId)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDateRaw) !== 1
        ) {
            return null;
        }

        $expenseDate = CarbonImmutable::parse($expenseDateRaw)->startOfDay();
        $today = CarbonImmutable::today();
        $deadline = $expenseDate->addDays(
            (int) $rule->reimbursement_deadline_days,
        );

        if ($expenseDate->isAfter($today) || $today->isAfter($deadline)) {
            return null;
        }

        $financeRule = DB::table('finance_expense_procurement_rules')
            ->where('business_id', $business->getKey())
            ->where('id', $rule->finance_expense_procurement_rule_id)
            ->where('control_type', 'reimbursement')
            ->first();

        if (
            $financeRule === null
            || (bool) $financeRule->prohibited
            || (
                $financeRule->amount_min_minor_units !== null
                && $amount < (int) $financeRule->amount_min_minor_units
            )
            || (
                $financeRule->amount_max_minor_units !== null
                && $amount > (int) $financeRule->amount_max_minor_units
            )
        ) {
            return null;
        }

        return [
            'transaction_type' => 'reimbursement',
            'amount_minor_units' => $amount,
            'governance_decision_type' => (string) $rule->governance_decision_type,
            'partner_id' => $partnerId,
            'payee_reference' => $this->partnerName($business, $partnerId),
            'description' => 'Approved business-expense reimbursement.',
            'entitlement_source_date' => $expenseDate->toDateString(),
        ];
    }

    /** @param array<string,mixed> $payload */
    private function bonus(
        Business $business,
        string $policyVersionId,
        string $ruleId,
        array $payload,
    ): ?array {
        $rule = DB::table('reward_bonus_rules')
            ->where('business_id', $business->getKey())
            ->where('formal_record_version_id', $policyVersionId)
            ->where('id', $ruleId)
            ->where('status', 'active')
            ->first();

        if ($rule === null) {
            return null;
        }

        $partnerId = $rule->partner_id === null
            ? trim((string) ($payload['partner_id'] ?? ''))
            : (string) $rule->partner_id;

        if (! $this->partnerExists($business, $partnerId)) {
            return null;
        }

        $amount = $rule->approved_amount_minor_units === null
            ? $this->positiveMoney($payload['amount_minor_units'] ?? null)
            : (int) $rule->approved_amount_minor_units;

        if ($rule->cap_minor_units !== null && $amount > (int) $rule->cap_minor_units) {
            return null;
        }

        return [
            'transaction_type' => 'bonus',
            'amount_minor_units' => $amount,
            'governance_decision_type' => (string) $rule->governance_decision_type,
            'partner_id' => $partnerId,
            'payee_reference' => $this->partnerName($business, $partnerId),
            'description' => 'Bonus under approved Reward Policy rule.',
        ];
    }

    private function loan(
        Business $business,
        string $policyVersionId,
        string $ruleId,
    ): ?array {
        $rule = DB::table('reward_loan_repayment_rules')
            ->where('business_id', $business->getKey())
            ->where('formal_record_version_id', $policyVersionId)
            ->where('id', $ruleId)
            ->where('status', 'active')
            ->first();

        if ($rule === null || ! $this->activeOnDate(
            (string) $rule->start_date,
            $rule->end_date === null ? null : (string) $rule->end_date,
            CarbonImmutable::today(),
        )) {
            return null;
        }

        return [
            'transaction_type' => 'loan_repayment',
            'amount_minor_units' => (int) $rule->scheduled_amount_minor_units,
            'governance_decision_type' => (string) $rule->governance_decision_type,
            'partner_id' => (string) $rule->partner_id,
            'payee_reference' => $this->partnerName($business, (string) $rule->partner_id),
            'description' => 'Loan repayment: '.(string) $rule->loan_reference,
        ];
    }

    private function partnerExists(Business $business, string $partnerId): bool
    {
        return $partnerId !== ''
            && DB::table('partners')
                ->where('business_id', $business->getKey())
                ->where('id', $partnerId)
                ->exists();
    }

    private function partnerName(Business $business, string $partnerId): string
    {
        return (string) DB::table('partners')
            ->where('business_id', $business->getKey())
            ->where('id', $partnerId)
            ->value('display_name');
    }

    private function activeOnDate(
        string $startDate,
        ?string $endDate,
        CarbonImmutable $date,
    ): bool {
        $start = CarbonImmutable::parse($startDate)->startOfDay();

        if ($date->isBefore($start)) {
            return false;
        }

        return $endDate === null
            || ! $date->isAfter(
                CarbonImmutable::parse($endDate)->startOfDay(),
            );
    }

    private function positiveMoney(mixed $value): int
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            throw new InvalidArgumentException(
                'Reward Payment amount must be positive minor units.',
            );
        }

        return (int) $value;
    }
}
