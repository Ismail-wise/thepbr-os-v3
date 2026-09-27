<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Application\Finance\FinancePaymentWorkflow;
use App\Application\Finance\FinanceReconciliationWorkflow;
use App\Application\Finance\ResolveFinanceControl;
use App\Domain\Access\CapabilityCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once __DIR__.'/F6CFinancePolicyTest.php';

final class F6CPaymentControlTest extends TestCase
{
    use F6CFixtureSupport;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_requester_role_and_payer_bank_access_are_independent_controls(): void
    {
        $context = $this->f6cContext();
        $finance = $this->f6cEffectiveFinance($context);

        $paymentId = $this->app->make(FinancePaymentWorkflow::class)->createDraft(
            $context['user'],
            $context['business'],
            [
                'transaction_type' => 'general_payment',
                'amount_minor_units' => 25000,
                'currency' => 'USD',
                'bank_account_reference_id' => $finance['bank_id'],
                'payee_reference' => 'Controlled Supplier',
                'description' => 'Controlled purchase.',
                'related_party' => false,
            ],
        );

        self::assertNotNull($paymentId);
        $this->assertDatabaseHas('finance_payments', [
            'id' => $paymentId,
            'requester_membership_id' => $context['membership']->getKey(),
            'requester_operations_role_id' => $context['role_id'],
            'status' => 'draft',
        ]);

        $controls = $this->app->make(ResolveFinanceControl::class);

        self::assertNull($controls->payerMembership(
            $context['user'],
            $context['business'],
            $finance['formal_record_version_id'],
            $finance['bank_id'],
            'payer',
            25000,
        ));

        self::assertNotNull($controls->payerMembership(
            $context['payer_user'],
            $context['business'],
            $finance['formal_record_version_id'],
            $finance['bank_id'],
            'payer',
            25000,
        ));

        self::assertNull($controls->payerMembership(
            $context['payer_user'],
            $context['business'],
            $finance['formal_record_version_id'],
            $finance['bank_id'],
            'payer',
            10000001,
        ));

        self::assertNull($controls->payerMembership(
            $context['payer_user'],
            $context['business'],
            $finance['formal_record_version_id'],
            $finance['bank_id'],
            'approver',
            25000,
        ));
    }

    public function test_finance_payment_uses_exact_governance_and_verified_evidence_before_completion(): void
    {
        Carbon::setTestNow(Carbon::now()->subMinutes(2));

        $context = $this->f6cContext();
        $this->f6cFormationAuthority($context, ['finance_payment_approval']);

        $payload = $this->f6cFinancePayload($context);
        $payload['payment_rules'][0]['evidence_required'] = true;
        $finance = $this->f6cEffectiveFinance($context, $payload);
        $workflow = $this->app->make(FinancePaymentWorkflow::class);

        $paymentId = $workflow->createDraft(
            $context['user'],
            $context['business'],
            [
                'transaction_type' => 'general_payment',
                'amount_minor_units' => 25000,
                'currency' => 'USD',
                'bank_account_reference_id' => $finance['bank_id'],
                'payee_reference' => 'Governed Supplier',
                'description' => 'Exact governed payment.',
                'related_party' => false,
            ],
        );

        self::assertNotNull($paymentId);
        self::assertNull($workflow->financeVerifyAndSubmit(
            $context['user'],
            $context['business'],
            $paymentId,
            1,
        ));

        $requestEvidenceId = $this->f6cVerifiedEvidence($context);
        self::assertTrue($workflow->attachEvidence(
            $context['user'],
            $context['business'],
            $paymentId,
            $requestEvidenceId,
            'request_support',
        ));

        $submission = $workflow->financeVerifyAndSubmit(
            $context['user'],
            $context['business'],
            $paymentId,
            1,
        );

        self::assertNotNull($submission);
        self::assertSame('finance_payment_approval', $submission['governance_decision_type']);
        self::assertSame('250.00', $submission['governance_amount']);

        $decisionId = $this->f6cApproveProposal(
            $context,
            $submission['proposal_version_id'],
            $submission['governance_decision_type'],
            $submission['governance_amount'],
        );

        $decision = DB::table('decisions')->where('id', $decisionId)->sole();
        self::assertSame(
            $submission['proposal_version_id'],
            (string) $decision->proposal_version_id,
        );
        self::assertSame('finance_payment_approval', (string) $decision->decision_type);
        self::assertSame('250.00', (string) $decision->decision_amount);
        self::assertNotNull($decision->authority_snapshot_id);

        self::assertTrue($workflow->syncGovernanceAuthorization(
            $context['user'],
            $context['business'],
            $paymentId,
        ));

        self::assertFalse($workflow->recordPayment(
            $context['payer_user'],
            $context['business'],
            $paymentId,
            'F6C-PAY-001',
            $requestEvidenceId,
            now(),
        ));

        $paymentEvidenceId = $this->f6cVerifiedEvidence($context);
        self::assertTrue($workflow->attachEvidence(
            $context['user'],
            $context['business'],
            $paymentId,
            $paymentEvidenceId,
            'payment_proof',
        ));

        self::assertTrue($workflow->recordPayment(
            $context['payer_user'],
            $context['business'],
            $paymentId,
            'F6C-PAY-001',
            $paymentEvidenceId,
            now(),
        ));

        $reconciliation = $this->app->make(
            FinanceReconciliationWorkflow::class,
        );
        $reconciliationId = $reconciliation->create(
            $context['user'],
            $context['business'],
            [
                'period_start' => now()->subDay()->toDateString(),
                'period_end' => now()->addDay()->toDateString(),
                'currency' => 'USD',
                'opening_cash_minor_units' => 100000,
                'inflows_minor_units' => 50000,
                'outflows_minor_units' => 25000,
                'closing_cash_minor_units' => 125000,
                'approved_net_profit_minor_units' => 25000,
                'tax_due_minor_units' => 0,
                'debt_due_minor_units' => 0,
                'cash_available_minor_units' => 125000,
                'unreconciled_items_count' => 0,
                'notes' => 'F6C payment completion reconciliation.',
            ],
        );

        self::assertNotNull($reconciliationId);
        self::assertSame('completed', $reconciliation->complete(
            $context['user'],
            $context['business'],
            $reconciliationId,
            1,
        ));

        self::assertTrue($workflow->completePayment(
            $context['user'],
            $context['business'],
            $paymentId,
            $reconciliationId,
        ));
        $this->assertDatabaseHas('finance_payments', [
            'id' => $paymentId,
            'status' => 'completed',
            'reconciliation_review_id' => $reconciliationId,
        ]);
    }

    public function test_system_permission_does_not_make_non_role_member_a_requester(): void
    {
        $context = $this->f6cContext();
        $finance = $this->f6cEffectiveFinance($context);

        $this->f6cGrant(
            $context['business'],
            $context['payer'],
            CapabilityCatalog::FINANCE_MANAGE,
            [],
        );

        self::assertNull(
            $this->app->make(FinancePaymentWorkflow::class)->createDraft(
                $context['payer_user'],
                $context['business'],
                [
                    'transaction_type' => 'general_payment',
                    'amount_minor_units' => 10000,
                    'currency' => 'USD',
                    'bank_account_reference_id' => $finance['bank_id'],
                    'payee_reference' => 'Must fail',
                    'related_party' => false,
                ],
            ),
        );
    }

    public function test_ambiguous_distribution_payment_authority_rules_fail_closed(): void
    {
        $context = $this->f6cContext();
        $payload = $this->f6cFinancePayload($context);
        $overlap = $payload['payment_rules'][5];
        $overlap['rule_key'] = 'distribution_overlap';
        $payload['payment_rules'][] = $overlap;

        $finance = $this->f6cEffectiveFinance($context, $payload);

        self::assertNull(
            $this->app->make(FinancePaymentWorkflow::class)
                ->createAuthorizedDistributionPayment(
                    $context['user'],
                    $context['business'],
                    (string) $context['membership']->getKey(),
                    $finance['formal_record_version_id'],
                    'profit_distribution',
                    25000,
                    'USD',
                    $finance['bank_id'],
                    'Distribution payee',
                    (string) $context['membership']->getKey(),
                    'profit_distribution_approval',
                ),
        );

        self::assertDatabaseCount('finance_payments', 0);
    }

    public function test_cross_business_bank_reference_fails_closed(): void
    {
        $first = $this->f6cContext();
        $firstFinance = $this->f6cEffectiveFinance($first);
        $other = $this->f6cContext();
        $otherFinance = $this->f6cEffectiveFinance($other);

        self::assertNotSame($firstFinance['bank_id'], $otherFinance['bank_id']);

        self::assertNull(
            $this->app->make(FinancePaymentWorkflow::class)->createDraft(
                $first['user'],
                $first['business'],
                [
                    'transaction_type' => 'general_payment',
                    'amount_minor_units' => 10000,
                    'currency' => 'USD',
                    'bank_account_reference_id' => $otherFinance['bank_id'],
                    'payee_reference' => 'Cross tenant',
                    'related_party' => false,
                ],
            ),
        );
    }
}
