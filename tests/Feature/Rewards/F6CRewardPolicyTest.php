<?php

declare(strict_types=1);

namespace Tests\Feature\Rewards;

use App\Application\Rewards\RewardPolicyWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Finance\F6CFixtureSupport;
use Tests\TestCase;

require_once dirname(__DIR__).'/Finance/F6CFinancePolicyTest.php';

final class F6CRewardPolicyTest extends TestCase
{
    use F6CFixtureSupport;
    use RefreshDatabase;

    public function test_reward_policy_binds_compensation_to_operations_and_keeps_ownership_out_of_salary(): void
    {
        $context = $this->f6cContext();
        $this->f6cEffectiveFinance($context);
        $partnerId = $this->f6cPartner($context);

        $created = $this->app->make(RewardPolicyWorkflow::class)->createDraft(
            $context['user'],
            $context['business'],
            $this->f6cRewardPayload($context, $partnerId),
            now()->subMinute(),
        );

        self::assertNotNull($created);
        $this->assertDatabaseHas('reward_role_compensation_rules', [
            'formal_record_version_id' => $created['formal_record_version_id'],
            'operations_formal_record_version_id' => $context['operations_version_id'],
            'operations_role_id' => $context['role_id'],
            'partner_id' => $partnerId,
            'membership_id' => $context['membership']->getKey(),
            'compensation_type' => 'salary',
        ]);

        foreach ([
            'reward_role_compensation_rules',
            'reward_policy_versions',
            'finance_payments',
        ] as $table) {
            self::assertFalse(
                DB::getSchemaBuilder()->hasColumn($table, 'ownership_percentage'),
                $table.' must not copy canonical ownership percentage.',
            );
        }
    }

    public function test_reimbursement_validity_and_entitlement_timing_have_one_canonical_owner_each(): void
    {
        $context = $this->f6cContext();
        $this->f6cEffectiveFinance($context);
        $partnerId = $this->f6cPartner($context);

        $created = $this->app->make(RewardPolicyWorkflow::class)->createDraft(
            $context['user'],
            $context['business'],
            $this->f6cRewardPayload($context, $partnerId),
            now()->subMinute(),
        );

        self::assertNotNull($created);

        self::assertFalse(
            DB::getSchemaBuilder()->hasColumn(
                'finance_expense_procurement_rules',
                'reimbursement_deadline_days',
            ),
        );

        self::assertTrue(
            DB::getSchemaBuilder()->hasColumn(
                'reward_reimbursement_rules',
                'reimbursement_deadline_days',
            ),
        );

        $rewardRule = DB::table('reward_reimbursement_rules')
            ->where('formal_record_version_id', $created['formal_record_version_id'])
            ->sole();

        self::assertSame(30, (int) $rewardRule->reimbursement_deadline_days);
        self::assertNotNull($rewardRule->finance_expense_procurement_rule_id);
    }
}
