<?php

declare(strict_types=1);

namespace App\Application\Finance;

use App\Application\Governance\GovernanceActorContext;
use App\Application\Governance\RecordGovernanceOccurrence;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Finance\Enums\FinanceExceptionStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class FinanceExceptionWorkflow
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly ResolveFinanceControl $controls,
        private readonly RecordGovernanceOccurrence $occurrence,
    ) {}

    public function open(
        User $user,
        Business $business,
        string $type,
        string $reason,
        ?string $paymentId = null,
        ?string $reconciliationId = null,
        bool $requiresCompensatingReview = false,
        string $severity = 'medium',
    ): ?string {
        $membership = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::FINANCE_MANAGE,
        );
        $policy = $this->controls->currentPolicy($business);

        if ($membership === null || $policy === null) {
            return null;
        }

        $type = trim($type);
        $reason = trim($reason);

        if (
            $type === ''
            || $reason === ''
            || ! in_array($severity, ['low', 'medium', 'high', 'critical'], true)
        ) {
            throw new InvalidArgumentException('Finance Exception fields are invalid.');
        }

        if ($paymentId !== null && ! DB::table('finance_payments')
            ->where('business_id', $business->getKey())
            ->where('id', $paymentId)->exists()) {
            return null;
        }

        if ($reconciliationId !== null && ! DB::table('finance_reconciliation_reviews')
            ->where('business_id', $business->getKey())
            ->where('id', $reconciliationId)->exists()) {
            return null;
        }

        $id = (string) Str::uuid7();
        DB::table('finance_exceptions')->insert([
            'id' => $id,
            'business_id' => $business->getKey(),
            'finance_policy_formal_record_version_id' => $policy['formal_record_version_id'],
            'finance_payment_id' => $paymentId,
            'reconciliation_review_id' => $reconciliationId,
            'exception_type' => $type,
            'severity' => $severity,
            'reason' => $reason,
            'requires_compensating_review' => $requiresCompensatingReview,
            'status' => $requiresCompensatingReview
                ? FinanceExceptionStatus::CompensatingReview->value
                : FinanceExceptionStatus::Open->value,
            'opened_by_membership_id' => $membership->getKey(),
            'opened_at' => now(),
            'resolved_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->occurrence->record(
            $user,
            $business,
            'finance.exception.opened',
            'finance_exception',
            $id,
            ['exception_type' => $type],
        );

        return $id;
    }

    public function completeCompensatingReview(
        User $user,
        Business $business,
        string $exceptionId,
        string $result,
        ?string $note = null,
    ): bool {
        if (! in_array($result, ['cleared', 'blocked'], true)) {
            throw new InvalidArgumentException(
                'Finance compensating review result is invalid.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $exceptionId,
            $result,
            $note,
        ): bool {
            $membership = $this->actorContext->membership(
                $user,
                $business,
                CapabilityCatalog::FINANCE_MANAGE,
            );

            if ($membership === null) {
                return false;
            }

            $exception = DB::table('finance_exceptions')
                ->where('business_id', $business->getKey())
                ->where('id', $exceptionId)
                ->lockForUpdate()
                ->first();

            if (
                $exception === null
                || ! (bool) $exception->requires_compensating_review
                || $exception->status !== FinanceExceptionStatus::CompensatingReview->value
            ) {
                return false;
            }

            $reviewerId = (string) $membership->getKey();

            if ((string) $exception->opened_by_membership_id === $reviewerId) {
                return false;
            }

            if ($exception->finance_payment_id !== null) {
                $payment = DB::table('finance_payments')
                    ->where('business_id', $business->getKey())
                    ->where('id', $exception->finance_payment_id)
                    ->first();

                if ($payment === null) {
                    return false;
                }

                $disallowed = [
                    (string) $payment->requester_membership_id,
                    $payment->payer_membership_id === null
                        ? ''
                        : (string) $payment->payer_membership_id,
                    ...$this->affirmativeGovernanceActors(
                        (string) $business->getKey(),
                        (string) $payment->id,
                    ),
                ];

                if (in_array($reviewerId, array_values(array_unique($disallowed)), true)) {
                    return false;
                }
            }

            if ($result === 'cleared' && ! $this->hasVerifiedEvidence(
                (string) $business->getKey(),
                $exceptionId,
            )) {
                return false;
            }

            DB::table('finance_exception_reviews')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'finance_exception_id' => $exceptionId,
                'reviewer_membership_id' => $reviewerId,
                'result' => $result,
                'note' => $note,
                'reviewed_at' => now(),
            ]);

            DB::table('finance_exceptions')
                ->where('business_id', $business->getKey())
                ->where('id', $exceptionId)
                ->update([
                    'status' => $result,
                    'resolved_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->occurrence->record(
                $user,
                $business,
                'finance.exception.reviewed',
                'finance_exception',
                $exceptionId,
                ['result' => $result],
            );

            return true;
        });
    }

    /** @return list<string> */
    public function affirmativeGovernanceActors(
        string $businessId,
        string $paymentId,
    ): array {
        $decisionId = DB::table('finance_payment_submissions')
            ->where('business_id', $businessId)
            ->where('finance_payment_id', $paymentId)
            ->value('governance_decision_id');

        if ($decisionId === null && DB::getSchemaBuilder()->hasTable('distribution_run_payment_links')) {
            $decisionId = DB::table('distribution_run_payment_links as link')
                ->join('distribution_run_lines as line', function ($join): void {
                    $join->on('line.id', '=', 'link.distribution_run_line_id')
                        ->on('line.business_id', '=', 'link.business_id');
                })
                ->join('distribution_run_submissions as sub', function ($join): void {
                    $join->on('sub.distribution_run_id', '=', 'line.distribution_run_id')
                        ->on('sub.business_id', '=', 'line.business_id');
                })
                ->where('link.business_id', $businessId)
                ->where('link.finance_payment_id', $paymentId)
                ->value('sub.governance_decision_id');
        }

        if ($decisionId === null) {
            return [];
        }

        return array_values(array_unique(array_merge(
            DB::table('approvals')
                ->where('business_id', $businessId)
                ->where('decision_id', $decisionId)
                ->where('outcome', 'approved')
                ->pluck('membership_id')->map(fn ($v) => (string) $v)->all(),
            DB::table('votes')
                ->where('business_id', $businessId)
                ->where('decision_id', $decisionId)
                ->where('choice', 'for')
                ->pluck('membership_id')->map(fn ($v) => (string) $v)->all(),
        )));
    }

    private function hasVerifiedEvidence(
        string $businessId,
        string $exceptionId,
    ): bool {
        return DB::table('evidence_links as link')
            ->join('evidence as e', function ($join): void {
                $join->on('e.id', '=', 'link.evidence_id')
                    ->on('e.business_id', '=', 'link.business_id');
            })
            ->where('link.business_id', $businessId)
            ->where('link.target_type', 'finance_exception')
            ->where('link.target_id', $exceptionId)
            ->whereNotNull('e.verified_at')
            ->exists();
    }
}
