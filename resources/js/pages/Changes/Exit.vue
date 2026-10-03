<script setup lang="ts">
import OptionalTemporalInput from '../../components/OptionalTemporalInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import { useI18n } from '../../i18n/useI18n';

type Partner = {
    id: string;
    display_name: string;
    status: string;
};

type Membership = {
    id: string;
    access_status: string;
    email: string;
};

type SharePosition = {
    id: string;
    share_class_name: string;
    shares_issued: string;
    shares_vested: string;
    shares_unvested: string;
    voting_rights: string;
    profit_rights: string;
};

type Requirement = {
    id: string;
    requirement_type: string;
    requirement_key: string;
    status: string;
    detail: string | null;
    source_type: string | null;
    source_id: string | null;
};

type FinanceLink = {
    finance_payment_id: string;
    purpose: string;
    installment_sequence: number | null;
    amount_minor_units: number;
    currency: string;
    status: string;
    completed_at: string | null;
};

type GovernanceSubmission = {
    formal_record_version_id: string;
    proposal_version_id: string;
    decision_type: string;
    decision_id: string | null;
    formal_record_state: string | null;
    effected_at: string | null;
};

type ExitCase = {
    id: string;
    case_number: string;
    partner_id: string;
    membership_id: string | null;
    trigger: string;
    trigger_detail: string | null;
    notice_date: string | null;
    intended_exit_date: string | null;
    required_notice_days: number | null;
    notice_summary: string | null;
    source_ownership_register_version_id: string | null;
    share_treatment: string | null;
    buyer_partner_id: string | null;
    partner_change_case_id: string | null;
    ownership_scenario_id: string | null;
    valuation_method: string | null;
    approved_business_value_minor_units: number | null;
    leaver_adjustment_minor_units: number | null;
    final_buyout_value_minor_units: number | null;
    currency: string | null;
    leaver_classification: string | null;
    leaver_rule_reference: string | null;
    payment_total_minor_units: number | null;
    payment_terms_summary: string | null;
    deposit_minor_units: number | null;
    installment_minor_units: number | null;
    installment_count: number | null;
    payment_frequency: string | null;
    first_payment_date: string | null;
    final_payment_date: string | null;
    interest_terms: string | null;
    security_terms: string | null;
    late_payment_rule: string | null;
    affordability_status: string;
    alternative_payment_structure: string | null;
    governance_decision_type: string;
    effective_from: string | null;
    settlement_status: string;
    settled_at: string | null;
    completed_at: string | null;
    status: string;
    revision: number;
    created_at: string | null;
    share_positions: SharePosition[];
    requirements: Requirement[];
    finance_links: FinanceLink[];
    governance_submission: GovernanceSubmission | null;
};

const props = defineProps<{
    exitWorkspace: {
        business: { id: string; name: string };
        permissions: {
            view: boolean;
            manage: boolean;
            access_admin: boolean;
            finance_view: boolean;
        };
        partners: Partner[];
        memberships: Membership[];
        cases: ExitCase[];
    };
}>();

const { t } = useI18n();
const selectedId = ref<string | null>(
    props.exitWorkspace.cases[0]?.id ?? null,
);

watch(
    () => props.exitWorkspace.cases,
    (cases) => {
        if (
            selectedId.value !== null &&
            cases.some((item) => item.id === selectedId.value)
        ) {
            return;
        }

        selectedId.value = cases[0]?.id ?? null;
    },
);

const selectedCase = computed(
    () =>
        props.exitWorkspace.cases.find(
            (item) => item.id === selectedId.value,
        ) ?? null,
);

const partnerName = (id: string | null): string => {
    if (id === null) return '—';

    return (
        props.exitWorkspace.partners.find((partner) => partner.id === id)
            ?.display_name ?? 'Unknown Partner'
    );
};

const membershipLabel = (id: string | null): string => {
    if (id === null) return 'No linked Membership';

    const membership = props.exitWorkspace.memberships.find(
        (item) => item.id === id,
    );

    return membership
        ? `${membership.email} · ${membership.access_status}`
        : 'Linked Membership';
};

const money = (
    minorUnits: number | null,
    currency: string | null,
): string => {
    if (minorUnits === null) return '—';

    return `${currency ?? ''} ${(minorUnits / 100).toLocaleString(
        undefined,
        {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        },
    )}`.trim();
};

const statusClass = (status: string): string => {
    if (['completed', 'effective'].includes(status)) {
        return 'bg-emerald-50 text-emerald-800';
    }

    if (['rejected', 'withdrawn', 'cancelled'].includes(status)) {
        return 'bg-rose-50 text-rose-800';
    }

    if (
        ['approved', 'ready_for_effect', 'settlement_pending'].includes(
            status,
        )
    ) {
        return 'bg-amber-50 text-amber-900';
    }

    return 'bg-slate-100 text-slate-700';
};

const createForm = useForm({
    partner_id: '',
    trigger: 'voluntary',
    governance_decision_type: 'partner_exit_approval',
    trigger_detail: '',
    effective_from: '',
});

const createCase = (): void => {
    createForm.post('/changes/exit', {
        preserveScroll: true,
        onSuccess: () => createForm.reset(),
    });
};

const noticeForm = useForm({
    expected_revision: 1,
    notice_date: '',
    intended_exit_date: '',
    required_notice_days: null as number | null,
    notice_summary: '',
});

const recordNotice = (): void => {
    const item = selectedCase.value;
    if (item === null) return;

    noticeForm.expected_revision = item.revision;
    noticeForm.post(`/changes/exit/${item.id}/notice`, {
        preserveScroll: true,
    });
};

const shareForm = useForm({
    expected_revision: 1,
    share_treatment: 'no_shares',
    leaver_classification: 'not_applicable',
    buyer_partner_id: '',
    partner_change_case_id: '',
    ownership_scenario_id: '',
    valuation_method: '',
    approved_business_value_minor_units: null as number | null,
    leaver_adjustment_minor_units: null as number | null,
    final_buyout_value_minor_units: null as number | null,
    currency: '',
    leaver_rule_reference: '',
});

const recordShareTreatment = (): void => {
    const item = selectedCase.value;
    if (item === null) return;

    shareForm.expected_revision = item.revision;
    shareForm.post(`/changes/exit/${item.id}/share-treatment`, {
        preserveScroll: true,
    });
};

const paymentForm = useForm({
    expected_revision: 1,
    payment_total_minor_units: null as number | null,
    payment_terms_summary: '',
    deposit_minor_units: null as number | null,
    installment_minor_units: null as number | null,
    installment_count: null as number | null,
    payment_frequency: '',
    first_payment_date: '',
    final_payment_date: '',
    interest_terms: '',
    security_terms: '',
    late_payment_rule: '',
    affordability_status: 'not_applicable',
    alternative_payment_structure: '',
});

const recordPaymentTerms = (): void => {
    const item = selectedCase.value;
    if (item === null) return;

    paymentForm.expected_revision = item.revision;
    paymentForm.post(`/changes/exit/${item.id}/payment-terms`, {
        preserveScroll: true,
    });
};

const requirementForm = useForm({
    expected_revision: 1,
    requirement_type: 'handover',
    requirement_key: 'handover_plan_ready',
    status: 'met',
    detail: '',
    source_type: '',
    source_id: '',
});

const recordRequirement = (): void => {
    const item = selectedCase.value;
    if (item === null) return;

    requirementForm.expected_revision = item.revision;
    requirementForm.post(`/changes/exit/${item.id}/requirements`, {
        preserveScroll: true,
    });
};

const financeForm = useForm({
    expected_revision: 1,
    finance_payment_id: '',
    purpose: 'installment',
    installment_sequence: null as number | null,
});

const linkFinancePayment = (): void => {
    const item = selectedCase.value;
    if (item === null) return;

    financeForm.expected_revision = item.revision;
    financeForm.post(`/changes/exit/${item.id}/finance-links`, {
        preserveScroll: true,
    });
};

const accessForm = useForm({
    expected_revision: 1,
    expected_access: 'active',
    target_access: 'revoked',
});

const transitionAccess = (): void => {
    const item = selectedCase.value;
    if (item === null) return;

    accessForm.expected_revision = item.revision;
    accessForm.post(`/changes/exit/${item.id}/access`, {
        preserveScroll: true,
    });
};

const revisionActionForm = useForm({
    expected_revision: 1,
});

const revisionAction = (suffix: string): void => {
    const item = selectedCase.value;
    if (item === null) return;

    revisionActionForm.clearErrors();
    revisionActionForm.expected_revision = item.revision;
    revisionActionForm.post(
        `/changes/exit/${item.id}/${suffix}`,
        {
            preserveScroll: true,
            onSuccess: () => revisionActionForm.clearErrors(),
        },
    );
};

const transition = (target: string): void => {
    const item = selectedCase.value;
    if (item === null) return;

    useForm({
        expected_revision: item.revision,
        target,
    }).post(`/changes/exit/${item.id}/transition`, {
        preserveScroll: true,
    });
};

const reviewRecord = (target: string): void => {
    const item = selectedCase.value;
    const recordId =
        item?.governance_submission?.formal_record_version_id;

    if (item === null || !recordId) return;

    useForm({ target }).post(
        `/changes/exit/${item.id}/records/${recordId}/content-review`,
        { preserveScroll: true },
    );
};

watch(
    selectedCase,
    (item) => {
        if (item === null) return;

        noticeForm.defaults({
            expected_revision: item.revision,
            notice_date: item.notice_date ?? '',
            intended_exit_date: item.intended_exit_date ?? '',
            required_notice_days: item.required_notice_days,
            notice_summary: item.notice_summary ?? '',
        });
        noticeForm.reset();

        shareForm.defaults({
            expected_revision: item.revision,
            share_treatment: item.share_treatment ?? 'no_shares',
            leaver_classification:
                item.leaver_classification ?? 'not_applicable',
            buyer_partner_id: item.buyer_partner_id ?? '',
            partner_change_case_id: item.partner_change_case_id ?? '',
            ownership_scenario_id: item.ownership_scenario_id ?? '',
            valuation_method: item.valuation_method ?? '',
            approved_business_value_minor_units:
                item.approved_business_value_minor_units,
            leaver_adjustment_minor_units:
                item.leaver_adjustment_minor_units,
            final_buyout_value_minor_units:
                item.final_buyout_value_minor_units,
            currency: item.currency ?? '',
            leaver_rule_reference: item.leaver_rule_reference ?? '',
        });
        shareForm.reset();

        paymentForm.defaults({
            expected_revision: item.revision,
            payment_total_minor_units: item.payment_total_minor_units,
            payment_terms_summary: item.payment_terms_summary ?? '',
            deposit_minor_units: item.deposit_minor_units,
            installment_minor_units: item.installment_minor_units,
            installment_count: item.installment_count,
            payment_frequency: item.payment_frequency ?? '',
            first_payment_date: item.first_payment_date ?? '',
            final_payment_date: item.final_payment_date ?? '',
            interest_terms: item.interest_terms ?? '',
            security_terms: item.security_terms ?? '',
            late_payment_rule: item.late_payment_rule ?? '',
            affordability_status: item.affordability_status,
            alternative_payment_structure:
                item.alternative_payment_structure ?? '',
        });
        paymentForm.reset();
    },
    { immediate: true },
);
</script>

<template>
    <Head :title="t('exit.title')" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-[1600px] space-y-6 px-4 py-5 sm:px-6 sm:py-6 lg:px-8 lg:py-7">
            <header class="pbr-surface p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                            {{ exitWorkspace.business.name }}
                        </p>
                        <h1 class="mt-2 text-2xl font-semibold text-slate-950">
                            {{ t('exit.title') }}
                        </h1>
                        <p class="mt-2 max-w-4xl text-sm leading-6 text-slate-600">
                            {{ t('exit.description') }}
                        </p>
                    </div>

                    <Link
                        href="/changes/partner-changes"
                        class="inline-flex min-h-11 items-center rounded-md border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-800 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-500"
                    >
                        {{ t('exit.openPartnerChanges') }}
                    </Link>
                </div>

                <div class="mt-4 rounded-[16px] border border-[#cfe1d3] bg-[#f3f8f4] px-4 py-3 text-sm font-semibold text-[var(--pbr-green-dark)]">
                    {{ t('exit.boundary') }}
                </div>
            </header>

            <section class="overflow-hidden pbr-surface">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                    <h2 class="font-semibold text-slate-950">
                        {{ t('exit.register') }}
                    </h2>
                    <span class="text-xs font-medium text-slate-500">
                        {{ exitWorkspace.cases.length }} cases
                    </span>
                </div>

                <div
                    v-if="exitWorkspace.cases.length === 0"
                    class="px-4 py-10 text-sm text-slate-500"
                >
                    {{ t('exit.noCases') }}
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3 font-semibold">Case</th>
                                <th class="px-4 py-3 font-semibold">{{ t('exit.partner') }}</th>
                                <th class="px-4 py-3 font-semibold">{{ t('exit.trigger') }}</th>
                                <th class="px-4 py-3 font-semibold">{{ t('exit.status') }}</th>
                                <th class="px-4 py-3 font-semibold">{{ t('exit.settlement') }}</th>
                                <th class="px-4 py-3 font-semibold">{{ t('exit.revision') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr
                                v-for="item in exitWorkspace.cases"
                                :key="item.id"
                                class="cursor-pointer hover:bg-slate-50"
                                :class="{ 'bg-slate-50': item.id === selectedId }"
                                tabindex="0"
                                @click="selectedId = item.id"
                                @keydown.enter="selectedId = item.id"
                            >
                                <td class="px-4 py-3 font-mono text-xs font-semibold text-slate-800">
                                    {{ item.case_number }}
                                </td>
                                <td class="px-4 py-3 text-slate-700">
                                    {{ partnerName(item.partner_id) }}
                                </td>
                                <td class="px-4 py-3 text-slate-700">
                                    {{ item.trigger.replaceAll('_', ' ') }}
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                        :class="statusClass(item.status)"
                                    >
                                        {{ item.status.replaceAll('_', ' ') }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-slate-700">
                                    {{ item.settlement_status.replaceAll('_', ' ') }}
                                </td>
                                <td class="px-4 py-3 tabular-nums text-slate-600">
                                    {{ item.revision }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
            <section
                v-if="exitWorkspace.permissions.manage"
                class="pbr-surface"
            >
                <details>
                    <summary class="cursor-pointer px-4 py-4 font-semibold text-slate-950">
                        {{ t('exit.newCase') }}
                    </summary>

                    <form
                        class="grid gap-4 border-t border-slate-200 p-4 md:grid-cols-2 xl:grid-cols-4"
                        @submit.prevent="createCase"
                    >
                        <label class="space-y-1 text-sm">
                            <span class="font-medium text-slate-700">{{ t('exit.partner') }}</span>
                            <select
                                v-model="createForm.partner_id"
                                required
                                class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                            >
                                <option value="">Select active Partner</option>
                                <option
                                    v-for="partner in exitWorkspace.partners.filter((item) => item.status === 'active')"
                                    :key="partner.id"
                                    :value="partner.id"
                                >
                                    {{ partner.display_name }}
                                </option>
                            </select>
                        </label>

                        <label class="space-y-1 text-sm">
                            <span class="font-medium text-slate-700">{{ t('exit.trigger') }}</span>
                            <select
                                v-model="createForm.trigger"
                                class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                            >
                                <option value="voluntary">Voluntary exit</option>
                                <option value="retirement">Retirement</option>
                                <option value="poor_performance">Poor performance</option>
                                <option value="misconduct">Misconduct</option>
                                <option value="incapacity">Incapacity / disability</option>
                                <option value="death">Death</option>
                                <option value="bankruptcy_insolvency">Bankruptcy / insolvency</option>
                                <option value="relationship_breakdown">Relationship breakdown</option>
                                <option value="agreement_breach">Agreement breach</option>
                                <option value="other">Other</option>
                            </select>
                        </label>

                        <label class="space-y-1 text-sm">
                            <span class="font-medium text-slate-700">Governance decision type</span>
                            <input
                                v-model="createForm.governance_decision_type"
                                required
                                class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                            />
                        </label>

                        <label class="space-y-1 text-sm">
                            <span class="font-medium text-slate-700">Effective from</span>
                            <OptionalTemporalInput
                                v-model="createForm.effective_from"
                                type="datetime-local"
                                class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                            />
                        </label>

                        <label class="space-y-1 text-sm md:col-span-2 xl:col-span-4">
                            <span class="font-medium text-slate-700">Trigger detail</span>
                            <textarea
                                v-model="createForm.trigger_detail"
                                rows="2"
                                class="w-full rounded-md border-slate-300 text-sm"
                            />
                        </label>

                        <div class="md:col-span-2 xl:col-span-4">
                            <button
                                type="submit"
                                :disabled="createForm.processing"
                                class="inline-flex min-h-11 items-center rounded-md bg-slate-950 px-4 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                            >
                                {{ t('exit.create') }}
                            </button>
                        </div>
                    </form>
                </details>
            </section>

            <section
                v-if="selectedCase !== null"
                class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(380px,0.46fr)]"
            >
                <div class="space-y-4">
                    <div class="pbr-surface">
                        <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 p-4">
                            <div>
                                <p class="font-mono text-xs font-semibold text-slate-500">
                                    {{ selectedCase.case_number }}
                                </p>
                                <h2 class="mt-1 text-lg font-semibold text-slate-950">
                                    {{ partnerName(selectedCase.partner_id) }}
                                </h2>
                                <p class="mt-1 text-sm text-slate-500">
                                    {{ membershipLabel(selectedCase.membership_id) }}
                                </p>
                            </div>
                            <span
                                class="rounded-full px-3 py-1 text-xs font-semibold"
                                :class="statusClass(selectedCase.status)"
                            >
                                {{ selectedCase.status.replaceAll('_', ' ') }}
                            </span>
                        </div>

                        <dl class="grid gap-x-6 gap-y-4 p-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                            <div>
                                <dt class="text-slate-500">{{ t('exit.trigger') }}</dt>
                                <dd class="mt-1 font-medium text-slate-900">
                                    {{ selectedCase.trigger.replaceAll('_', ' ') }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Ownership baseline</dt>
                                <dd class="mt-1 font-medium text-slate-900">
                                    {{ selectedCase.source_ownership_register_version_id ? 'Captured from Current Effective Ownership' : 'No Effective Ownership Register' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">{{ t('exit.shareTreatment') }}</dt>
                                <dd class="mt-1 font-medium text-slate-900">
                                    {{ selectedCase.share_treatment?.replaceAll('_', ' ') ?? 'Pending' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Buyer</dt>
                                <dd class="mt-1 font-medium text-slate-900">
                                    {{ partnerName(selectedCase.buyer_partner_id) }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Final buyout value</dt>
                                <dd class="mt-1 font-medium text-slate-900">
                                    {{ money(selectedCase.final_buyout_value_minor_units, selectedCase.currency) }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">{{ t('exit.revision') }}</dt>
                                <dd class="mt-1 font-medium text-slate-900">
                                    {{ selectedCase.revision }}
                                </dd>
                            </div>
                        </dl>

                        <div
                            v-if="exitWorkspace.permissions.manage"
                            class="border-t border-slate-200 p-4"
                        >
                            <h3 class="text-sm font-semibold text-slate-950">Workflow</h3>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button
                                    v-if="selectedCase.status === 'treatment_ready'"
                                    type="button"
                                    class="min-h-10 rounded-md border border-slate-300 px-3 text-sm font-semibold text-slate-800 hover:bg-slate-50"
                                    @click="transition('terms_ready')"
                                >
                                    Mark terms ready
                                </button>
                                <button
                                    v-if="selectedCase.status === 'terms_ready'"
                                    type="button"
                                    class="min-h-10 rounded-md bg-slate-950 px-3 text-sm font-semibold text-white hover:bg-slate-800"
                                    @click="revisionAction('governance')"
                                >
                                    Submit frozen Exit proposal
                                </button>
                                <button
                                    v-if="selectedCase.status === 'under_governance'"
                                    type="button"
                                    class="min-h-10 rounded-md border border-slate-300 px-3 text-sm font-semibold text-slate-800 hover:bg-slate-50"
                                    @click="revisionAction('sync-decision')"
                                >
                                    Sync Governance decision
                                </button>
                                <button
                                    v-if="selectedCase.status === 'approved'"
                                    type="button"
                                    class="min-h-10 rounded-md border border-amber-300 bg-amber-50 px-3 text-sm font-semibold text-amber-900 hover:bg-amber-100"
                                    @click="revisionAction('prepare-effect')"
                                >
                                    Prepare for effect
                                </button>
                                <button
                                    v-if="selectedCase.status === 'ready_for_effect'"
                                    type="button"
                                    class="min-h-10 rounded-md bg-emerald-700 px-3 text-sm font-semibold text-white hover:bg-emerald-800"
                                    @click="revisionAction('effect')"
                                >
                                    Make Exit effective
                                </button>
                                <button
                                    v-if="['effective', 'settlement_pending'].includes(selectedCase.status)"
                                    type="button"
                                    class="min-h-10 rounded-md border border-slate-300 px-3 text-sm font-semibold text-slate-800 hover:bg-slate-50"
                                    @click="revisionAction('settlement/refresh')"
                                >
                                    Refresh settlement
                                </button>
                                <button
                                    v-if="['effective', 'settlement_pending'].includes(selectedCase.status)"
                                    type="button"
                                    class="min-h-10 rounded-md bg-slate-950 px-3 text-sm font-semibold text-white hover:bg-slate-800"
                                    @click="revisionAction('complete')"
                                >
                                    {{ t('exit.former') }}
                                </button>
                                <button
                                    v-if="['draft', 'notice_recorded', 'treatment_ready', 'terms_ready'].includes(selectedCase.status)"
                                    type="button"
                                    class="min-h-10 rounded-md border border-rose-300 bg-rose-50 px-3 text-sm font-semibold text-rose-900"
                                    @click="transition('withdrawn')"
                                >
                                    Withdraw
                                </button>
                            </div>
                            <p
                                v-if="Object.keys(revisionActionForm.errors).length"
                                class="mt-3 text-sm text-rose-700"
                            >
                                {{ Object.values(revisionActionForm.errors)[0] }}
                            </p>
                        </div>
                    </div>

                    <details
                        v-if="exitWorkspace.permissions.manage && selectedCase.status === 'draft'"
                        open
                        class="pbr-surface"
                    >
                        <summary class="cursor-pointer p-4 font-semibold text-slate-950">
                            {{ t('exit.notice') }}
                        </summary>
                        <form
                            class="grid gap-4 border-t border-slate-200 p-4 md:grid-cols-2"
                            @submit.prevent="recordNotice"
                        >
                            <label class="space-y-1 text-sm">
                                <span class="font-medium text-slate-700">Notice date</span>
                                <input
                                    v-model="noticeForm.notice_date"
                                    type="date"
                                    required
                                    class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                                />
                            </label>
                            <label class="space-y-1 text-sm">
                                <span class="font-medium text-slate-700">Intended exit date</span>
                                <OptionalTemporalInput
                                    v-model="noticeForm.intended_exit_date"
                                    type="date"
                                    class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                                />
                            </label>
                            <label class="space-y-1 text-sm">
                                <span class="font-medium text-slate-700">Required notice days</span>
                                <input
                                    v-model.number="noticeForm.required_notice_days"
                                    type="number"
                                    min="0"
                                    class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                                />
                            </label>
                            <label class="space-y-1 text-sm md:col-span-2">
                                <span class="font-medium text-slate-700">Notice summary</span>
                                <textarea
                                    v-model="noticeForm.notice_summary"
                                    required
                                    rows="3"
                                    class="w-full rounded-md border-slate-300 text-sm"
                                />
                            </label>
                            <div class="md:col-span-2">
                                <button
                                    type="submit"
                                    class="min-h-11 rounded-md bg-slate-950 px-4 text-sm font-semibold text-white"
                                >
                                    Record Exit Notice
                                </button>
                            </div>
                        </form>
                    </details>
                    <details
                        v-if="exitWorkspace.permissions.manage && selectedCase.status === 'notice_recorded'"
                        open
                        class="pbr-surface"
                    >
                        <summary class="cursor-pointer p-4 font-semibold text-slate-950">
                            {{ t('exit.shareTreatment') }}
                        </summary>
                        <form
                            class="grid gap-4 border-t border-slate-200 p-4 md:grid-cols-2"
                            @submit.prevent="recordShareTreatment"
                        >
                            <label class="space-y-1 text-sm">
                                <span class="font-medium text-slate-700">Share treatment</span>
                                <select
                                    v-model="shareForm.share_treatment"
                                    class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                                >
                                    <option value="no_shares">No shares</option>
                                    <option value="remaining_partners_buy">Remaining Partners buy</option>
                                    <option value="company_buyback">Company buyback</option>
                                    <option value="third_party_sale">Third-party sale</option>
                                    <option value="partial_buyout">Partial buyout</option>
                                    <option value="permitted_person_transfer">Permitted-person transfer</option>
                                    <option value="cancellation">Cancellation</option>
                                    <option value="retain_per_agreement">Retain per agreement</option>
                                    <option value="other">Other governed treatment</option>
                                </select>
                            </label>

                            <label class="space-y-1 text-sm">
                                <span class="font-medium text-slate-700">Leaver classification</span>
                                <select
                                    v-model="shareForm.leaver_classification"
                                    class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                                >
                                    <option value="not_applicable">Not applicable</option>
                                    <option value="good">Good</option>
                                    <option value="bad">Bad</option>
                                    <option value="neutral">Neutral</option>
                                    <option value="other">Other</option>
                                </select>
                            </label>

                            <label class="space-y-1 text-sm">
                                <span class="font-medium text-slate-700">Buyer Partner</span>
                                <select
                                    v-model="shareForm.buyer_partner_id"
                                    class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                                >
                                    <option value="">No Partner buyer</option>
                                    <option
                                        v-for="partner in exitWorkspace.partners.filter((item) => item.id !== selectedCase?.partner_id)"
                                        :key="partner.id"
                                        :value="partner.id"
                                    >
                                        {{ partner.display_name }}
                                    </option>
                                </select>
                            </label>

                            <details class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm md:col-span-2">
                                <summary class="cursor-pointer font-semibold text-slate-700">{{ t('governance.advancedDetails') }}</summary>
                                <div class="mt-3 grid gap-3 md:grid-cols-2">
                                    <label class="space-y-1 text-sm">
                                        <span class="font-medium text-slate-700">Partner Change case reference</span>
                                        <input v-model="shareForm.partner_change_case_id" class="min-h-11 w-full rounded-md border-slate-300 font-mono text-xs" />
                                    </label>
                                    <label class="space-y-1 text-sm">
                                        <span class="font-medium text-slate-700">Frozen Ownership scenario reference</span>
                                        <input v-model="shareForm.ownership_scenario_id" class="min-h-11 w-full rounded-md border-slate-300 font-mono text-xs" />
                                    </label>
                                </div>
                            </details>

                            <label class="space-y-1 text-sm">
                                <span class="font-medium text-slate-700">Valuation method</span>
                                <input
                                    v-model="shareForm.valuation_method"
                                    class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                                />
                            </label>

                            <label class="space-y-1 text-sm">
                                <span class="font-medium text-slate-700">Approved business value (minor units)</span>
                                <input
                                    v-model.number="shareForm.approved_business_value_minor_units"
                                    type="number"
                                    min="0"
                                    class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                                />
                            </label>

                            <label class="space-y-1 text-sm">
                                <span class="font-medium text-slate-700">Leaver adjustment (minor units)</span>
                                <input
                                    v-model.number="shareForm.leaver_adjustment_minor_units"
                                    type="number"
                                    class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                                />
                            </label>

                            <label class="space-y-1 text-sm">
                                <span class="font-medium text-slate-700">Final buyout value (minor units)</span>
                                <input
                                    v-model.number="shareForm.final_buyout_value_minor_units"
                                    type="number"
                                    min="0"
                                    class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                                />
                            </label>

                            <label class="space-y-1 text-sm">
                                <span class="font-medium text-slate-700">Currency</span>
                                <input
                                    v-model="shareForm.currency"
                                    maxlength="3"
                                    class="min-h-11 w-full rounded-md border-slate-300 text-sm uppercase"
                                />
                            </label>

                            <label class="space-y-1 text-sm md:col-span-2">
                                <span class="font-medium text-slate-700">Leaver rule / evidence reference</span>
                                <textarea
                                    v-model="shareForm.leaver_rule_reference"
                                    rows="2"
                                    class="w-full rounded-md border-slate-300 text-sm"
                                />
                            </label>

                            <div class="md:col-span-2">
                                <button
                                    type="submit"
                                    class="min-h-11 rounded-md bg-slate-950 px-4 text-sm font-semibold text-white"
                                >
                                    Record share treatment
                                </button>
                            </div>
                        </form>
                    </details>

                    <details
                        v-if="exitWorkspace.permissions.manage && selectedCase.status === 'treatment_ready'"
                        open
                        class="pbr-surface"
                    >
                        <summary class="cursor-pointer p-4 font-semibold text-slate-950">
                            {{ t('exit.payment') }}
                        </summary>
                        <form
                            class="grid gap-4 border-t border-slate-200 p-4 md:grid-cols-2"
                            @submit.prevent="recordPaymentTerms"
                        >
                            <label class="space-y-1 text-sm">
                                <span class="font-medium text-slate-700">Payment total (minor units)</span>
                                <input
                                    v-model.number="paymentForm.payment_total_minor_units"
                                    type="number"
                                    min="0"
                                    class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                                />
                            </label>
                            <label class="space-y-1 text-sm">
                                <span class="font-medium text-slate-700">Affordability</span>
                                <select
                                    v-model="paymentForm.affordability_status"
                                    class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                                >
                                    <option value="not_applicable">Not applicable</option>
                                    <option value="affordable">Affordable</option>
                                    <option value="not_affordable">Not affordable</option>
                                    <option value="alternative_approved">Alternative approved</option>
                                    <option value="pending">Pending</option>
                                </select>
                            </label>
                            <label class="space-y-1 text-sm md:col-span-2">
                                <span class="font-medium text-slate-700">Payment terms summary</span>
                                <textarea
                                    v-model="paymentForm.payment_terms_summary"
                                    rows="2"
                                    class="w-full rounded-md border-slate-300 text-sm"
                                />
                            </label>
                            <label class="space-y-1 text-sm">
                                <span class="font-medium text-slate-700">Deposit</span>
                                <input
                                    v-model.number="paymentForm.deposit_minor_units"
                                    type="number"
                                    min="0"
                                    class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                                />
                            </label>
                            <label class="space-y-1 text-sm">
                                <span class="font-medium text-slate-700">Installment amount</span>
                                <input
                                    v-model.number="paymentForm.installment_minor_units"
                                    type="number"
                                    min="0"
                                    class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                                />
                            </label>
                            <label class="space-y-1 text-sm">
                                <span class="font-medium text-slate-700">Installment count</span>
                                <input
                                    v-model.number="paymentForm.installment_count"
                                    type="number"
                                    min="1"
                                    class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                                />
                            </label>
                            <label class="space-y-1 text-sm">
                                <span class="font-medium text-slate-700">Payment frequency</span>
                                <input
                                    v-model="paymentForm.payment_frequency"
                                    class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                                />
                            </label>
                            <label class="space-y-1 text-sm">
                                <span class="font-medium text-slate-700">First payment date</span>
                                <OptionalTemporalInput
                                    v-model="paymentForm.first_payment_date"
                                    type="date"
                                    class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                                />
                            </label>
                            <label class="space-y-1 text-sm">
                                <span class="font-medium text-slate-700">Final payment date</span>
                                <OptionalTemporalInput
                                    v-model="paymentForm.final_payment_date"
                                    type="date"
                                    class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                                />
                            </label>
                            <label class="space-y-1 text-sm md:col-span-2">
                                <span class="font-medium text-slate-700">Alternative payment structure</span>
                                <textarea
                                    v-model="paymentForm.alternative_payment_structure"
                                    rows="2"
                                    class="w-full rounded-md border-slate-300 text-sm"
                                />
                            </label>
                            <div class="md:col-span-2">
                                <button
                                    type="submit"
                                    class="min-h-11 rounded-md bg-slate-950 px-4 text-sm font-semibold text-white"
                                >
                                    Record payment terms
                                </button>
                            </div>
                        </form>
                    </details>

                    <div class="pbr-surface">
                        <div class="border-b border-slate-200 px-4 py-3">
                            <h3 class="font-semibold text-slate-950">
                                {{ t('exit.sharePosition') }}
                            </h3>
                            <p class="mt-1 text-xs text-slate-500">
                                Captured from exact Effective Ownership truth when the Exit Case opened. This snapshot never mutates live Ownership.
                            </p>
                        </div>

                        <div
                            v-if="selectedCase.share_positions.length === 0"
                            class="p-4 text-sm text-slate-500"
                        >
                            No issued Share position was captured.
                        </div>

                        <div v-else class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th class="px-4 py-3">Share Class</th>
                                        <th class="px-4 py-3">Issued</th>
                                        <th class="px-4 py-3">Vested</th>
                                        <th class="px-4 py-3">Unvested</th>
                                        <th class="px-4 py-3">Voting</th>
                                        <th class="px-4 py-3">Profit</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr
                                        v-for="position in selectedCase.share_positions"
                                        :key="position.id"
                                    >
                                        <td class="px-4 py-3 font-medium text-slate-900">
                                            {{ position.share_class_name }}
                                        </td>
                                        <td class="px-4 py-3">{{ position.shares_issued }}</td>
                                        <td class="px-4 py-3">{{ position.shares_vested }}</td>
                                        <td class="px-4 py-3">{{ position.shares_unvested }}</td>
                                        <td class="px-4 py-3">{{ position.voting_rights }}</td>
                                        <td class="px-4 py-3">{{ position.profit_rights }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <aside class="space-y-4">
                    <details
                        v-if="exitWorkspace.permissions.manage && !['completed', 'rejected', 'withdrawn', 'cancelled'].includes(selectedCase.status)"
                        class="pbr-surface"
                    >
                        <summary class="cursor-pointer p-4 font-semibold text-slate-950">
                            {{ t('exit.handover') }}
                        </summary>
                        <form
                            class="space-y-3 border-t border-slate-200 p-4"
                            @submit.prevent="recordRequirement"
                        >
                            <label class="block space-y-1 text-sm">
                                <span class="font-medium text-slate-700">{{ t('exit.requirementType') }}</span>
                                <select
                                    v-model="requirementForm.requirement_type"
                                    class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                                >
                                    <option value="ownership">Ownership</option>
                                    <option value="finance">Finance</option>
                                    <option value="handover">Handover</option>
                                    <option value="access">Access</option>
                                    <option value="operations">Operations</option>
                                    <option value="continuity">Continuity</option>
                                    <option value="post_exit">Post-exit</option>
                                    <option value="legal">Legal</option>
                                    <option value="governance">Governance</option>
                                    <option value="conflict">Conflict</option>
                                    <option value="other">Other</option>
                                </select>
                            </label>
                            <label class="block space-y-1 text-sm">
                                <span class="font-medium text-slate-700">{{ t('exit.requirementKey') }}</span>
                                <input
                                    v-model="requirementForm.requirement_key"
                                    required
                                    class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                                    placeholder="e.g. legal_documentation_complete"
                                />
                            </label>
                            <label class="block space-y-1 text-sm">
                                <span class="font-medium text-slate-700">{{ t('exit.requirementStatus') }}</span>
                                <select
                                    v-model="requirementForm.status"
                                    class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                                >
                                    <option value="pending">Pending</option>
                                    <option value="met">Met</option>
                                    <option value="blocked">Blocked</option>
                                    <option value="not_applicable">Not applicable</option>
                                </select>
                            </label>
                            <label class="block space-y-1 text-sm">
                                <span class="font-medium text-slate-700">{{ t('exit.requirementDetail') }}</span>
                                <textarea
                                    v-model="requirementForm.detail"
                                    rows="2"
                                    class="w-full rounded-md border-slate-300 text-sm"
                                    placeholder="Optional supporting context"
                                />
                            </label>
                            <details class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <summary class="cursor-pointer text-sm font-semibold text-slate-700">{{ t('governance.advancedDetails') }}</summary>
                                <div class="mt-3 space-y-3">
                                    <label class="block space-y-1 text-sm">
                                        <span class="font-medium text-slate-700">{{ t('exit.requirementSourceType') }}</span>
                                        <input v-model="requirementForm.source_type" class="min-h-11 w-full rounded-md border-slate-300 text-sm" placeholder="e.g. formal_record" />
                                    </label>
                                    <label class="block space-y-1 text-sm">
                                        <span class="font-medium text-slate-700">{{ t('exit.requirementSourceId') }}</span>
                                        <input v-model="requirementForm.source_id" class="min-h-11 w-full rounded-md border-slate-300 font-mono text-xs" placeholder="Authorized source record ID" />
                                    </label>
                                </div>
                            </details>
                            <button
                                type="submit"
                                class="min-h-10 rounded-md bg-slate-950 px-3 text-sm font-semibold text-white"
                            >
                                Record requirement
                            </button>
                        </form>
                    </details>

                    <div class="pbr-surface p-4">
                        <h3 class="font-semibold text-slate-950">Requirements</h3>
                        <div
                            v-if="selectedCase.requirements.length === 0"
                            class="mt-3 text-sm text-slate-500"
                        >
                            No requirements recorded yet.
                        </div>
                        <ul v-else class="mt-3 space-y-3 text-sm">
                            <li
                                v-for="requirement in selectedCase.requirements"
                                :key="requirement.id"
                                class="border-t border-slate-100 pt-3"
                            >
                                <div class="flex items-center justify-between gap-3">
                                    <span class="font-medium text-slate-900">
                                        {{ requirement.requirement_key.replaceAll('_', ' ') }}
                                    </span>
                                    <span class="text-xs font-semibold text-slate-600">
                                        {{ requirement.status.replaceAll('_', ' ') }}
                                    </span>
                                </div>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ requirement.requirement_type }}
                                </p>
                                <p
                                    v-if="requirement.detail"
                                    class="mt-1 text-slate-600"
                                >
                                    {{ requirement.detail }}
                                </p>
                            </li>
                        </ul>
                    </div>

                    <div
                        v-if="selectedCase.governance_submission"
                        class="pbr-surface p-4 text-sm"
                    >
                        <h3 class="font-semibold text-slate-950">
                            {{ t('exit.governance') }}
                        </h3>
                        <dl class="mt-3 space-y-3">
                            <div>
                                <dt class="text-slate-500">Formal record state</dt>
                                <dd class="mt-1 font-medium text-slate-900">
                                    {{ selectedCase.governance_submission.formal_record_state ?? '—' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Decision</dt>
                                <dd class="mt-1 font-medium text-slate-900">
                                    {{ selectedCase.governance_submission.decision_id ? 'Recorded' : 'Pending' }}
                                </dd>
                            </div>
                        </dl>
                        <details class="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
                            <summary class="cursor-pointer font-semibold">{{ t('governance.advancedDetails') }}</summary>
                            <dl class="mt-3 space-y-2">
                                <div><dt>Proposal version</dt><dd class="mt-1 break-all font-mono">{{ selectedCase.governance_submission.proposal_version_id }}</dd></div>
                                <div><dt>Formal record version</dt><dd class="mt-1 break-all font-mono">{{ selectedCase.governance_submission.formal_record_version_id }}</dd></div>
                                <div v-if="selectedCase.governance_submission.decision_id"><dt>Decision reference</dt><dd class="mt-1 break-all font-mono">{{ selectedCase.governance_submission.decision_id }}</dd></div>
                                <div v-if="selectedCase.source_ownership_register_version_id"><dt>Ownership baseline version</dt><dd class="mt-1 break-all font-mono">{{ selectedCase.source_ownership_register_version_id }}</dd></div>
                            </dl>
                        </details>
                        <div
                            v-if="exitWorkspace.permissions.manage"
                            class="mt-3 flex flex-wrap gap-2"
                        >
                            <button
                                type="button"
                                class="min-h-10 rounded-md border border-slate-300 px-3 text-xs font-semibold"
                                @click="reviewRecord('under_review')"
                            >
                                Start record review
                            </button>
                            <button
                                type="button"
                                class="min-h-10 rounded-md border border-slate-300 px-3 text-xs font-semibold"
                                @click="reviewRecord('approved')"
                            >
                                Approve record content
                            </button>
                        </div>
                    </div>

                    <details
                        v-if="exitWorkspace.permissions.manage && exitWorkspace.permissions.finance_view"
                        class="pbr-surface"
                    >
                        <summary class="cursor-pointer p-4 font-semibold text-slate-950">
                            F6C Finance payment links
                        </summary>
                        <form
                            class="space-y-3 border-t border-slate-200 p-4"
                            @submit.prevent="linkFinancePayment"
                        >
                            <label class="block space-y-1 text-sm">
                                <span class="font-medium text-slate-700">{{ t('exit.financePaymentId') }}</span>
                                <input
                                    v-model="financeForm.finance_payment_id"
                                    required
                                    class="min-h-11 w-full rounded-md border-slate-300 font-mono text-xs"
                                    placeholder="Authorized Finance payment record ID"
                                />
                            </label>
                            <label class="block space-y-1 text-sm">
                                <span class="font-medium text-slate-700">{{ t('exit.financePurpose') }}</span>
                                <select
                                    v-model="financeForm.purpose"
                                    class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                                >
                                    <option value="deposit">Deposit</option>
                                    <option value="installment">Installment</option>
                                    <option value="final_settlement">Final settlement</option>
                                    <option value="loan_settlement">Loan settlement</option>
                                    <option value="other">Other</option>
                                </select>
                            </label>
                            <label class="block space-y-1 text-sm">
                                <span class="font-medium text-slate-700">{{ t('exit.installmentSequence') }}</span>
                                <input
                                    v-model.number="financeForm.installment_sequence"
                                    type="number"
                                    min="1"
                                    class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                                    placeholder="e.g. 1"
                                />
                            </label>
                            <button
                                type="submit"
                                class="min-h-10 rounded-md bg-slate-950 px-3 text-sm font-semibold text-white"
                            >
                                Link canonical Finance Payment
                            </button>
                        </form>
                    </details>

                    <div
                        v-if="exitWorkspace.permissions.finance_view"
                        class="pbr-surface p-4"
                    >
                        <h3 class="font-semibold text-slate-950">
                            {{ t('exit.settlement') }}
                        </h3>
                        <p class="mt-1 text-xs text-slate-500">
                            Finance remains canonical. Exit only references authorized F6C Payments.
                        </p>
                        <ul
                            v-if="selectedCase.finance_links.length"
                            class="mt-3 space-y-2 text-sm"
                        >
                            <li
                                v-for="link in selectedCase.finance_links"
                                :key="`${link.finance_payment_id}-${link.purpose}`"
                                class="border-t border-slate-100 pt-2"
                            >
                                <div class="flex justify-between gap-3">
                                    <span>{{ link.purpose.replaceAll('_', ' ') }}</span>
                                    <span class="font-semibold">
                                        {{ money(link.amount_minor_units, link.currency) }}
                                    </span>
                                </div>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ link.status }}
                                </p>
                            </li>
                        </ul>
                    </div>

                    <details
                        v-if="exitWorkspace.permissions.manage && exitWorkspace.permissions.access_admin && selectedCase.membership_id !== null && ['effective', 'settlement_pending'].includes(selectedCase.status)"
                        class="pbr-surface"
                    >
                        <summary class="cursor-pointer p-4 font-semibold text-slate-950">
                            {{ t('exit.access') }}
                        </summary>
                        <form
                            class="space-y-3 border-t border-slate-200 p-4"
                            @submit.prevent="transitionAccess"
                        >
                            <p class="text-xs leading-5 text-slate-600">
                                Exit-related access changes happen only after Exit effectivity. Access changes never mutate Ownership.
                            </p>
                            <select
                                v-model="accessForm.expected_access"
                                class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                            >
                                <option value="active">Active</option>
                                <option value="suspended">Suspended</option>
                                <option value="revoked">Revoked</option>
                            </select>
                            <select
                                v-model="accessForm.target_access"
                                class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                            >
                                <option value="suspended">Suspend</option>
                                <option value="revoked">Revoke</option>
                            </select>
                            <button
                                type="submit"
                                class="min-h-10 rounded-md border border-rose-300 bg-rose-50 px-3 text-sm font-semibold text-rose-900"
                            >
                                Apply explicit access transition
                            </button>
                        </form>
                    </details>
                </aside>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
