<script setup lang="ts">
import OptionalTemporalInput from '../../components/OptionalTemporalInput.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import { useI18n } from '../../i18n/useI18n';

type Membership = { id: string; email: string };
type Role = { id: string; role_key: string; name: string };
type VersionRow = { id: string; version_number: number; revision: number; frozen_at: string | null; effective_from: string | null; state: string | null };
type Bank = { id: string; bank_name: string; account_name: string; account_reference: string; currency: string; account_purpose: string; status: string };
type BankAccess = { id: string; bank_account_reference_id: string; membership_id: string; access_level: string; is_signatory: boolean; is_backup_access: boolean; payment_limit_minor_units: number | null };
type PaymentRule = { id: string; rule_key: string; transaction_type: string; amount_min_minor_units: number | null; amount_max_minor_units: number | null; requester_operations_role_key: string; governance_decision_type: string; payer_access_level: string; evidence_required: boolean; strict_three_way_separation: boolean; compensating_review_allowed: boolean };
type ExpenseRule = { id: string; rule_key: string; control_type: string; category: string; receipt_required: boolean; quotation_count: number; prohibited: boolean };
type Payment = { id: string; transaction_type: string; amount_minor_units: number; currency: string; payee_reference: string; status: string; revision: number; related_party: boolean; payer_membership_id: string | null; reconciliation_review_id: string | null };
type Reconciliation = { id: string; period_start: string; period_end: string; currency: string; closing_cash_minor_units: number; approved_net_profit_minor_units: number; unreconciled_items_count: number; status: string; revision: number };
type ExceptionRow = { id: string; exception_type: string; severity: string; reason: string; status: string; requires_compensating_review: boolean; finance_payment_id: string | null };
type CurrentPolicy = {
    formal_record_version_id: string;
    header: { base_currency: string; accounting_method: string; fiscal_period: string; finance_owner_membership_id: string; control_owner_membership_id: string; bookkeeping_owner_membership_id: string };
    bank_accounts: Bank[];
    bank_access: BankAccess[];
    payment_rules: PaymentRule[];
    expense_rules: ExpenseRule[];
};

const props = defineProps<{
    finance: {
        business: { id: string; name: string };
        permissions: { manage: boolean; pay: boolean };
        current: CurrentPolicy | null;
        versions: VersionRow[];
        payments: Payment[];
        reconciliations: Reconciliation[];
        exceptions: ExceptionRow[];
        memberships: Membership[];
        operations_roles: Role[];
    };
}>();

const { t } = useI18n();
const firstMember = props.finance.memberships[0]?.id ?? '';
const firstRoleKey = props.finance.operations_roles[0]?.role_key ?? '';
const currentCurrency = props.finance.current?.header.base_currency ?? 'USD';
const today = new Date().toISOString().slice(0, 10);
const memberLabel = (id: string | null) => props.finance.memberships.find((member) => member.id === id)?.email ?? id ?? '—';
const bankLabel = (id: string) => {
    const bank = props.finance.current?.bank_accounts.find((row) => row.id === id);
    return bank ? bank.bank_name + ' · ' + bank.account_reference : id;
};
const money = (minor: number | null, currency = currentCurrency) =>
    minor === null ? '—' : (Number(minor) / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + currency;
type PostData = NonNullable<Parameters<typeof router.post>[1]>;
const post = (url: string, data: PostData = {}) => router.post(url, data, { preserveScroll: true });

const policy = useForm({
    effective_from: today,
    review_due_at: '',
    finance_owner_membership_id: firstMember,
    control_owner_membership_id: firstMember,
    bookkeeping_owner_membership_id: firstMember,
    accounting_method: 'Accrual',
    fiscal_period: 'Calendar year',
    base_currency: currentCurrency,
    cash_handling_rules: 'All cash movements require recorded purpose and supporting evidence.',
    monthly_closing_rules: 'Reconcile controlled accounts monthly before closing the period.',
    tax_coordination_rules: 'Track tax due separately and verify before any distribution.',
    audit_review_rules: 'Review exceptions, access and reconciliations on a defined schedule.',
    bank_accounts: [{ key: 'operating', bank_name: '', account_name: '', account_reference: '', currency: currentCurrency, account_purpose: 'Operating payments', status: 'active' }],
    bank_access: [{ bank_key: 'operating', membership_id: firstMember, access_level: 'payer', is_signatory: true, is_backup_access: false, payment_limit_minor_units: null as number | null, last_access_review_date: '', status: 'active' }],
    payment_rules: [{ rule_key: 'general_payment', transaction_type: 'general_payment', amount_min_minor_units: 0, amount_max_minor_units: null as number | null, requester_operations_role_key: firstRoleKey, governance_decision_type: 'finance_payment_approval', payer_access_level: 'payer', evidence_required: true, strict_three_way_separation: true, compensating_review_allowed: false, related_party_review_required: true }],
    expense_procurement_rules: [] as Array<{ rule_key: string; control_type: string; category: string; amount_min_minor_units: number | null; amount_max_minor_units: number | null; receipt_required: boolean; quotation_count: number; supplier_approval_required: boolean; purchase_order_required: boolean; invoice_match_required: boolean; prohibited: boolean; rule_text: string }>,
});
const addExpenseRule = () => policy.expense_procurement_rules.push({ rule_key: 'expense_' + (policy.expense_procurement_rules.length + 1), control_type: 'expense', category: 'General', amount_min_minor_units: 0, amount_max_minor_units: null, receipt_required: true, quotation_count: 0, supplier_approval_required: false, purchase_order_required: false, invoice_match_required: false, prohibited: false, rule_text: '' });

const reconciliation = useForm({ period_start: today.slice(0, 8) + '01', period_end: today, currency: currentCurrency, opening_cash_minor_units: 0, inflows_minor_units: 0, outflows_minor_units: 0, closing_cash_minor_units: 0, approved_net_profit_minor_units: 0, tax_due_minor_units: 0, debt_due_minor_units: 0, cash_available_minor_units: 0, unreconciled_items_count: 0, notes: '' });
const payment = useForm({ transaction_type: props.finance.current?.payment_rules[0]?.transaction_type ?? 'general_payment', amount_minor_units: 1, currency: currentCurrency, bank_account_reference_id: props.finance.current?.bank_accounts[0]?.id ?? '', payee_reference: '', description: '', related_party: false });
const exceptionForm = useForm({ exception_type: 'segregation_of_duties', reason: '', finance_payment_id: '', reconciliation_review_id: '', requires_compensating_review: true, severity: 'medium' });
const evidenceIds = reactive<Record<string, string>>({});
const paymentReferences = reactive<Record<string, string>>({});
const reconciliationIds = reactive<Record<string, string>>({});
const exceptionNotes = reactive<Record<string, string>>({});
const completedReconciliations = computed(() => props.finance.reconciliations.filter((row) => row.status === 'completed'));
const pendingCount = computed(() => props.finance.payments.filter((row) => !['completed', 'rejected', 'cancelled'].includes(row.status)).length + props.finance.exceptions.filter((row) => !['cleared', 'blocked', 'resolved'].includes(row.status)).length);
</script>

<template>
    <Head :title="t('finance.title')" />
    <AuthenticatedLayout>
        <main class="min-h-screen bg-[radial-gradient(circle_at_88%_0%,rgb(210_167_67_/_8%),transparent_26rem),linear-gradient(180deg,#f7f9f6_0%,#f1f5f1_100%)] px-4 py-5 text-[var(--pbr-ink)] sm:px-6 sm:py-6 lg:px-8 lg:py-7">
            <div class="mx-auto w-full max-w-[1500px]">
            <header class="rounded-[24px] border border-[#d8e4da] bg-white/90 p-5 shadow-[0_14px_34px_rgb(16_35_26_/_5%)] sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ finance.business.name }}</p>
                        <h1 class="mt-2 text-2xl font-black tracking-[-0.02em] text-[var(--pbr-ink)]">{{ t('finance.title') }}</h1>
                        <p class="mt-2 max-w-4xl text-sm leading-6 text-[var(--pbr-muted)]">{{ t('finance.description') }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Link href="/records/documents" class="inline-flex min-h-11 items-center rounded-xl border border-[#d8e4da] bg-white px-4 text-sm font-bold text-slate-800">Document Vault</Link>
                        <Link href="/governance" class="inline-flex min-h-11 items-center rounded-xl border border-[#d8e4da] bg-white px-4 text-sm font-bold text-slate-800">Governance</Link>
                    </div>
                </div>
                <p class="mt-5 rounded-[16px] border border-[#cfe1d3] bg-[#f3f8f4] px-4 py-3 text-sm font-bold text-[var(--pbr-green-dark)]">{{ t('finance.boundary') }}</p>
            </header>

            <section class="mt-5 rounded-[22px] border border-[#d8e4da] bg-white/90 p-5 shadow-[0_10px_28px_rgb(16_35_26_/_4%)]">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]">{{ t('finance.controlFlowTitle') }}</p>
                <p class="mt-2 max-w-4xl text-sm leading-6 text-[var(--pbr-muted)]">{{ t('finance.controlFlowHelp') }}</p>
                <div class="mt-4 grid gap-2 sm:grid-cols-5">
                    <div
                        v-for="(label, index) in [
                            t('finance.stepRequest'),
                            t('finance.stepApprove'),
                            t('finance.stepPay'),
                            t('finance.stepEvidence'),
                            t('finance.stepReconcile'),
                        ]"
                        :key="label"
                        class="rounded-[16px] border border-[#dde7df] bg-[#f8faf8] px-3 py-3"
                    >
                        <span class="text-[10px] font-black text-[#829087]">{{ index + 1 }}</span>
                        <p class="mt-1 text-sm font-black text-[var(--pbr-ink)]">{{ label }}</p>
                    </div>
                </div>
            </section>

            <section class="mt-5 grid gap-3 md:grid-cols-3">
                <div class="rounded-[18px] border border-[#dde7df] bg-white/90 p-4 shadow-[0_8px_22px_rgb(16_35_26_/_3%)]"><p class="text-xs font-bold uppercase tracking-wide text-[var(--pbr-muted)]">Needs attention</p><p class="mt-2 text-2xl font-black">{{ pendingCount }}</p><p class="mt-1 text-xs text-[var(--pbr-muted)]">Open payments + exceptions</p></div>
                <div class="rounded-[18px] border border-[#dde7df] bg-white/90 p-4 shadow-[0_8px_22px_rgb(16_35_26_/_3%)]"><p class="text-xs font-bold uppercase tracking-wide text-[var(--pbr-muted)]">Effective policy</p><p class="mt-2 font-black">{{ finance.current ? finance.current.header.fiscal_period : 'Not effective' }}</p><p class="mt-1 text-xs text-[var(--pbr-muted)]">{{ finance.current?.header.accounting_method ?? 'Create and govern a policy first.' }}</p></div>
                <div class="rounded-[18px] border border-[#e8d9ab] bg-[#fffaf0] p-4 shadow-[0_8px_22px_rgb(16_35_26_/_3%)]"><p class="text-xs font-bold uppercase tracking-wide text-[#7d672d]">Completed reconciliations</p><p class="mt-2 text-2xl font-black text-[#66531f]">{{ completedReconciliations.length }}</p><p class="mt-1 text-xs text-[#7d672d]">Canonical period verification</p></div>
            </section>

            <section class="mt-6 rounded-[22px] border border-[#d8e4da] bg-white/90 p-5 shadow-[0_10px_28px_rgb(16_35_26_/_4%)] sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div><h2 class="text-lg font-bold">Current Effective Finance Policy</h2><p class="mt-1 text-sm text-slate-600">Only the Effective version controls Finance. Draft/Review versions cannot authorize money.</p></div>
                    <span v-if="finance.current" class="text-xs font-semibold text-slate-500">{{ finance.current.header.base_currency }}</span>
                </div>
                <div v-if="!finance.current" class="mt-4 border border-dashed border-slate-300 p-5 text-sm text-slate-500">No Effective Finance Policy yet.</div>
                <template v-else>
                    <div class="mt-4 grid gap-5 xl:grid-cols-2">
                        <div class="overflow-x-auto border border-slate-200">
                            <div class="border-b border-slate-200 px-4 py-3 font-semibold">Bank & Access Register</div>
                            <table class="min-w-full text-left text-sm">
                                <thead><tr class="border-b border-slate-200 bg-slate-50 text-slate-600"><th class="px-3 py-3">Account</th><th class="px-3 py-3">Purpose</th><th class="px-3 py-3">Access</th></tr></thead>
                                <tbody>
                                    <tr v-for="bank in finance.current.bank_accounts" :key="bank.id" class="border-b border-slate-100 align-top">
                                        <td class="px-3 py-3"><p class="font-semibold">{{ bank.bank_name }}</p><p class="text-xs text-slate-500">{{ bank.account_name }} · {{ bank.account_reference }}</p></td>
                                        <td class="px-3 py-3">{{ bank.account_purpose }}</td>
                                        <td class="px-3 py-3 text-xs"><p v-for="access in finance.current.bank_access.filter((row) => row.bank_account_reference_id === bank.id)" :key="access.id">{{ memberLabel(access.membership_id) }} · {{ access.access_level }} · limit {{ money(access.payment_limit_minor_units, bank.currency) }}</p></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="overflow-x-auto border border-slate-200">
                            <div class="border-b border-slate-200 px-4 py-3 font-semibold">Payment Authority Matrix</div>
                            <table class="min-w-full text-left text-sm">
                                <thead><tr class="border-b border-slate-200 bg-slate-50 text-slate-600"><th class="px-3 py-3">Transaction</th><th class="px-3 py-3">Requester</th><th class="px-3 py-3">Governance</th><th class="px-3 py-3">Separation</th></tr></thead>
                                <tbody><tr v-for="rule in finance.current.payment_rules" :key="rule.id" class="border-b border-slate-100"><td class="px-3 py-3"><p class="font-semibold">{{ rule.transaction_type }}</p><p class="text-xs text-slate-500">{{ money(rule.amount_min_minor_units) }} → {{ money(rule.amount_max_minor_units) }}</p></td><td class="px-3 py-3">{{ rule.requester_operations_role_key }}</td><td class="px-3 py-3 text-xs">{{ rule.governance_decision_type }}</td><td class="px-3 py-3 text-xs">{{ rule.strict_three_way_separation ? 'Strict 3-way' : rule.compensating_review_allowed ? 'Compensating review allowed' : 'Controlled' }}</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </template>
            </section>

            <section class="mt-6 rounded-[22px] border border-[#d8e4da] bg-white/90 p-5 shadow-[0_10px_28px_rgb(16_35_26_/_4%)]">
                <h2 class="text-lg font-black text-[var(--pbr-ink)]">Finance Policy History</h2>
                <div class="mt-3 overflow-x-auto border border-slate-200">
                    <table class="min-w-full text-left text-sm">
                        <thead><tr class="border-b border-slate-200 bg-slate-50"><th class="px-3 py-3">Version</th><th class="px-3 py-3">State</th><th class="px-3 py-3">Effective from</th><th class="px-3 py-3">Action</th></tr></thead>
                        <tbody>
                            <tr v-for="version in finance.versions" :key="version.id" class="border-b border-slate-100">
                                <td class="px-3 py-3 font-semibold">v{{ version.version_number }}</td><td class="px-3 py-3"><span class="border border-slate-300 px-2 py-1 text-xs">{{ version.state ?? 'Unknown' }}</span></td><td class="px-3 py-3">{{ version.effective_from ?? '—' }}</td>
                                <td class="px-3 py-3"><div v-if="finance.permissions.manage" class="flex flex-wrap gap-2"><button v-if="version.state === 'draft'" class="text-xs font-semibold underline" @click="post('/finance/policy/' + version.id + '/submit', { expected_revision: version.revision })">Submit</button><button v-if="version.state === 'ready_for_review'" class="text-xs font-semibold underline" @click="post('/finance/policy/' + version.id + '/content-review', { target: 'under_review' })">Start review</button><button v-if="version.state === 'under_review'" class="text-xs font-semibold underline" @click="post('/finance/policy/' + version.id + '/content-review', { target: 'approved' })">Approve content</button></div></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <details v-if="finance.permissions.manage" class="mt-6 rounded-[20px] border border-[#d8e4da] bg-white/90 shadow-[0_10px_28px_rgb(16_35_26_/_4%)]">
                <summary class="cursor-pointer px-5 py-4 font-semibold">Create Finance Policy Draft / Amendment</summary>
                <form class="space-y-6 border-t border-slate-200 p-5" @submit.prevent="policy.post('/finance/policy', { preserveScroll: true })">
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <label class="text-sm font-medium">Effective from<input v-model="policy.effective_from" type="date" required class="mt-1 min-h-11 w-full border border-slate-300 px-3" /></label>
                        <label class="text-sm font-medium">Base currency<input v-model="policy.base_currency" maxlength="3" required class="mt-1 min-h-11 w-full border border-slate-300 px-3 uppercase" /></label>
                        <label class="text-sm font-medium">Accounting method<input v-model="policy.accounting_method" required class="mt-1 min-h-11 w-full border border-slate-300 px-3" /></label>
                        <label class="text-sm font-medium">Fiscal period<input v-model="policy.fiscal_period" required class="mt-1 min-h-11 w-full border border-slate-300 px-3" /></label>
                        <label class="text-sm font-medium">Finance Owner<select v-model="policy.finance_owner_membership_id" class="mt-1 min-h-11 w-full border border-slate-300 px-3"><option v-for="m in finance.memberships" :key="m.id" :value="m.id">{{ m.email }}</option></select></label>
                        <label class="text-sm font-medium">Control Owner<select v-model="policy.control_owner_membership_id" class="mt-1 min-h-11 w-full border border-slate-300 px-3"><option v-for="m in finance.memberships" :key="m.id" :value="m.id">{{ m.email }}</option></select></label>
                        <label class="text-sm font-medium">Bookkeeping Owner<select v-model="policy.bookkeeping_owner_membership_id" class="mt-1 min-h-11 w-full border border-slate-300 px-3"><option v-for="m in finance.memberships" :key="m.id" :value="m.id">{{ m.email }}</option></select></label>
                        <label class="text-sm font-medium">Review due<OptionalTemporalInput v-model="policy.review_due_at" type="date" class="mt-1 min-h-11 w-full border border-slate-300 px-3" /></label>
                    </div>
                    <div class="grid gap-3 lg:grid-cols-2"><textarea v-model="policy.cash_handling_rules" class="min-h-24 border border-slate-300 p-3 text-sm" placeholder="Cash handling rules" /><textarea v-model="policy.monthly_closing_rules" class="min-h-24 border border-slate-300 p-3 text-sm" placeholder="Monthly closing rules" /><textarea v-model="policy.tax_coordination_rules" class="min-h-24 border border-slate-300 p-3 text-sm" placeholder="Tax coordination rules" /><textarea v-model="policy.audit_review_rules" class="min-h-24 border border-slate-300 p-3 text-sm" placeholder="Audit/review rules" /></div>

                    <div>
                        <div class="flex items-center justify-between"><h3 class="font-semibold">Bank Account References</h3><button type="button" class="text-xs font-semibold underline" @click="policy.bank_accounts.push({ key: 'bank_' + (policy.bank_accounts.length + 1), bank_name: '', account_name: '', account_reference: '', currency: policy.base_currency, account_purpose: '', status: 'active' })">Add account</button></div>
                        <div v-for="(bank, index) in policy.bank_accounts" :key="index" class="mt-3 grid gap-2 border-l-2 border-slate-300 pl-3 md:grid-cols-3"><input v-model="bank.key" required class="min-h-10 border border-slate-300 px-2" placeholder="Key" /><input v-model="bank.bank_name" required class="min-h-10 border border-slate-300 px-2" placeholder="Bank name" /><input v-model="bank.account_name" required class="min-h-10 border border-slate-300 px-2" placeholder="Account name" /><input v-model="bank.account_reference" required class="min-h-10 border border-slate-300 px-2" placeholder="Reference / masked number" /><input v-model="bank.currency" maxlength="3" required class="min-h-10 border border-slate-300 px-2 uppercase" placeholder="Currency" /><input v-model="bank.account_purpose" required class="min-h-10 border border-slate-300 px-2" placeholder="Purpose" /></div>
                        <p class="mt-2 text-xs font-medium text-amber-800">Never enter password, PIN, OTP, token or secret credentials.</p>
                    </div>

                    <div><h3 class="font-semibold">Bank Access Assignments</h3><div v-for="(access, index) in policy.bank_access" :key="index" class="mt-3 grid gap-2 md:grid-cols-4"><select v-model="access.bank_key" class="min-h-10 border border-slate-300 px-2"><option v-for="bank in policy.bank_accounts" :key="bank.key" :value="bank.key">{{ bank.key }}</option></select><select v-model="access.membership_id" class="min-h-10 border border-slate-300 px-2"><option v-for="m in finance.memberships" :key="m.id" :value="m.id">{{ m.email }}</option></select><input v-model="access.access_level" required class="min-h-10 border border-slate-300 px-2" placeholder="Access level" /><input v-model.number="access.payment_limit_minor_units" type="number" min="0" class="min-h-10 border border-slate-300 px-2" placeholder="Limit minor units" /></div><button type="button" class="mt-2 text-xs font-semibold underline" @click="policy.bank_access.push({ bank_key: policy.bank_accounts[0]?.key ?? '', membership_id: firstMember, access_level: 'payer', is_signatory: false, is_backup_access: false, payment_limit_minor_units: null, last_access_review_date: '', status: 'active' })">Add access</button></div>

                    <div><h3 class="font-semibold">Payment Authority Rules</h3><div v-for="(rule, index) in policy.payment_rules" :key="index" class="mt-3 grid gap-2 border border-slate-200 p-3 md:grid-cols-3"><input v-model="rule.rule_key" required class="min-h-10 border border-slate-300 px-2" placeholder="Rule key" /><input v-model="rule.transaction_type" required class="min-h-10 border border-slate-300 px-2" placeholder="Transaction type" /><select v-model="rule.requester_operations_role_key" class="min-h-10 border border-slate-300 px-2"><option v-for="role in finance.operations_roles" :key="role.id" :value="role.role_key">{{ role.name }}</option></select><input v-model="rule.governance_decision_type" required class="min-h-10 border border-slate-300 px-2" placeholder="Governance decision type" /><input v-model="rule.payer_access_level" required class="min-h-10 border border-slate-300 px-2" placeholder="Payer access level" /><div class="flex flex-wrap items-center gap-3 text-xs"><label><input v-model="rule.evidence_required" type="checkbox" /> Evidence</label><label><input v-model="rule.strict_three_way_separation" type="checkbox" /> Strict 3-way</label><label><input v-model="rule.compensating_review_allowed" type="checkbox" /> Compensating review</label></div></div></div>
                    <div><div class="flex items-center justify-between"><h3 class="font-semibold">Expense / Procurement Controls</h3><button type="button" class="text-xs font-semibold underline" @click="addExpenseRule">Add control</button></div><div v-for="(rule, index) in policy.expense_procurement_rules" :key="index" class="mt-3 grid gap-2 md:grid-cols-4"><input v-model="rule.rule_key" class="min-h-10 border border-slate-300 px-2" placeholder="Rule key" /><select v-model="rule.control_type" class="min-h-10 border border-slate-300 px-2"><option value="expense">Expense</option><option value="reimbursement">Reimbursement validity</option><option value="procurement">Procurement</option><option value="cash">Cash</option></select><input v-model="rule.category" class="min-h-10 border border-slate-300 px-2" placeholder="Category" /><label class="flex min-h-10 items-center gap-2 text-xs"><input v-model="rule.receipt_required" type="checkbox" /> Receipt required</label></div></div>
                    <button type="submit" :disabled="policy.processing" class="min-h-11 bg-slate-950 px-5 text-sm font-semibold text-white disabled:opacity-50">Save Draft</button>
                </form>
            </details>

            <section class="mt-6 grid gap-5 xl:grid-cols-2">
                <div class="rounded-[22px] border border-[#d8e4da] bg-white/90 p-5 shadow-[0_10px_28px_rgb(16_35_26_/_4%)]">
                    <h2 class="text-lg font-black text-[var(--pbr-ink)]">Reconciliation Register</h2>
                    <div class="mt-3 overflow-x-auto border border-slate-200"><table class="min-w-full text-left text-sm"><thead><tr class="border-b bg-slate-50"><th class="px-3 py-3">Period</th><th class="px-3 py-3">Cash</th><th class="px-3 py-3">Status</th><th class="px-3 py-3">Action</th></tr></thead><tbody><tr v-for="row in finance.reconciliations" :key="row.id" class="border-b border-slate-100"><td class="px-3 py-3">{{ row.period_start }} → {{ row.period_end }}</td><td class="px-3 py-3">{{ money(row.closing_cash_minor_units, row.currency) }}</td><td class="px-3 py-3">{{ row.status }}<span v-if="row.unreconciled_items_count" class="block text-xs text-amber-700">{{ row.unreconciled_items_count }} unreconciled</span></td><td class="px-3 py-3"><button v-if="finance.permissions.manage && row.status === 'open'" class="text-xs font-semibold underline" @click="post('/finance/reconciliations/' + row.id + '/complete', { expected_revision: row.revision })">Complete review</button></td></tr><tr v-if="finance.reconciliations.length === 0"><td colspan="4" class="px-3 py-5 text-slate-500">No reconciliation records.</td></tr></tbody></table></div>
                    <details v-if="finance.permissions.manage && finance.current" class="mt-3 border border-slate-200">
                        <summary class="cursor-pointer px-4 py-3 text-sm font-semibold">New reconciliation</summary>
                        <form class="grid gap-3 border-t border-slate-200 p-4 md:grid-cols-2" @submit.prevent="reconciliation.post('/finance/reconciliations', { preserveScroll: true })">
                            <label class="text-sm font-medium text-slate-700">{{ t('finance.reconciliation.periodStart') }}<input v-model="reconciliation.period_start" type="date" required class="mt-1 min-h-10 w-full border border-slate-300 px-2" /></label>
                            <label class="text-sm font-medium text-slate-700">{{ t('finance.reconciliation.periodEnd') }}<input v-model="reconciliation.period_end" type="date" required class="mt-1 min-h-10 w-full border border-slate-300 px-2" /></label>
                            <label class="text-sm font-medium text-slate-700">{{ t('finance.reconciliation.openingCash') }}<input v-model.number="reconciliation.opening_cash_minor_units" type="number" class="mt-1 min-h-10 w-full border border-slate-300 px-2" placeholder="e.g. 100000" /></label>
                            <label class="text-sm font-medium text-slate-700">{{ t('finance.reconciliation.closingCash') }}<input v-model.number="reconciliation.closing_cash_minor_units" type="number" class="mt-1 min-h-10 w-full border border-slate-300 px-2" placeholder="e.g. 125000" /></label>
                            <label class="text-sm font-medium text-slate-700">{{ t('finance.reconciliation.inflows') }}<input v-model.number="reconciliation.inflows_minor_units" type="number" class="mt-1 min-h-10 w-full border border-slate-300 px-2" placeholder="e.g. 50000" /></label>
                            <label class="text-sm font-medium text-slate-700">{{ t('finance.reconciliation.outflows') }}<input v-model.number="reconciliation.outflows_minor_units" type="number" class="mt-1 min-h-10 w-full border border-slate-300 px-2" placeholder="e.g. 25000" /></label>
                            <label class="text-sm font-medium text-slate-700">{{ t('finance.reconciliation.approvedNetProfit') }}<input v-model.number="reconciliation.approved_net_profit_minor_units" type="number" class="mt-1 min-h-10 w-full border border-slate-300 px-2" placeholder="e.g. 20000" /></label>
                            <label class="text-sm font-medium text-slate-700">{{ t('finance.reconciliation.cashAvailable') }}<input v-model.number="reconciliation.cash_available_minor_units" type="number" class="mt-1 min-h-10 w-full border border-slate-300 px-2" placeholder="e.g. 125000" /></label>
                            <label class="text-sm font-medium text-slate-700">{{ t('finance.reconciliation.taxDue') }}<input v-model.number="reconciliation.tax_due_minor_units" type="number" min="0" class="mt-1 min-h-10 w-full border border-slate-300 px-2" placeholder="e.g. 5000" /></label>
                            <label class="text-sm font-medium text-slate-700">{{ t('finance.reconciliation.debtDue') }}<input v-model.number="reconciliation.debt_due_minor_units" type="number" min="0" class="mt-1 min-h-10 w-full border border-slate-300 px-2" placeholder="e.g. 10000" /></label>
                            <label class="text-sm font-medium text-slate-700">{{ t('finance.reconciliation.unreconciledItems') }}<input v-model.number="reconciliation.unreconciled_items_count" type="number" min="0" class="mt-1 min-h-10 w-full border border-slate-300 px-2" placeholder="e.g. 0" /></label>
                            <button type="submit" class="min-h-10 bg-slate-950 px-4 text-sm font-semibold text-white md:self-end">Create</button>
                        </form>
                    </details>
                </div>
                <div class="rounded-[22px] border border-[#d8e4da] bg-white/90 p-5 shadow-[0_10px_28px_rgb(16_35_26_/_4%)]">
                    <h2 class="text-lg font-black text-[var(--pbr-ink)]">Exceptions & Compensating Review</h2>
                    <div class="mt-3 space-y-2"><article v-for="row in finance.exceptions" :key="row.id" class="border border-slate-200 p-4"><div class="flex items-start justify-between gap-3"><div><p class="font-semibold">{{ row.exception_type }}</p><p class="mt-1 text-xs text-slate-600">{{ row.reason }}</p></div><span class="border border-slate-300 px-2 py-1 text-xs">{{ row.status }}</span></div><div v-if="finance.permissions.manage && row.status === 'compensating_review'" class="mt-3 flex flex-wrap gap-2"><input v-model="exceptionNotes[row.id]" class="min-h-9 flex-1 border border-slate-300 px-2 text-xs" placeholder="Review note" /><button class="text-xs font-semibold underline" @click="post('/finance/exceptions/' + row.id + '/review', { result: 'cleared', note: exceptionNotes[row.id] ?? '' })">Clear</button><button class="text-xs font-semibold text-red-700 underline" @click="post('/finance/exceptions/' + row.id + '/review', { result: 'blocked', note: exceptionNotes[row.id] ?? '' })">Block</button></div></article><p v-if="finance.exceptions.length === 0" class="text-sm text-slate-500">No Finance exceptions.</p></div>
                    <details v-if="finance.permissions.manage && finance.current" class="mt-3 border border-slate-200"><summary class="cursor-pointer px-4 py-3 text-sm font-semibold">Open exception</summary><form class="grid gap-2 border-t border-slate-200 p-4" @submit.prevent="exceptionForm.post('/finance/exceptions', { preserveScroll: true })"><input v-model="exceptionForm.exception_type" required class="min-h-10 border border-slate-300 px-2" placeholder="Exception type" /><textarea v-model="exceptionForm.reason" required class="border border-slate-300 p-2" placeholder="Reason" /><select v-model="exceptionForm.finance_payment_id" class="min-h-10 border border-slate-300 px-2"><option value="">No payment binding</option><option v-for="p in finance.payments" :key="p.id" :value="p.id">{{ p.payee_reference }} · {{ p.status }}</option></select><button type="submit" class="min-h-10 bg-slate-950 px-4 text-sm font-semibold text-white">Open</button></form></details>
                </div>
            </section>

            <section class="mt-6 rounded-[22px] border border-[#d8e4da] bg-white/90 p-5 shadow-[0_10px_28px_rgb(16_35_26_/_4%)]">
                <div><h2 class="text-lg font-black text-[var(--pbr-ink)]">Payment Register</h2><p class="mt-1 text-sm text-slate-600">Requester, Governance authorization, Payer and payment evidence remain distinct.</p></div>
                <div class="mt-3 overflow-x-auto border border-slate-200"><table class="min-w-full text-left text-sm"><thead><tr class="border-b bg-slate-50"><th class="px-3 py-3">Payment</th><th class="px-3 py-3">Amount</th><th class="px-3 py-3">Status</th><th class="px-3 py-3">Context action</th></tr></thead><tbody>
                    <tr v-for="row in finance.payments" :key="row.id" class="border-b border-slate-100 align-top"><td class="px-3 py-3"><p class="font-semibold">{{ row.payee_reference }}</p><p class="text-xs text-slate-500">{{ row.transaction_type }}<span v-if="row.related_party"> · related party</span></p></td><td class="px-3 py-3">{{ money(row.amount_minor_units, row.currency) }}</td><td class="px-3 py-3"><span class="border border-slate-300 px-2 py-1 text-xs">{{ row.status }}</span></td><td class="min-w-72 px-3 py-3">
                        <div v-if="row.status === 'draft' && finance.permissions.manage" class="flex flex-wrap items-start gap-2"><details class="rounded-lg border border-slate-200 bg-slate-50 p-2 text-xs"><summary class="cursor-pointer font-semibold">{{ t('governance.advancedDetails') }}</summary><div class="mt-2 flex gap-2"><input v-model="evidenceIds[row.id]" class="min-h-9 flex-1 border border-slate-300 px-2 text-xs" placeholder="Verified evidence reference" /><button class="font-semibold underline" @click="evidenceIds[row.id] && post('/finance/payments/' + row.id + '/evidence', { evidence_id: evidenceIds[row.id], purpose: 'request_support' })">Attach</button></div></details><button class="min-h-9 text-xs font-semibold underline" @click="post('/finance/payments/' + row.id + '/verify', { expected_revision: row.revision })">Finance verify</button></div>
                        <button v-else-if="row.status === 'governance_pending' && finance.permissions.manage" class="text-xs font-semibold underline" @click="post('/finance/payments/' + row.id + '/sync-decision')">Sync approved Decision</button>
                        <div v-else-if="row.status === 'authorized'" class="grid gap-2"><details v-if="finance.permissions.manage || finance.permissions.pay" class="rounded-lg border border-slate-200 bg-slate-50 p-2 text-xs"><summary class="cursor-pointer font-semibold">{{ t('governance.advancedDetails') }}</summary><input v-model="evidenceIds[row.id]" class="mt-2 min-h-9 w-full border border-slate-300 px-2 text-xs" placeholder="Verified payment evidence reference" /><button v-if="finance.permissions.manage" class="mt-2 text-left font-semibold underline" @click="evidenceIds[row.id] && post('/finance/payments/' + row.id + '/evidence', { evidence_id: evidenceIds[row.id], purpose: 'payment_proof' })">Attach payment proof</button></details><template v-if="finance.permissions.pay"><input v-model="paymentReferences[row.id]" class="min-h-9 border border-slate-300 px-2 text-xs" placeholder="Payment reference" /><button class="text-left text-xs font-semibold underline" @click="post('/finance/payments/' + row.id + '/pay', { payment_reference: paymentReferences[row.id] ?? '', payment_evidence_id: evidenceIds[row.id] ?? '', paid_at: today })">Record payment</button></template><span v-if="!finance.permissions.manage && !finance.permissions.pay" class="text-xs text-slate-500">No action</span></div>
                        <div v-else-if="row.status === 'paid' && finance.permissions.manage" class="flex gap-2"><select v-model="reconciliationIds[row.id]" class="min-h-9 flex-1 border border-slate-300 px-2 text-xs"><option value="">Reconciliation</option><option v-for="rec in completedReconciliations" :key="rec.id" :value="rec.id">{{ rec.period_end }}</option></select><button class="text-xs font-semibold underline" @click="reconciliationIds[row.id] && post('/finance/payments/' + row.id + '/complete', { reconciliation_review_id: reconciliationIds[row.id] })">Complete</button></div><span v-else class="text-xs text-slate-500">No action</span>
                    </td></tr><tr v-if="finance.payments.length === 0"><td colspan="4" class="px-3 py-6 text-slate-500">No controlled payments.</td></tr>
                </tbody></table></div>
                <details v-if="finance.permissions.manage && finance.current" class="mt-3 border border-slate-200"><summary class="cursor-pointer px-4 py-3 font-semibold">Create Payment Request</summary><form class="grid gap-3 border-t border-slate-200 p-4 md:grid-cols-2 xl:grid-cols-4" @submit.prevent="payment.post('/finance/payments', { preserveScroll: true })"><select v-model="payment.transaction_type" class="min-h-10 border border-slate-300 px-2"><option v-for="rule in finance.current.payment_rules" :key="rule.id" :value="rule.transaction_type">{{ rule.transaction_type }}</option></select><input v-model.number="payment.amount_minor_units" type="number" min="1" required class="min-h-10 border border-slate-300 px-2" placeholder="Amount minor units" /><select v-model="payment.bank_account_reference_id" class="min-h-10 border border-slate-300 px-2"><option v-for="bank in finance.current.bank_accounts" :key="bank.id" :value="bank.id">{{ bankLabel(bank.id) }}</option></select><input v-model="payment.payee_reference" required class="min-h-10 border border-slate-300 px-2" placeholder="Payee" /><input v-model="payment.description" class="min-h-10 border border-slate-300 px-2 md:col-span-2" placeholder="Purpose / description" /><label class="flex items-center gap-2 text-sm"><input v-model="payment.related_party" type="checkbox" /> Related party</label><button type="submit" class="min-h-10 bg-slate-950 px-4 text-sm font-semibold text-white">Create Draft</button></form></details>
            </section>
            </div>
        </main>
    </AuthenticatedLayout>
</template>
