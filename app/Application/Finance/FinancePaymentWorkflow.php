<?php

declare(strict_types=1);

namespace App\Application\Finance;

use App\Application\Evidence\LinkEvidence;
use App\Application\Governance\GovernanceActorContext;
use App\Application\Governance\MakeGovernedRecordEffective;
use App\Application\Governance\PrepareGovernedRecordForEffect;
use App\Application\Governance\RecordGovernanceOccurrence;
use App\Application\Records\CreateDraftRecordVersion;
use App\Application\Records\CreateFormalRecordFamily;
use App\Application\Records\CreateProposal;
use App\Application\Records\FreezeProposalVersion;
use App\Application\Records\SubmitRecordVersionForReview;
use App\Application\Records\TransitionFormalRecordVersion;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Finance\Enums\FinancePaymentStatus;
use App\Domain\Finance\Services\SegregationOfDuties;
use App\Domain\Records\Enums\FormalRecordState;
use App\Domain\Records\ValueObjects\RecordScope;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Finance\FinancePayment;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class FinancePaymentWorkflow
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly ResolveFinanceControl $controls,
        private readonly FinanceExceptionWorkflow $exceptions,
        private readonly SegregationOfDuties $segregation,
        private readonly CreateFormalRecordFamily $createFamily,
        private readonly CreateDraftRecordVersion $createDraftVersion,
        private readonly SubmitRecordVersionForReview $submitForReview,
        private readonly TransitionFormalRecordVersion $transitionRecord,
        private readonly CreateProposal $createProposal,
        private readonly FreezeProposalVersion $freezeProposal,
        private readonly PrepareGovernedRecordForEffect $prepareEffect,
        private readonly MakeGovernedRecordEffective $makeEffective,
        private readonly LinkEvidence $linkEvidence,
        private readonly RecordGovernanceOccurrence $occurrence,
    ) {}

    /** @param array<string,mixed> $payload */
    public function createDraft(
        User $user,
        Business $business,
        array $payload,
    ): ?string {
        $requester = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::FINANCE_MANAGE,
        );
        $policy = $this->controls->currentPolicy($business);

        if ($requester === null || $policy === null) {
            return null;
        }

        $type = strtolower(trim((string) ($payload['transaction_type'] ?? '')));
        $amount = $this->positiveMinorUnits($payload['amount_minor_units'] ?? null);
        $rule = $this->controls->paymentRule($business, $type, $amount);

        if ($rule === null) {
            return null;
        }

        $header = $policy['header'];
        $role = DB::table('operations_roles')
            ->where('business_id', $business->getKey())
            ->where(
                'formal_record_version_id',
                $header->operations_formal_record_version_id,
            )
            ->where('role_key', $rule->requester_operations_role_key)
            ->first();

        if ($role === null || ! DB::table('operations_role_assignments')
            ->where('business_id', $business->getKey())
            ->where('operations_role_id', $role->id)
            ->where('membership_id', $requester->getKey())
            ->where('assignment_type', 'primary')
            ->exists()) {
            return null;
        }

        $bankId = trim((string) ($payload['bank_account_reference_id'] ?? ''));
        $bank = DB::table('finance_bank_account_references')
            ->where('business_id', $business->getKey())
            ->where('formal_record_version_id', $policy['formal_record_version_id'])
            ->where('id', $bankId)
            ->where('status', 'active')
            ->first();

        $currency = strtoupper(trim((string) ($payload['currency'] ?? '')));

        if (
            $bank === null
            || $currency !== (string) $bank->currency
            || preg_match('/^[A-Z]{3}$/', $currency) !== 1
        ) {
            return null;
        }

        $payee = trim((string) ($payload['payee_reference'] ?? ''));

        if ($payee === '') {
            throw new InvalidArgumentException('Finance Payment payee reference is required.');
        }

        $id = (string) Str::uuid7();

        FinancePayment::query()->create([
            'id' => $id,
            'business_id' => $business->getKey(),
            'finance_policy_formal_record_version_id' => $policy['formal_record_version_id'],
            'payment_authority_rule_id' => $rule->id,
            'operations_formal_record_version_id' => $header->operations_formal_record_version_id,
            'requester_operations_role_id' => $role->id,
            'requester_membership_id' => $requester->getKey(),
            'bank_account_reference_id' => $bankId,
            'transaction_type' => $type,
            'amount_minor_units' => $amount,
            'currency' => $currency,
            'payee_reference' => $payee,
            'description' => $this->nullableText($payload['description'] ?? null),
            'related_party' => (bool) ($payload['related_party'] ?? false),
            'status' => FinancePaymentStatus::Draft->value,
            'revision' => 1,
            'created_by_membership_id' => $requester->getKey(),
        ]);

        $this->occurrence->record(
            $user,
            $business,
            'finance.payment.draft_created',
            'finance_payment',
            $id,
            [
                'transaction_type' => $type,
                'amount_minor_units' => $amount,
            ],
        );

        return $id;
    }

    /** @param array<string,mixed> $payload */
    public function updateDraft(
        User $user,
        Business $business,
        string $paymentId,
        int $expectedRevision,
        array $payload,
    ): bool {
        return DB::transaction(function () use (
            $user,
            $business,
            $paymentId,
            $expectedRevision,
            $payload,
        ): bool {
            $requester = $this->actorContext->membership(
                $user,
                $business,
                CapabilityCatalog::FINANCE_MANAGE,
            );

            $payment = FinancePayment::query()
                ->where('business_id', $business->getKey())
                ->whereKey($paymentId)
                ->lockForUpdate()
                ->first();

            if (
                $requester === null
                || $payment === null
                || $payment->status !== FinancePaymentStatus::Draft->value
                || (string) $payment->requester_membership_id
                    !== (string) $requester->getKey()
                || (int) $payment->revision !== $expectedRevision
            ) {
                return false;
            }

            $type = strtolower(trim((string) ($payload['transaction_type'] ?? $payment->transaction_type)));
            $amount = $this->positiveMinorUnits($payload['amount_minor_units'] ?? $payment->amount_minor_units);
            $rule = $this->controls->paymentRule($business, $type, $amount);

            if (
                $rule === null
                || (string) $rule->requester_operations_role_key
                    !== (string) DB::table('operations_roles')
                        ->where('id', $payment->requester_operations_role_id)
                        ->value('role_key')
            ) {
                return false;
            }

            $updated = FinancePayment::query()
                ->where('business_id', $business->getKey())
                ->whereKey($paymentId)
                ->where('revision', $expectedRevision)
                ->update([
                    'payment_authority_rule_id' => $rule->id,
                    'transaction_type' => $type,
                    'amount_minor_units' => $amount,
                    'payee_reference' => trim((string) ($payload['payee_reference'] ?? $payment->payee_reference)),
                    'description' => $this->nullableText($payload['description'] ?? $payment->description),
                    'related_party' => (bool) ($payload['related_party'] ?? $payment->related_party),
                    'revision' => $expectedRevision + 1,
                ]);

            return $updated === 1;
        });
    }

    public function attachEvidence(
        User $user,
        Business $business,
        string $paymentId,
        string $evidenceId,
        string $purpose,
    ): bool {
        if (! in_array($purpose, ['request_support', 'payment_proof'], true)) {
            throw new InvalidArgumentException('Finance Payment evidence purpose is invalid.');
        }

        $payment = FinancePayment::query()
            ->where('business_id', $business->getKey())
            ->whereKey($paymentId)
            ->first();

        if ($payment === null) {
            return false;
        }

        if (
            $purpose === 'request_support'
            && $payment->status !== FinancePaymentStatus::Draft->value
        ) {
            return false;
        }

        if (
            $purpose === 'payment_proof'
            && ! in_array(
                $payment->status,
                [FinancePaymentStatus::Authorized->value, FinancePaymentStatus::Paid->value],
                true,
            )
        ) {
            return false;
        }

        $link = $this->linkEvidence->execute(
            $user,
            $business,
            $evidenceId,
            'finance_payment',
            $paymentId,
        );

        if ($link === null) {
            return false;
        }

        DB::table('finance_payment_evidence_refs')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'finance_payment_id' => $paymentId,
            'evidence_id' => $evidenceId,
            'purpose' => $purpose,
            'linked_by_membership_id' => $link->created_by_membership_id,
            'created_at' => now(),
        ]);

        return true;
    }

    public function financeVerifyAndSubmit(
        User $user,
        Business $business,
        string $paymentId,
        int $expectedRevision,
    ): ?array {
        return DB::transaction(function () use (
            $user,
            $business,
            $paymentId,
            $expectedRevision,
        ): ?array {
            $payment = FinancePayment::query()
                ->where('business_id', $business->getKey())
                ->whereKey($paymentId)
                ->lockForUpdate()
                ->first();

            if (
                $payment === null
                || $payment->status !== FinancePaymentStatus::Draft->value
                || (int) $payment->revision !== $expectedRevision
            ) {
                return null;
            }

            $verifier = $this->controls->verifierMembership(
                $user,
                $business,
                (string) $payment->finance_policy_formal_record_version_id,
            );

            if ($verifier === null) {
                return null;
            }

            $rule = DB::table('finance_payment_authority_rules')
                ->where('business_id', $business->getKey())
                ->where('id', $payment->payment_authority_rule_id)
                ->where(
                    'formal_record_version_id',
                    $payment->finance_policy_formal_record_version_id,
                )
                ->first();

            if ($rule === null) {
                return null;
            }

            if ((bool) $rule->evidence_required && ! $this->hasVerifiedEvidence(
                (string) $business->getKey(),
                $paymentId,
                'request_support',
            )) {
                return null;
            }

            if (
                (bool) $payment->related_party
                && (bool) $rule->related_party_review_required
                && ! $this->hasClearedException(
                    (string) $business->getKey(),
                    $paymentId,
                    'related_party',
                )
            ) {
                return null;
            }

            $snapshot = $this->paymentSnapshot(
                $payment,
                (string) $rule->governance_decision_type,
            );
            $contentHash = hash(
                'sha256',
                json_encode(
                    $snapshot,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
            );
            $capability = new Capability(CapabilityCatalog::RECORDS_MANAGE);

            $family = $this->createFamily->execute(
                $user,
                $business,
                $capability,
                new RecordScope(
                    'finance_payment',
                    'finance_payment',
                    $paymentId,
                ),
            );

            if ($family === null) {
                return null;
            }

            $version = $this->createDraftVersion->execute(
                $user,
                $business,
                $capability,
                (string) $family->getKey(),
                $contentHash,
                'Finance Payment authorization snapshot.',
                now(),
            );

            if ($version === null) {
                return null;
            }

            $frozen = $this->submitForReview->execute(
                $user,
                $business,
                $capability,
                (string) $version->getKey(),
                1,
            );

            if ($frozen === null) {
                return null;
            }

            if (
                $this->transitionRecord->execute(
                    $user,
                    $business,
                    $capability,
                    (string) $frozen->getKey(),
                    FormalRecordState::UnderReview,
                ) === null
                || $this->transitionRecord->execute(
                    $user,
                    $business,
                    $capability,
                    (string) $frozen->getKey(),
                    FormalRecordState::Approved,
                ) === null
            ) {
                return null;
            }

            $proposal = $this->createProposal->execute(
                $user,
                $business,
                $capability,
                $contentHash,
            );

            if ($proposal === null) {
                return null;
            }

            $proposalVersion = $this->freezeProposal->execute(
                $user,
                $business,
                $capability,
                (string) $proposal->getKey(),
                1,
                [(string) $frozen->getKey()],
            );

            if ($proposalVersion === null) {
                return null;
            }

            DB::table('finance_payment_submissions')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'finance_payment_id' => $paymentId,
                'payment_revision' => $expectedRevision,
                'content_hash' => $contentHash,
                'formal_record_version_id' => $frozen->getKey(),
                'proposal_version_id' => $proposalVersion->getKey(),
                'governance_decision_id' => null,
                'authorized_at' => null,
                'created_at' => now(),
            ]);

            FinancePayment::query()
                ->where('business_id', $business->getKey())
                ->whereKey($paymentId)
                ->update([
                    'status' => FinancePaymentStatus::GovernancePending->value,
                    'finance_verified_by_membership_id' => $verifier->getKey(),
                    'finance_verified_at' => now(),
                    'revision' => $expectedRevision + 1,
                ]);

            $this->occurrence->record(
                $user,
                $business,
                'finance.payment.finance_verified',
                'finance_payment',
                $paymentId,
                [
                    'proposal_version_id' => (string) $proposalVersion->getKey(),
                    'governance_decision_type' => (string) $rule->governance_decision_type,
                ],
                (string) $frozen->getKey(),
            );

            return [
                'formal_record_version_id' => (string) $frozen->getKey(),
                'proposal_version_id' => (string) $proposalVersion->getKey(),
                'governance_decision_type' => (string) $rule->governance_decision_type,
                'governance_amount' => $this->minorToMajor((int) $payment->amount_minor_units),
            ];
        });
    }

    public function syncGovernanceAuthorization(
        User $user,
        Business $business,
        string $paymentId,
    ): bool {
        return DB::transaction(function () use ($user, $business, $paymentId): bool {
            if ($this->actorContext->membership(
                $user,
                $business,
                CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
            ) === null) {
                return false;
            }

            $payment = FinancePayment::query()
                ->where('business_id', $business->getKey())
                ->whereKey($paymentId)
                ->lockForUpdate()
                ->first();

            $submission = DB::table('finance_payment_submissions')
                ->where('business_id', $business->getKey())
                ->where('finance_payment_id', $paymentId)
                ->lockForUpdate()
                ->first();

            if (
                $payment === null
                || $submission === null
                || $payment->status !== FinancePaymentStatus::GovernancePending->value
            ) {
                return false;
            }

            $rule = DB::table('finance_payment_authority_rules')
                ->where('business_id', $business->getKey())
                ->where('id', $payment->payment_authority_rule_id)
                ->first();

            if ($rule === null) {
                return false;
            }

            $decision = DB::table('decisions')
                ->where('business_id', $business->getKey())
                ->where('proposal_version_id', $submission->proposal_version_id)
                ->where('decision_type', $rule->governance_decision_type)
                ->where('decision_amount', $this->minorToMajor((int) $payment->amount_minor_units))
                ->where('status', 'decided')
                ->where('outcome', 'approved')
                ->first();

            if ($decision === null) {
                return false;
            }

            if (
                ! $this->prepareEffect->execute(
                    $user,
                    $business,
                    (string) $decision->id,
                    (string) $submission->formal_record_version_id,
                )
                || ! $this->makeEffective->execute(
                    $user,
                    $business,
                    (string) $decision->id,
                    (string) $submission->formal_record_version_id,
                )
            ) {
                return false;
            }

            DB::table('finance_payment_submissions')
                ->where('business_id', $business->getKey())
                ->where('finance_payment_id', $paymentId)
                ->update([
                    'governance_decision_id' => $decision->id,
                    'authorized_at' => now(),
                ]);

            FinancePayment::query()
                ->where('business_id', $business->getKey())
                ->whereKey($paymentId)
                ->update(['status' => FinancePaymentStatus::Authorized->value]);

            $this->occurrence->record(
                $user,
                $business,
                'finance.payment.authorized',
                'finance_payment',
                $paymentId,
                ['governance_decision_id' => (string) $decision->id],
                (string) $submission->formal_record_version_id,
            );

            return true;
        });
    }

    public function recordPayment(
        User $user,
        Business $business,
        string $paymentId,
        string $paymentReference,
        string $paymentEvidenceId,
        DateTimeInterface $paidAt,
    ): bool {
        $payment = FinancePayment::query()
            ->where('business_id', $business->getKey())
            ->whereKey($paymentId)
            ->first();

        if (
            $payment === null
            || $payment->status !== FinancePaymentStatus::Authorized->value
        ) {
            return false;
        }

        $rule = DB::table('finance_payment_authority_rules')
            ->where('business_id', $business->getKey())
            ->where('id', $payment->payment_authority_rule_id)
            ->first();

        if ($rule === null) {
            return false;
        }

        $payer = $this->controls->payerMembership(
            $user,
            $business,
            (string) $payment->finance_policy_formal_record_version_id,
            (string) $payment->bank_account_reference_id,
            (string) $rule->payer_access_level,
            (int) $payment->amount_minor_units,
        );

        if ($payer === null) {
            return false;
        }

        $actors = $this->exceptions->affirmativeGovernanceActors(
            (string) $business->getKey(),
            $paymentId,
        );

        $separation = $this->segregation->evaluate(
            (string) $payment->requester_membership_id,
            (string) $payer->getKey(),
            $actors,
            (bool) $rule->strict_three_way_separation,
            (bool) $rule->compensating_review_allowed,
        );

        if (! $separation['separated']) {
            if (! $separation['requires_compensating_review']) {
                return false;
            }

            if (! $this->hasClearedException(
                (string) $business->getKey(),
                $paymentId,
                'segregation_of_duties',
            )) {
                return false;
            }
        }

        if (! $this->hasVerifiedPaymentEvidence(
            (string) $business->getKey(),
            $paymentId,
            $paymentEvidenceId,
        )) {
            return false;
        }

        $reference = trim($paymentReference);

        if ($reference === '') {
            return false;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $paymentId,
            $payer,
            $paidAt,
            $reference,
        ): bool {
            $locked = FinancePayment::query()
                ->where('business_id', $business->getKey())
                ->whereKey($paymentId)
                ->lockForUpdate()
                ->first();

            if (
                $locked === null
                || $locked->status !== FinancePaymentStatus::Authorized->value
            ) {
                return false;
            }

            $locked->update([
                'payer_membership_id' => $payer->getKey(),
                'paid_at' => $paidAt,
                'payment_reference' => $reference,
                'status' => FinancePaymentStatus::Paid->value,
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'finance.payment.paid',
                'finance_payment',
                $paymentId,
                ['payer_membership_id' => (string) $payer->getKey()],
            );

            return true;
        });
    }

    public function completePayment(
        User $user,
        Business $business,
        string $paymentId,
        string $reconciliationReviewId,
    ): bool {
        return DB::transaction(function () use (
            $user,
            $business,
            $paymentId,
            $reconciliationReviewId,
        ): bool {
            $payment = FinancePayment::query()
                ->where('business_id', $business->getKey())
                ->whereKey($paymentId)
                ->lockForUpdate()
                ->first();

            if (
                $payment === null
                || $payment->status !== FinancePaymentStatus::Paid->value
                || $payment->paid_at === null
                || $this->controls->verifierMembership(
                    $user,
                    $business,
                    (string) $payment->finance_policy_formal_record_version_id,
                ) === null
            ) {
                return false;
            }

            $review = DB::table('finance_reconciliation_reviews')
                ->where('business_id', $business->getKey())
                ->where('id', $reconciliationReviewId)
                ->where('finance_policy_formal_record_version_id', $payment->finance_policy_formal_record_version_id)
                ->where('status', 'completed')
                ->first();

            if ($review === null) {
                return false;
            }

            $distributionReconciliationId = DB::table(
                'distribution_run_payment_links as link',
            )
                ->join('distribution_run_lines as line', function ($join): void {
                    $join->on(
                        'line.id',
                        '=',
                        'link.distribution_run_line_id',
                    )->on(
                        'line.business_id',
                        '=',
                        'link.business_id',
                    );
                })
                ->join('distribution_runs as run', function ($join): void {
                    $join->on(
                        'run.id',
                        '=',
                        'line.distribution_run_id',
                    )->on(
                        'run.business_id',
                        '=',
                        'line.business_id',
                    );
                })
                ->where('link.business_id', $business->getKey())
                ->where('link.finance_payment_id', $paymentId)
                ->value('run.reconciliation_review_id');

            if ($distributionReconciliationId !== null) {
                if (
                    (string) $distributionReconciliationId
                        !== $reconciliationReviewId
                ) {
                    return false;
                }
            } else {
                $paidDate = substr((string) $payment->paid_at, 0, 10);

                if (
                    $paidDate < (string) $review->period_start
                    || $paidDate > (string) $review->period_end
                ) {
                    return false;
                }
            }

            $payment->update([
                'reconciliation_review_id' => $reconciliationReviewId,
                'status' => FinancePaymentStatus::Completed->value,
                'completed_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'finance.payment.completed',
                'finance_payment',
                $paymentId,
                ['reconciliation_review_id' => $reconciliationReviewId],
            );

            return true;
        });
    }

    /**
     * Used only by an already-approved Distribution Run to create execution
     * payments without introducing a second Governance decision per line.
     */
    public function createAuthorizedDistributionPayment(
        User $user,
        Business $business,
        string $requesterMembershipId,
        string $financePolicyVersionId,
        string $transactionType,
        int $amountMinorUnits,
        string $currency,
        string $bankAccountReferenceId,
        string $payeeReference,
        string $financeVerifierMembershipId,
        string $expectedGovernanceDecisionType,
    ): ?string {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::REWARDS_MANAGE,
        ) === null) {
            return null;
        }

        if (
            $amountMinorUnits <= 0
            || preg_match('/^[A-Z]{3}$/', $currency) !== 1
        ) {
            return null;
        }

        $rules = DB::table('finance_payment_authority_rules')
            ->where('business_id', $business->getKey())
            ->where('formal_record_version_id', $financePolicyVersionId)
            ->where('transaction_type', $transactionType)
            ->where(function ($query) use ($amountMinorUnits): void {
                $query->whereNull('amount_min_minor_units')
                    ->orWhere('amount_min_minor_units', '<=', $amountMinorUnits);
            })
            ->where(function ($query) use ($amountMinorUnits): void {
                $query->whereNull('amount_max_minor_units')
                    ->orWhere('amount_max_minor_units', '>=', $amountMinorUnits);
            })
            ->orderBy('sequence')
            ->get();

        if ($rules->count() !== 1) {
            return null;
        }

        $rule = $rules->first();

        $policy = DB::table('finance_policy_versions')
            ->where('business_id', $business->getKey())
            ->where('formal_record_version_id', $financePolicyVersionId)
            ->first();

        $bank = DB::table('finance_bank_account_references')
            ->where('business_id', $business->getKey())
            ->where('formal_record_version_id', $financePolicyVersionId)
            ->where('id', $bankAccountReferenceId)
            ->where('status', 'active')
            ->first();

        if (
            $rule === null
            || $policy === null
            || $bank === null
            || (string) $bank->currency !== $currency
            || (string) $rule->governance_decision_type
                !== $expectedGovernanceDecisionType
        ) {
            return null;
        }

        $role = DB::table('operations_roles')
            ->where('business_id', $business->getKey())
            ->where('formal_record_version_id', $policy->operations_formal_record_version_id)
            ->where('role_key', $rule->requester_operations_role_key)
            ->first();

        if (
            $role === null
            || ! DB::table('operations_role_assignments')
                ->where('business_id', $business->getKey())
                ->where('operations_role_id', $role->id)
                ->where('membership_id', $requesterMembershipId)
                ->where('assignment_type', 'primary')
                ->exists()
        ) {
            return null;
        }

        $id = (string) Str::uuid7();

        FinancePayment::query()->create([
            'id' => $id,
            'business_id' => $business->getKey(),
            'finance_policy_formal_record_version_id' => $financePolicyVersionId,
            'payment_authority_rule_id' => $rule->id,
            'operations_formal_record_version_id' => $policy->operations_formal_record_version_id,
            'requester_operations_role_id' => $role->id,
            'requester_membership_id' => $requesterMembershipId,
            'bank_account_reference_id' => $bankAccountReferenceId,
            'transaction_type' => $transactionType,
            'amount_minor_units' => $amountMinorUnits,
            'currency' => $currency,
            'payee_reference' => $payeeReference,
            'description' => 'Distribution Run authorized payment.',
            'related_party' => false,
            'status' => FinancePaymentStatus::Authorized->value,
            'revision' => 1,
            'finance_verified_by_membership_id' => $financeVerifierMembershipId,
            'finance_verified_at' => now(),
            'created_by_membership_id' => $requesterMembershipId,
        ]);

        return $id;
    }

    private function hasVerifiedEvidence(
        string $businessId,
        string $paymentId,
        string $purpose,
    ): bool {
        return DB::table('finance_payment_evidence_refs as ref')
            ->join('evidence as e', function ($join): void {
                $join->on('e.id', '=', 'ref.evidence_id')
                    ->on('e.business_id', '=', 'ref.business_id');
            })
            ->where('ref.business_id', $businessId)
            ->where('ref.finance_payment_id', $paymentId)
            ->where('ref.purpose', $purpose)
            ->whereNotNull('e.verified_at')
            ->exists();
    }

    private function hasVerifiedPaymentEvidence(
        string $businessId,
        string $paymentId,
        string $evidenceId,
    ): bool {
        return DB::table('finance_payment_evidence_refs as ref')
            ->join('evidence as e', function ($join): void {
                $join->on('e.id', '=', 'ref.evidence_id')
                    ->on('e.business_id', '=', 'ref.business_id');
            })
            ->where('ref.business_id', $businessId)
            ->where('ref.finance_payment_id', $paymentId)
            ->where('ref.evidence_id', $evidenceId)
            ->where('ref.purpose', 'payment_proof')
            ->whereNotNull('e.verified_at')
            ->exists();
    }

    private function hasClearedException(
        string $businessId,
        string $paymentId,
        string $type,
    ): bool {
        return DB::table('finance_exceptions')
            ->where('business_id', $businessId)
            ->where('finance_payment_id', $paymentId)
            ->where('exception_type', $type)
            ->where('status', 'cleared')
            ->exists();
    }

    /** @return array<string,mixed> */
    private function paymentSnapshot(
        FinancePayment $payment,
        string $governanceDecisionType,
    ): array {
        $evidenceIds = DB::table('finance_payment_evidence_refs')
            ->where('business_id', $payment->business_id)
            ->where('finance_payment_id', $payment->getKey())
            ->where('purpose', 'request_support')
            ->orderBy('evidence_id')
            ->pluck('evidence_id')
            ->map(fn ($v): string => (string) $v)
            ->all();

        return [
            'finance_payment_id' => (string) $payment->getKey(),
            'payment_revision' => (int) $payment->revision,
            'finance_policy_formal_record_version_id' => (string) $payment->finance_policy_formal_record_version_id,
            'payment_authority_rule_id' => (string) $payment->payment_authority_rule_id,
            'operations_formal_record_version_id' => (string) $payment->operations_formal_record_version_id,
            'requester_operations_role_id' => (string) $payment->requester_operations_role_id,
            'requester_membership_id' => (string) $payment->requester_membership_id,
            'bank_account_reference_id' => (string) $payment->bank_account_reference_id,
            'transaction_type' => (string) $payment->transaction_type,
            'amount_minor_units' => (int) $payment->amount_minor_units,
            'currency' => (string) $payment->currency,
            'payee_reference' => (string) $payment->payee_reference,
            'description' => $payment->description,
            'related_party' => (bool) $payment->related_party,
            'governance_decision_type' => $governanceDecisionType,
            'request_evidence_ids' => $evidenceIds,
        ];
    }

    private function positiveMinorUnits(mixed $value): int
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            throw new InvalidArgumentException(
                'Finance Payment amount must be positive minor units.',
            );
        }

        return (int) $value;
    }

    private function minorToMajor(int $minorUnits): string
    {
        return number_format($minorUnits / 100, 2, '.', '');
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
