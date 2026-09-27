<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once __DIR__.'/F6CFinancePolicyTest.php';

final class F6CFinanceDatabaseInvariantTest extends TestCase
{
    use F6CFixtureSupport;
    use RefreshDatabase;

    public function test_f6c_finance_tables_and_protection_triggers_exist(): void
    {
        foreach ([
            'finance_policy_versions',
            'finance_bank_account_references',
            'finance_bank_access_assignments',
            'finance_payment_authority_rules',
            'finance_reconciliation_reviews',
            'finance_payments',
            'finance_payment_submissions',
            'finance_payment_evidence_refs',
            'finance_exceptions',
            'finance_exception_reviews',
        ] as $table) {
            self::assertTrue(DB::getSchemaBuilder()->hasTable($table), $table);
        }

        foreach ([
            'fin_policy_header_mutable',
            'fin_payment_binding',
            'fin_payment_submission_binding',
            'fin_payment_history',
            'fin_payment_evidence_history',
            'fin_reconciliation_history',
            'fin_exception_history',
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

    public function test_frozen_finance_policy_header_cannot_be_overwritten(): void
    {
        $context = $this->f6cContext();
        $finance = $this->f6cEffectiveFinance($context);

        DB::beginTransaction();
        try {
            DB::table('finance_policy_versions')
                ->where(
                    'formal_record_version_id',
                    $finance['formal_record_version_id'],
                )
                ->update(['fiscal_period' => 'Silent rewrite']);
            DB::rollBack();
            self::fail('Frozen Finance Policy mutation must be rejected.');
        } catch (QueryException) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->addToAssertionCount(1);
        }
    }
}
