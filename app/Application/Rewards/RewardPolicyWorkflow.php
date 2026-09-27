<?php

declare(strict_types=1);

namespace App\Application\Rewards;

use App\Application\Finance\ResolveFinanceControl;
use App\Application\Governance\GovernanceActorContext;
use App\Application\Governance\RecordGovernanceOccurrence;
use App\Application\Records\CreateAmendedDraftVersion;
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
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\RecordFamilyEffectiveHead;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class RewardPolicyWorkflow
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly ResolveFinanceControl $finance,
        private readonly CreateFormalRecordFamily $createFamily,
        private readonly CreateDraftRecordVersion $createDraftVersion,
        private readonly CreateAmendedDraftVersion $createAmendedDraft,
        private readonly SubmitRecordVersionForReview $submitForReview,
        private readonly TransitionFormalRecordVersion $transitionRecord,
        private readonly CreateProposal $createProposal,
        private readonly FreezeProposalVersion $freezeProposal,
        private readonly RecordGovernanceOccurrence $occurrence,
    ) {}

    /** @param array<string,mixed> $payload */
    public function createDraft(
        User $user,
        Business $business,
        array $payload,
        DateTimeInterface $effectiveFrom,
        ?DateTimeInterface $reviewDueAt = null,
    ): ?array {
        if (
            $this->actorContext->membership(
                $user,
                $business,
                CapabilityCatalog::REWARDS_MANAGE,
            ) === null
            || $this->actorContext->membership(
                $user,
                $business,
                CapabilityCatalog::RECORDS_MANAGE,
            ) === null
        ) {
            return null;
        }

        $normalized = $this->normalizePayload($business, $payload);
        $contentHash = hash(
            'sha256',
            json_encode(
                $normalized,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        );

        return DB::transaction(function () use (
            $user,
            $business,
            $normalized,
            $contentHash,
            $effectiveFrom,
            $reviewDueAt,
        ): ?array {
            $capability = new Capability(CapabilityCatalog::RECORDS_MANAGE);
            $family = FormalRecordFamily::query()
                ->where('business_id', $business->getKey())
                ->where('record_type', 'reward_policy')
                ->where('subject_type', 'business')
                ->where('subject_id', $business->getKey())
                ->lockForUpdate()
                ->first();

            if ($family === null) {
                $family = $this->createFamily->execute(
                    $user,
                    $business,
                    $capability,
                    new RecordScope(
                        'reward_policy',
                        'business',
                        (string) $business->getKey(),
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
                    'Initial Reward & Distribution Policy.',
                    $effectiveFrom,
                    null,
                    $reviewDueAt,
                );
            } else {
                $head = RecordFamilyEffectiveHead::query()
                    ->where('business_id', $business->getKey())
                    ->where('formal_record_family_id', $family->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($head === null) {
                    throw new RuntimeException(
                        'An existing Reward Policy draft/review must finish before another version can begin.',
                    );
                }

                $version = $this->createAmendedDraft->execute(
                    $user,
                    $business,
                    $capability,
                    (string) $head->formal_record_version_id,
                    $contentHash,
                    'Reward & Distribution Policy amendment.',
                    $effectiveFrom,
                    null,
                    $reviewDueAt,
                );
            }

            if ($version === null) {
                return null;
            }

            $this->insertSnapshot($business, $version, $normalized);

            $this->occurrence->record(
                $user,
                $business,
                'rewards.policy.draft_created',
                'reward_policy',
                (string) $version->formal_record_family_id,
                [
                    'formal_record_version_id' => (string) $version->getKey(),
                    'version_number' => (int) $version->version_number,
                ],
                (string) $version->getKey(),
            );

            return [
                'formal_record_version_id' => (string) $version->getKey(),
                'version_number' => (int) $version->version_number,
            ];
        });
    }

    public function submitForGovernance(
        User $user,
        Business $business,
        string $formalRecordVersionId,
        int $expectedRevision,
    ): ?array {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::REWARDS_MANAGE,
        ) === null) {
            return null;
        }

        $version = FormalRecordVersion::query()
            ->where('business_id', $business->getKey())
            ->whereKey($formalRecordVersionId)
            ->first();

        if ($version === null || ! FormalRecordFamily::query()
            ->where('business_id', $business->getKey())
            ->whereKey($version->formal_record_family_id)
            ->where('record_type', 'reward_policy')
            ->exists()) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $version,
            $expectedRevision,
        ): ?array {
            $capability = new Capability(CapabilityCatalog::RECORDS_MANAGE);
            $frozen = $this->submitForReview->execute(
                $user,
                $business,
                $capability,
                (string) $version->getKey(),
                $expectedRevision,
            );

            if ($frozen === null) {
                return null;
            }

            $proposal = $this->createProposal->execute(
                $user,
                $business,
                $capability,
                (string) $frozen->content_hash,
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

            $this->occurrence->record(
                $user,
                $business,
                'rewards.policy.submitted',
                'reward_policy',
                (string) $frozen->formal_record_family_id,
                [
                    'formal_record_version_id' => (string) $frozen->getKey(),
                    'proposal_version_id' => (string) $proposalVersion->getKey(),
                ],
                (string) $frozen->getKey(),
            );

            return [
                'proposal_id' => (string) $proposal->getKey(),
                'proposal_version_id' => (string) $proposalVersion->getKey(),
            ];
        });
    }

    public function advanceContentReview(
        User $user,
        Business $business,
        string $formalRecordVersionId,
        FormalRecordState $target,
    ): bool {
        if (! in_array(
            $target,
            [
                FormalRecordState::UnderReview,
                FormalRecordState::Approved,
                FormalRecordState::ChangesRequested,
            ],
            true,
        )) {
            throw new InvalidArgumentException(
                'Reward Policy content review target is invalid.',
            );
        }

        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::REWARDS_MANAGE,
        ) === null) {
            return false;
        }

        return $this->transitionRecord->execute(
            $user,
            $business,
            new Capability(CapabilityCatalog::RECORDS_MANAGE),
            $formalRecordVersionId,
            $target,
        ) !== null;
    }

    /** @param array<string,mixed> $payload */
    private function normalizePayload(Business $business, array $payload): array
    {
        $finance = $this->finance->currentPolicy($business);

        if ($finance === null) {
            throw new InvalidArgumentException(
                'Reward Policy requires a Current Effective Finance Policy.',
            );
        }

        $financeHeader = $finance['header'];
        $operationsVersionId = (string) $financeHeader->operations_formal_record_version_id;
        $currentOperationsVersion = DB::table('record_family_effective_heads as h')
            ->join('formal_record_versions as v', 'v.id', '=', 'h.formal_record_version_id')
            ->join('formal_record_families as f', 'f.id', '=', 'v.formal_record_family_id')
            ->where('h.business_id', $business->getKey())
            ->where('f.business_id', $business->getKey())
            ->where('f.record_type', 'operations_register')
            ->value('v.id');

        if (
            $currentOperationsVersion === null
            || (string) $currentOperationsVersion !== $operationsVersionId
        ) {
            throw new InvalidArgumentException(
                'Reward Policy requires Finance and Operations to reference the same Current Effective Operations Register.',
            );
        }

        $rewardOwner = trim((string) ($payload['reward_owner_membership_id'] ?? ''));

        if (! DB::table('memberships')
            ->where('business_id', $business->getKey())
            ->where('id', $rewardOwner)
            ->where('access_status', 'active')
            ->exists()) {
            throw new InvalidArgumentException(
                'Reward Owner must be an active same-Business Membership.',
            );
        }

        $currency = strtoupper(trim((string) ($payload['currency'] ?? '')));

        if (
            preg_match('/^[A-Z]{3}$/', $currency) !== 1
            || $currency !== (string) $financeHeader->base_currency
        ) {
            throw new InvalidArgumentException(
                'Reward Policy currency must match the Effective Finance Policy.',
            );
        }

        $reservePct = $this->percent($payload['minimum_reserve_percent'] ?? 0);
        $reinvestPct = $this->percent($payload['reinvestment_percent'] ?? 0);
        $decisionType = trim((string) ($payload['distribution_governance_decision_type'] ?? ''));

        if ($decisionType === '') {
            throw new InvalidArgumentException(
                'Reward Policy distribution Governance decision type is required.',
            );
        }

        return [
            'finance_policy_formal_record_version_id' => $finance['formal_record_version_id'],
            'operations_formal_record_version_id' => $operationsVersionId,
            'reward_owner_membership_id' => $rewardOwner,
            'currency' => $currency,
            'payment_frequency' => trim((string) ($payload['payment_frequency'] ?? 'Monthly')) ?: 'Monthly',
            'minimum_reserve_percent' => $reservePct,
            'target_cash_buffer_minor_units' => $this->nonNegativeMoney($payload['target_cash_buffer_minor_units'] ?? 0),
            'reinvestment_percent' => $reinvestPct,
            'minimum_cash_after_distribution_minor_units' => $this->nonNegativeMoney($payload['minimum_cash_after_distribution_minor_units'] ?? 0),
            'distribution_governance_decision_type' => $decisionType,
            'manual_adjustments_allowed' => (bool) ($payload['manual_adjustments_allowed'] ?? false),
            'role_compensation_rules' => $this->roleCompensation(
                $business,
                $operationsVersionId,
                $payload['role_compensation_rules'] ?? [],
            ),
            'reimbursement_rules' => $this->reimbursements(
                $business,
                $finance['formal_record_version_id'],
                $payload['reimbursement_rules'] ?? [],
            ),
            'bonus_rules' => $this->bonuses(
                $business,
                $operationsVersionId,
                $payload['bonus_rules'] ?? [],
            ),
            'loan_repayment_rules' => $this->loans(
                $business,
                $payload['loan_repayment_rules'] ?? [],
            ),
            'distribution_rule' => $this->distributionRule(
                $payload['distribution_rule'] ?? [],
                (bool) ($payload['manual_adjustments_allowed'] ?? false),
            ),
        ];
    }

    private function roleCompensation(
        Business $business,
        string $operationsVersionId,
        mixed $input,
    ): array {
        if (! is_array($input)) {
            throw new InvalidArgumentException('Role Compensation rules must be structured.');
        }

        $rows = [];

        foreach ($input as $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException('Role Compensation Rule must be structured.');
            }

            $roleId = trim((string) ($raw['operations_role_id'] ?? ''));
            $partnerId = trim((string) ($raw['partner_id'] ?? ''));
            $memberId = trim((string) ($raw['membership_id'] ?? ''));
            $type = (string) ($raw['compensation_type'] ?? '');
            $frequency = trim((string) ($raw['frequency'] ?? ''));
            $start = trim((string) ($raw['start_date'] ?? ''));
            $end = trim((string) ($raw['end_date'] ?? ''));
            $decision = trim((string) ($raw['governance_decision_type'] ?? ''));

            if (
                ! in_array($type, ['salary', 'service_fee'], true)
                || $frequency === ''
                || preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) !== 1
                || ($end !== '' && $end < $start)
                || $decision === ''
            ) {
                throw new InvalidArgumentException('Role Compensation Rule is invalid.');
            }

            if (! DB::table('operations_roles')
                ->where('business_id', $business->getKey())
                ->where('formal_record_version_id', $operationsVersionId)
                ->where('id', $roleId)->exists()) {
                throw new InvalidArgumentException('Role Compensation must use the exact Operations role.');
            }

            if (! DB::table('operations_role_assignments')
                ->where('business_id', $business->getKey())
                ->where('operations_role_id', $roleId)
                ->where('membership_id', $memberId)
                ->where('assignment_type', 'primary')->exists()) {
                throw new InvalidArgumentException('Role Compensation payee must own the Operations role.');
            }

            if (! DB::table('partner_membership_links')
                ->where('business_id', $business->getKey())
                ->where('partner_id', $partnerId)
                ->where('membership_id', $memberId)->exists()) {
                throw new InvalidArgumentException('Role Compensation Partner/Membership link is invalid.');
            }

            $rows[] = [
                'operations_formal_record_version_id' => $operationsVersionId,
                'operations_role_id' => $roleId,
                'partner_id' => $partnerId,
                'membership_id' => $memberId,
                'compensation_type' => $type,
                'market_rate_minor_units' => isset($raw['market_rate_minor_units'])
                    ? $this->nonNegativeMoney($raw['market_rate_minor_units'])
                    : null,
                'amount_minor_units' => $this->positiveMoney($raw['amount_minor_units'] ?? null),
                'frequency' => $frequency,
                'start_date' => $start,
                'end_date' => $end === '' ? null : $end,
                'governance_decision_type' => $decision,
                'status' => ($raw['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            ];
        }

        return $rows;
    }

    private function reimbursements(
        Business $business,
        string $financePolicyVersionId,
        mixed $input,
    ): array {
        if (! is_array($input)) {
            throw new InvalidArgumentException('Reimbursement rules must be structured.');
        }

        $rows = [];
        $keys = [];

        foreach ($input as $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException('Reimbursement Rule must be structured.');
            }

            $key = strtolower(trim((string) ($raw['rule_key'] ?? '')));
            $financeRuleId = trim((string) ($raw['finance_expense_procurement_rule_id'] ?? ''));
            $decision = trim((string) ($raw['governance_decision_type'] ?? ''));

            if (
                preg_match('/^[a-z0-9][a-z0-9_-]{0,95}$/', $key) !== 1
                || isset($keys[$key])
                || $decision === ''
                || ! DB::table('finance_expense_procurement_rules')
                    ->where('business_id', $business->getKey())
                    ->where('formal_record_version_id', $financePolicyVersionId)
                    ->where('id', $financeRuleId)
                    ->where('control_type', 'reimbursement')
                    ->exists()
            ) {
                throw new InvalidArgumentException(
                    'Reimbursement Rule must reference a same-policy Finance reimbursement control.',
                );
            }

            $keys[$key] = true;
            $rows[] = [
                'rule_key' => $key,
                'finance_expense_procurement_rule_id' => $financeRuleId,
                'reimbursement_deadline_days' => max(0, (int) ($raw['reimbursement_deadline_days'] ?? 0)),
                'governance_decision_type' => $decision,
                'notes' => $this->nullableText($raw['notes'] ?? null),
            ];
        }

        return $rows;
    }

    private function bonuses(
        Business $business,
        string $operationsVersionId,
        mixed $input,
    ): array {
        if (! is_array($input)) {
            throw new InvalidArgumentException('Bonus rules must be structured.');
        }

        $rows = [];

        foreach ($input as $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException('Bonus Rule must be structured.');
            }

            $roleId = trim((string) ($raw['operations_role_id'] ?? ''));
            $kpiId = trim((string) ($raw['operations_kpi_id'] ?? ''));
            $partnerId = trim((string) ($raw['partner_id'] ?? ''));
            $type = trim((string) ($raw['bonus_type'] ?? ''));
            $trigger = trim((string) ($raw['trigger_description'] ?? ''));
            $decision = trim((string) ($raw['governance_decision_type'] ?? ''));

            if (
                $type === ''
                || $trigger === ''
                || $decision === ''
                || ! DB::table('operations_roles')
                    ->where('business_id', $business->getKey())
                    ->where('formal_record_version_id', $operationsVersionId)
                    ->where('id', $roleId)->exists()
            ) {
                throw new InvalidArgumentException('Bonus Rule Operations context is invalid.');
            }

            if ($kpiId !== '' && ! DB::table('operations_kpis')
                ->where('business_id', $business->getKey())
                ->where('formal_record_version_id', $operationsVersionId)
                ->where('operations_role_id', $roleId)
                ->where('id', $kpiId)->exists()) {
                throw new InvalidArgumentException('Bonus KPI must belong to the exact Operations role.');
            }

            if ($partnerId !== '' && ! DB::table('partners')
                ->where('business_id', $business->getKey())
                ->where('id', $partnerId)->exists()) {
                throw new InvalidArgumentException('Bonus Partner is invalid.');
            }

            $rows[] = [
                'operations_formal_record_version_id' => $operationsVersionId,
                'operations_role_id' => $roleId,
                'operations_kpi_id' => $kpiId === '' ? null : $kpiId,
                'partner_id' => $partnerId === '' ? null : $partnerId,
                'bonus_type' => $type,
                'trigger_description' => $trigger,
                'formula_text' => $this->nullableText($raw['formula_text'] ?? null),
                'approved_amount_minor_units' => isset($raw['approved_amount_minor_units'])
                    ? $this->nonNegativeMoney($raw['approved_amount_minor_units'])
                    : null,
                'cap_minor_units' => isset($raw['cap_minor_units'])
                    ? $this->nonNegativeMoney($raw['cap_minor_units'])
                    : null,
                'governance_decision_type' => $decision,
                'status' => ($raw['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            ];
        }

        return $rows;
    }

    private function loans(Business $business, mixed $input): array
    {
        if (! is_array($input)) {
            throw new InvalidArgumentException('Loan Repayment rules must be structured.');
        }

        $rows = [];

        foreach ($input as $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException('Loan Repayment Rule must be structured.');
            }

            $partnerId = trim((string) ($raw['partner_id'] ?? ''));
            $reference = trim((string) ($raw['loan_reference'] ?? ''));
            $frequency = trim((string) ($raw['frequency'] ?? ''));
            $start = trim((string) ($raw['start_date'] ?? ''));
            $end = trim((string) ($raw['end_date'] ?? ''));
            $decision = trim((string) ($raw['governance_decision_type'] ?? ''));

            if (
                $reference === ''
                || $frequency === ''
                || $decision === ''
                || preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) !== 1
                || ($end !== '' && $end < $start)
                || ! DB::table('partners')
                    ->where('business_id', $business->getKey())
                    ->where('id', $partnerId)->exists()
            ) {
                throw new InvalidArgumentException('Loan Repayment Rule is invalid.');
            }

            $rows[] = [
                'partner_id' => $partnerId,
                'loan_reference' => $reference,
                'scheduled_amount_minor_units' => $this->positiveMoney($raw['scheduled_amount_minor_units'] ?? null),
                'frequency' => $frequency,
                'start_date' => $start,
                'end_date' => $end === '' ? null : $end,
                'governance_decision_type' => $decision,
                'status' => ($raw['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            ];
        }

        return $rows;
    }

    private function distributionRule(
        mixed $input,
        bool $policyManualAdjustments,
    ): array {
        if (! is_array($input)) {
            throw new InvalidArgumentException('Distribution Rule must be structured.');
        }

        $basis = (string) ($input['distribution_basis'] ?? 'vested_profit_rights');

        if (! in_array(
            $basis,
            ['profit_rights', 'vested_profit_rights', 'shares_issued', 'shares_vested', 'special_rule'],
            true,
        )) {
            throw new InvalidArgumentException('Distribution basis is invalid.');
        }

        $statuses = $input['partner_status_rules'] ?? [];
        $classes = $input['share_class_rules'] ?? [];

        if (! is_array($statuses) || ! is_array($classes)) {
            throw new InvalidArgumentException('Distribution eligibility rules must be structured.');
        }

        return [
            'distribution_basis' => $basis,
            'vested_only' => (bool) ($input['vested_only'] ?? true),
            'record_date_rule' => $this->nullableText($input['record_date_rule'] ?? null),
            'unpaid_contribution_restriction' => (bool) ($input['unpaid_contribution_restriction'] ?? false),
            'leaver_treatment' => $this->nullableText($input['leaver_treatment'] ?? null),
            'special_rule_text' => $this->nullableText($input['special_rule_text'] ?? null),
            'manual_adjustment_allowed' => $policyManualAdjustments
                && (bool) ($input['manual_adjustment_allowed'] ?? false),
            'partner_status_rules' => array_map(
                static fn ($row): array => [
                    'partner_status' => trim((string) ($row['partner_status'] ?? '')),
                    'eligible' => (bool) ($row['eligible'] ?? false),
                ],
                $statuses,
            ),
            'share_class_rules' => array_map(
                static fn ($row): array => [
                    'share_class_name' => trim((string) ($row['share_class_name'] ?? '')),
                    'eligible' => (bool) ($row['eligible'] ?? false),
                ],
                $classes,
            ),
        ];
    }

    private function insertSnapshot(
        Business $business,
        FormalRecordVersion $version,
        array $normalized,
    ): void {
        $businessId = (string) $business->getKey();
        $versionId = (string) $version->getKey();

        DB::table('reward_policy_versions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $businessId,
            'formal_record_version_id' => $versionId,
            'finance_policy_formal_record_version_id' => $normalized['finance_policy_formal_record_version_id'],
            'operations_formal_record_version_id' => $normalized['operations_formal_record_version_id'],
            'reward_owner_membership_id' => $normalized['reward_owner_membership_id'],
            'currency' => $normalized['currency'],
            'payment_frequency' => $normalized['payment_frequency'],
            'minimum_reserve_percent' => $normalized['minimum_reserve_percent'],
            'target_cash_buffer_minor_units' => $normalized['target_cash_buffer_minor_units'],
            'reinvestment_percent' => $normalized['reinvestment_percent'],
            'minimum_cash_after_distribution_minor_units' => $normalized['minimum_cash_after_distribution_minor_units'],
            'distribution_governance_decision_type' => $normalized['distribution_governance_decision_type'],
            'manual_adjustments_allowed' => $normalized['manual_adjustments_allowed'],
            'created_at' => now(),
        ]);

        foreach ($normalized['role_compensation_rules'] as $row) {
            DB::table('reward_role_compensation_rules')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $businessId,
                'formal_record_version_id' => $versionId,
                ...$row,
                'created_at' => now(),
            ]);
        }

        foreach ($normalized['reimbursement_rules'] as $row) {
            DB::table('reward_reimbursement_rules')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $businessId,
                'formal_record_version_id' => $versionId,
                ...$row,
                'created_at' => now(),
            ]);
        }

        foreach ($normalized['bonus_rules'] as $row) {
            DB::table('reward_bonus_rules')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $businessId,
                'formal_record_version_id' => $versionId,
                ...$row,
                'created_at' => now(),
            ]);
        }

        foreach ($normalized['loan_repayment_rules'] as $row) {
            DB::table('reward_loan_repayment_rules')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $businessId,
                'formal_record_version_id' => $versionId,
                ...$row,
                'created_at' => now(),
            ]);
        }

        $dist = $normalized['distribution_rule'];
        $distId = (string) Str::uuid7();
        DB::table('reward_distribution_rules')->insert([
            'id' => $distId,
            'business_id' => $businessId,
            'formal_record_version_id' => $versionId,
            'distribution_basis' => $dist['distribution_basis'],
            'vested_only' => $dist['vested_only'],
            'record_date_rule' => $dist['record_date_rule'],
            'unpaid_contribution_restriction' => $dist['unpaid_contribution_restriction'],
            'leaver_treatment' => $dist['leaver_treatment'],
            'special_rule_text' => $dist['special_rule_text'],
            'manual_adjustment_allowed' => $dist['manual_adjustment_allowed'],
            'created_at' => now(),
        ]);

        foreach ($dist['partner_status_rules'] as $row) {
            if ($row['partner_status'] === '') {
                throw new InvalidArgumentException('Distribution Partner status rule cannot be blank.');
            }

            DB::table('reward_distribution_status_rules')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $businessId,
                'formal_record_version_id' => $versionId,
                'distribution_rule_id' => $distId,
                ...$row,
                'created_at' => now(),
            ]);
        }

        foreach ($dist['share_class_rules'] as $row) {
            if ($row['share_class_name'] === '') {
                throw new InvalidArgumentException('Distribution Share Class rule cannot be blank.');
            }

            DB::table('reward_distribution_class_rules')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $businessId,
                'formal_record_version_id' => $versionId,
                'distribution_rule_id' => $distId,
                ...$row,
                'created_at' => now(),
            ]);
        }
    }

    private function percent(mixed $value): string
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException('Reward percentage must be numeric.');
        }

        $number = (float) $value;

        if ($number < 0 || $number > 100) {
            throw new InvalidArgumentException('Reward percentage must be between 0 and 100.');
        }

        return number_format($number, 4, '.', '');
    }

    private function nonNegativeMoney(mixed $value): int
    {
        if (! is_numeric($value) || (int) $value < 0) {
            throw new InvalidArgumentException('Reward amount must be non-negative minor units.');
        }

        return (int) $value;
    }

    private function positiveMoney(mixed $value): int
    {
        $amount = $this->nonNegativeMoney($value);

        if ($amount <= 0) {
            throw new InvalidArgumentException('Reward amount must be positive.');
        }

        return $amount;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
