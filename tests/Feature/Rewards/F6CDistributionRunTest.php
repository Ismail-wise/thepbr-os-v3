<?php

declare(strict_types=1);

namespace Tests\Feature\Rewards;

use App\Application\Finance\FinancePaymentWorkflow;
use App\Application\Finance\FinanceReconciliationWorkflow;
use App\Application\Rewards\DistributionRunWorkflow;
use App\Application\Rewards\SimulateDistribution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionClass;
use Tests\Feature\Finance\F6CFixtureSupport;
use Tests\TestCase;

require_once dirname(__DIR__).'/Finance/F6CFinancePolicyTest.php';

final class F6CDistributionRunTest extends TestCase
{
    use F6CFixtureSupport;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_distribution_scenario_is_pure_calculation_and_writes_no_live_truth(): void
    {
        $context = $this->f6cContext();

        $before = [
            'runs' => DB::table('distribution_runs')->count(),
            'lines' => DB::table('distribution_run_lines')->count(),
            'payments' => DB::table('finance_payments')->count(),
            'records' => DB::table('formal_record_versions')->count(),
        ];

        $result = $this->app->make(SimulateDistribution::class)->execute(
            $context['user'],
            $context['business'],
            [
                'approved_net_profit_minor_units' => 100000,
                'tax_due_minor_units' => 10000,
                'debt_due_minor_units' => 5000,
                'required_reserve_minor_units' => 15000,
                'reinvestment_minor_units' => 20000,
                'adjustments_minor_units' => 0,
                'weights' => [
                    'partner-a' => '2.00000000',
                    'partner-b' => '1.00000000',
                ],
            ],
        );

        self::assertNotNull($result);
        self::assertTrue($result['scenario_only']);
        self::assertSame(50000, $result['waterfall']['distributable_profit_minor_units']);
        self::assertSame(50000, array_sum($result['allocations']));

        self::assertSame($before['runs'], DB::table('distribution_runs')->count());
        self::assertSame($before['lines'], DB::table('distribution_run_lines')->count());
        self::assertSame($before['payments'], DB::table('finance_payments')->count());
        self::assertSame($before['records'], DB::table('formal_record_versions')->count());
    }

    public function test_distribution_runtime_binds_exact_sources_and_effects_only_after_completed_payments(): void
    {
        Carbon::setTestNow(Carbon::now()->subMinutes(2));

        $context = $this->f6cContext();

        $this->f6cFormationAuthority($context, [
            'ownership_approval',
            'profit_distribution_approval',
        ]);

        $finance = $this->f6cEffectiveFinance($context);
        $partnerId = $this->f6cPartner($context);
        $reward = $this->f6cEffectiveReward($context, $partnerId);
        $ownershipVersionId = $this->f6cEffectiveOwnership(
            $context,
            $partnerId,
        );

        $reconciliationWorkflow = $this->app->make(
            FinanceReconciliationWorkflow::class,
        );

        $reconciliationId = $reconciliationWorkflow->create(
            $context['user'],
            $context['business'],
            [
                'period_start' => now()->subDays(7)->toDateString(),
                'period_end' => now()->toDateString(),
                'currency' => 'USD',
                'opening_cash_minor_units' => 150000,
                'inflows_minor_units' => 100000,
                'outflows_minor_units' => 50000,
                'closing_cash_minor_units' => 200000,
                'approved_net_profit_minor_units' => 100000,
                'tax_due_minor_units' => 10000,
                'debt_due_minor_units' => 5000,
                'cash_available_minor_units' => 200000,
                'unreconciled_items_count' => 0,
                'notes' => 'F6C governed Distribution source reconciliation.',
            ],
        );

        self::assertNotNull($reconciliationId);
        self::assertSame(
            'completed',
            $reconciliationWorkflow->complete(
                $context['user'],
                $context['business'],
                $reconciliationId,
                1,
            ),
        );

        $workflow = $this->app->make(DistributionRunWorkflow::class);
        $runId = $workflow->createDraft(
            $context['user'],
            $context['business'],
            [
                'reconciliation_review_id' => $reconciliationId,
                'record_date' => now()->toDateString(),
                'adjustments_minor_units' => 0,
                'special_weights' => [],
                'notes' => 'F6C full governed Distribution lifecycle.',
            ],
        );

        self::assertNotNull($runId);

        $run = DB::table('distribution_runs')
            ->where('id', $runId)
            ->sole();

        self::assertSame(
            $reward['formal_record_version_id'],
            (string) $run->reward_policy_formal_record_version_id,
        );
        self::assertSame(
            $finance['formal_record_version_id'],
            (string) $run->finance_policy_formal_record_version_id,
        );
        self::assertSame(
            $ownershipVersionId,
            (string) $run->ownership_register_version_id,
        );
        self::assertSame(
            $reconciliationId,
            (string) $run->reconciliation_review_id,
        );
        self::assertSame('draft', $run->status);
        self::assertDatabaseCount('distribution_run_lines', 1);

        $line = DB::table('distribution_run_lines')
            ->where('distribution_run_id', $runId)
            ->sole();

        self::assertFalse($workflow->adjustLine(
            $context['user'],
            $context['business'],
            $runId,
            (string) $line->id,
            1,
            100,
            'Manual adjustment must remain disabled by policy.',
        ));

        self::assertFalse($workflow->schedulePayments(
            $context['user'],
            $context['business'],
            $runId,
            $finance['bank_id'],
        ));

        $submission = $workflow->financeVerifyAndSubmit(
            $context['user'],
            $context['business'],
            $runId,
            1,
        );

        self::assertNotNull($submission);
        self::assertSame(
            'profit_distribution_approval',
            $submission['governance_decision_type'],
        );

        $decisionId = $this->f6cApproveProposal(
            $context,
            $submission['proposal_version_id'],
            $submission['governance_decision_type'],
            $submission['governance_amount'],
        );

        self::assertTrue($workflow->syncGovernanceApproval(
            $context['user'],
            $context['business'],
            $runId,
        ));

        self::assertSame(
            'ready_for_effect',
            DB::table('record_version_state_transitions')
                ->where(
                    'formal_record_version_id',
                    $submission['formal_record_version_id'],
                )
                ->orderByDesc('sequence')
                ->value('to_state'),
        );

        self::assertDatabaseHas('distribution_run_submissions', [
            'distribution_run_id' => $runId,
            'proposal_version_id' => $submission['proposal_version_id'],
            'governance_decision_id' => $decisionId,
        ]);

        self::assertTrue($workflow->schedulePayments(
            $context['user'],
            $context['business'],
            $runId,
            $finance['bank_id'],
        ));

        $payment = DB::table('finance_payments')
            ->where('business_id', $context['business']->getKey())
            ->where('transaction_type', 'profit_distribution')
            ->sole();

        self::assertSame('authorized', $payment->status);
        self::assertSame(
            (int) $line->final_amount_minor_units,
            (int) $payment->amount_minor_units,
        );

        self::assertFalse($workflow->complete(
            $context['user'],
            $context['business'],
            $runId,
        ));

        $evidenceId = $this->f6cVerifiedEvidence($context);

        DB::table('finance_payment_evidence_refs')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $context['business']->getKey(),
            'finance_payment_id' => $payment->id,
            'evidence_id' => $evidenceId,
            'purpose' => 'payment_proof',
            'linked_by_membership_id' => $context['membership']->getKey(),
            'created_at' => now(),
        ]);

        $financePayments = $this->app->make(FinancePaymentWorkflow::class);

        self::assertTrue($financePayments->recordPayment(
            $context['payer_user'],
            $context['business'],
            (string) $payment->id,
            'F6C-PAYMENT-001',
            $evidenceId,
            now(),
        ));

        self::assertTrue($financePayments->completePayment(
            $context['user'],
            $context['business'],
            (string) $payment->id,
            $reconciliationId,
        ));

        self::assertSame(
            'ready_for_effect',
            DB::table('record_version_state_transitions')
                ->where(
                    'formal_record_version_id',
                    $submission['formal_record_version_id'],
                )
                ->orderByDesc('sequence')
                ->value('to_state'),
            'Operational payment completion must not itself make the Formal Record Effective.',
        );

        self::assertTrue($workflow->complete(
            $context['user'],
            $context['business'],
            $runId,
        ));

        self::assertDatabaseHas('distribution_runs', [
            'id' => $runId,
            'status' => 'completed',
        ]);
        self::assertSame(
            'effective',
            DB::table('record_version_state_transitions')
                ->where(
                    'formal_record_version_id',
                    $submission['formal_record_version_id'],
                )
                ->orderByDesc('sequence')
                ->value('to_state'),
        );
    }

    public function test_manual_distribution_adjustment_requires_policy_reason_and_verified_evidence(): void
    {
        Carbon::setTestNow(Carbon::now()->subMinutes(2));

        $context = $this->f6cContext();
        $this->f6cFormationAuthority($context, ['ownership_approval']);

        $this->f6cEffectiveFinance($context);
        $partnerId = $this->f6cPartner($context);
        $rewardPayload = $this->f6cRewardPayload($context, $partnerId);
        $rewardPayload['manual_adjustments_allowed'] = true;
        $rewardPayload['distribution_rule']['manual_adjustment_allowed'] = true;
        $this->f6cEffectiveReward($context, $partnerId, $rewardPayload);
        $this->f6cEffectiveOwnership($context, $partnerId);

        $reconciliation = $this->app->make(
            FinanceReconciliationWorkflow::class,
        );
        $reconciliationId = $reconciliation->create(
            $context['user'],
            $context['business'],
            [
                'period_start' => now()->subDays(7)->toDateString(),
                'period_end' => now()->toDateString(),
                'currency' => 'USD',
                'opening_cash_minor_units' => 150000,
                'inflows_minor_units' => 100000,
                'outflows_minor_units' => 50000,
                'closing_cash_minor_units' => 200000,
                'approved_net_profit_minor_units' => 100000,
                'tax_due_minor_units' => 10000,
                'debt_due_minor_units' => 5000,
                'cash_available_minor_units' => 200000,
                'unreconciled_items_count' => 0,
                'notes' => 'Manual-adjustment control fixture.',
            ],
        );

        self::assertNotNull($reconciliationId);
        self::assertSame('completed', $reconciliation->complete(
            $context['user'],
            $context['business'],
            $reconciliationId,
            1,
        ));

        $workflow = $this->app->make(DistributionRunWorkflow::class);
        $payload = [
            'reconciliation_review_id' => $reconciliationId,
            'record_date' => now()->toDateString(),
            'adjustments_minor_units' => 1000,
            'special_weights' => [],
            'notes' => '',
        ];

        try {
            $workflow->createDraft(
                $context['user'],
                $context['business'],
                $payload,
            );
            self::fail('Nonzero Distribution adjustment must require a reason.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Distribution adjustment requires a documented reason.',
                $exception->getMessage(),
            );
        }

        $payload['notes'] = 'Approved one-off adjustment rationale.';
        $runId = $workflow->createDraft(
            $context['user'],
            $context['business'],
            $payload,
        );

        self::assertNotNull($runId);
        $line = DB::table('distribution_run_lines')
            ->where('distribution_run_id', $runId)
            ->sole();

        self::assertFalse($workflow->adjustLine(
            $context['user'],
            $context['business'],
            $runId,
            (string) $line->id,
            1,
            100,
            '',
        ));

        self::assertNull($workflow->financeVerifyAndSubmit(
            $context['user'],
            $context['business'],
            $runId,
            1,
        ));

        $evidenceId = $this->f6cVerifiedEvidence($context);
        self::assertTrue($workflow->attachEvidence(
            $context['user'],
            $context['business'],
            $runId,
            $evidenceId,
        ));

        self::assertNotNull($workflow->financeVerifyAndSubmit(
            $context['user'],
            $context['business'],
            $runId,
            1,
        ));
    }

    public function test_distribution_workflow_keeps_approval_payment_and_effectivity_as_separate_steps(): void
    {
        $source = file_get_contents(
            (new ReflectionClass(DistributionRunWorkflow::class))->getFileName(),
        );

        self::assertIsString($source);

        $syncStart = strpos($source, 'public function syncGovernanceApproval');
        $scheduleStart = strpos($source, 'public function schedulePayments');
        $completeStart = strpos($source, 'public function complete');

        self::assertNotFalse($syncStart);
        self::assertNotFalse($scheduleStart);
        self::assertNotFalse($completeStart);
        self::assertLessThan($scheduleStart, $syncStart);
        self::assertLessThan($completeStart, $scheduleStart);

        $syncBody = substr(
            $source,
            $syncStart,
            $scheduleStart - $syncStart,
        );
        $scheduleBody = substr(
            $source,
            $scheduleStart,
            $completeStart - $scheduleStart,
        );
        $completeBody = substr($source, $completeStart);

        self::assertStringContainsString(
            'prepareEffect->execute',
            $syncBody,
        );
        self::assertStringNotContainsString(
            'makeEffective->execute',
            $syncBody,
        );
        self::assertStringContainsString(
            'createAuthorizedDistributionPayment',
            $scheduleBody,
        );
        self::assertStringContainsString(
            'makeEffective->execute',
            $completeBody,
        );
    }

    public function test_distribution_source_has_no_direct_apply_scenario_path(): void
    {
        $source = file_get_contents(
            (new ReflectionClass(DistributionRunWorkflow::class))->getFileName(),
        );

        self::assertIsString($source);
        self::assertStringNotContainsString('Apply Scenario', $source);
        self::assertStringNotContainsString('applyScenario', $source);
    }
}
