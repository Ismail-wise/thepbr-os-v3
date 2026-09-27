<?php

declare(strict_types=1);

namespace App\Application\Rewards;

use App\Application\Evidence\LinkEvidence;
use App\Application\Finance\FinancePaymentWorkflow;
use App\Application\Finance\ResolveFinanceControl;
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
use App\Domain\Records\Enums\FormalRecordState;
use App\Domain\Records\ValueObjects\RecordScope;
use App\Domain\Rewards\Enums\DistributionRunStatus;
use App\Domain\Rewards\Services\DistributionCalculator;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Rewards\DistributionRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class DistributionRunWorkflow
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly ResolveRewardPolicy $rewards,
        private readonly ResolveFinanceControl $finance,
        private readonly DistributionCalculator $calculator,
        private readonly FinancePaymentWorkflow $financePayments,
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
        $membership = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::REWARDS_MANAGE,
        );
        $policy = $this->rewards->currentPolicy($business);

        if (
            $membership === null
            || $policy === null
            || (string) $policy['header']->reward_owner_membership_id
                !== (string) $membership->getKey()
        ) {
            return null;
        }

        $reconciliationId = trim((string) ($payload['reconciliation_review_id'] ?? ''));
        $reconciliation = DB::table('finance_reconciliation_reviews')
            ->where('business_id', $business->getKey())
            ->where('id', $reconciliationId)
            ->where('status', 'completed')
            ->first();

        if (
            $reconciliation === null
            || (string) $reconciliation->finance_policy_formal_record_version_id
                !== (string) $policy['header']->finance_policy_formal_record_version_id
            || (string) $reconciliation->currency !== (string) $policy['header']->currency
        ) {
            return null;
        }

        $recordDate = trim((string) ($payload['record_date'] ?? ''));

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $recordDate) !== 1) {
            throw new InvalidArgumentException('Distribution record date is invalid.');
        }

        $ownership = DB::table('ownership_register_versions')
            ->where('business_id', $business->getKey())
            ->whereDate('effective_from', '<=', $recordDate)
            ->where(function ($query) use ($recordDate): void {
                $query->whereNull('effective_until')
                    ->orWhereDate('effective_until', '>', $recordDate);
            })
            ->whereIn('status', ['effective', 'superseded'])
            ->orderByDesc('effective_from')
            ->first();

        if ($ownership === null) {
            return null;
        }

        $netProfit = (int) $reconciliation->approved_net_profit_minor_units;
        $minimumReserve = $this->percentageFloor(
            max(0, $netProfit),
            (string) $policy['header']->minimum_reserve_percent,
        );
        $minimumReinvestment = $this->percentageFloor(
            max(0, $netProfit),
            (string) $policy['header']->reinvestment_percent,
        );
        $reserve = isset($payload['required_reserve_minor_units'])
            ? $this->nonNegative($payload['required_reserve_minor_units'])
            : $minimumReserve;
        $reinvestment = isset($payload['reinvestment_minor_units'])
            ? $this->nonNegative($payload['reinvestment_minor_units'])
            : $minimumReinvestment;
        $adjustments = (int) ($payload['adjustments_minor_units'] ?? 0);

        if ($reserve < $minimumReserve || $reinvestment < $minimumReinvestment) {
            throw new InvalidArgumentException(
                'Distribution reserve/reinvestment cannot fall below Effective Reward Policy minimums.',
            );
        }

        if ($adjustments !== 0) {
            if (! (bool) $policy['header']->manual_adjustments_allowed) {
                throw new InvalidArgumentException(
                    'Effective Reward Policy does not allow Distribution adjustments.',
                );
            }

            if ($this->nullableText($payload['notes'] ?? null) === null) {
                throw new InvalidArgumentException(
                    'Distribution adjustment requires a documented reason.',
                );
            }
        }

        $waterfall = $this->calculator->waterfall(
            $netProfit,
            (int) $reconciliation->tax_due_minor_units,
            (int) $reconciliation->debt_due_minor_units,
            $reserve,
            $reinvestment,
            $adjustments,
        );

        if ($waterfall['distributable_profit_minor_units'] <= 0) {
            throw new InvalidArgumentException(
                'Distribution Run requires positive distributable profit after the approved waterfall.',
            );
        }

        $positions = $this->eligiblePositions(
            $business,
            (string) $ownership->id,
            $policy,
            $payload['special_weights'] ?? [],
        );

        if ($positions === []) {
            return null;
        }

        $weights = [];

        foreach ($positions as $position) {
            $weights[(string) $position->id] = (string) $position->eligible_weight;
        }

        $allocations = $this->calculator->allocate(
            $waterfall['distributable_profit_minor_units'],
            $weights,
        );

        return DB::transaction(function () use (
            $user,
            $business,
            $membership,
            $policy,
            $reconciliation,
            $ownership,
            $recordDate,
            $waterfall,
            $positions,
            $allocations,
            $payload,
        ): string {
            $id = (string) Str::uuid7();

            DistributionRun::query()->create([
                'id' => $id,
                'business_id' => $business->getKey(),
                'reward_policy_formal_record_version_id' => $policy['formal_record_version_id'],
                'finance_policy_formal_record_version_id' => $policy['header']->finance_policy_formal_record_version_id,
                'reconciliation_review_id' => $reconciliation->id,
                'ownership_register_version_id' => $ownership->id,
                'period_start' => $reconciliation->period_start,
                'period_end' => $reconciliation->period_end,
                'record_date' => $recordDate,
                'currency' => $reconciliation->currency,
                'approved_net_profit_minor_units' => $waterfall['approved_net_profit_minor_units'],
                'tax_due_minor_units' => $waterfall['tax_due_minor_units'],
                'debt_due_minor_units' => $waterfall['debt_due_minor_units'],
                'required_reserve_minor_units' => $waterfall['required_reserve_minor_units'],
                'reinvestment_minor_units' => $waterfall['reinvestment_minor_units'],
                'adjustments_minor_units' => $waterfall['adjustments_minor_units'],
                'distributable_profit_minor_units' => $waterfall['distributable_profit_minor_units'],
                'cash_available_minor_units' => $reconciliation->cash_available_minor_units,
                'status' => DistributionRunStatus::Draft->value,
                'revision' => 1,
                'notes' => $this->nullableText($payload['notes'] ?? null),
                'created_by_membership_id' => $membership->getKey(),
            ]);

            foreach ($positions as $position) {
                DB::table('distribution_run_lines')->insert([
                    'id' => (string) Str::uuid7(),
                    'business_id' => $business->getKey(),
                    'distribution_run_id' => $id,
                    'ownership_register_position_id' => $position->id,
                    'ownership_share_class_id' => $position->share_class_id,
                    'partner_id' => $position->partner_id,
                    'eligible_weight' => $position->eligible_weight,
                    'calculated_amount_minor_units' => $allocations[(string) $position->id],
                    'manual_adjustment_minor_units' => 0,
                    'adjustment_reason' => null,
                    'final_amount_minor_units' => $allocations[(string) $position->id],
                    'created_at' => now(),
                ]);
            }

            $this->occurrence->record(
                $user,
                $business,
                'rewards.distribution.draft_created',
                'distribution_run',
                $id,
                [
                    'ownership_register_version_id' => (string) $ownership->id,
                    'distributable_profit_minor_units' => $waterfall['distributable_profit_minor_units'],
                ],
            );

            return $id;
        });
    }

    public function attachEvidence(
        User $user,
        Business $business,
        string $runId,
        string $evidenceId,
    ): bool {
        if (! DistributionRun::query()
            ->where('business_id', $business->getKey())
            ->whereKey($runId)
            ->exists()) {
            return false;
        }

        return $this->linkEvidence->execute(
            $user,
            $business,
            $evidenceId,
            'distribution_run',
            $runId,
        ) !== null;
    }

    public function adjustLine(
        User $user,
        Business $business,
        string $runId,
        string $lineId,
        int $expectedRevision,
        int $adjustmentMinorUnits,
        string $reason,
    ): bool {
        return DB::transaction(function () use (
            $user,
            $business,
            $runId,
            $lineId,
            $expectedRevision,
            $adjustmentMinorUnits,
            $reason,
        ): bool {
            $membership = $this->actorContext->membership(
                $user,
                $business,
                CapabilityCatalog::REWARDS_MANAGE,
            );
            $run = DistributionRun::query()
                ->where('business_id', $business->getKey())
                ->whereKey($runId)
                ->lockForUpdate()
                ->first();
            $policy = $this->rewards->currentPolicy($business);

            if (
                $membership === null
                || $run === null
                || $run->status !== DistributionRunStatus::Draft->value
                || (int) $run->revision !== $expectedRevision
                || $policy === null
                || (string) $policy['formal_record_version_id']
                    !== (string) $run->reward_policy_formal_record_version_id
                || ! (bool) $policy['distribution_rule']?->manual_adjustment_allowed
            ) {
                return false;
            }

            $line = DB::table('distribution_run_lines')
                ->where('business_id', $business->getKey())
                ->where('distribution_run_id', $runId)
                ->where('id', $lineId)
                ->lockForUpdate()
                ->first();

            $reason = trim($reason);
            $final = $line === null
                ? -1
                : (int) $line->calculated_amount_minor_units + $adjustmentMinorUnits;

            if ($line === null || $reason === '' || $final < 0) {
                return false;
            }

            DB::table('distribution_run_lines')
                ->where('business_id', $business->getKey())
                ->where('id', $lineId)
                ->update([
                    'manual_adjustment_minor_units' => $adjustmentMinorUnits,
                    'adjustment_reason' => $reason,
                    'final_amount_minor_units' => $final,
                ]);

            $run->update(['revision' => $expectedRevision + 1]);

            return true;
        });
    }

    public function financeVerifyAndSubmit(
        User $user,
        Business $business,
        string $runId,
        int $expectedRevision,
    ): ?array {
        return DB::transaction(function () use (
            $user,
            $business,
            $runId,
            $expectedRevision,
        ): ?array {
            $run = DistributionRun::query()
                ->where('business_id', $business->getKey())
                ->whereKey($runId)
                ->lockForUpdate()
                ->first();

            if (
                $run === null
                || $run->status !== DistributionRunStatus::Draft->value
                || (int) $run->revision !== $expectedRevision
            ) {
                return null;
            }

            $verifier = $this->finance->verifierMembership(
                $user,
                $business,
                (string) $run->finance_policy_formal_record_version_id,
            );

            if ($verifier === null) {
                return null;
            }

            $policy = $this->rewards->currentPolicy($business);

            if (
                $policy === null
                || (string) $policy['formal_record_version_id']
                    !== (string) $run->reward_policy_formal_record_version_id
            ) {
                return null;
            }

            $lines = DB::table('distribution_run_lines')
                ->where('business_id', $business->getKey())
                ->where('distribution_run_id', $runId)
                ->orderBy('id')
                ->get();

            if (
                $lines->isEmpty()
                || (int) $lines->sum('final_amount_minor_units')
                    !== (int) $run->distributable_profit_minor_units
            ) {
                throw new RuntimeException(
                    'Distribution final allocations must exactly equal distributable profit.',
                );
            }

            $hasManualLineAdjustment = $lines->contains(
                fn ($line): bool => (int) $line->manual_adjustment_minor_units !== 0,
            );

            if (
                ((int) $run->adjustments_minor_units !== 0 || $hasManualLineAdjustment)
                && (
                    ! (bool) $policy['header']->manual_adjustments_allowed
                    || ! $this->hasVerifiedRunEvidence(
                        (string) $business->getKey(),
                        $runId,
                    )
                )
            ) {
                return null;
            }

            $cashFloor = max(
                (int) $policy['header']->target_cash_buffer_minor_units,
                (int) $policy['header']->minimum_cash_after_distribution_minor_units,
            );

            if (
                (int) $run->cash_available_minor_units
                    - (int) $run->distributable_profit_minor_units
                < $cashFloor
            ) {
                throw new RuntimeException(
                    'Distribution would breach the Effective Reward Policy cash floor.',
                );
            }

            $snapshot = $this->snapshot($run, $lines->all());
            $hash = hash(
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
                    'distribution_run',
                    'distribution_run',
                    $runId,
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
                $hash,
                'Finance-verified Distribution Run.',
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

            if (
                $frozen === null
                || $this->transitionRecord->execute(
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
                $hash,
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

            DB::table('distribution_run_submissions')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'distribution_run_id' => $runId,
                'run_revision' => $expectedRevision,
                'content_hash' => $hash,
                'formal_record_version_id' => $frozen->getKey(),
                'proposal_version_id' => $proposalVersion->getKey(),
                'governance_decision_id' => null,
                'approved_at' => null,
                'created_at' => now(),
            ]);

            $run->update([
                'status' => DistributionRunStatus::GovernancePending->value,
                'finance_verified_by_membership_id' => $verifier->getKey(),
                'finance_verified_at' => now(),
                'revision' => $expectedRevision + 1,
            ]);

            return [
                'formal_record_version_id' => (string) $frozen->getKey(),
                'proposal_version_id' => (string) $proposalVersion->getKey(),
                'governance_decision_type' => (string) $policy['header']->distribution_governance_decision_type,
                'governance_amount' => $this->minorToMajor((int) $run->distributable_profit_minor_units),
            ];
        });
    }

    public function syncGovernanceApproval(
        User $user,
        Business $business,
        string $runId,
    ): bool {
        return DB::transaction(function () use ($user, $business, $runId): bool {
            if ($this->actorContext->membership(
                $user,
                $business,
                CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
            ) === null) {
                return false;
            }

            $run = DistributionRun::query()
                ->where('business_id', $business->getKey())
                ->whereKey($runId)
                ->lockForUpdate()
                ->first();
            $submission = DB::table('distribution_run_submissions')
                ->where('business_id', $business->getKey())
                ->where('distribution_run_id', $runId)
                ->lockForUpdate()
                ->first();
            $policy = $this->rewards->currentPolicy($business);

            if (
                $run === null
                || $submission === null
                || $policy === null
                || $run->status !== DistributionRunStatus::GovernancePending->value
                || (string) $policy['formal_record_version_id']
                    !== (string) $run->reward_policy_formal_record_version_id
            ) {
                return false;
            }

            $decision = DB::table('decisions')
                ->where('business_id', $business->getKey())
                ->where('proposal_version_id', $submission->proposal_version_id)
                ->where(
                    'decision_type',
                    $policy['header']->distribution_governance_decision_type,
                )
                ->where(
                    'decision_amount',
                    $this->minorToMajor((int) $run->distributable_profit_minor_units),
                )
                ->where('status', 'decided')
                ->where('outcome', 'approved')
                ->first();

            if (
                $decision === null
                || ! $this->prepareEffect->execute(
                    $user,
                    $business,
                    (string) $decision->id,
                    (string) $submission->formal_record_version_id,
                )
            ) {
                return false;
            }

            DB::table('distribution_run_submissions')
                ->where('business_id', $business->getKey())
                ->where('distribution_run_id', $runId)
                ->update([
                    'governance_decision_id' => $decision->id,
                    'approved_at' => now(),
                ]);

            $run->update(['status' => DistributionRunStatus::Approved->value]);

            $this->occurrence->record(
                $user,
                $business,
                'rewards.distribution.governance_approved',
                'distribution_run',
                $runId,
                ['governance_decision_id' => (string) $decision->id],
                (string) $submission->formal_record_version_id,
            );

            return true;
        });
    }

    public function schedulePayments(
        User $user,
        Business $business,
        string $runId,
        string $bankAccountReferenceId,
    ): bool {
        return DB::transaction(function () use (
            $user,
            $business,
            $runId,
            $bankAccountReferenceId,
        ): bool {
            $membership = $this->actorContext->membership(
                $user,
                $business,
                CapabilityCatalog::REWARDS_MANAGE,
            );
            $run = DistributionRun::query()
                ->where('business_id', $business->getKey())
                ->whereKey($runId)
                ->lockForUpdate()
                ->first();
            $submission = DB::table('distribution_run_submissions')
                ->where('business_id', $business->getKey())
                ->where('distribution_run_id', $runId)
                ->first();
            $policy = $this->rewards->currentPolicy($business);

            if (
                $membership === null
                || $run === null
                || $submission === null
                || $submission->governance_decision_id === null
                || $policy === null
                || $run->status !== DistributionRunStatus::Approved->value
            ) {
                return false;
            }

            $bank = DB::table('finance_bank_account_references')
                ->where('business_id', $business->getKey())
                ->where(
                    'formal_record_version_id',
                    $run->finance_policy_formal_record_version_id,
                )
                ->where('id', $bankAccountReferenceId)
                ->where('status', 'active')
                ->first();

            if ($bank === null || (string) $bank->currency !== (string) $run->currency) {
                return false;
            }

            $lines = DB::table('distribution_run_lines')
                ->where('business_id', $business->getKey())
                ->where('distribution_run_id', $runId)
                ->orderBy('id')
                ->get();

            foreach ($lines as $line) {
                if ((int) $line->final_amount_minor_units === 0) {
                    continue;
                }

                $partner = DB::table('partners')
                    ->where('business_id', $business->getKey())
                    ->where('id', $line->partner_id)
                    ->first();

                if ($partner === null) {
                    return false;
                }

                $paymentId = $this->financePayments
                    ->createAuthorizedDistributionPayment(
                        $user,
                        $business,
                        (string) $membership->getKey(),
                        (string) $run->finance_policy_formal_record_version_id,
                        'profit_distribution',
                        (int) $line->final_amount_minor_units,
                        (string) $run->currency,
                        $bankAccountReferenceId,
                        (string) $partner->display_name,
                        (string) $run->finance_verified_by_membership_id,
                        (string) $policy['header']->distribution_governance_decision_type,
                    );

                if ($paymentId === null) {
                    throw new RuntimeException(
                        'Distribution payment could not satisfy the Effective Finance Payment Authority Rule.',
                    );
                }

                DB::table('distribution_run_payment_links')->insert([
                    'id' => (string) Str::uuid7(),
                    'business_id' => $business->getKey(),
                    'distribution_run_line_id' => $line->id,
                    'finance_payment_id' => $paymentId,
                    'created_at' => now(),
                ]);
            }

            $run->update([
                'status' => DistributionRunStatus::PaymentScheduled->value,
            ]);

            return true;
        });
    }

    public function complete(
        User $user,
        Business $business,
        string $runId,
    ): bool {
        return DB::transaction(function () use ($user, $business, $runId): bool {
            if ($this->actorContext->membership(
                $user,
                $business,
                CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
            ) === null) {
                return false;
            }

            $run = DistributionRun::query()
                ->where('business_id', $business->getKey())
                ->whereKey($runId)
                ->lockForUpdate()
                ->first();
            $submission = DB::table('distribution_run_submissions')
                ->where('business_id', $business->getKey())
                ->where('distribution_run_id', $runId)
                ->first();

            if (
                $run === null
                || $submission === null
                || $submission->governance_decision_id === null
                || $run->status !== DistributionRunStatus::PaymentScheduled->value
            ) {
                return false;
            }

            $pending = DB::table('distribution_run_lines as line')
                ->leftJoin('distribution_run_payment_links as link', function ($join): void {
                    $join->on('link.distribution_run_line_id', '=', 'line.id')
                        ->on('link.business_id', '=', 'line.business_id');
                })
                ->leftJoin('finance_payments as payment', function ($join): void {
                    $join->on('payment.id', '=', 'link.finance_payment_id')
                        ->on('payment.business_id', '=', 'link.business_id');
                })
                ->where('line.business_id', $business->getKey())
                ->where('line.distribution_run_id', $runId)
                ->where('line.final_amount_minor_units', '>', 0)
                ->where(function ($query): void {
                    $query->whereNull('payment.id')
                        ->orWhere('payment.status', '<>', 'completed');
                })
                ->exists();

            if ($pending) {
                return false;
            }

            if (! $this->makeEffective->execute(
                $user,
                $business,
                (string) $submission->governance_decision_id,
                (string) $submission->formal_record_version_id,
            )) {
                return false;
            }

            $run->update([
                'status' => DistributionRunStatus::Completed->value,
                'completed_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'rewards.distribution.completed',
                'distribution_run',
                $runId,
                ['formal_record_version_id' => (string) $submission->formal_record_version_id],
                (string) $submission->formal_record_version_id,
            );

            return true;
        });
    }

    /** @return list<object> */
    private function eligiblePositions(
        Business $business,
        string $ownershipVersionId,
        array $policy,
        mixed $specialWeights,
    ): array {
        $rule = $policy['distribution_rule'];

        if ($rule === null) {
            return [];
        }

        $statusRules = DB::table('reward_distribution_status_rules')
            ->where('business_id', $business->getKey())
            ->where('distribution_rule_id', $rule->id)
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->partner_status => (bool) $row->eligible])
            ->all();
        $classRules = DB::table('reward_distribution_class_rules')
            ->where('business_id', $business->getKey())
            ->where('distribution_rule_id', $rule->id)
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->share_class_name => (bool) $row->eligible])
            ->all();

        $rows = DB::table('ownership_register_positions as p')
            ->join('ownership_register_share_classes as c', 'c.id', '=', 'p.share_class_id')
            ->join('partners as partner', function ($join): void {
                $join->on('partner.id', '=', 'p.partner_id')
                    ->on('partner.business_id', '=', 'p.business_id');
            })
            ->where('p.business_id', $business->getKey())
            ->where('p.ownership_register_version_id', $ownershipVersionId)
            ->orderBy('p.id')
            ->get([
                'p.id',
                'p.partner_id',
                'p.share_class_id',
                'p.shares_issued',
                'p.shares_vested',
                'p.profit_rights',
                'c.name as share_class_name',
                'c.profit_right_per_share',
                'partner.status as partner_status',
                DB::raw(
                    'CAST((p.shares_vested * c.profit_right_per_share) AS numeric(28,8)) as vested_profit_rights',
                ),
            ]);

        $special = is_array($specialWeights) ? $specialWeights : [];
        $eligible = [];

        foreach ($rows as $row) {
            if (
                $statusRules !== []
                && ! ($statusRules[(string) $row->partner_status] ?? false)
            ) {
                continue;
            }

            if (
                $classRules !== []
                && ! ($classRules[(string) $row->share_class_name] ?? false)
            ) {
                continue;
            }

            $weight = match ((string) $rule->distribution_basis) {
                'profit_rights' => (string) $row->profit_rights,
                'vested_profit_rights' => (string) $row->vested_profit_rights,
                'shares_issued' => (string) $row->shares_issued,
                'shares_vested' => (string) $row->shares_vested,
                'special_rule' => isset($special[(string) $row->id])
                    ? (string) $special[(string) $row->id]
                    : '0',
                default => '0',
            };

            $row->eligible_weight = $weight;
            $eligible[] = $row;
        }

        return $eligible;
    }

    /** @param list<object> $lines */
    private function snapshot(DistributionRun $run, array $lines): array
    {
        $evidence = DB::table('evidence_links')
            ->where('business_id', $run->business_id)
            ->where('target_type', 'distribution_run')
            ->where('target_id', $run->getKey())
            ->orderBy('evidence_id')
            ->pluck('evidence_id')
            ->map(fn ($v): string => (string) $v)
            ->all();

        return [
            'distribution_run_id' => (string) $run->getKey(),
            'run_revision' => (int) $run->revision,
            'reward_policy_formal_record_version_id' => (string) $run->reward_policy_formal_record_version_id,
            'finance_policy_formal_record_version_id' => (string) $run->finance_policy_formal_record_version_id,
            'reconciliation_review_id' => (string) $run->reconciliation_review_id,
            'ownership_register_version_id' => (string) $run->ownership_register_version_id,
            'period_start' => (string) $run->period_start,
            'period_end' => (string) $run->period_end,
            'record_date' => (string) $run->record_date,
            'currency' => (string) $run->currency,
            'approved_net_profit_minor_units' => (int) $run->approved_net_profit_minor_units,
            'tax_due_minor_units' => (int) $run->tax_due_minor_units,
            'debt_due_minor_units' => (int) $run->debt_due_minor_units,
            'required_reserve_minor_units' => (int) $run->required_reserve_minor_units,
            'reinvestment_minor_units' => (int) $run->reinvestment_minor_units,
            'adjustments_minor_units' => (int) $run->adjustments_minor_units,
            'distributable_profit_minor_units' => (int) $run->distributable_profit_minor_units,
            'lines' => array_map(
                static fn ($line): array => [
                    'ownership_register_position_id' => (string) $line->ownership_register_position_id,
                    'ownership_share_class_id' => (string) $line->ownership_share_class_id,
                    'partner_id' => (string) $line->partner_id,
                    'eligible_weight' => (string) $line->eligible_weight,
                    'calculated_amount_minor_units' => (int) $line->calculated_amount_minor_units,
                    'manual_adjustment_minor_units' => (int) $line->manual_adjustment_minor_units,
                    'adjustment_reason' => $line->adjustment_reason,
                    'final_amount_minor_units' => (int) $line->final_amount_minor_units,
                ],
                $lines,
            ),
            'evidence_ids' => $evidence,
        ];
    }

    private function hasVerifiedRunEvidence(
        string $businessId,
        string $runId,
    ): bool {
        return DB::table('evidence_links as link')
            ->join('evidence as e', function ($join): void {
                $join->on('e.id', '=', 'link.evidence_id')
                    ->on('e.business_id', '=', 'link.business_id');
            })
            ->where('link.business_id', $businessId)
            ->where('link.target_type', 'distribution_run')
            ->where('link.target_id', $runId)
            ->whereNotNull('e.verified_at')
            ->exists();
    }

    private function percentageFloor(int $amount, string $percentage): int
    {
        $basisPoints = (int) round((float) $percentage * 10000);

        return intdiv(($amount * $basisPoints) + 999999, 1000000);
    }

    private function nonNegative(mixed $value): int
    {
        if (! is_numeric($value) || (int) $value < 0) {
            throw new InvalidArgumentException(
                'Distribution amount must be non-negative minor units.',
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
