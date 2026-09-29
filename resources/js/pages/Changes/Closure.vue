<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import { useI18n } from '../../i18n/useI18n';

type Claim = {
    id: string;
    claim_reference: string;
    claim_type: string;
    claimant_reference: string;
    description: string | null;
    amount_minor_units: number | null;
    currency: string | null;
    required: boolean;
    legal_priority_reference: string | null;
    source_type: string | null;
    source_id: string | null;
    external_source_reference: string | null;
    status: string;
    revision: number;
};

type Requirement = {
    id: string;
    requirement_type: string;
    requirement_key: string;
    status: string;
    detail: string | null;
    source_type: string | null;
    source_id: string | null;
    external_source_reference: string | null;
};

type FinanceLink = {
    id: string;
    closure_claim_id: string | null;
    finance_payment_id: string;
    purpose: string;
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
    authorized_at: string | null;
    effected_at: string | null;
};

type ClosureCase = {
    id: string;
    case_number: string;
    trigger: string;
    trigger_detail: string | null;
    jurisdiction_reference: string;
    legal_entity_reference: string | null;
    governance_decision_type: string;
    intended_legal_closure_at: string | null;
    residual_distribution_minor_units: number | null;
    currency: string | null;
    residual_distribution_basis: string | null;
    residual_distribution_status: string;
    legal_closed_at: string | null;
    workspace_closed_at: string | null;
    status: string;
    revision: number;
    created_at: string | null;
    claims: Claim[];
    requirements: Requirement[];
    finance_links: FinanceLink[];
    governance_submission: GovernanceSubmission | null;
};

const props = defineProps<{
    closureWorkspace: {
        business: {
            id: string;
            name: string;
            workspace_status: string;
        };
        permissions: {
            view: boolean;
            manage: boolean;
            finance_view: boolean;
        };
        cases: ClosureCase[];
    };
}>();

const { t } = useI18n();

const selectedId = ref<string | null>(
    props.closureWorkspace.cases[0]?.id ?? null,
);

watch(
    () => props.closureWorkspace.cases,
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
        props.closureWorkspace.cases.find(
            (item) => item.id === selectedId.value,
        ) ?? null,
);

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
    if (['completed', 'legally_closed'].includes(status)) {
        return 'bg-emerald-50 text-emerald-800';
    }

    if (['rejected', 'withdrawn', 'cancelled', 'blocked'].includes(status)) {
        return 'bg-rose-50 text-rose-800';
    }

    if (
        [
            'approved',
            'wind_down_active',
            'residual_ready',
            'legal_closure_ready',
        ].includes(status)
    ) {
        return 'bg-amber-50 text-amber-900';
    }

    return 'bg-slate-100 text-slate-700';
};

const createForm = useForm({
    trigger: '',
    trigger_detail: '',
    jurisdiction_reference: '',
    legal_entity_reference: '',
    governance_decision_type: 'business_closure_approval',
    intended_legal_closure_at: '',
});

const createCaseError = computed(
    () =>
        (createForm.errors as Record<string, string | undefined>).closure ??
        null,
);

const createCase = (): void => {
    createForm.post('/changes/closure', {
        preserveScroll: true,
        onSuccess: () => createForm.reset(),
    });
};

const requirementForm = useForm({
    expected_revision: 1,
    requirement_type: 'legal',
    requirement_key: '',
    status: 'met',
    detail: '',
    source_type: '',
    source_id: '',
    external_source_reference: '',
});

const recordRequirement = (): void => {
    const item = selectedCase.value;
    if (item === null) return;

    requirementForm.expected_revision = item.revision;
    requirementForm.post(
        `/changes/closure/${item.id}/requirements`,
        { preserveScroll: true },
    );
};

const claimForm = useForm({
    expected_revision: 1,
    claim_reference: '',
    claim_type: 'creditor',
    claimant_reference: '',
    required: true,
    amount_minor_units: null as number | null,
    currency: '',
    description: '',
    legal_priority_reference: '',
    source_type: '',
    source_id: '',
    external_source_reference: '',
});

const createClaim = (): void => {
    const item = selectedCase.value;
    if (item === null) return;

    claimForm.expected_revision = item.revision;
    claimForm.post(`/changes/closure/${item.id}/claims`, {
        preserveScroll: true,
    });
};

const financeForm = useForm({
    expected_revision: 1,
    finance_payment_id: '',
    purpose: 'claim_settlement',
    claim_id: '',
});

const linkFinance = (): void => {
    const item = selectedCase.value;
    if (item === null) return;

    financeForm.expected_revision = item.revision;
    financeForm.post(`/changes/closure/${item.id}/finance-links`, {
        preserveScroll: true,
    });
};

const residualForm = useForm({
    expected_revision: 1,
    status: 'not_applicable',
    amount_minor_units: null as number | null,
    currency: '',
    basis: '',
});

const recordResidual = (): void => {
    const item = selectedCase.value;
    if (item === null) return;

    residualForm.expected_revision = item.revision;
    residualForm.post(`/changes/closure/${item.id}/residual`, {
        preserveScroll: true,
    });
};

const closeForm = useForm({
    expected_revision: 1,
    confirmation: '',
});

const closeWorkspace = (): void => {
    const item = selectedCase.value;
    if (item === null) return;

    closeForm.expected_revision = item.revision;
    closeForm.post(`/changes/closure/${item.id}/close-workspace`, {
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
        `/changes/closure/${item.id}/${suffix}`,
        {
            preserveScroll: true,
            onSuccess: () => revisionActionForm.clearErrors(),
        },
    );
};

const transitionCase = (target: string): void => {
    const item = selectedCase.value;
    if (item === null) return;

    useForm({
        expected_revision: item.revision,
        target,
    }).post(`/changes/closure/${item.id}/transition`, {
        preserveScroll: true,
    });
};

const reviewRecord = (target: string): void => {
    const item = selectedCase.value;
    const recordId = item?.governance_submission?.formal_record_version_id;
    if (item === null || !recordId) return;

    useForm({ target }).post(
        `/changes/closure/${item.id}/records/${recordId}/content-review`,
        { preserveScroll: true },
    );
};

const transitionClaim = (claim: Claim, target: string): void => {
    const item = selectedCase.value;
    if (item === null) return;

    useForm({
        expected_revision: item.revision,
        expected_claim_revision: claim.revision,
        target,
        note: '',
    }).post(
        `/changes/closure/${item.id}/claims/${claim.id}/transition`,
        { preserveScroll: true },
    );
};
</script>

<template>
    <Head :title="t('closure.title')" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <header class="border-b border-slate-200 pb-5">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                            {{ t('closure.eyebrow') }}
                        </p>
                        <h1 class="mt-2 text-2xl font-semibold text-slate-950">
                            {{ t('closure.title') }}
                        </h1>
                        <p class="mt-2 max-w-3xl text-sm text-slate-600">
                            {{ t('closure.subtitle') }}
                        </p>
                    </div>

                    <div class="text-sm text-slate-600">
                        <span class="font-medium text-slate-900">
                            {{ closureWorkspace.business.name }}
                        </span>
                        <span class="mx-2">·</span>
                        {{ t('closure.workspaceStatus') }}:
                        <span class="font-medium text-slate-900">
                            {{ closureWorkspace.business.workspace_status }}
                        </span>
                    </div>
                </div>
            </header>

            <section
                class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950"
            >
                <p class="font-semibold">{{ t('closure.legalBoundaryTitle') }}</p>
                <p class="mt-1">{{ t('closure.legalBoundaryBody') }}</p>
            </section>

            <div class="grid gap-6 xl:grid-cols-[22rem_minmax(0,1fr)]">
                <aside class="space-y-4">
                    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                        <div class="border-b border-slate-200 px-4 py-3">
                            <h2 class="text-sm font-semibold text-slate-950">
                                {{ t('closure.registerTitle') }}
                            </h2>
                        </div>

                        <div v-if="closureWorkspace.cases.length === 0" class="px-4 py-8 text-sm text-slate-500">
                            {{ t('closure.empty') }}
                        </div>

                        <button
                            v-for="item in closureWorkspace.cases"
                            :key="item.id"
                            type="button"
                            class="block w-full border-b border-slate-100 px-4 py-4 text-left last:border-b-0 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-500"
                            :class="selectedId === item.id ? 'bg-slate-50' : 'bg-white'"
                            @click="selectedId = item.id"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-slate-950">
                                        {{ item.case_number }}
                                    </p>
                                    <p class="mt-1 line-clamp-2 text-xs text-slate-600">
                                        {{ item.trigger }}
                                    </p>
                                </div>
                                <span
                                    class="shrink-0 rounded-full px-2 py-1 text-[11px] font-semibold"
                                    :class="statusClass(item.status)"
                                >
                                    {{ item.status }}
                                </span>
                            </div>
                            <p class="mt-2 text-xs text-slate-500">
                                {{ t('closure.revision') }} {{ item.revision }}
                            </p>
                        </button>
                    </section>

                    <form
                        v-if="closureWorkspace.permissions.manage"
                        class="space-y-3 rounded-xl border border-slate-200 bg-white p-4"
                        @submit.prevent="createCase"
                    >
                        <h2 class="text-sm font-semibold text-slate-950">
                            {{ t('closure.openCase') }}
                        </h2>

                        <label class="block text-xs font-medium text-slate-700">
                            {{ t('closure.trigger') }}
                            <input
                                v-model="createForm.trigger"
                                required
                                class="mt-1 w-full rounded-lg border-slate-300 text-sm"
                            />
                        </label>

                        <label class="block text-xs font-medium text-slate-700">
                            {{ t('closure.jurisdictionReference') }}
                            <textarea
                                v-model="createForm.jurisdiction_reference"
                                required
                                rows="2"
                                class="mt-1 w-full rounded-lg border-slate-300 text-sm"
                            />
                        </label>

                        <label class="block text-xs font-medium text-slate-700">
                            {{ t('closure.decisionType') }}
                            <input
                                v-model="createForm.governance_decision_type"
                                required
                                class="mt-1 w-full rounded-lg border-slate-300 text-sm"
                            />
                        </label>

                        <label class="block text-xs font-medium text-slate-700">
                            {{ t('closure.triggerDetail') }}
                            <textarea
                                v-model="createForm.trigger_detail"
                                rows="2"
                                class="mt-1 w-full rounded-lg border-slate-300 text-sm"
                            />
                        </label>

                        <label class="block text-xs font-medium text-slate-700">
                            {{ t('closure.legalEntityReference') }}
                            <input
                                v-model="createForm.legal_entity_reference"
                                class="mt-1 w-full rounded-lg border-slate-300 text-sm"
                            />
                        </label>

                        <label class="block text-xs font-medium text-slate-700">
                            {{ t('closure.intendedLegalClosureAt') }}
                            <input
                                v-model="createForm.intended_legal_closure_at"
                                type="datetime-local"
                                class="mt-1 w-full rounded-lg border-slate-300 text-sm"
                            />
                        </label>

                        <button
                            type="submit"
                            class="min-h-11 w-full rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                            :disabled="createForm.processing"
                        >
                            {{ t('closure.openCaseAction') }}
                        </button>

                        <p v-if="createCaseError" class="text-xs text-rose-700">
                            {{ createCaseError }}
                        </p>
                    </form>
                </aside>

                <main v-if="selectedCase" class="space-y-6">
                    <section class="rounded-xl border border-slate-200 bg-white">
                        <div class="flex flex-col gap-4 border-b border-slate-200 px-5 py-4 lg:flex-row lg:items-start lg:justify-between">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="text-lg font-semibold text-slate-950">
                                        {{ selectedCase.case_number }}
                                    </h2>
                                    <span
                                        class="rounded-full px-2.5 py-1 text-xs font-semibold"
                                        :class="statusClass(selectedCase.status)"
                                    >
                                        {{ selectedCase.status }}
                                    </span>
                                </div>
                                <p class="mt-2 text-sm text-slate-600">
                                    {{ selectedCase.trigger }}
                                </p>
                            </div>

                            <div class="text-right text-xs text-slate-500">
                                <div>{{ t('closure.revision') }} {{ selectedCase.revision }}</div>
                                <div class="mt-1">
                                    {{ t('closure.residualStatus') }}:
                                    {{ selectedCase.residual_distribution_status }}
                                </div>
                            </div>
                        </div>

                        <dl class="grid gap-4 px-5 py-5 text-sm md:grid-cols-2">
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">
                                    {{ t('closure.jurisdictionReference') }}
                                </dt>
                                <dd class="mt-1 whitespace-pre-wrap text-slate-900">
                                    {{ selectedCase.jurisdiction_reference }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">
                                    {{ t('closure.legalEntityReference') }}
                                </dt>
                                <dd class="mt-1 text-slate-900">
                                    {{ selectedCase.legal_entity_reference || '—' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">
                                    {{ t('closure.decisionType') }}
                                </dt>
                                <dd class="mt-1 text-slate-900">
                                    {{ selectedCase.governance_decision_type }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">
                                    {{ t('closure.intendedLegalClosureAt') }}
                                </dt>
                                <dd class="mt-1 text-slate-900">
                                    {{ selectedCase.intended_legal_closure_at || '—' }}
                                </dd>
                            </div>
                            <div class="md:col-span-2">
                                <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">
                                    {{ t('closure.triggerDetail') }}
                                </dt>
                                <dd class="mt-1 whitespace-pre-wrap text-slate-900">
                                    {{ selectedCase.trigger_detail || '—' }}
                                </dd>
                            </div>
                        </dl>
                    </section>

                    <section class="rounded-xl border border-slate-200 bg-white">
                        <div class="border-b border-slate-200 px-5 py-4">
                            <h3 class="text-sm font-semibold text-slate-950">
                                {{ t('closure.claimsTitle') }}
                            </h3>
                            <p class="mt-1 text-xs text-slate-500">
                                {{ t('closure.claimsHelp') }}
                            </p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th class="px-4 py-3">{{ t('closure.claimReference') }}</th>
                                        <th class="px-4 py-3">{{ t('closure.claimant') }}</th>
                                        <th class="px-4 py-3">{{ t('closure.amount') }}</th>
                                        <th class="px-4 py-3">{{ t('closure.priorityReference') }}</th>
                                        <th class="px-4 py-3">{{ t('closure.status') }}</th>
                                        <th class="px-4 py-3">{{ t('closure.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr v-for="claim in selectedCase.claims" :key="claim.id">
                                        <td class="px-4 py-3 align-top">
                                            <p class="font-medium text-slate-950">
                                                {{ claim.claim_reference }}
                                            </p>
                                            <p class="mt-1 text-xs text-slate-500">
                                                {{ claim.claim_type }}
                                                <span v-if="claim.required"> · {{ t('closure.required') }}</span>
                                            </p>
                                        </td>
                                        <td class="px-4 py-3 align-top text-slate-700">
                                            {{ claim.claimant_reference }}
                                        </td>
                                        <td class="px-4 py-3 align-top text-slate-700">
                                            {{ money(claim.amount_minor_units, claim.currency) }}
                                        </td>
                                        <td class="max-w-xs px-4 py-3 align-top text-xs text-slate-600">
                                            {{ claim.legal_priority_reference || t('closure.noUniversalPriority') }}
                                        </td>
                                        <td class="px-4 py-3 align-top">
                                            <span
                                                class="rounded-full px-2 py-1 text-xs font-semibold"
                                                :class="statusClass(claim.status)"
                                            >
                                                {{ claim.status }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 align-top">
                                            <div
                                                v-if="closureWorkspace.permissions.manage"
                                                class="flex flex-wrap gap-2"
                                            >
                                                <button
                                                    v-if="claim.status === 'identified'"
                                                    type="button"
                                                    class="text-xs font-semibold text-slate-700 underline"
                                                    @click="transitionClaim(claim, 'verified')"
                                                >
                                                    {{ t('closure.verify') }}
                                                </button>
                                                <button
                                                    v-if="['identified', 'verified'].includes(claim.status)"
                                                    type="button"
                                                    class="text-xs font-semibold text-slate-700 underline"
                                                    @click="transitionClaim(claim, 'disputed')"
                                                >
                                                    {{ t('closure.dispute') }}
                                                </button>
                                                <button
                                                    v-if="['verified', 'disputed'].includes(claim.status)"
                                                    type="button"
                                                    class="text-xs font-semibold text-slate-700 underline"
                                                    @click="transitionClaim(claim, 'settled')"
                                                >
                                                    {{ t('closure.settle') }}
                                                </button>
                                                <button
                                                    v-if="!['settled', 'waived'].includes(claim.status)"
                                                    type="button"
                                                    class="text-xs font-semibold text-slate-700 underline"
                                                    @click="transitionClaim(claim, 'waived')"
                                                >
                                                    {{ t('closure.waive') }}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr v-if="selectedCase.claims.length === 0">
                                        <td colspan="6" class="px-4 py-6 text-center text-slate-500">
                                            {{ t('closure.noClaims') }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <div
                        v-if="closureWorkspace.permissions.manage"
                        class="grid gap-6 lg:grid-cols-2"
                    >
                        <form
                            class="space-y-3 rounded-xl border border-slate-200 bg-white p-5"
                            @submit.prevent="recordRequirement"
                        >
                            <h3 class="text-sm font-semibold text-slate-950">
                                {{ t('closure.requirementTitle') }}
                            </h3>

                            <label class="block text-xs font-medium text-slate-700">
                                {{ t('closure.requirementType') }}
                                <input
                                    v-model="requirementForm.requirement_type"
                                    required
                                    class="mt-1 w-full rounded-lg border-slate-300 text-sm"
                                />
                            </label>

                            <label class="block text-xs font-medium text-slate-700">
                                {{ t('closure.requirementKey') }}
                                <input
                                    v-model="requirementForm.requirement_key"
                                    required
                                    class="mt-1 w-full rounded-lg border-slate-300 text-sm"
                                />
                            </label>

                            <label class="block text-xs font-medium text-slate-700">
                                {{ t('closure.status') }}
                                <select
                                    v-model="requirementForm.status"
                                    class="mt-1 w-full rounded-lg border-slate-300 text-sm"
                                >
                                    <option value="pending">pending</option>
                                    <option value="met">met</option>
                                    <option value="blocked">blocked</option>
                                    <option value="not_applicable">not_applicable</option>
                                </select>
                            </label>

                            <label class="block text-xs font-medium text-slate-700">
                                {{ t('closure.detail') }}
                                <textarea
                                    v-model="requirementForm.detail"
                                    rows="2"
                                    class="mt-1 w-full rounded-lg border-slate-300 text-sm"
                                />
                            </label>

                            <button
                                type="submit"
                                class="min-h-11 rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-900"
                                :disabled="requirementForm.processing"
                            >
                                {{ t('closure.recordRequirement') }}
                            </button>
                        </form>

                        <form
                            class="space-y-3 rounded-xl border border-slate-200 bg-white p-5"
                            @submit.prevent="createClaim"
                        >
                            <h3 class="text-sm font-semibold text-slate-950">
                                {{ t('closure.addClaim') }}
                            </h3>

                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="block text-xs font-medium text-slate-700">
                                    {{ t('closure.claimReference') }}
                                    <input
                                        v-model="claimForm.claim_reference"
                                        required
                                        class="mt-1 w-full rounded-lg border-slate-300 text-sm"
                                    />
                                </label>
                                <label class="block text-xs font-medium text-slate-700">
                                    {{ t('closure.claimType') }}
                                    <input
                                        v-model="claimForm.claim_type"
                                        required
                                        class="mt-1 w-full rounded-lg border-slate-300 text-sm"
                                    />
                                </label>
                            </div>

                            <label class="block text-xs font-medium text-slate-700">
                                {{ t('closure.claimant') }}
                                <input
                                    v-model="claimForm.claimant_reference"
                                    required
                                    class="mt-1 w-full rounded-lg border-slate-300 text-sm"
                                />
                            </label>

                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="block text-xs font-medium text-slate-700">
                                    {{ t('closure.amountMinorUnits') }}
                                    <input
                                        v-model.number="claimForm.amount_minor_units"
                                        type="number"
                                        min="0"
                                        class="mt-1 w-full rounded-lg border-slate-300 text-sm"
                                    />
                                </label>
                                <label class="block text-xs font-medium text-slate-700">
                                    {{ t('closure.currency') }}
                                    <input
                                        v-model="claimForm.currency"
                                        maxlength="3"
                                        class="mt-1 w-full rounded-lg border-slate-300 text-sm uppercase"
                                    />
                                </label>
                            </div>

                            <label class="block text-xs font-medium text-slate-700">
                                {{ t('closure.priorityReference') }}
                                <textarea
                                    v-model="claimForm.legal_priority_reference"
                                    rows="2"
                                    class="mt-1 w-full rounded-lg border-slate-300 text-sm"
                                />
                            </label>

                            <label class="flex items-center gap-2 text-xs font-medium text-slate-700">
                                <input v-model="claimForm.required" type="checkbox" />
                                {{ t('closure.requiredClaim') }}
                            </label>

                            <button
                                type="submit"
                                class="min-h-11 rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-900"
                                :disabled="claimForm.processing"
                            >
                                {{ t('closure.addClaimAction') }}
                            </button>
                        </form>

                        <form
                            v-if="closureWorkspace.permissions.finance_view"
                            class="space-y-3 rounded-xl border border-slate-200 bg-white p-5"
                            @submit.prevent="linkFinance"
                        >
                            <h3 class="text-sm font-semibold text-slate-950">
                                {{ t('closure.financeLinkTitle') }}
                            </h3>
                            <p class="text-xs text-slate-500">
                                {{ t('closure.financeLinkHelp') }}
                            </p>

                            <label class="block text-xs font-medium text-slate-700">
                                {{ t('closure.financePaymentId') }}
                                <input
                                    v-model="financeForm.finance_payment_id"
                                    required
                                    class="mt-1 w-full rounded-lg border-slate-300 text-sm"
                                />
                            </label>

                            <label class="block text-xs font-medium text-slate-700">
                                {{ t('closure.purpose') }}
                                <select
                                    v-model="financeForm.purpose"
                                    class="mt-1 w-full rounded-lg border-slate-300 text-sm"
                                >
                                    <option value="claim_settlement">claim_settlement</option>
                                    <option value="tax">tax</option>
                                    <option value="residual_distribution">residual_distribution</option>
                                    <option value="other">other</option>
                                </select>
                            </label>

                            <label class="block text-xs font-medium text-slate-700">
                                {{ t('closure.claimIdOptional') }}
                                <input
                                    v-model="financeForm.claim_id"
                                    class="mt-1 w-full rounded-lg border-slate-300 text-sm"
                                />
                            </label>

                            <button
                                type="submit"
                                class="min-h-11 rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-900"
                                :disabled="financeForm.processing"
                            >
                                {{ t('closure.linkFinanceAction') }}
                            </button>
                        </form>

                        <form
                            class="space-y-3 rounded-xl border border-slate-200 bg-white p-5"
                            @submit.prevent="recordResidual"
                        >
                            <h3 class="text-sm font-semibold text-slate-950">
                                {{ t('closure.residualTitle') }}
                            </h3>

                            <label class="block text-xs font-medium text-slate-700">
                                {{ t('closure.status') }}
                                <select
                                    v-model="residualForm.status"
                                    class="mt-1 w-full rounded-lg border-slate-300 text-sm"
                                >
                                    <option value="not_applicable">not_applicable</option>
                                    <option value="planned">planned</option>
                                    <option value="completed">completed</option>
                                </select>
                            </label>

                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="block text-xs font-medium text-slate-700">
                                    {{ t('closure.amountMinorUnits') }}
                                    <input
                                        v-model.number="residualForm.amount_minor_units"
                                        type="number"
                                        min="0"
                                        class="mt-1 w-full rounded-lg border-slate-300 text-sm"
                                    />
                                </label>

                                <label class="block text-xs font-medium text-slate-700">
                                    {{ t('closure.currency') }}
                                    <input
                                        v-model="residualForm.currency"
                                        maxlength="3"
                                        class="mt-1 w-full rounded-lg border-slate-300 text-sm uppercase"
                                    />
                                </label>
                            </div>

                            <label class="block text-xs font-medium text-slate-700">
                                {{ t('closure.residualBasis') }}
                                <textarea
                                    v-model="residualForm.basis"
                                    rows="2"
                                    class="mt-1 w-full rounded-lg border-slate-300 text-sm"
                                />
                            </label>

                            <button
                                type="submit"
                                class="min-h-11 rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-900"
                                :disabled="residualForm.processing"
                            >
                                {{ t('closure.recordResidualAction') }}
                            </button>
                        </form>
                    </div>

                    <section class="rounded-xl border border-slate-200 bg-white">
                        <div class="border-b border-slate-200 px-5 py-4">
                            <h3 class="text-sm font-semibold text-slate-950">
                                {{ t('closure.governanceTitle') }}
                            </h3>
                            <p class="mt-1 text-xs text-slate-500">
                                {{ t('closure.governanceHelp') }}
                            </p>
                        </div>

                        <div class="space-y-4 px-5 py-5">
                            <div
                                v-if="selectedCase.governance_submission"
                                class="grid gap-3 rounded-lg bg-slate-50 p-4 text-xs text-slate-700 md:grid-cols-2"
                            >
                                <div>
                                    <span class="font-medium">{{ t('closure.proposalVersion') }}:</span>
                                    {{ selectedCase.governance_submission.proposal_version_id }}
                                </div>
                                <div>
                                    <span class="font-medium">{{ t('closure.decisionId') }}:</span>
                                    {{ selectedCase.governance_submission.decision_id || '—' }}
                                </div>
                                <div>
                                    <span class="font-medium">{{ t('closure.formalState') }}:</span>
                                    {{ selectedCase.governance_submission.formal_record_state || '—' }}
                                </div>
                                <div>
                                    <span class="font-medium">{{ t('closure.authorizedAt') }}:</span>
                                    {{ selectedCase.governance_submission.authorized_at || '—' }}
                                </div>
                            </div>

                            <div
                                v-if="closureWorkspace.permissions.manage"
                                class="flex flex-wrap gap-2"
                            >
                                <button
                                    v-if="selectedCase.status === 'draft'"
                                    type="button"
                                    class="min-h-11 rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white"
                                    @click="revisionAction('governance')"
                                >
                                    {{ t('closure.submitGovernance') }}
                                </button>

                                <button
                                    v-if="selectedCase.status === 'under_governance'"
                                    type="button"
                                    class="min-h-11 rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-900"
                                    @click="reviewRecord('under_review')"
                                >
                                    {{ t('closure.startContentReview') }}
                                </button>

                                <button
                                    v-if="selectedCase.status === 'under_governance'"
                                    type="button"
                                    class="min-h-11 rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-900"
                                    @click="reviewRecord('approved')"
                                >
                                    {{ t('closure.approveContent') }}
                                </button>

                                <button
                                    v-if="selectedCase.status === 'under_governance'"
                                    type="button"
                                    class="min-h-11 rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-900"
                                    @click="revisionAction('sync-decision')"
                                >
                                    {{ t('closure.syncDecision') }}
                                </button>

                                <button
                                    v-if="selectedCase.status === 'approved'"
                                    type="button"
                                    class="min-h-11 rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white"
                                    @click="revisionAction('activate-wind-down')"
                                >
                                    {{ t('closure.activateWindDown') }}
                                </button>

                                <button
                                    v-if="selectedCase.status === 'wind_down_active'"
                                    type="button"
                                    class="min-h-11 rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white"
                                    @click="revisionAction('prepare-residual')"
                                >
                                    {{ t('closure.prepareResidual') }}
                                </button>

                                <button
                                    v-if="selectedCase.status === 'residual_ready'"
                                    type="button"
                                    class="min-h-11 rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white"
                                    @click="revisionAction('prepare-legal-closure')"
                                >
                                    {{ t('closure.prepareLegalClosure') }}
                                </button>

                                <button
                                    v-if="selectedCase.status === 'legal_closure_ready'"
                                    type="button"
                                    class="min-h-11 rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white"
                                    @click="revisionAction('effect-legal-closure')"
                                >
                                    {{ t('closure.effectLegalClosure') }}
                                </button>

                                <button
                                    v-if="selectedCase.status === 'draft'"
                                    type="button"
                                    class="min-h-11 rounded-lg border border-rose-300 px-4 py-2 text-sm font-semibold text-rose-700"
                                    @click="transitionCase('withdrawn')"
                                >
                                    {{ t('closure.withdraw') }}
                                </button>

                                <button
                                    v-if="selectedCase.status === 'draft'"
                                    type="button"
                                    class="min-h-11 rounded-lg border border-rose-300 px-4 py-2 text-sm font-semibold text-rose-700"
                                    @click="transitionCase('cancelled')"
                                >
                                    {{ t('closure.cancel') }}
                                </button>
                            </div>
                            <p
                                v-if="Object.keys(revisionActionForm.errors).length"
                                class="text-sm text-rose-700"
                            >
                                {{ Object.values(revisionActionForm.errors)[0] }}
                            </p>
                        </div>
                    </section>

                    <section
                        v-if="
                            closureWorkspace.permissions.manage &&
                            selectedCase.status === 'legally_closed'
                        "
                        class="rounded-xl border border-rose-200 bg-rose-50 p-5"
                    >
                        <h3 class="text-sm font-semibold text-rose-950">
                            {{ t('closure.closeWorkspaceTitle') }}
                        </h3>
                        <p class="mt-2 text-xs text-rose-800">
                            {{ t('closure.closeWorkspaceHelp') }}
                        </p>

                        <form class="mt-4 flex flex-col gap-3 sm:flex-row" @submit.prevent="closeWorkspace">
                            <input
                                v-model="closeForm.confirmation"
                                :placeholder="closureWorkspace.business.name"
                                required
                                class="min-h-11 flex-1 rounded-lg border-rose-300 text-sm"
                            />
                            <button
                                type="submit"
                                class="min-h-11 rounded-lg bg-rose-800 px-4 py-2 text-sm font-semibold text-white"
                                :disabled="closeForm.processing"
                            >
                                {{ t('closure.closeWorkspaceAction') }}
                            </button>
                        </form>
                    </section>

                    <section class="rounded-xl border border-slate-200 bg-white">
                        <div class="border-b border-slate-200 px-5 py-4">
                            <h3 class="text-sm font-semibold text-slate-950">
                                {{ t('closure.requirementRegister') }}
                            </h3>
                        </div>
                        <div class="divide-y divide-slate-100">
                            <div
                                v-for="requirement in selectedCase.requirements"
                                :key="requirement.id"
                                class="grid gap-2 px-5 py-4 text-sm md:grid-cols-[14rem_10rem_minmax(0,1fr)]"
                            >
                                <div>
                                    <p class="font-medium text-slate-950">
                                        {{ requirement.requirement_key }}
                                    </p>
                                    <p class="mt-1 text-xs text-slate-500">
                                        {{ requirement.requirement_type }}
                                    </p>
                                </div>
                                <div>
                                    <span
                                        class="rounded-full px-2 py-1 text-xs font-semibold"
                                        :class="statusClass(requirement.status)"
                                    >
                                        {{ requirement.status }}
                                    </span>
                                </div>
                                <p class="text-slate-600">
                                    {{ requirement.detail || '—' }}
                                </p>
                            </div>
                            <p
                                v-if="selectedCase.requirements.length === 0"
                                class="px-5 py-6 text-sm text-slate-500"
                            >
                                {{ t('closure.noRequirements') }}
                            </p>
                        </div>
                    </section>

                    <section
                        v-if="closureWorkspace.permissions.finance_view"
                        class="rounded-xl border border-slate-200 bg-white"
                    >
                        <div class="border-b border-slate-200 px-5 py-4">
                            <h3 class="text-sm font-semibold text-slate-950">
                                {{ t('closure.financeEvidenceTitle') }}
                            </h3>
                        </div>
                        <div class="divide-y divide-slate-100">
                            <div
                                v-for="link in selectedCase.finance_links"
                                :key="link.id"
                                class="grid gap-2 px-5 py-4 text-sm md:grid-cols-[12rem_1fr_10rem]"
                            >
                                <div class="font-medium text-slate-950">
                                    {{ link.purpose }}
                                </div>
                                <div class="text-slate-600">
                                    {{ link.finance_payment_id }}
                                </div>
                                <div class="text-right">
                                    {{ money(link.amount_minor_units, link.currency) }}
                                    <span class="ml-2 text-xs text-slate-500">
                                        {{ link.status }}
                                    </span>
                                </div>
                            </div>
                            <p
                                v-if="selectedCase.finance_links.length === 0"
                                class="px-5 py-6 text-sm text-slate-500"
                            >
                                {{ t('closure.noFinanceEvidence') }}
                            </p>
                        </div>
                    </section>
                </main>

                <main
                    v-else
                    class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500"
                >
                    {{ t('closure.selectCase') }}
                </main>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
