<script setup lang="ts">
import OptionalTemporalInput from '../../components/OptionalTemporalInput.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import { useI18n } from '../../i18n/useI18n';

type Membership = { id: string; email: string };
type Partner = { id: string; display_name: string; status: string; membership_id: string | null };
type Role = { id: string; role_key: string; name: string };
type Kpi = { id: string; operations_role_id: string; name: string; current_status: string };
type FinanceRule = { id: string; rule_key: string; category: string };
type Bank = { id: string; bank_name: string; account_reference: string; currency: string };
type VersionRow = { id: string; version_number: number; revision: number; frozen_at: string | null; effective_from: string | null; state: string | null };
type RoleRule = { id: string; operations_role_id: string; partner_id: string; membership_id: string; compensation_type: string; amount_minor_units: number; frequency: string; governance_decision_type: string; status: string };
type ReimbursementRule = { id: string; rule_key: string; finance_expense_procurement_rule_id: string; reimbursement_deadline_days: number; governance_decision_type: string };
type BonusRule = { id: string; operations_role_id: string; operations_kpi_id: string | null; partner_id: string | null; bonus_type: string; approved_amount_minor_units: number | null; governance_decision_type: string; status: string };
type LoanRule = { id: string; partner_id: string; loan_reference: string; scheduled_amount_minor_units: number; governance_decision_type: string; status: string };
type DistributionRule = { id: string; distribution_basis: string; vested_only: boolean; manual_adjustment_allowed: boolean };
type CurrentPolicy = { formal_record_version_id: string; header: { currency: string; payment_frequency: string; minimum_reserve_percent: string; reinvestment_percent: string; minimum_cash_after_distribution_minor_units: number; distribution_governance_decision_type: string; manual_adjustments_allowed: boolean }; role_compensation_rules: RoleRule[]; reimbursement_rules: ReimbursementRule[]; bonus_rules: BonusRule[]; loan_repayment_rules: LoanRule[]; distribution_rule: DistributionRule | null; distribution_status_rules: Array<{ id: string; partner_status: string; eligible: boolean }>; distribution_class_rules: Array<{ id: string; share_class_name: string; eligible: boolean }> };
type Reconciliation = { id: string; period_start: string; period_end: string; currency: string; approved_net_profit_minor_units: number; tax_due_minor_units: number; debt_due_minor_units: number; cash_available_minor_units: number; status: string };
type Run = { id: string; period_start: string; period_end: string; record_date: string; currency: string; approved_net_profit_minor_units: number; required_reserve_minor_units: number; reinvestment_minor_units: number; adjustments_minor_units: number; distributable_profit_minor_units: number; cash_available_minor_units: number; status: string; revision: number };
type RunLine = { id: string; distribution_run_id: string; partner_id: string; eligible_weight: string; calculated_amount_minor_units: number; manual_adjustment_minor_units: number; final_amount_minor_units: number; adjustment_reason: string | null };
type RewardPayment = { id: string; finance_payment_id: string; reward_payment_type: string; partner_id: string; entitlement_source_date: string | null; status: string; amount_minor_units: number; currency: string; payee_reference: string; paid_at: string | null };

const props = defineProps<{
    rewards: {
        business: { id: string; name: string };
        permissions: { manage: boolean; governance_manage: boolean };
        current: CurrentPolicy | null;
        versions: VersionRow[];
        distribution_runs: Run[];
        distribution_lines: RunLine[];
        distribution_payment_links: Array<{ distribution_run_line_id: string; finance_payment_id: string }>;
        reward_payments: RewardPayment[];
        reconciliations: Reconciliation[];
        partners: Partner[];
        memberships: Membership[];
        operations_roles: Role[];
        operations_kpis: Kpi[];
        finance_expense_rules: FinanceRule[];
        bank_accounts: Bank[];
    };
}>();

const { t } = useI18n();
const today = new Date().toISOString().slice(0, 10);
const firstMember = props.rewards.memberships[0]?.id ?? '';
const firstRole = props.rewards.operations_roles[0]?.id ?? '';
const firstPartner = props.rewards.partners.find((row) => row.membership_id)?.id ?? props.rewards.partners[0]?.id ?? '';
const firstPartnerMember = props.rewards.partners.find((row) => row.id === firstPartner)?.membership_id ?? firstMember;
const currency = props.rewards.current?.header.currency ?? props.rewards.reconciliations[0]?.currency ?? 'USD';
type PostData = NonNullable<Parameters<typeof router.post>[1]>;
const post = (url: string, data: PostData = {}) => router.post(url, data, { preserveScroll: true });
const partnerName = (id: string) => props.rewards.partners.find((row) => row.id === id)?.display_name ?? id;
const roleName = (id: string) => props.rewards.operations_roles.find((row) => row.id === id)?.name ?? id;
const money = (minor: number | null, code = currency) => minor === null ? '—' : (Number(minor) / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + code;
const linesFor = (runId: string) => props.rewards.distribution_lines.filter((row) => row.distribution_run_id === runId);

const policy = useForm({
    effective_from: today,
    review_due_at: '',
    reward_owner_membership_id: firstMember,
    currency,
    payment_frequency: 'Monthly',
    minimum_reserve_percent: 10,
    target_cash_buffer_minor_units: 0,
    reinvestment_percent: 0,
    minimum_cash_after_distribution_minor_units: 0,
    distribution_governance_decision_type: 'profit_distribution_approval',
    manual_adjustments_allowed: false,
    role_compensation_rules: [] as Array<{ operations_role_id: string; partner_id: string; membership_id: string; compensation_type: string; market_rate_minor_units: number | null; amount_minor_units: number; frequency: string; start_date: string; end_date: string; governance_decision_type: string; status: string }>,
    reimbursement_rules: [] as Array<{ rule_key: string; finance_expense_procurement_rule_id: string; reimbursement_deadline_days: number; governance_decision_type: string; notes: string }>,
    bonus_rules: [] as Array<{ operations_role_id: string; operations_kpi_id: string; partner_id: string; bonus_type: string; trigger_description: string; formula_text: string; approved_amount_minor_units: number | null; cap_minor_units: number | null; governance_decision_type: string; status: string }>,
    loan_repayment_rules: [] as Array<{ partner_id: string; loan_reference: string; scheduled_amount_minor_units: number; frequency: string; start_date: string; end_date: string; governance_decision_type: string; status: string }>,
    distribution_rule: { distribution_basis: 'vested_profit_rights', vested_only: true, record_date_rule: 'Use the approved Distribution Run record date.', unpaid_contribution_restriction: false, leaver_treatment: '', special_rule_text: '', manual_adjustment_allowed: false, partner_status_rules: [] as Array<{ partner_status: string; eligible: boolean }>, share_class_rules: [] as Array<{ share_class_name: string; eligible: boolean }> },
});
const addRoleComp = () => policy.role_compensation_rules.push({ operations_role_id: firstRole, partner_id: firstPartner, membership_id: firstPartnerMember, compensation_type: 'salary', market_rate_minor_units: null, amount_minor_units: 1, frequency: 'Monthly', start_date: today, end_date: '', governance_decision_type: 'role_compensation_approval', status: 'active' });
const addReimbursement = () => policy.reimbursement_rules.push({ rule_key: 'standard_reimbursement_' + (policy.reimbursement_rules.length + 1), finance_expense_procurement_rule_id: props.rewards.finance_expense_rules[0]?.id ?? '', reimbursement_deadline_days: 30, governance_decision_type: 'reimbursement_approval', notes: '' });
const addBonus = () => policy.bonus_rules.push({ operations_role_id: firstRole, operations_kpi_id: '', partner_id: firstPartner, bonus_type: 'performance', trigger_description: '', formula_text: '', approved_amount_minor_units: null, cap_minor_units: null, governance_decision_type: 'bonus_approval', status: 'active' });
const addLoan = () => policy.loan_repayment_rules.push({ partner_id: firstPartner, loan_reference: '', scheduled_amount_minor_units: 1, frequency: 'Monthly', start_date: today, end_date: '', governance_decision_type: 'loan_repayment_approval', status: 'active' });

const rewardPayment = useForm({ type: 'salary_service_fee', rule_id: '', bank_account_reference_id: props.rewards.bank_accounts[0]?.id ?? '', partner_id: firstPartner, amount_minor_units: null as number | null, expense_date: today });
const rewardRules = computed(() => {
    if (!props.rewards.current) return [] as Array<{ id: string; label: string }>;
    if (rewardPayment.type === 'salary_service_fee') return props.rewards.current.role_compensation_rules.map((row) => ({ id: row.id, label: roleName(row.operations_role_id) + ' · ' + partnerName(row.partner_id) }));
    if (rewardPayment.type === 'reimbursement') return props.rewards.current.reimbursement_rules.map((row) => ({ id: row.id, label: row.rule_key }));
    if (rewardPayment.type === 'bonus') return props.rewards.current.bonus_rules.map((row) => ({ id: row.id, label: row.bonus_type }));
    return props.rewards.current.loan_repayment_rules.map((row) => ({ id: row.id, label: row.loan_reference }));
});

const distribution = useForm({ reconciliation_review_id: props.rewards.reconciliations[0]?.id ?? '', record_date: today, required_reserve_minor_units: null as number | null, reinvestment_minor_units: null as number | null, adjustments_minor_units: 0, special_weights: {} as Record<string, string>, notes: '' });
const evidenceIds = reactive<Record<string, string>>({});
const bankIds = reactive<Record<string, string>>({});
const adjustmentValues = reactive<Record<string, number>>({});
const adjustmentReasons = reactive<Record<string, string>>({});
type DistributionSimulationResult = {
    scenario_only: boolean;
    waterfall: {
        distributable_profit_minor_units: number;
    };
    allocations: Record<string, number>;
};

const simulator = reactive({
    approved_net_profit_minor_units: 0,
    tax_due_minor_units: 0,
    debt_due_minor_units: 0,
    required_reserve_minor_units: 0,
    reinvestment_minor_units: 0,
    adjustments_minor_units: 0,
    weights: {} as Record<string, string>,
});
const simulationResult = ref<DistributionSimulationResult | null>(null);
const simulate = async () => {
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    const response = await fetch('/rewards/distribution/simulate', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token }, body: JSON.stringify(simulator) });
    if (response.ok) simulationResult.value = await response.json() as DistributionSimulationResult;
};
const attentionCount = computed(() => props.rewards.distribution_runs.filter((row) => !['completed', 'rejected', 'cancelled'].includes(row.status)).length + props.rewards.reward_payments.filter((row) => row.status !== 'completed').length);
</script>

<template>
    <Head :title="t('rewards.title')" />
    <AuthenticatedLayout>
        <main class="min-h-screen bg-[radial-gradient(circle_at_88%_0%,rgb(210_167_67_/_8%),transparent_26rem),linear-gradient(180deg,#f7f9f6_0%,#f1f5f1_100%)] px-4 py-5 text-[var(--pbr-ink)] sm:px-6 sm:py-6 lg:px-8 lg:py-7">
            <div class="mx-auto w-full max-w-[1500px]">
            <header class="rounded-[24px] border border-[#d8e4da] bg-white/90 p-5 shadow-[0_14px_34px_rgb(16_35_26_/_5%)] sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ rewards.business.name }}</p><h1 class="mt-2 text-2xl font-black tracking-[-0.02em] text-[var(--pbr-ink)]">{{ t('rewards.title') }}</h1><p class="mt-2 max-w-4xl text-sm leading-6 text-[var(--pbr-muted)]">{{ t('rewards.description') }}</p></div>
                    <div class="flex flex-wrap gap-2"><Link href="/finance" class="inline-flex min-h-11 items-center rounded-xl border border-[#d8e4da] bg-white px-4 text-sm font-bold text-slate-800">Finance</Link><Link href="/governance" class="inline-flex min-h-11 items-center rounded-xl border border-[#d8e4da] bg-white px-4 text-sm font-bold text-slate-800">Governance</Link></div>
                </div>
                <p class="mt-5 rounded-[16px] border border-[#cfe1d3] bg-[#f3f8f4] px-4 py-3 text-sm font-bold text-[var(--pbr-green-dark)]">{{ t('rewards.boundary') }}</p>
            </header>

            <section class="mt-5 rounded-[22px] border border-[#d8e4da] bg-white/90 p-5 shadow-[0_10px_28px_rgb(16_35_26_/_4%)]">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]">{{ t('rewards.separationTitle') }}</p>
                <p class="mt-2 max-w-4xl text-sm leading-6 text-[var(--pbr-muted)]">{{ t('rewards.separationHelp') }}</p>
                <div class="mt-4 grid gap-3 md:grid-cols-3">
                    <div class="rounded-[16px] border border-[#dde7df] bg-[#f8faf8] p-4">
                        <p class="text-sm font-black text-[var(--pbr-ink)]">{{ t('rewards.compensationLane') }}</p>
                        <p class="mt-1 text-xs leading-5 text-[var(--pbr-muted)]">{{ t('rewards.compensationLaneHelp') }}</p>
                    </div>
                    <div class="rounded-[16px] border border-[#dde7df] bg-[#f8faf8] p-4">
                        <p class="text-sm font-black text-[var(--pbr-ink)]">{{ t('rewards.entitlementLane') }}</p>
                        <p class="mt-1 text-xs leading-5 text-[var(--pbr-muted)]">{{ t('rewards.entitlementLaneHelp') }}</p>
                    </div>
                    <div class="rounded-[16px] border border-[#e8d9ab] bg-[#fffaf0] p-4">
                        <p class="text-sm font-black text-[#66531f]">{{ t('rewards.distributionLane') }}</p>
                        <p class="mt-1 text-xs leading-5 text-[#7d672d]">{{ t('rewards.distributionLaneHelp') }}</p>
                    </div>
                </div>
            </section>

            <section class="mt-5 grid gap-3 md:grid-cols-3">
                <div class="rounded-[18px] border border-[#dde7df] bg-white/90 p-4 shadow-[0_8px_22px_rgb(16_35_26_/_3%)]"><p class="text-xs font-bold uppercase text-[var(--pbr-muted)]">Needs attention</p><p class="mt-2 text-2xl font-black">{{ attentionCount }}</p><p class="text-xs text-[var(--pbr-muted)]">Open rewards + Distribution Runs</p></div>
                <div class="rounded-[18px] border border-[#dde7df] bg-white/90 p-4 shadow-[0_8px_22px_rgb(16_35_26_/_3%)]"><p class="text-xs font-bold uppercase text-[var(--pbr-muted)]">Effective policy</p><p class="mt-2 font-black">{{ rewards.current ? rewards.current.header.payment_frequency : 'Not effective' }}</p><p class="text-xs text-[var(--pbr-muted)]">Role compensation is not ownership.</p></div>
                <div class="rounded-[18px] border border-[#e8d9ab] bg-[#fffaf0] p-4 shadow-[0_8px_22px_rgb(16_35_26_/_3%)]"><p class="text-xs font-bold uppercase text-[#7d672d]">Distribution basis</p><p class="mt-2 font-black text-[#66531f]">{{ rewards.current?.distribution_rule?.distribution_basis ?? 'Not configured' }}</p><p class="text-xs text-[#7d672d]">Exact historical Ownership source at record date.</p></div>
            </section>

            <section class="mt-6 rounded-[22px] border border-[#d8e4da] bg-white/90 p-5 shadow-[0_10px_28px_rgb(16_35_26_/_4%)] sm:p-6">
                <h2 class="text-lg font-black text-[var(--pbr-ink)]">Current Effective Reward Policy</h2>
                <p class="mt-1 text-sm text-slate-600">Salary/service fee, reimbursement, bonus, loan repayment and profit distribution remain separate records and rules.</p>
                <div v-if="!rewards.current" class="mt-4 border border-dashed border-slate-300 p-5 text-sm text-slate-500">No Effective Reward Policy yet. Effective Finance + Operations must exist first.</div>
                <template v-else>
                    <div class="mt-4 overflow-x-auto border border-slate-200">
                        <table class="min-w-full text-left text-sm"><thead><tr class="border-b bg-slate-50"><th class="px-3 py-3">Role compensation</th><th class="px-3 py-3">Partner</th><th class="px-3 py-3">Amount</th><th class="px-3 py-3">Governance</th></tr></thead><tbody><tr v-for="row in rewards.current.role_compensation_rules" :key="row.id" class="border-b border-slate-100"><td class="px-3 py-3"><p class="font-semibold">{{ roleName(row.operations_role_id) }}</p><p class="text-xs text-slate-500">{{ row.compensation_type }} · {{ row.frequency }}</p></td><td class="px-3 py-3">{{ partnerName(row.partner_id) }}</td><td class="px-3 py-3">{{ money(row.amount_minor_units) }}</td><td class="px-3 py-3 text-xs">{{ row.governance_decision_type }}</td></tr><tr v-if="rewards.current.role_compensation_rules.length === 0"><td colspan="4" class="px-3 py-5 text-slate-500">No role compensation rules.</td></tr></tbody></table>
                    </div>
                    <div class="mt-4 grid gap-4 lg:grid-cols-3">
                        <div class="border border-slate-200 p-4"><p class="text-sm font-semibold">Reimbursement</p><p class="mt-1 text-2xl font-bold">{{ rewards.current.reimbursement_rules.length }}</p><p class="text-xs text-slate-500">Entitlement/timing rules; Finance owns expense validity.</p></div>
                        <div class="border border-slate-200 p-4"><p class="text-sm font-semibold">Bonus</p><p class="mt-1 text-2xl font-bold">{{ rewards.current.bonus_rules.length }}</p><p class="text-xs text-slate-500">Bound to Operations role/KPI context.</p></div>
                        <div class="border border-slate-200 p-4"><p class="text-sm font-semibold">Loan repayment</p><p class="mt-1 text-2xl font-bold">{{ rewards.current.loan_repayment_rules.length }}</p><p class="text-xs text-slate-500">Separate from salary and profit distribution.</p></div>
                    </div>
                </template>
            </section>

            <section class="mt-6 rounded-[22px] border border-[#d8e4da] bg-white/90 p-5 shadow-[0_10px_28px_rgb(16_35_26_/_4%)]">
                <h2 class="text-lg font-black text-[var(--pbr-ink)]">Reward Policy History</h2>
                <div class="mt-3 overflow-x-auto border border-slate-200"><table class="min-w-full text-left text-sm"><thead><tr class="border-b bg-slate-50"><th class="px-3 py-3">Version</th><th class="px-3 py-3">State</th><th class="px-3 py-3">Effective</th><th class="px-3 py-3">Action</th></tr></thead><tbody><tr v-for="version in rewards.versions" :key="version.id" class="border-b border-slate-100"><td class="px-3 py-3 font-semibold">v{{ version.version_number }}</td><td class="px-3 py-3"><span class="border border-slate-300 px-2 py-1 text-xs">{{ version.state }}</span></td><td class="px-3 py-3">{{ version.effective_from ?? '—' }}</td><td class="px-3 py-3"><div v-if="rewards.permissions.manage" class="flex gap-2"><button v-if="version.state === 'draft'" class="text-xs font-semibold underline" @click="post('/rewards/policy/' + version.id + '/submit', { expected_revision: version.revision })">Submit</button><button v-if="version.state === 'ready_for_review'" class="text-xs font-semibold underline" @click="post('/rewards/policy/' + version.id + '/content-review', { target: 'under_review' })">Start review</button><button v-if="version.state === 'under_review'" class="text-xs font-semibold underline" @click="post('/rewards/policy/' + version.id + '/content-review', { target: 'approved' })">Approve content</button></div></td></tr></tbody></table></div>
            </section>

            <details v-if="rewards.permissions.manage" class="mt-6 rounded-[20px] border border-[#d8e4da] bg-white/90 shadow-[0_10px_28px_rgb(16_35_26_/_4%)]">
                <summary class="cursor-pointer px-5 py-4 font-semibold">Create Reward Policy Draft / Amendment</summary>
                <form class="space-y-6 border-t border-slate-200 p-5" @submit.prevent="policy.post('/rewards/policy', { preserveScroll: true })">
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <label class="text-sm font-medium">Effective from<OptionalTemporalInput v-model="policy.effective_from" type="date" class="mt-1 min-h-10 w-full border border-slate-300 px-2" /></label>
                        <label class="text-sm font-medium">Reward Owner<select v-model="policy.reward_owner_membership_id" class="mt-1 min-h-10 w-full border border-slate-300 px-2"><option v-for="m in rewards.memberships" :key="m.id" :value="m.id">{{ m.email }}</option></select></label>
                        <label class="text-sm font-medium">Currency<input v-model="policy.currency" maxlength="3" class="mt-1 min-h-10 w-full border border-slate-300 px-2 uppercase" /></label>
                        <label class="text-sm font-medium">Payment frequency<input v-model="policy.payment_frequency" class="mt-1 min-h-10 w-full border border-slate-300 px-2" /></label>
                        <label class="text-sm font-medium">Minimum reserve %<input v-model.number="policy.minimum_reserve_percent" type="number" min="0" max="100" step="0.01" class="mt-1 min-h-10 w-full border border-slate-300 px-2" /></label>
                        <label class="text-sm font-medium">{{ t('rewards.policy.targetCashBuffer') }}<input v-model.number="policy.target_cash_buffer_minor_units" type="number" min="0" class="mt-1 min-h-10 w-full border border-slate-300 px-2" /></label>
                        <label class="text-sm font-medium">Reinvestment %<input v-model.number="policy.reinvestment_percent" type="number" min="0" max="100" step="0.01" class="mt-1 min-h-10 w-full border border-slate-300 px-2" /></label>
                        <label class="text-sm font-medium">Minimum post-distribution cash<input v-model.number="policy.minimum_cash_after_distribution_minor_units" type="number" min="0" class="mt-1 min-h-10 w-full border border-slate-300 px-2" /></label>
                        <label class="text-sm font-medium">Distribution decision type<input v-model="policy.distribution_governance_decision_type" class="mt-1 min-h-10 w-full border border-slate-300 px-2" /></label>
                    </div>
                    <label class="flex items-center gap-2 text-sm"><input v-model="policy.manual_adjustments_allowed" type="checkbox" /> Policy may permit evidenced manual Distribution adjustments</label>

                    <div>
                        <div class="flex justify-between"><h3 class="font-semibold">Role Compensation</h3><button type="button" class="text-xs font-semibold underline" @click="addRoleComp">Add rule</button></div>
                        <div v-for="(row, index) in policy.role_compensation_rules" :key="index" class="mt-3 grid gap-2 border-l-2 border-slate-300 pl-3 md:grid-cols-4">
                            <select v-model="row.operations_role_id" class="min-h-10 border border-slate-300 px-2"><option v-for="role in rewards.operations_roles" :key="role.id" :value="role.id">{{ role.name }}</option></select>
                            <select v-model="row.partner_id" class="min-h-10 border border-slate-300 px-2" @change="row.membership_id = rewards.partners.find((p) => p.id === row.partner_id)?.membership_id ?? ''"><option v-for="partner in rewards.partners" :key="partner.id" :value="partner.id">{{ partner.display_name }}</option></select>
                            <select v-model="row.compensation_type" class="min-h-10 border border-slate-300 px-2"><option value="salary">Salary</option><option value="service_fee">Service fee</option></select>
                            <input v-model.number="row.amount_minor_units" type="number" min="1" class="min-h-10 border border-slate-300 px-2" placeholder="Amount minor units" />
                            <input v-model="row.frequency" class="min-h-10 border border-slate-300 px-2" placeholder="Frequency" /><OptionalTemporalInput v-model="row.start_date" type="date" class="min-h-10 border border-slate-300 px-2" /><input v-model="row.governance_decision_type" class="min-h-10 border border-slate-300 px-2" placeholder="Decision type" /><button type="button" class="text-left text-xs font-semibold text-red-700" @click="policy.role_compensation_rules.splice(index, 1)">Remove</button>
                        </div>
                    </div>

                    <div>
                        <div class="flex justify-between"><h3 class="font-semibold">Reimbursement Entitlement & Timing</h3><button type="button" class="text-xs font-semibold underline" @click="addReimbursement">Add rule</button></div>
                        <div v-for="(row, index) in policy.reimbursement_rules" :key="index" class="mt-3 grid gap-2 md:grid-cols-4"><input v-model="row.rule_key" class="min-h-10 border border-slate-300 px-2" placeholder="Rule key" /><select v-model="row.finance_expense_procurement_rule_id" class="min-h-10 border border-slate-300 px-2"><option v-for="rule in rewards.finance_expense_rules" :key="rule.id" :value="rule.id">{{ rule.category }} · {{ rule.rule_key }}</option></select><input v-model.number="row.reimbursement_deadline_days" type="number" min="0" class="min-h-10 border border-slate-300 px-2" placeholder="Deadline days" /><input v-model="row.governance_decision_type" class="min-h-10 border border-slate-300 px-2" placeholder="Decision type" /></div>
                    </div>

                    <div>
                        <div class="flex justify-between"><h3 class="font-semibold">Bonus Rules</h3><button type="button" class="text-xs font-semibold underline" @click="addBonus">Add rule</button></div>
                        <div v-for="(row, index) in policy.bonus_rules" :key="index" class="mt-3 grid gap-2 md:grid-cols-4"><select v-model="row.operations_role_id" class="min-h-10 border border-slate-300 px-2"><option v-for="role in rewards.operations_roles" :key="role.id" :value="role.id">{{ role.name }}</option></select><select v-model="row.operations_kpi_id" class="min-h-10 border border-slate-300 px-2"><option value="">No KPI binding</option><option v-for="kpi in rewards.operations_kpis.filter((k) => k.operations_role_id === row.operations_role_id)" :key="kpi.id" :value="kpi.id">{{ kpi.name }}</option></select><input v-model="row.bonus_type" class="min-h-10 border border-slate-300 px-2" placeholder="Bonus type" /><input v-model="row.trigger_description" class="min-h-10 border border-slate-300 px-2" placeholder="Trigger" /><input v-model.number="row.approved_amount_minor_units" type="number" min="0" class="min-h-10 border border-slate-300 px-2" placeholder="Approved amount" /><input v-model="row.governance_decision_type" class="min-h-10 border border-slate-300 px-2" placeholder="Decision type" /></div>
                    </div>

                    <div>
                        <div class="flex justify-between"><h3 class="font-semibold">Loan Repayment Rules</h3><button type="button" class="text-xs font-semibold underline" @click="addLoan">Add rule</button></div>
                        <div v-for="(row, index) in policy.loan_repayment_rules" :key="index" class="mt-3 grid gap-2 md:grid-cols-4"><select v-model="row.partner_id" class="min-h-10 border border-slate-300 px-2"><option v-for="partner in rewards.partners" :key="partner.id" :value="partner.id">{{ partner.display_name }}</option></select><input v-model="row.loan_reference" class="min-h-10 border border-slate-300 px-2" placeholder="Loan reference" /><input v-model.number="row.scheduled_amount_minor_units" type="number" min="1" class="min-h-10 border border-slate-300 px-2" placeholder="Amount" /><input v-model="row.governance_decision_type" class="min-h-10 border border-slate-300 px-2" placeholder="Decision type" /></div>
                    </div>

                    <div class="border border-slate-200 p-4">
                        <h3 class="font-semibold">Profit Distribution Rule</h3>
                        <div class="mt-3 grid gap-3 md:grid-cols-3"><select v-model="policy.distribution_rule.distribution_basis" class="min-h-10 border border-slate-300 px-2"><option value="profit_rights">Profit rights</option><option value="vested_profit_rights">Vested profit rights</option><option value="shares_issued">Shares issued</option><option value="shares_vested">Shares vested</option><option value="special_rule">Special rule</option></select><input v-model="policy.distribution_rule.record_date_rule" class="min-h-10 border border-slate-300 px-2" placeholder="Record date rule" /><label class="flex items-center gap-2 text-sm"><input v-model="policy.distribution_rule.vested_only" type="checkbox" /> Vested only</label></div>
                    </div>
                    <button type="submit" class="min-h-11 bg-slate-950 px-5 text-sm font-semibold text-white">Save Draft</button>
                </form>
            </details>

            <section class="mt-6 grid gap-5 xl:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)]">
                <div class="rounded-[22px] border border-[#d8e4da] bg-white/90 p-5 shadow-[0_10px_28px_rgb(16_35_26_/_4%)]">
                    <h2 class="text-lg font-black text-[var(--pbr-ink)]">Distribution Run Register</h2>
                    <p class="mt-1 text-sm text-slate-600">Calculated does not mean payable. Completion follows Finance verification, Governance approval, scheduled payments and verified payment evidence.</p>
                    <div class="mt-3 space-y-3">
                        <article v-for="run in rewards.distribution_runs" :key="run.id" class="border border-slate-200 p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3"><div><p class="font-semibold">{{ run.period_start }} → {{ run.period_end }}</p><p class="mt-1 text-xs text-slate-500">Record date {{ run.record_date }} · distributable {{ money(run.distributable_profit_minor_units, run.currency) }}</p></div><span class="border border-slate-300 px-2 py-1 text-xs font-semibold">{{ run.status }}</span></div>
                            <div class="mt-3 overflow-x-auto"><table class="min-w-full text-left text-xs"><thead><tr class="border-b"><th class="py-2">Partner</th><th class="py-2">Weight</th><th class="py-2">Final amount</th><th class="py-2">Adjustment</th></tr></thead><tbody><tr v-for="line in linesFor(run.id)" :key="line.id" class="border-b border-slate-100"><td class="py-2">{{ partnerName(line.partner_id) }}</td><td class="py-2">{{ line.eligible_weight }}</td><td class="py-2">{{ money(line.final_amount_minor_units, run.currency) }}</td><td class="py-2"><div v-if="run.status === 'draft' && rewards.permissions.manage" class="flex gap-1"><input v-model.number="adjustmentValues[line.id]" type="number" class="w-24 border border-slate-300 px-1" placeholder="minor" /><input v-model="adjustmentReasons[line.id]" class="w-32 border border-slate-300 px-1" placeholder="reason" /><button class="font-semibold underline" @click="post('/rewards/distributions/' + run.id + '/lines/' + line.id + '/adjust', { expected_revision: run.revision, adjustment_minor_units: adjustmentValues[line.id] ?? 0, reason: adjustmentReasons[line.id] ?? '' })">Set</button></div><span v-else>{{ line.manual_adjustment_minor_units }}</span></td></tr></tbody></table></div>
                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                <template v-if="run.status === 'draft' && rewards.permissions.manage"><input v-model="evidenceIds[run.id]" class="min-h-9 border border-slate-300 px-2 text-xs" placeholder="Verified Evidence ID" /><button class="text-xs font-semibold underline" @click="evidenceIds[run.id] && post('/rewards/distributions/' + run.id + '/evidence', { evidence_id: evidenceIds[run.id] })">Attach evidence</button><button class="text-xs font-semibold underline" @click="post('/rewards/distributions/' + run.id + '/verify', { expected_revision: run.revision })">Finance verify</button></template>
                                <button v-if="run.status === 'governance_pending' && rewards.permissions.governance_manage" class="text-xs font-semibold underline" @click="post('/rewards/distributions/' + run.id + '/sync-decision')">Sync approved Decision</button>
                                <template v-if="run.status === 'approved' && rewards.permissions.manage"><select v-model="bankIds[run.id]" class="min-h-9 border border-slate-300 px-2 text-xs"><option value="">Payment bank</option><option v-for="bank in rewards.bank_accounts" :key="bank.id" :value="bank.id">{{ bank.bank_name }} · {{ bank.account_reference }}</option></select><button class="text-xs font-semibold underline" @click="bankIds[run.id] && post('/rewards/distributions/' + run.id + '/schedule-payments', { bank_account_reference_id: bankIds[run.id] })">Schedule payments</button></template>
                                <button v-if="run.status === 'payment_scheduled' && rewards.permissions.manage" class="text-xs font-semibold underline" @click="post('/rewards/distributions/' + run.id + '/complete')">Complete after all payments</button>
                            </div>
                        </article>
                        <p v-if="rewards.distribution_runs.length === 0" class="text-sm text-slate-500">No Distribution Runs.</p>
                    </div>
                    <details v-if="rewards.permissions.manage && rewards.current" class="mt-3 border border-slate-200">
                        <summary class="cursor-pointer px-4 py-3 font-semibold">Create Distribution Run</summary>
                        <form class="grid gap-3 border-t border-slate-200 p-4 md:grid-cols-2" @submit.prevent="distribution.post('/rewards/distributions', { preserveScroll: true })">
                            <label class="text-sm font-medium text-slate-700">{{ t('rewards.distribution.reconciliation') }}<select v-model="distribution.reconciliation_review_id" class="mt-1 min-h-10 w-full border border-slate-300 px-2"><option v-for="rec in rewards.reconciliations" :key="rec.id" :value="rec.id">{{ rec.period_end }} · {{ money(rec.approved_net_profit_minor_units, rec.currency) }}</option></select></label>
                            <label class="text-sm font-medium text-slate-700">{{ t('rewards.distribution.recordDate') }}<OptionalTemporalInput v-model="distribution.record_date" type="date" class="mt-1 min-h-10 w-full border border-slate-300 px-2" /></label>
                            <label class="text-sm font-medium text-slate-700">{{ t('rewards.distribution.requiredReserve') }}<input v-model.number="distribution.required_reserve_minor_units" type="number" min="0" class="mt-1 min-h-10 w-full border border-slate-300 px-2" placeholder="e.g. 15000" /></label>
                            <label class="text-sm font-medium text-slate-700">{{ t('rewards.distribution.reinvestment') }}<input v-model.number="distribution.reinvestment_minor_units" type="number" min="0" class="mt-1 min-h-10 w-full border border-slate-300 px-2" placeholder="e.g. 20000" /></label>
                            <label class="text-sm font-medium text-slate-700">{{ t('rewards.distribution.adjustments') }}<input v-model.number="distribution.adjustments_minor_units" type="number" class="mt-1 min-h-10 w-full border border-slate-300 px-2" placeholder="e.g. 0" /></label>
                            <label class="text-sm font-medium text-slate-700">{{ t('rewards.distribution.notes') }}<input v-model="distribution.notes" class="mt-1 min-h-10 w-full border border-slate-300 px-2" placeholder="e.g. Approved adjustment note" /></label>
                            <button type="submit" class="min-h-10 bg-slate-950 px-4 text-sm font-semibold text-white md:self-end">Create Run</button>
                        </form>
                    </details>
                </div>

                <aside class="rounded-[22px] border border-[#e8d9ab] bg-[#fffaf0] p-5 shadow-[0_10px_28px_rgb(16_35_26_/_4%)]">
                    <h2 class="text-lg font-black text-[#66531f]">Distribution Scenario</h2>
                    <p class="mt-1 text-sm text-slate-600">Pure calculation only. This never changes live Finance or Ownership truth.</p>
                    <div class="mt-3 grid gap-3 border border-dashed border-slate-300 p-4">
                        <label class="text-sm font-medium text-slate-700">
                            {{ t('rewards.simulation.approvedNetProfit') }}
                            <input v-model.number="simulator.approved_net_profit_minor_units" type="number" class="mt-1 min-h-9 w-full border border-slate-300 px-2 text-sm" placeholder="e.g. 100000" />
                        </label>
                        <label class="text-sm font-medium text-slate-700">
                            {{ t('rewards.simulation.taxDue') }}
                            <input v-model.number="simulator.tax_due_minor_units" type="number" min="0" class="mt-1 min-h-9 w-full border border-slate-300 px-2 text-sm" placeholder="e.g. 10000" />
                        </label>
                        <label class="text-sm font-medium text-slate-700">
                            {{ t('rewards.simulation.debtDue') }}
                            <input v-model.number="simulator.debt_due_minor_units" type="number" min="0" class="mt-1 min-h-9 w-full border border-slate-300 px-2 text-sm" placeholder="e.g. 5000" />
                        </label>
                        <label class="text-sm font-medium text-slate-700">
                            {{ t('rewards.simulation.requiredReserve') }}
                            <input v-model.number="simulator.required_reserve_minor_units" type="number" min="0" class="mt-1 min-h-9 w-full border border-slate-300 px-2 text-sm" placeholder="e.g. 15000" />
                        </label>
                        <label class="text-sm font-medium text-slate-700">
                            {{ t('rewards.simulation.reinvestment') }}
                            <input v-model.number="simulator.reinvestment_minor_units" type="number" min="0" class="mt-1 min-h-9 w-full border border-slate-300 px-2 text-sm" placeholder="e.g. 20000" />
                        </label>
                        <label class="text-sm font-medium text-slate-700">
                            {{ t('rewards.simulation.adjustments') }}
                            <input v-model.number="simulator.adjustments_minor_units" type="number" class="mt-1 min-h-9 w-full border border-slate-300 px-2 text-sm" placeholder="e.g. 0" />
                        </label>
                        <button type="button" class="min-h-10 bg-slate-950 px-4 text-sm font-semibold text-white" @click="simulate">Calculate only</button>
                        <p v-if="simulationResult" class="border-t border-slate-200 pt-3 text-sm"><strong>Distributable:</strong> {{ money(simulationResult.waterfall.distributable_profit_minor_units) }}</p>
                    </div>
                </aside>
            </section>

            <section class="mt-6 rounded-[22px] border border-[#d8e4da] bg-white/90 p-5 shadow-[0_10px_28px_rgb(16_35_26_/_4%)]">
                <h2 class="text-lg font-black text-[var(--pbr-ink)]">Reward Payments</h2>
                <div class="mt-3 overflow-x-auto border border-slate-200"><table class="min-w-full text-left text-sm"><thead><tr class="border-b bg-slate-50"><th class="px-3 py-3">Type</th><th class="px-3 py-3">Payee</th><th class="px-3 py-3">Amount</th><th class="px-3 py-3">Status</th><th class="px-3 py-3">Source date</th></tr></thead><tbody><tr v-for="row in rewards.reward_payments" :key="row.id" class="border-b border-slate-100"><td class="px-3 py-3 font-semibold">{{ row.reward_payment_type }}</td><td class="px-3 py-3">{{ row.payee_reference }}</td><td class="px-3 py-3">{{ money(row.amount_minor_units, row.currency) }}</td><td class="px-3 py-3">{{ row.status }}</td><td class="px-3 py-3">{{ row.entitlement_source_date ?? '—' }}</td></tr><tr v-if="rewards.reward_payments.length === 0"><td colspan="5" class="px-3 py-5 text-slate-500">No Reward Payments.</td></tr></tbody></table></div>
                <details v-if="rewards.permissions.manage && rewards.current" class="mt-3 border border-slate-200"><summary class="cursor-pointer px-4 py-3 font-semibold">Create Reward Payment Request</summary><form class="grid gap-3 border-t border-slate-200 p-4 md:grid-cols-2 xl:grid-cols-4" @submit.prevent="rewardPayment.post('/rewards/payments', { preserveScroll: true })"><select v-model="rewardPayment.type" class="min-h-10 border border-slate-300 px-2" @change="rewardPayment.rule_id = ''"><option value="salary_service_fee">Salary / service fee</option><option value="reimbursement">Reimbursement</option><option value="bonus">Bonus</option><option value="loan_repayment">Loan repayment</option></select><select v-model="rewardPayment.rule_id" required class="min-h-10 border border-slate-300 px-2"><option value="">Choose rule</option><option v-for="rule in rewardRules" :key="rule.id" :value="rule.id">{{ rule.label }}</option></select><select v-model="rewardPayment.bank_account_reference_id" required class="min-h-10 border border-slate-300 px-2"><option v-for="bank in rewards.bank_accounts" :key="bank.id" :value="bank.id">{{ bank.bank_name }} · {{ bank.account_reference }}</option></select><select v-if="rewardPayment.type === 'reimbursement'" v-model="rewardPayment.partner_id" class="min-h-10 border border-slate-300 px-2"><option v-for="partner in rewards.partners" :key="partner.id" :value="partner.id">{{ partner.display_name }}</option></select><input v-if="rewardPayment.type === 'reimbursement' || rewardPayment.type === 'bonus'" v-model.number="rewardPayment.amount_minor_units" type="number" min="1" class="min-h-10 border border-slate-300 px-2" placeholder="Amount minor units" /><OptionalTemporalInput v-if="rewardPayment.type === 'reimbursement'" v-model="rewardPayment.expense_date" type="date" class="min-h-10 border border-slate-300 px-2" /><button type="submit" class="min-h-10 bg-slate-950 px-4 text-sm font-semibold text-white">Create controlled payment</button></form></details>
            </section>
            </div>
        </main>
    </AuthenticatedLayout>
</template>
