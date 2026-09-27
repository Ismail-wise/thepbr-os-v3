<?php

declare(strict_types=1);

namespace Tests\Feature\Rewards;

use App\Application\Rewards\RewardPaymentWorkflow;
use App\Domain\Rewards\Enums\RewardPaymentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Finance\F6CFixtureSupport;
use Tests\TestCase;

require_once dirname(__DIR__).'/Finance/F6CFinancePolicyTest.php';

final class F6CRewardPaymentTest extends TestCase
{
    use F6CFixtureSupport;
    use RefreshDatabase;

    public function test_salary_payment_uses_role_compensation_rule_not_ownership(): void
    {
        $context = $this->f6cContext();
        $finance = $this->f6cEffectiveFinance($context);
        $partnerId = $this->f6cPartner($context);
        $reward = $this->f6cEffectiveReward($context, $partnerId);

        $ruleId = (string) DB::table('reward_role_compensation_rules')
            ->where(
                'formal_record_version_id',
                $reward['formal_record_version_id'],
            )
            ->value('id');

        $paymentId = $this->app->make(RewardPaymentWorkflow::class)->create(
            $context['user'],
            $context['business'],
            RewardPaymentType::SalaryServiceFee,
            $ruleId,
            ['bank_account_reference_id' => $finance['bank_id']],
        );

        self::assertNotNull($paymentId);
        $this->assertDatabaseHas('reward_payment_links', [
            'finance_payment_id' => $paymentId,
            'reward_payment_type' => 'salary_service_fee',
            'partner_id' => $partnerId,
            'role_compensation_rule_id' => $ruleId,
        ]);
        $this->assertDatabaseHas('finance_payments', [
            'id' => $paymentId,
            'transaction_type' => 'salary_service_fee',
            'amount_minor_units' => 90000,
        ]);
    }

    public function test_bonus_payment_preserves_exact_operations_role_and_kpi_binding(): void
    {
        $context = $this->f6cContext();
        $finance = $this->f6cEffectiveFinance($context);
        $partnerId = $this->f6cPartner($context);
        $reward = $this->f6cEffectiveReward($context, $partnerId);

        $rule = DB::table('reward_bonus_rules')
            ->where(
                'formal_record_version_id',
                $reward['formal_record_version_id'],
            )
            ->sole();

        self::assertSame($context['role_id'], (string) $rule->operations_role_id);
        self::assertSame(
            $context['operations_kpi_id'],
            (string) $rule->operations_kpi_id,
        );

        $paymentId = $this->app->make(RewardPaymentWorkflow::class)->create(
            $context['user'],
            $context['business'],
            RewardPaymentType::Bonus,
            (string) $rule->id,
            ['bank_account_reference_id' => $finance['bank_id']],
        );

        self::assertNotNull($paymentId);
        $this->assertDatabaseHas('reward_payment_links', [
            'finance_payment_id' => $paymentId,
            'reward_payment_type' => 'bonus',
            'partner_id' => $partnerId,
            'bonus_rule_id' => (string) $rule->id,
        ]);
        $this->assertDatabaseHas('finance_payments', [
            'id' => $paymentId,
            'transaction_type' => 'bonus',
            'amount_minor_units' => 25000,
        ]);
    }

    public function test_loan_repayment_remains_a_distinct_reward_payment_type(): void
    {
        $context = $this->f6cContext();
        $finance = $this->f6cEffectiveFinance($context);
        $partnerId = $this->f6cPartner($context);
        $reward = $this->f6cEffectiveReward($context, $partnerId);

        $ruleId = (string) DB::table('reward_loan_repayment_rules')
            ->where(
                'formal_record_version_id',
                $reward['formal_record_version_id'],
            )
            ->value('id');

        $paymentId = $this->app->make(RewardPaymentWorkflow::class)->create(
            $context['user'],
            $context['business'],
            RewardPaymentType::LoanRepayment,
            $ruleId,
            ['bank_account_reference_id' => $finance['bank_id']],
        );

        self::assertNotNull($paymentId);
        $this->assertDatabaseHas('reward_payment_links', [
            'finance_payment_id' => $paymentId,
            'reward_payment_type' => 'loan_repayment',
            'partner_id' => $partnerId,
            'loan_repayment_rule_id' => $ruleId,
        ]);
        $this->assertDatabaseHas('finance_payments', [
            'id' => $paymentId,
            'transaction_type' => 'loan_repayment',
            'amount_minor_units' => 30000,
        ]);
    }

    public function test_reimbursement_deadline_is_enforced_by_reward_policy_and_source_date_is_preserved(): void
    {
        $context = $this->f6cContext();
        $finance = $this->f6cEffectiveFinance($context);
        $partnerId = $this->f6cPartner($context);
        $reward = $this->f6cEffectiveReward($context, $partnerId);

        $ruleId = (string) DB::table('reward_reimbursement_rules')
            ->where(
                'formal_record_version_id',
                $reward['formal_record_version_id'],
            )
            ->value('id');

        $workflow = $this->app->make(RewardPaymentWorkflow::class);

        self::assertNull($workflow->create(
            $context['user'],
            $context['business'],
            RewardPaymentType::Reimbursement,
            $ruleId,
            [
                'bank_account_reference_id' => $finance['bank_id'],
                'partner_id' => $partnerId,
                'amount_minor_units' => 15000,
                'expense_date' => now()->subDays(31)->toDateString(),
            ],
        ));

        $paymentId = $workflow->create(
            $context['user'],
            $context['business'],
            RewardPaymentType::Reimbursement,
            $ruleId,
            [
                'bank_account_reference_id' => $finance['bank_id'],
                'partner_id' => $partnerId,
                'amount_minor_units' => 15000,
                'expense_date' => now()->toDateString(),
            ],
        );

        self::assertNotNull($paymentId);
        $this->assertDatabaseHas('reward_payment_links', [
            'finance_payment_id' => $paymentId,
            'reward_payment_type' => 'reimbursement',
            'entitlement_source_date' => now()->toDateString(),
        ]);
    }
}
