<?php

declare(strict_types=1);

namespace Tests\Feature\Rewards;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Finance\F6CFixtureSupport;
use Tests\TestCase;

require_once dirname(__DIR__).'/Finance/F6CFinancePolicyTest.php';

final class F6CRewardsDatabaseInvariantTest extends TestCase
{
    use F6CFixtureSupport;
    use RefreshDatabase;

    public function test_f6c_reward_and_distribution_tables_and_guards_exist(): void
    {
        foreach ([
            'reward_policy_versions',
            'reward_role_compensation_rules',
            'reward_reimbursement_rules',
            'reward_bonus_rules',
            'reward_loan_repayment_rules',
            'reward_distribution_rules',
            'distribution_runs',
            'distribution_run_lines',
            'distribution_run_submissions',
            'distribution_run_payment_links',
            'reward_payment_links',
        ] as $table) {
            self::assertTrue(DB::getSchemaBuilder()->hasTable($table), $table);
        }

        foreach ([
            'reward_policy_header_mutable',
            'reward_payment_link_binding',
            'reward_role_comp_binding',
            'dist_run_binding',
            'dist_line_binding',
            'dist_submission_binding',
            'dist_payment_link_binding',
            'dist_run_history',
            'dist_line_history',
        ] as $trigger) {
            self::assertSame(
                1,
                (int) DB::selectOne(
                    'SELECT COUNT(*)::int AS count FROM pg_trigger WHERE tgname = ? AND NOT tgisinternal',
                    [$trigger],
                )->count,
                $trigger,
            );
        }
    }

    public function test_rewards_tables_do_not_become_canonical_ownership_source(): void
    {
        foreach ([
            'reward_policy_versions',
            'reward_role_compensation_rules',
            'distribution_runs',
            'distribution_run_lines',
        ] as $table) {
            foreach ([
                'ownership_percentage',
                'ownership_percent',
                'canonical_ownership_percentage',
            ] as $column) {
                self::assertFalse(
                    DB::getSchemaBuilder()->hasColumn($table, $column),
                    $table.'.'.$column.' must not exist.',
                );
            }
        }
    }

    public function test_frozen_reward_policy_header_is_immutable(): void
    {
        $context = $this->f6cContext();
        $this->f6cEffectiveFinance($context);
        $partnerId = $this->f6cPartner($context);
        $reward = $this->f6cEffectiveReward($context, $partnerId);

        DB::beginTransaction();
        try {
            DB::table('reward_policy_versions')
                ->where(
                    'formal_record_version_id',
                    $reward['formal_record_version_id'],
                )
                ->update(['payment_frequency' => 'Silent overwrite']);
            DB::rollBack();
            self::fail('Frozen Reward Policy mutation must be rejected.');
        } catch (QueryException) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->addToAssertionCount(1);
        }
    }
}
