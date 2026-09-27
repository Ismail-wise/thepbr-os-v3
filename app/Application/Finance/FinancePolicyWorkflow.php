<?php

declare(strict_types=1);

namespace App\Application\Finance;

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

final class FinancePolicyWorkflow
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
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
                CapabilityCatalog::FINANCE_MANAGE,
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
        $contentHash = $this->contentHash($normalized);

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
                ->where('record_type', 'finance_policy')
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
                        'finance_policy',
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
                    'Initial Finance & Control Policy.',
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
                        'An existing Finance Policy draft/review must finish before another version can begin.',
                    );
                }

                $version = $this->createAmendedDraft->execute(
                    $user,
                    $business,
                    $capability,
                    (string) $head->formal_record_version_id,
                    $contentHash,
                    'Finance & Control Policy amendment.',
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
                'finance.policy.draft_created',
                'finance_policy',
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
        if (
            $this->actorContext->membership(
                $user,
                $business,
                CapabilityCatalog::FINANCE_MANAGE,
            ) === null
        ) {
            return null;
        }

        $version = FormalRecordVersion::query()
            ->where('business_id', $business->getKey())
            ->whereKey($formalRecordVersionId)
            ->first();

        if ($version === null) {
            return null;
        }

        $family = FormalRecordFamily::query()
            ->where('business_id', $business->getKey())
            ->whereKey($version->formal_record_family_id)
            ->where('record_type', 'finance_policy')
            ->first();

        if ($family === null) {
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
                'finance.policy.submitted',
                'finance_policy',
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
                'Finance Policy content review target is invalid.',
            );
        }

        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::FINANCE_MANAGE,
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
        $this->assertNoSecretPayloadKeys($payload);

        $operationsVersionId = DB::table('record_family_effective_heads as h')
            ->join('formal_record_versions as v', 'v.id', '=', 'h.formal_record_version_id')
            ->join('formal_record_families as f', 'f.id', '=', 'v.formal_record_family_id')
            ->where('h.business_id', $business->getKey())
            ->where('f.business_id', $business->getKey())
            ->where('f.record_type', 'operations_register')
            ->value('v.id');

        if ($operationsVersionId === null) {
            throw new InvalidArgumentException(
                'Finance Policy requires a Current Effective Operations Register.',
            );
        }

        $ownerFields = [
            'finance_owner_membership_id',
            'control_owner_membership_id',
            'bookkeeping_owner_membership_id',
        ];
        $ownerIds = [];

        foreach ($ownerFields as $field) {
            $id = trim((string) ($payload[$field] ?? ''));

            if ($id === '') {
                throw new InvalidArgumentException(
                    'Finance Policy owner assignments are required.',
                );
            }

            $ownerIds[] = $id;
        }

        if (
            DB::table('memberships')
                ->where('business_id', $business->getKey())
                ->where('access_status', 'active')
                ->whereIn('id', array_values(array_unique($ownerIds)))
                ->count()
            !== count(array_unique($ownerIds))
        ) {
            throw new InvalidArgumentException(
                'Finance Policy owners must be active Memberships in the current Business.',
            );
        }

        $currency = strtoupper(trim((string) ($payload['base_currency'] ?? '')));
        $accountingMethod = trim((string) ($payload['accounting_method'] ?? ''));
        $fiscalPeriod = trim((string) ($payload['fiscal_period'] ?? ''));

        if (
            preg_match('/^[A-Z]{3}$/', $currency) !== 1
            || $accountingMethod === ''
            || $fiscalPeriod === ''
        ) {
            throw new InvalidArgumentException(
                'Finance Policy currency/accounting/fiscal fields are invalid.',
            );
        }

        $banks = $this->normalizeBanks($payload['bank_accounts'] ?? []);
        $access = $this->normalizeBankAccess(
            $business,
            $banks,
            $payload['bank_access'] ?? [],
        );
        $paymentRules = $this->normalizePaymentRules(
            $business,
            (string) $operationsVersionId,
            $payload['payment_rules'] ?? [],
        );
        $expenseRules = $this->normalizeExpenseRules(
            $payload['expense_procurement_rules'] ?? [],
        );

        return [
            'operations_formal_record_version_id' => (string) $operationsVersionId,
            'finance_owner_membership_id' => $ownerIds[0],
            'control_owner_membership_id' => $ownerIds[1],
            'bookkeeping_owner_membership_id' => $ownerIds[2],
            'accounting_method' => $accountingMethod,
            'fiscal_period' => $fiscalPeriod,
            'base_currency' => $currency,
            'cash_handling_rules' => $this->nullableText($payload['cash_handling_rules'] ?? null),
            'monthly_closing_rules' => $this->nullableText($payload['monthly_closing_rules'] ?? null),
            'tax_coordination_rules' => $this->nullableText($payload['tax_coordination_rules'] ?? null),
            'audit_review_rules' => $this->nullableText($payload['audit_review_rules'] ?? null),
            'bank_accounts' => $banks,
            'bank_access' => $access,
            'payment_rules' => $paymentRules,
            'expense_procurement_rules' => $expenseRules,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function normalizeBanks(mixed $input): array
    {
        if (! is_array($input) || $input === []) {
            throw new InvalidArgumentException(
                'Finance Policy requires at least one Bank Account Reference.',
            );
        }

        $banks = [];
        $keys = [];

        foreach ($input as $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException('Bank reference must be structured.');
            }

            $key = strtolower(trim((string) ($raw['key'] ?? '')));
            $bankName = trim((string) ($raw['bank_name'] ?? ''));
            $accountName = trim((string) ($raw['account_name'] ?? ''));
            $reference = trim((string) ($raw['account_reference'] ?? ''));
            $currency = strtoupper(trim((string) ($raw['currency'] ?? '')));
            $purpose = trim((string) ($raw['account_purpose'] ?? ''));

            if (
                preg_match('/^[a-z0-9][a-z0-9_-]{0,95}$/', $key) !== 1
                || isset($keys[$key])
                || $bankName === ''
                || $accountName === ''
                || $reference === ''
                || preg_match('/^[A-Z]{3}$/', $currency) !== 1
                || $purpose === ''
            ) {
                throw new InvalidArgumentException('Bank Account Reference is invalid or duplicated.');
            }

            $keys[$key] = true;
            $banks[] = [
                'key' => $key,
                'bank_name' => $bankName,
                'account_name' => $accountName,
                'account_reference' => $reference,
                'currency' => $currency,
                'account_purpose' => $purpose,
                'status' => in_array(($raw['status'] ?? 'active'), ['active', 'inactive', 'closed'], true)
                    ? $raw['status'] ?? 'active'
                    : 'active',
            ];
        }

        usort($banks, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

        return $banks;
    }

    /** @param list<array<string,mixed>> $banks */
    private function normalizeBankAccess(
        Business $business,
        array $banks,
        mixed $input,
    ): array {
        if (! is_array($input) || $input === []) {
            throw new InvalidArgumentException(
                'Finance Policy requires controlled Bank Access assignments.',
            );
        }

        $bankKeys = array_fill_keys(array_column($banks, 'key'), true);
        $rows = [];
        $membershipIds = [];

        foreach ($input as $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException('Bank Access must be structured.');
            }

            $bankKey = strtolower(trim((string) ($raw['bank_key'] ?? '')));
            $membershipId = trim((string) ($raw['membership_id'] ?? ''));
            $level = trim((string) ($raw['access_level'] ?? ''));

            if (
                ! isset($bankKeys[$bankKey])
                || $membershipId === ''
                || $level === ''
            ) {
                throw new InvalidArgumentException('Bank Access assignment is invalid.');
            }

            $membershipIds[] = $membershipId;
            $limit = $raw['payment_limit_minor_units'] ?? null;

            if ($limit !== null && (! is_numeric($limit) || (int) $limit < 0)) {
                throw new InvalidArgumentException('Bank Access payment limit is invalid.');
            }

            $rows[] = [
                'bank_key' => $bankKey,
                'membership_id' => $membershipId,
                'access_level' => $level,
                'is_signatory' => (bool) ($raw['is_signatory'] ?? false),
                'is_backup_access' => (bool) ($raw['is_backup_access'] ?? false),
                'payment_limit_minor_units' => $limit === null ? null : (int) $limit,
                'last_access_review_date' => $this->nullableText($raw['last_access_review_date'] ?? null),
                'status' => ($raw['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            ];
        }

        $ids = array_values(array_unique($membershipIds));

        if (
            DB::table('memberships')
                ->where('business_id', $business->getKey())
                ->where('access_status', 'active')
                ->whereIn('id', $ids)
                ->count() !== count($ids)
        ) {
            throw new InvalidArgumentException(
                'Bank Access may reference only active same-Business Memberships.',
            );
        }

        usort($rows, static fn (array $a, array $b): int => strcmp(
            $a['bank_key'].$a['membership_id'],
            $b['bank_key'].$b['membership_id'],
        ));

        return $rows;
    }

    private function normalizePaymentRules(
        Business $business,
        string $operationsVersionId,
        mixed $input,
    ): array {
        if (! is_array($input) || $input === []) {
            throw new InvalidArgumentException(
                'Finance Policy requires a Payment & Approval Matrix.',
            );
        }

        $roleKeys = DB::table('operations_roles')
            ->where('business_id', $business->getKey())
            ->where('formal_record_version_id', $operationsVersionId)
            ->pluck('role_key')
            ->map(static fn ($v): string => (string) $v)
            ->all();
        $roleMap = array_fill_keys($roleKeys, true);
        $rules = [];
        $keys = [];

        foreach (array_values($input) as $index => $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException('Payment Authority Rule must be structured.');
            }

            $key = strtolower(trim((string) ($raw['rule_key'] ?? '')));
            $type = strtolower(trim((string) ($raw['transaction_type'] ?? '')));
            $requesterRole = strtolower(trim((string) ($raw['requester_operations_role_key'] ?? '')));
            $decisionType = trim((string) ($raw['governance_decision_type'] ?? ''));
            $payerLevel = trim((string) ($raw['payer_access_level'] ?? ''));

            if (
                preg_match('/^[a-z0-9][a-z0-9_-]{0,95}$/', $key) !== 1
                || isset($keys[$key])
                || $type === ''
                || ! isset($roleMap[$requesterRole])
                || $decisionType === ''
                || $payerLevel === ''
            ) {
                throw new InvalidArgumentException('Payment Authority Rule is invalid or duplicated.');
            }

            $keys[$key] = true;
            $min = $this->moneyOrNull($raw['amount_min_minor_units'] ?? null);
            $max = $this->moneyOrNull($raw['amount_max_minor_units'] ?? null);

            if ($min !== null && $max !== null && $max < $min) {
                throw new InvalidArgumentException('Payment Authority range is invalid.');
            }

            $strict = (bool) ($raw['strict_three_way_separation'] ?? true);
            $compensating = (bool) ($raw['compensating_review_allowed'] ?? false);

            if ($strict && $compensating) {
                throw new InvalidArgumentException(
                    'Strict three-way separation cannot simultaneously allow overlap compensation.',
                );
            }

            $rules[] = [
                'sequence' => $index + 1,
                'rule_key' => $key,
                'transaction_type' => $type,
                'amount_min_minor_units' => $min,
                'amount_max_minor_units' => $max,
                'requester_operations_role_key' => $requesterRole,
                'governance_decision_type' => $decisionType,
                'payer_access_level' => $payerLevel,
                'evidence_required' => (bool) ($raw['evidence_required'] ?? true),
                'strict_three_way_separation' => $strict,
                'compensating_review_allowed' => $compensating,
                'related_party_review_required' => (bool) ($raw['related_party_review_required'] ?? false),
            ];
        }

        return $rules;
    }

    private function normalizeExpenseRules(mixed $input): array
    {
        if (! is_array($input)) {
            throw new InvalidArgumentException('Expense/Procurement rules must be structured.');
        }

        $rows = [];
        $keys = [];

        foreach (array_values($input) as $index => $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException('Expense/Procurement Rule must be structured.');
            }

            $key = strtolower(trim((string) ($raw['rule_key'] ?? '')));
            $type = strtolower(trim((string) ($raw['control_type'] ?? 'expense')));
            $category = trim((string) ($raw['category'] ?? ''));

            if (
                preg_match('/^[a-z0-9][a-z0-9_-]{0,95}$/', $key) !== 1
                || isset($keys[$key])
                || ! in_array($type, ['expense', 'reimbursement', 'procurement', 'cash'], true)
                || $category === ''
            ) {
                throw new InvalidArgumentException('Expense/Procurement Rule is invalid.');
            }

            $keys[$key] = true;
            $rows[] = [
                'sequence' => $index + 1,
                'rule_key' => $key,
                'control_type' => $type,
                'category' => $category,
                'amount_min_minor_units' => $this->moneyOrNull($raw['amount_min_minor_units'] ?? null),
                'amount_max_minor_units' => $this->moneyOrNull($raw['amount_max_minor_units'] ?? null),
                'receipt_required' => (bool) ($raw['receipt_required'] ?? true),
                'quotation_count' => max(0, (int) ($raw['quotation_count'] ?? 0)),
                'supplier_approval_required' => (bool) ($raw['supplier_approval_required'] ?? false),
                'purchase_order_required' => (bool) ($raw['purchase_order_required'] ?? false),
                'invoice_match_required' => (bool) ($raw['invoice_match_required'] ?? false),
                'prohibited' => (bool) ($raw['prohibited'] ?? false),
                'rule_text' => $this->nullableText($raw['rule_text'] ?? null),
            ];
        }

        return $rows;
    }

    private function insertSnapshot(
        Business $business,
        FormalRecordVersion $version,
        array $normalized,
    ): void {
        $businessId = (string) $business->getKey();
        $versionId = (string) $version->getKey();

        DB::table('finance_policy_versions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $businessId,
            'formal_record_version_id' => $versionId,
            'operations_formal_record_version_id' => $normalized['operations_formal_record_version_id'],
            'finance_owner_membership_id' => $normalized['finance_owner_membership_id'],
            'control_owner_membership_id' => $normalized['control_owner_membership_id'],
            'bookkeeping_owner_membership_id' => $normalized['bookkeeping_owner_membership_id'],
            'accounting_method' => $normalized['accounting_method'],
            'fiscal_period' => $normalized['fiscal_period'],
            'base_currency' => $normalized['base_currency'],
            'cash_handling_rules' => $normalized['cash_handling_rules'],
            'monthly_closing_rules' => $normalized['monthly_closing_rules'],
            'tax_coordination_rules' => $normalized['tax_coordination_rules'],
            'audit_review_rules' => $normalized['audit_review_rules'],
            'created_at' => now(),
        ]);

        $bankIds = [];

        foreach ($normalized['bank_accounts'] as $bank) {
            $bankId = (string) Str::uuid7();
            $bankIds[$bank['key']] = $bankId;

            DB::table('finance_bank_account_references')->insert([
                'id' => $bankId,
                'business_id' => $businessId,
                'formal_record_version_id' => $versionId,
                'bank_name' => $bank['bank_name'],
                'account_name' => $bank['account_name'],
                'account_reference' => $bank['account_reference'],
                'currency' => $bank['currency'],
                'account_purpose' => $bank['account_purpose'],
                'status' => $bank['status'],
                'created_at' => now(),
            ]);
        }

        foreach ($normalized['bank_access'] as $access) {
            DB::table('finance_bank_access_assignments')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $businessId,
                'formal_record_version_id' => $versionId,
                'bank_account_reference_id' => $bankIds[$access['bank_key']],
                'membership_id' => $access['membership_id'],
                'access_level' => $access['access_level'],
                'is_signatory' => $access['is_signatory'],
                'is_backup_access' => $access['is_backup_access'],
                'payment_limit_minor_units' => $access['payment_limit_minor_units'],
                'last_access_review_date' => $access['last_access_review_date'],
                'status' => $access['status'],
                'created_at' => now(),
            ]);
        }

        foreach ($normalized['payment_rules'] as $rule) {
            DB::table('finance_payment_authority_rules')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $businessId,
                'formal_record_version_id' => $versionId,
                ...$rule,
                'created_at' => now(),
            ]);
        }

        foreach ($normalized['expense_procurement_rules'] as $rule) {
            DB::table('finance_expense_procurement_rules')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $businessId,
                'formal_record_version_id' => $versionId,
                ...$rule,
                'created_at' => now(),
            ]);
        }
    }

    /** @param array<string,mixed> $payload */
    private function assertNoSecretPayloadKeys(array $payload, string $prefix = ''): void
    {
        foreach ($payload as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if (preg_match('/password|passcode|\bpin\b|secret|otp|token|credential/', $normalizedKey) === 1) {
                throw new InvalidArgumentException(
                    'Finance Policy must never store passwords, PINs, OTPs, tokens or secret credentials.',
                );
            }

            if (is_array($value)) {
                $this->assertNoSecretPayloadKeys($value, $prefix.$normalizedKey.'.');
            }
        }
    }

    private function moneyOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (int) $value < 0) {
            throw new InvalidArgumentException('Money value must be non-negative minor units.');
        }

        return (int) $value;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    /** @param array<string,mixed> $payload */
    private function contentHash(array $payload): string
    {
        return hash(
            'sha256',
            json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        );
    }
}
