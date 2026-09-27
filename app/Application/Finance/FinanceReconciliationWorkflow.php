<?php

declare(strict_types=1);

namespace App\Application\Finance;

use App\Application\Governance\RecordGovernanceOccurrence;
use App\Domain\Finance\Enums\FinanceReconciliationStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class FinanceReconciliationWorkflow
{
    public function __construct(
        private readonly ResolveFinanceControl $controls,
        private readonly RecordGovernanceOccurrence $occurrence,
    ) {}

    /** @param array<string,mixed> $fields */
    public function create(
        User $user,
        Business $business,
        array $fields,
    ): ?string {
        $policy = $this->controls->currentPolicy($business);

        if ($policy === null) {
            return null;
        }

        $membership = $this->controls->verifierMembership(
            $user,
            $business,
            $policy['formal_record_version_id'],
        );

        if ($membership === null) {
            return null;
        }

        $normalized = $this->normalize($fields);

        $id = (string) Str::uuid7();

        DB::table('finance_reconciliation_reviews')->insert([
            'id' => $id,
            'business_id' => $business->getKey(),
            'finance_policy_formal_record_version_id' => $policy['formal_record_version_id'],
            ...$normalized,
            'status' => FinanceReconciliationStatus::Open->value,
            'revision' => 1,
            'created_by_membership_id' => $membership->getKey(),
            'reviewed_by_membership_id' => null,
            'completed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->occurrence->record(
            $user,
            $business,
            'finance.reconciliation.created',
            'finance_reconciliation',
            $id,
            [
                'period_start' => $normalized['period_start'],
                'period_end' => $normalized['period_end'],
            ],
        );

        return $id;
    }

    /** @param array<string,mixed> $fields */
    public function updateOpen(
        User $user,
        Business $business,
        string $reviewId,
        int $expectedRevision,
        array $fields,
    ): bool {
        return DB::transaction(function () use (
            $user,
            $business,
            $reviewId,
            $expectedRevision,
            $fields,
        ): bool {
            $row = DB::table('finance_reconciliation_reviews')
                ->where('business_id', $business->getKey())
                ->where('id', $reviewId)
                ->lockForUpdate()
                ->first();

            if (
                $row === null
                || $row->status !== FinanceReconciliationStatus::Open->value
                || (int) $row->revision !== $expectedRevision
                || $this->controls->verifierMembership(
                    $user,
                    $business,
                    (string) $row->finance_policy_formal_record_version_id,
                ) === null
            ) {
                return false;
            }

            $normalized = $this->normalize($fields);

            $updated = DB::table('finance_reconciliation_reviews')
                ->where('business_id', $business->getKey())
                ->where('id', $reviewId)
                ->where('revision', $expectedRevision)
                ->update([
                    ...$normalized,
                    'revision' => $expectedRevision + 1,
                    'updated_at' => now(),
                ]);

            return $updated === 1;
        });
    }

    public function complete(
        User $user,
        Business $business,
        string $reviewId,
        int $expectedRevision,
    ): ?string {
        return DB::transaction(function () use (
            $user,
            $business,
            $reviewId,
            $expectedRevision,
        ): ?string {
            $row = DB::table('finance_reconciliation_reviews')
                ->where('business_id', $business->getKey())
                ->where('id', $reviewId)
                ->lockForUpdate()
                ->first();

            if (
                $row === null
                || $row->status !== FinanceReconciliationStatus::Open->value
                || (int) $row->revision !== $expectedRevision
            ) {
                return null;
            }

            $membership = $this->controls->verifierMembership(
                $user,
                $business,
                (string) $row->finance_policy_formal_record_version_id,
            );

            if ($membership === null) {
                return null;
            }

            $status = (int) $row->unreconciled_items_count === 0
                ? FinanceReconciliationStatus::Completed
                : FinanceReconciliationStatus::Exception;

            DB::table('finance_reconciliation_reviews')
                ->where('business_id', $business->getKey())
                ->where('id', $reviewId)
                ->where('revision', $expectedRevision)
                ->update([
                    'status' => $status->value,
                    'reviewed_by_membership_id' => $membership->getKey(),
                    'completed_at' => now(),
                    'revision' => $expectedRevision + 1,
                    'updated_at' => now(),
                ]);

            $this->occurrence->record(
                $user,
                $business,
                'finance.reconciliation.'.$status->value,
                'finance_reconciliation',
                $reviewId,
                ['status' => $status->value],
            );

            return $status->value;
        });
    }

    /** @param array<string,mixed> $fields */
    private function normalize(array $fields): array
    {
        $periodStart = trim((string) ($fields['period_start'] ?? ''));
        $periodEnd = trim((string) ($fields['period_end'] ?? ''));
        $currency = strtoupper(trim((string) ($fields['currency'] ?? '')));

        if (
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd) !== 1
            || $periodEnd < $periodStart
            || preg_match('/^[A-Z]{3}$/', $currency) !== 1
        ) {
            throw new InvalidArgumentException(
                'Finance Reconciliation period/currency is invalid.',
            );
        }

        $signed = [
            'opening_cash_minor_units',
            'inflows_minor_units',
            'outflows_minor_units',
            'closing_cash_minor_units',
            'approved_net_profit_minor_units',
            'cash_available_minor_units',
        ];
        $normalized = [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'currency' => $currency,
        ];

        foreach ($signed as $key) {
            if (! isset($fields[$key]) || ! is_numeric($fields[$key])) {
                throw new InvalidArgumentException(
                    'Finance Reconciliation monetary fields require integer minor units.',
                );
            }

            $normalized[$key] = (int) $fields[$key];
        }

        foreach (['tax_due_minor_units', 'debt_due_minor_units'] as $key) {
            if (! isset($fields[$key]) || ! is_numeric($fields[$key]) || (int) $fields[$key] < 0) {
                throw new InvalidArgumentException(
                    'Finance Reconciliation tax/debt values must be non-negative.',
                );
            }

            $normalized[$key] = (int) $fields[$key];
        }

        $unreconciled = (int) ($fields['unreconciled_items_count'] ?? 0);

        if ($unreconciled < 0) {
            throw new InvalidArgumentException(
                'Unreconciled item count cannot be negative.',
            );
        }

        $normalized['unreconciled_items_count'] = $unreconciled;
        $notes = trim((string) ($fields['notes'] ?? ''));
        $normalized['notes'] = $notes === '' ? null : $notes;

        return $normalized;
    }
}
