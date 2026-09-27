<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Application\Finance\FinanceExceptionWorkflow;
use App\Application\Finance\FinanceReconciliationWorkflow;
use App\Domain\Access\CapabilityCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once __DIR__.'/F6CFinancePolicyTest.php';

final class F6CReconciliationExceptionTest extends TestCase
{
    use F6CFixtureSupport;
    use RefreshDatabase;

    public function test_completed_reconciliation_is_immutable_canonical_finance_evidence(): void
    {
        $context = $this->f6cContext();
        $this->f6cEffectiveFinance($context);
        $workflow = $this->app->make(FinanceReconciliationWorkflow::class);

        $id = $workflow->create(
            $context['user'],
            $context['business'],
            [
                'period_start' => '2026-09-01',
                'period_end' => '2026-09-30',
                'currency' => 'USD',
                'opening_cash_minor_units' => 100000,
                'inflows_minor_units' => 50000,
                'outflows_minor_units' => 20000,
                'closing_cash_minor_units' => 130000,
                'approved_net_profit_minor_units' => 30000,
                'tax_due_minor_units' => 5000,
                'debt_due_minor_units' => 2500,
                'cash_available_minor_units' => 122500,
                'unreconciled_items_count' => 0,
                'notes' => 'F6C reconciliation.',
            ],
        );

        self::assertNotNull($id);
        self::assertSame(
            'completed',
            $workflow->complete(
                $context['user'],
                $context['business'],
                $id,
                1,
            ),
        );

        DB::beginTransaction();
        try {
            DB::table('finance_reconciliation_reviews')
                ->where('id', $id)
                ->update(['approved_net_profit_minor_units' => 1]);
            DB::rollBack();
            self::fail('Completed reconciliation mutation must be rejected.');
        } catch (QueryException) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->addToAssertionCount(1);
        }
    }

    public function test_independent_reviewer_can_block_compensating_exception(): void
    {
        $context = $this->f6cContext();
        $this->f6cEffectiveFinance($context);
        $this->f6cGrant(
            $context['business'],
            $context['payer'],
            CapabilityCatalog::FINANCE_MANAGE,
            [],
        );

        $workflow = $this->app->make(FinanceExceptionWorkflow::class);
        $id = $workflow->open(
            $context['user'],
            $context['business'],
            'segregation_of_duties',
            'Small-business role overlap needs review.',
            null,
            null,
            true,
        );

        self::assertNotNull($id);
        self::assertTrue($workflow->completeCompensatingReview(
            $context['payer_user'],
            $context['business'],
            $id,
            'blocked',
            'Independent reviewer blocked payment.',
        ));

        $this->assertDatabaseHas('finance_exception_reviews', [
            'finance_exception_id' => $id,
            'reviewer_membership_id' => $context['payer']->getKey(),
            'result' => 'blocked',
        ]);
    }
}
