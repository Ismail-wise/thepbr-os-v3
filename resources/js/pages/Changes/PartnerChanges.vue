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

type ShareClass = {
    id: string;
    name: string;
    voting_right_per_share: string;
    profit_right_per_share: string;
    transfer_allowed: boolean;
    restrictions: string | null;
};

type Position = {
    partner_id: string;
    share_class_id: string;
    shares_issued: string;
    shares_vested: string;
    voting_rights: string;
    profit_rights: string;
};

type GovernanceSubmission = {
    formal_record_version_id: string;
    proposal_version_id: string;
    decision_type: string;
    decision_id: string | null;
    effective_register_version_id: string | null;
    formal_record_state: string | null;
};

type PartnerChangeCase = {
    id: string;
    case_number: string;
    transaction_type: 'admission' | 'transfer_existing' | 'issue_new';
    status: string;
    source_ownership_register_version_id: string | null;
    seller_partner_id: string | null;
    buyer_partner_id: string;
    source_share_class_id: string | null;
    shares: string | null;
    currency: string | null;
    consideration_minor_units: number | null;
    valuation_method: string | null;
    rights_impact_summary: string | null;
    governance_decision_type: string;
    rofr_required: boolean;
    effective_from: string | null;
    revision: number;
    created_at: string | null;
    eligibility: Array<Record<string, unknown>>;
    requirements: Array<Record<string, unknown>>;
    rofr_rounds: Array<Record<string, unknown>>;
    governance_submission: GovernanceSubmission | null;
};

const props = defineProps<{
    partnerChanges: {
        business: { id: string; name: string };
        permissions: { view: boolean; manage: boolean };
        partners: Partner[];
        current_ownership_register: null | {
            id: string;
            version_number: number;
            currency: string;
            effective_from: string | null;
            share_classes: ShareClass[];
            positions: Position[];
        };
        cases: PartnerChangeCase[];
    };
}>();

const { t } = useI18n();
const selectedId = ref<string | null>(props.partnerChanges.cases[0]?.id ?? null);

watch(
    () => props.partnerChanges.cases,
    (cases) => {
        if (
            selectedId.value !== null
            && cases.some((item) => item.id === selectedId.value)
        ) {
            return;
        }

        selectedId.value = cases[0]?.id ?? null;
    },
);

const selectedCase = computed(
    () =>
        props.partnerChanges.cases.find(
            (item) => item.id === selectedId.value,
        ) ?? null,
);

const partnerName = (id: string | null): string => {
    if (id === null) {
        return '—';
    }

    return (
        props.partnerChanges.partners.find((partner) => partner.id === id)
            ?.display_name ?? 'Unknown Partner'
    );
};

const shareClassName = (id: string | null): string => {
    if (id === null) {
        return '—';
    }

    return (
        props.partnerChanges.current_ownership_register?.share_classes.find(
            (shareClass) => shareClass.id === id,
        )?.name ?? 'Unknown Share Class'
    );
};

const createForm = useForm({
    transaction_type: 'transfer_existing',
    buyer_partner_id: '',
    seller_partner_id: '',
    source_share_class_id: '',
    shares: '',
    currency:
        props.partnerChanges.current_ownership_register?.currency ?? 'USD',
    consideration_minor_units: null as number | null,
    valuation_method: '',
    rights_impact_summary: '',
    governance_decision_type: 'partner_change_approval',
    rofr_required: false,
    effective_from: '',
});

const isAdmission = computed(
    () => createForm.transaction_type === 'admission',
);
const isOwnershipChange = computed(() => !isAdmission.value);

const localDateTimeToUtcIso = (value: string): string => {
    const parsed = new Date(value);

    return Number.isNaN(parsed.getTime())
        ? value
        : parsed.toISOString();
};

const utcIsoToLocalDateTime = (value: string): string => {
    const parsed = new Date(value);

    if (Number.isNaN(parsed.getTime())) {
        return value;
    }

    const local = new Date(
        parsed.getTime() - parsed.getTimezoneOffset() * 60_000,
    );

    return local.toISOString().slice(0, 16);
};

const editDraftForm = useForm({
    expected_revision: 1,
    currency: '',
    consideration_minor_units: null as number | null,
    valuation_method: '',
    rights_impact_summary: '',
    rofr_required: false,
    effective_from: '',
});

const hydrateDraftForm = (item: PartnerChangeCase | null): void => {
    editDraftForm.clearErrors();

    if (item === null) {
        return;
    }

    editDraftForm.expected_revision = item.revision;
    editDraftForm.currency =
        item.currency
        ?? props.partnerChanges.current_ownership_register?.currency
        ?? '';
    editDraftForm.consideration_minor_units =
        item.consideration_minor_units;
    editDraftForm.valuation_method = item.valuation_method ?? '';
    editDraftForm.rights_impact_summary =
        item.rights_impact_summary ?? '';
    editDraftForm.rofr_required = item.rofr_required;
    editDraftForm.effective_from = item.effective_from
        ? utcIsoToLocalDateTime(item.effective_from)
        : '';
};

watch(
    selectedCase,
    (item) => hydrateDraftForm(item),
    { immediate: true, deep: true },
);

const updateDraftTerms = (): void => {
    const item = selectedCase.value;

    if (item === null || item.status !== 'draft') {
        return;
    }

    editDraftForm.expected_revision = item.revision;

    editDraftForm
        .transform((data) => ({
            ...data,
            rofr_required:
                item.transaction_type === 'admission'
                    ? false
                    : data.rofr_required,
            effective_from: data.effective_from
                ? localDateTimeToUtcIso(data.effective_from)
                : data.effective_from,
        }))
        .patch(`/changes/partner-changes/${item.id}/draft`, {
            preserveScroll: true,
        });
};

const createCase = (): void => {
    if (isAdmission.value) {
        createForm.seller_partner_id = '';
        createForm.source_share_class_id = '';
        createForm.shares = '';
        createForm.rofr_required = false;
    }

    createForm
        .transform((data) => ({
            ...data,
            effective_from: data.effective_from
                ? localDateTimeToUtcIso(data.effective_from)
                : data.effective_from,
        }))
        .post('/changes/partner-changes', {
            preserveScroll: true,
            onSuccess: () => createForm.reset(),
        });
};

const transitionForm = useForm({
    expected_revision: 1,
    target: '',
});

const transition = (target: string): void => {
    const item = selectedCase.value;

    if (item === null) {
        return;
    }

    transitionForm.clearErrors();
    transitionForm.expected_revision = item.revision;
    transitionForm.target = target;
    transitionForm.post(
        `/changes/partner-changes/${item.id}/transition`,
        {
            preserveScroll: true,
            onSuccess: () => transitionForm.clearErrors(),
        },
    );
};

const nextActions = computed(() => {
    const item = selectedCase.value;

    if (item === null) {
        return [] as Array<{ label: string; target: string }>;
    }

    switch (item.status) {
        case 'draft':
            return [
                {
                    label: 'Start eligibility review',
                    target: 'eligibility_review',
                },
                {
                    label: 'Withdraw case',
                    target: 'withdrawn',
                },
            ];
        case 'eligibility_review':
            return [
                { label: 'Mark eligible', target: 'eligible' },
                { label: 'Block case', target: 'blocked' },
            ];
        case 'blocked':
            return [{ label: 'Re-open eligibility', target: 'eligibility_review' }];
        case 'eligible':
            return item.rofr_required
                ? [{ label: 'Start ROFR', target: 'rofr' }]
                : [{ label: 'Terms ready', target: 'terms_ready' }];
        case 'rofr':
            return [{ label: 'Terms ready', target: 'terms_ready' }];
        default:
            return [];
    }
});

const eligibilityForm = useForm({
    expected_revision: 1,
    check_key: 'buyer_eligible',
    result: 'met',
    detail: '',
    source_type: '',
    source_id: '',
});

const submitEligibility = (): void => {
    const item = selectedCase.value;

    if (item === null) {
        return;
    }

    eligibilityForm.expected_revision = item.revision;
    eligibilityForm.post(
        `/changes/partner-changes/${item.id}/eligibility`,
        { preserveScroll: true },
    );
};

const requirementForm = useForm({
    expected_revision: 1,
    requirement_type: 'legal_document',
    requirement_key: 'legal_documentation_complete',
    status: 'met',
    detail: '',
    source_type: '',
    source_id: '',
});

const submitRequirement = (): void => {
    const item = selectedCase.value;

    if (item === null) {
        return;
    }

    requirementForm.expected_revision = item.revision;
    requirementForm.post(
        `/changes/partner-changes/${item.id}/requirements`,
        {
            preserveScroll: true,
            onSuccess: () => transitionForm.clearErrors(),
        },
    );
};

const rofrForm = useForm({
    expected_revision: 1,
    terms_summary: '',
    deadline_at: '',
});

const openRofr = (): void => {
    const item = selectedCase.value;

    if (item === null) {
        return;
    }

    rofrForm.expected_revision = item.revision;
    rofrForm
        .transform((data) => ({
            ...data,
            deadline_at: data.deadline_at
                ? localDateTimeToUtcIso(data.deadline_at)
                : data.deadline_at,
        }))
        .post(`/changes/partner-changes/${item.id}/rofr`, {
            preserveScroll: true,
        });
};

const revisionActionForm = useForm({
    expected_revision: 1,
});

const postRevisionAction = (suffix: string): void => {
    const item = selectedCase.value;

    if (item === null) {
        return;
    }

    revisionActionForm.clearErrors();
    revisionActionForm.expected_revision = item.revision;
    revisionActionForm.post(
        `/changes/partner-changes/${item.id}/${suffix}`,
        {
            preserveScroll: true,
            onSuccess: () => revisionActionForm.clearErrors(),
        },
    );
};

const reviewRecord = (target: string): void => {
    const item = selectedCase.value;
    const recordId = item?.governance_submission?.formal_record_version_id;

    if (item === null || !recordId) {
        return;
    }

    useForm({ target }).post(
        `/changes/partner-changes/${item.id}/records/${recordId}/content-review`,
        { preserveScroll: true },
    );
};

const statusClass = (status: string): string => {
    if (['completed', 'effective'].includes(status)) {
        return 'bg-emerald-50 text-emerald-800';
    }

    if (['blocked', 'rejected', 'withdrawn'].includes(status)) {
        return 'bg-rose-50 text-rose-800';
    }

    if (['approved', 'ready_for_effect'].includes(status)) {
        return 'bg-amber-50 text-amber-900';
    }

    return 'bg-slate-100 text-slate-700';
};
</script>

<template>
    <Head :title="t('partnerChanges.title')" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-[1600px] space-y-6 px-4 py-5 sm:px-6 sm:py-6 lg:px-8 lg:py-7">
            <header class="pbr-surface p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                            {{ partnerChanges.business.name }}
                        </p>
                        <h1 class="mt-2 text-2xl font-semibold text-slate-950">
                            {{ t('partnerChanges.title') }}
                        </h1>
                        <p class="mt-2 max-w-4xl text-sm leading-6 text-slate-600">
                            {{ t('partnerChanges.description') }}
                        </p>
                    </div>

                    <Link
                        href="/governance"
                        class="inline-flex min-h-11 items-center rounded-md border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-800 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-500"
                    >
                        {{ t('partnerChanges.openGovernance') }}
                    </Link>
                </div>

                <div class="mt-4 rounded-[16px] border border-[#cfe1d3] bg-[#f3f8f4] px-4 py-3 text-sm font-semibold text-[var(--pbr-green-dark)]">
                    {{ t('partnerChanges.boundary') }}
                </div>
            </header>

            <section class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(320px,0.42fr)]">
                <div class="overflow-hidden pbr-surface">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                        <div>
                            <h2 class="font-semibold text-slate-950">
                                {{ t('partnerChanges.register') }}
                            </h2>
                            <p class="mt-1 text-xs text-slate-500">
                                {{ t('partnerChanges.scenarioNotice') }}
                            </p>
                        </div>
                        <span class="text-xs font-medium text-slate-500">
                            {{ partnerChanges.cases.length }} cases
                        </span>
                    </div>

                    <div v-if="partnerChanges.cases.length === 0" class="px-4 py-10 text-sm text-slate-500">
                        {{ t('partnerChanges.noCases') }}
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-3 font-semibold">Case</th>
                                    <th class="px-4 py-3 font-semibold">{{ t('partnerChanges.type') }}</th>
                                    <th class="px-4 py-3 font-semibold">{{ t('partnerChanges.buyer') }}</th>
                                    <th class="px-4 py-3 font-semibold">{{ t('partnerChanges.status') }}</th>
                                    <th class="px-4 py-3 font-semibold">{{ t('partnerChanges.revision') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr
                                    v-for="item in partnerChanges.cases"
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
                                        {{ item.transaction_type.replaceAll('_', ' ') }}
                                    </td>
                                    <td class="px-4 py-3 text-slate-700">
                                        {{ partnerName(item.buyer_partner_id) }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <span
                                            class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                            :class="statusClass(item.status)"
                                        >
                                            {{ item.status.replaceAll('_', ' ') }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 tabular-nums text-slate-600">
                                        {{ item.revision }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <aside class="pbr-surface p-4">
                    <h2 class="font-semibold text-slate-950">
                        {{ t('partnerChanges.currentOwnership') }}
                    </h2>

                    <div
                        v-if="partnerChanges.current_ownership_register === null"
                        class="mt-4 text-sm text-slate-500"
                    >
                        {{ t('partnerChanges.noOwnership') }}
                    </div>

                    <template v-else>
                        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <dt class="text-slate-500">Version</dt>
                                <dd class="mt-1 font-semibold text-slate-900">
                                    {{ partnerChanges.current_ownership_register.version_number }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Currency</dt>
                                <dd class="mt-1 font-semibold text-slate-900">
                                    {{ partnerChanges.current_ownership_register.currency }}
                                </dd>
                            </div>
                        </dl>

                        <div class="mt-4 space-y-2">
                            <div
                                v-for="shareClass in partnerChanges.current_ownership_register.share_classes"
                                :key="shareClass.id"
                                class="border-t border-slate-100 pt-3 text-sm"
                            >
                                <div class="flex items-center justify-between gap-3">
                                    <span class="font-semibold text-slate-900">{{ shareClass.name }}</span>
                                    <span class="text-xs text-slate-500">
                                        {{ shareClass.transfer_allowed ? 'Transferable' : 'Restricted' }}
                                    </span>
                                </div>
                                <p class="mt-1 text-xs text-slate-500">
                                    Voting {{ shareClass.voting_right_per_share }} · Profit {{ shareClass.profit_right_per_share }}
                                </p>
                            </div>
                        </div>
                    </template>
                </aside>
            </section>
            <section
                v-if="partnerChanges.permissions.manage"
                class="pbr-surface"
            >
                <details>
                    <summary class="cursor-pointer px-4 py-4 font-semibold text-slate-950">
                        {{ t('partnerChanges.newCase') }}
                    </summary>

                    <form class="grid gap-4 border-t border-slate-200 p-4 md:grid-cols-2 xl:grid-cols-4" @submit.prevent="createCase">
                        <label class="space-y-1 text-sm">
                            <span class="font-medium text-slate-700">{{ t('partnerChanges.type') }}</span>
                            <select v-model="createForm.transaction_type" class="min-h-11 w-full rounded-md border-slate-300 text-sm">
                                <option value="admission">New Partner admission</option>
                                <option value="transfer_existing">Transfer existing shares</option>
                                <option value="issue_new">Issue new shares</option>
                            </select>
                        </label>

                        <label class="space-y-1 text-sm">
                            <span class="font-medium text-slate-700">{{ t('partnerChanges.buyer') }}</span>
                            <select v-model="createForm.buyer_partner_id" required class="min-h-11 w-full rounded-md border-slate-300 text-sm">
                                <option value="">Select Partner</option>
                                <option v-for="partner in partnerChanges.partners" :key="partner.id" :value="partner.id">
                                    {{ partner.display_name }} · {{ partner.status }}
                                </option>
                            </select>
                        </label>

                        <label v-if="isOwnershipChange && createForm.transaction_type === 'transfer_existing'" class="space-y-1 text-sm">
                            <span class="font-medium text-slate-700">{{ t('partnerChanges.seller') }}</span>
                            <select v-model="createForm.seller_partner_id" required class="min-h-11 w-full rounded-md border-slate-300 text-sm">
                                <option value="">Select Partner</option>
                                <option v-for="partner in partnerChanges.partners" :key="partner.id" :value="partner.id">
                                    {{ partner.display_name }} · {{ partner.status }}
                                </option>
                            </select>
                        </label>

                        <label v-if="isOwnershipChange" class="space-y-1 text-sm">
                            <span class="font-medium text-slate-700">{{ t('partnerChanges.shareClass') }}</span>
                            <select v-model="createForm.source_share_class_id" required class="min-h-11 w-full rounded-md border-slate-300 text-sm">
                                <option value="">Select Share Class</option>
                                <option
                                    v-for="shareClass in partnerChanges.current_ownership_register?.share_classes ?? []"
                                    :key="shareClass.id"
                                    :value="shareClass.id"
                                >
                                    {{ shareClass.name }}
                                </option>
                            </select>
                        </label>

                        <label v-if="isOwnershipChange" class="space-y-1 text-sm">
                            <span class="font-medium text-slate-700">{{ t('partnerChanges.shares') }}</span>
                            <input v-model="createForm.shares" required inputmode="decimal" class="min-h-11 w-full rounded-md border-slate-300 text-sm" />
                        </label>

                        <label class="space-y-1 text-sm">
                            <span class="font-medium text-slate-700">Governance decision type</span>
                            <input v-model="createForm.governance_decision_type" required class="min-h-11 w-full rounded-md border-slate-300 text-sm" />
                        </label>

                        <label class="space-y-1 text-sm">
                            <span class="font-medium text-slate-700">{{ t('partnerChanges.effectiveFrom') }}</span>
                            <OptionalTemporalInput v-model="createForm.effective_from" required type="datetime-local" class="min-h-11 w-full rounded-md border-slate-300 text-sm" />
                        </label>

                        <template v-if="isOwnershipChange">
                            <label class="space-y-1 text-sm">
                                <span class="font-medium text-slate-700">Valuation method</span>
                                <textarea v-model="createForm.valuation_method" rows="2" class="w-full rounded-md border-slate-300 text-sm" />
                            </label>

                            <label class="space-y-1 text-sm">
                                <span class="font-medium text-slate-700">Rights impact summary</span>
                                <textarea v-model="createForm.rights_impact_summary" rows="2" class="w-full rounded-md border-slate-300 text-sm" />
                            </label>

                            <label class="flex min-h-11 items-center gap-2 text-sm text-slate-700">
                                <input v-model="createForm.rofr_required" type="checkbox" class="rounded border-slate-300" />
                                ROFR required
                            </label>
                        </template>

                        <div class="md:col-span-2 xl:col-span-4">
                            <div v-if="Object.keys(createForm.errors).length" class="mb-3 rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800">
                                {{ Object.values(createForm.errors)[0] }}
                            </div>
                            <button
                                type="submit"
                                :disabled="createForm.processing"
                                class="inline-flex min-h-11 items-center rounded-md bg-slate-950 px-4 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                            >
                                {{ t('partnerChanges.create') }}
                            </button>
                        </div>
                    </form>
                </details>
            </section>

            <section v-if="selectedCase !== null" class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(360px,0.48fr)]">
                <div class="pbr-surface">
                    <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 p-4">
                        <div>
                            <p class="font-mono text-xs font-semibold text-slate-500">
                                {{ selectedCase.case_number }}
                            </p>
                            <h2 class="mt-1 text-lg font-semibold text-slate-950">
                                {{ selectedCase.transaction_type.replaceAll('_', ' ') }}
                            </h2>
                        </div>
                        <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="statusClass(selectedCase.status)">
                            {{ selectedCase.status.replaceAll('_', ' ') }}
                        </span>
                    </div>

                    <dl class="grid gap-x-6 gap-y-4 p-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <dt class="text-slate-500">{{ t('partnerChanges.seller') }}</dt>
                            <dd class="mt-1 font-medium text-slate-900">{{ partnerName(selectedCase.seller_partner_id) }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">{{ t('partnerChanges.buyer') }}</dt>
                            <dd class="mt-1 font-medium text-slate-900">{{ partnerName(selectedCase.buyer_partner_id) }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">{{ t('partnerChanges.shareClass') }}</dt>
                            <dd class="mt-1 font-medium text-slate-900">{{ shareClassName(selectedCase.source_share_class_id) }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">{{ t('partnerChanges.shares') }}</dt>
                            <dd class="mt-1 font-medium text-slate-900">{{ selectedCase.shares ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">Governance decision type</dt>
                            <dd class="mt-1 font-medium text-slate-900">{{ selectedCase.governance_decision_type.replaceAll('_', ' ') }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">{{ t('partnerChanges.revision') }}</dt>
                            <dd class="mt-1 font-medium text-slate-900">{{ selectedCase.revision }}</dd>
                        </div>
                    </dl>

                    <div v-if="partnerChanges.permissions.manage" class="border-t border-slate-200 p-4">
                        <h3 class="text-sm font-semibold text-slate-950">{{ t('partnerChanges.workflow') }}</h3>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button
                                v-for="action in nextActions"
                                :key="action.target"
                                type="button"
                                :disabled="transitionForm.processing"
                                class="min-h-10 rounded-md border border-slate-300 px-3 text-sm font-semibold text-slate-800 hover:bg-slate-50 disabled:opacity-50"
                                @click="transition(action.target)"
                            >
                                {{ action.label }}
                            </button>

                            <button
                                v-if="selectedCase.status === 'terms_ready'"
                                type="button"
                                class="min-h-10 rounded-md bg-slate-950 px-3 text-sm font-semibold text-white hover:bg-slate-800"
                                @click="postRevisionAction('governance')"
                            >
                                Submit frozen proposal
                            </button>

                            <button
                                v-if="selectedCase.status === 'under_governance'"
                                type="button"
                                class="min-h-10 rounded-md border border-slate-300 px-3 text-sm font-semibold text-slate-800 hover:bg-slate-50"
                                @click="postRevisionAction('sync-decision')"
                            >
                                Sync Governance decision
                            </button>

                            <button
                                v-if="
                                    selectedCase.status === 'approved'
                                        && selectedCase.governance_submission?.formal_record_state === 'approved'
                                "
                                type="button"
                                class="min-h-10 rounded-md border border-amber-300 bg-amber-50 px-3 text-sm font-semibold text-amber-900 hover:bg-amber-100"
                                @click="postRevisionAction('prepare-effect')"
                            >
                                Prepare for effect
                            </button>

                            <button
                                v-if="selectedCase.status === 'ready_for_effect'"
                                type="button"
                                :disabled="revisionActionForm.processing || !selectedCase.effective_from"
                                :class="[
                                    'min-h-10 rounded-md px-3 text-sm font-semibold',
                                    selectedCase.effective_from
                                        ? 'bg-emerald-700 text-white hover:bg-emerald-800'
                                        : 'cursor-not-allowed bg-slate-200 text-slate-500',
                                ]"
                                @click="postRevisionAction('effect')"
                            >
                                Make effective
                            </button>
                            <p
                                v-if="selectedCase.status === 'ready_for_effect' && !selectedCase.effective_from"
                                class="w-full text-sm text-rose-700"
                            >
                                Effective From is required before this Partner Change can become Effective.
                            </p>
                        </div>
                        <p
                            v-if="Object.keys(transitionForm.errors).length"
                            class="mt-3 text-sm text-rose-700"
                        >
                            {{ Object.values(transitionForm.errors)[0] }}
                        </p>
                        <p
                            v-if="Object.keys(revisionActionForm.errors).length"
                            class="mt-3 text-sm text-rose-700"
                        >
                            {{ Object.values(revisionActionForm.errors)[0] }}
                        </p>
                    </div>

                    <div v-if="selectedCase.governance_submission" class="border-t border-slate-200 p-4 text-sm">
                        <h3 class="font-semibold text-slate-950">{{ t('partnerChanges.governance') }}</h3>
                        <dl class="mt-3 grid gap-3 sm:grid-cols-2">
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
                            <dl class="mt-3 grid gap-2 sm:grid-cols-2">
                                <div><dt>Proposal version</dt><dd class="mt-1 break-all font-mono">{{ selectedCase.governance_submission.proposal_version_id }}</dd></div>
                                <div><dt>Formal record version</dt><dd class="mt-1 break-all font-mono">{{ selectedCase.governance_submission.formal_record_version_id }}</dd></div>
                                <div v-if="selectedCase.governance_submission.decision_id"><dt>Decision reference</dt><dd class="mt-1 break-all font-mono">{{ selectedCase.governance_submission.decision_id }}</dd></div>
                            </dl>
                        </details>

                        <div v-if="partnerChanges.permissions.manage" class="mt-3 flex flex-wrap gap-2">
                            <button
                                v-if="selectedCase.governance_submission.formal_record_state === 'ready_for_review'"
                                type="button"
                                class="min-h-10 rounded-md border border-slate-300 px-3 text-xs font-semibold"
                                @click="reviewRecord('under_review')"
                            >
                                Start record review
                            </button>
                            <button
                                v-if="selectedCase.governance_submission.formal_record_state === 'under_review'"
                                type="button"
                                class="min-h-10 rounded-md border border-slate-300 px-3 text-xs font-semibold"
                                @click="reviewRecord('approved')"
                            >
                                Approve record content
                            </button>
                        </div>
                    </div>
                </div>
                <div class="space-y-4">
                    <details
                        v-if="
                            partnerChanges.permissions.manage
                                && selectedCase.status === 'draft'
                        "
                        open
                        class="pbr-surface"
                    >
                        <summary class="cursor-pointer p-4 font-semibold text-slate-950">
                            Edit draft terms
                        </summary>

                        <form
                            class="space-y-3 border-t border-slate-200 p-4"
                            @submit.prevent="updateDraftTerms"
                        >
                            <label class="block space-y-1 text-sm">
                                <span class="font-medium text-slate-700">
                                    Currency
                                </span>
                                <input
                                    v-model="editDraftForm.currency"
                                    maxlength="3"
                                    class="min-h-11 w-full rounded-md border-slate-300 text-sm uppercase"
                                >
                            </label>

                            <label class="block space-y-1 text-sm">
                                <span class="font-medium text-slate-700">
                                    Consideration minor units (optional)
                                </span>
                                <input
                                    v-model.number="editDraftForm.consideration_minor_units"
                                    type="number"
                                    min="0"
                                    class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                                >
                            </label>

                            <label
                                v-if="selectedCase.transaction_type !== 'admission'"
                                class="block space-y-1 text-sm"
                            >
                                <span class="font-medium text-slate-700">
                                    Valuation method
                                </span>
                                <textarea
                                    v-model="editDraftForm.valuation_method"
                                    rows="2"
                                    class="w-full rounded-md border-slate-300 text-sm"
                                />
                                <span class="text-xs text-slate-500">
                                    Required before Eligibility Review for ownership-changing cases.
                                </span>
                            </label>

                            <label
                                v-if="selectedCase.transaction_type !== 'admission'"
                                class="block space-y-1 text-sm"
                            >
                                <span class="font-medium text-slate-700">
                                    Rights impact summary
                                </span>
                                <textarea
                                    v-model="editDraftForm.rights_impact_summary"
                                    rows="3"
                                    class="w-full rounded-md border-slate-300 text-sm"
                                />
                                <span class="text-xs text-slate-500">
                                    Required before Eligibility Review for ownership-changing cases.
                                </span>
                            </label>

                            <label class="block space-y-1 text-sm">
                                <span class="font-medium text-slate-700">
                                    Effective From
                                </span>
                                <OptionalTemporalInput
                                    v-model="editDraftForm.effective_from"
                                    type="datetime-local"
                                    class="min-h-11 w-full rounded-md border-slate-300 text-sm"
                                />
                            </label>

                            <label
                                v-if="selectedCase.transaction_type !== 'admission'"
                                class="flex min-h-11 items-center gap-2 text-sm text-slate-700"
                            >
                                <input
                                    v-model="editDraftForm.rofr_required"
                                    type="checkbox"
                                    class="rounded border-slate-300"
                                >
                                ROFR required
                            </label>

                            <div
                                v-if="Object.keys(editDraftForm.errors).length"
                                class="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800"
                                role="alert"
                            >
                                {{ Object.values(editDraftForm.errors)[0] }}
                            </div>

                            <button
                                type="submit"
                                :disabled="editDraftForm.processing"
                                class="min-h-10 rounded-md bg-slate-950 px-3 text-sm font-semibold text-white disabled:opacity-50"
                            >
                                Save draft changes
                            </button>
                        </form>
                    </details>

                    <details
                        v-if="partnerChanges.permissions.manage && selectedCase.status === 'eligibility_review'"
                        class="pbr-surface"
                    >
                        <summary class="cursor-pointer p-4 font-semibold text-slate-950">
                            {{ t('partnerChanges.eligibility') }}
                        </summary>
                        <form class="space-y-3 border-t border-slate-200 p-4" @submit.prevent="submitEligibility">
                            <label class="block space-y-1 text-sm">
                                <span class="font-medium text-slate-700">{{ t('partnerChanges.eligibilityCheck') }}</span>
                                <select v-model="eligibilityForm.check_key" class="min-h-11 w-full rounded-md border-slate-300 text-sm">
                                    <option value="buyer_eligible">Buyer eligibility</option>
                                    <option value="vesting_and_restrictions">Vesting & restrictions</option>
                                    <option value="obligations_clear">Obligations clear</option>
                                    <option value="pledge_lien_clear">Pledge / lien clear</option>
                                    <option value="agreement_conflict_clear">Agreement conflict clear</option>
                                </select>
                            </label>
                            <label class="block space-y-1 text-sm">
                                <span class="font-medium text-slate-700">{{ t('partnerChanges.eligibilityResult') }}</span>
                                <select v-model="eligibilityForm.result" class="min-h-11 w-full rounded-md border-slate-300 text-sm">
                                    <option value="met">Met</option>
                                    <option value="blocked">Blocked</option>
                                    <option value="not_applicable">Not applicable</option>
                                </select>
                            </label>
                            <label class="block space-y-1 text-sm">
                                <span class="font-medium text-slate-700">{{ t('partnerChanges.eligibilityDetail') }}</span>
                                <textarea v-model="eligibilityForm.detail" rows="2" class="w-full rounded-md border-slate-300 text-sm" placeholder="Optional supporting context" />
                            </label>
                            <button
                                type="submit"
                                :disabled="eligibilityForm.processing"
                                class="min-h-10 rounded-md bg-slate-950 px-3 text-sm font-semibold text-white disabled:opacity-50"
                            >
                                Record eligibility
                            </button>
                        </form>
                    </details>

                    <details
                        v-if="partnerChanges.permissions.manage"
                        class="pbr-surface"
                    >
                        <summary class="cursor-pointer p-4 font-semibold text-slate-950">
                            {{ t('partnerChanges.requirements') }}
                        </summary>
                        <form class="space-y-3 border-t border-slate-200 p-4" @submit.prevent="submitRequirement">
                            <label class="block space-y-1 text-sm">
                                <span class="font-medium text-slate-700">{{ t('partnerChanges.requirementType') }}</span>
                                <select v-model="requirementForm.requirement_type" class="min-h-11 w-full rounded-md border-slate-300 text-sm">
                                    <option value="due_diligence">Due diligence</option>
                                    <option value="contribution">Contribution</option>
                                    <option value="legal_document">Legal document</option>
                                    <option value="onboarding">Onboarding</option>
                                    <option value="governance">Governance</option>
                                    <option value="ownership">Ownership</option>
                                    <option value="other">Other</option>
                                </select>
                            </label>
                            <label class="block space-y-1 text-sm">
                                <span class="font-medium text-slate-700">{{ t('partnerChanges.requirementKey') }}</span>
                                <input v-model="requirementForm.requirement_key" class="min-h-11 w-full rounded-md border-slate-300 text-sm" placeholder="e.g. contribution_terms_resolved" />
                            </label>
                            <label class="block space-y-1 text-sm">
                                <span class="font-medium text-slate-700">{{ t('partnerChanges.requirementStatus') }}</span>
                                <select v-model="requirementForm.status" class="min-h-11 w-full rounded-md border-slate-300 text-sm">
                                    <option value="met">Met</option>
                                    <option value="blocked">Blocked</option>
                                    <option value="not_applicable">Not applicable</option>
                                </select>
                            </label>
                            <label class="block space-y-1 text-sm">
                                <span class="font-medium text-slate-700">{{ t('partnerChanges.requirementDetail') }}</span>
                                <textarea v-model="requirementForm.detail" rows="2" class="w-full rounded-md border-slate-300 text-sm" placeholder="Optional supporting context" />
                            </label>
                            <button type="submit" class="min-h-10 rounded-md bg-slate-950 px-3 text-sm font-semibold text-white">
                                Record requirement
                            </button>
                        </form>
                    </details>

                    <details
                        v-if="partnerChanges.permissions.manage && selectedCase.status === 'rofr'"
                        class="pbr-surface"
                    >
                        <summary class="cursor-pointer p-4 font-semibold text-slate-950">
                            {{ t('partnerChanges.rofr') }}
                        </summary>
                        <form class="space-y-3 border-t border-slate-200 p-4" @submit.prevent="openRofr">
                            <label class="block space-y-1 text-sm">
                                <span class="font-medium text-slate-700">{{ t('partnerChanges.rofrTerms') }}</span>
                                <textarea v-model="rofrForm.terms_summary" required rows="3" class="w-full rounded-md border-slate-300 text-sm" placeholder="Enter the exact offered terms" />
                            </label>
                            <label class="block space-y-1 text-sm">
                                <span class="font-medium text-slate-700">{{ t('partnerChanges.rofrDeadline') }}</span>
                                <input v-model="rofrForm.deadline_at" required type="datetime-local" class="min-h-11 w-full rounded-md border-slate-300 text-sm" />
                            </label>
                            <button type="submit" class="min-h-10 rounded-md bg-slate-950 px-3 text-sm font-semibold text-white">
                                Open ROFR round
                            </button>
                        </form>
                    </details>

                    <div class="pbr-surface p-4">
                        <h3 class="font-semibold text-slate-950">Case evidence</h3>
                        <dl class="mt-3 space-y-3 text-sm">
                            <div>
                                <dt class="text-slate-500">Eligibility records</dt>
                                <dd class="font-semibold text-slate-900">{{ selectedCase.eligibility.length }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Requirements</dt>
                                <dd class="font-semibold text-slate-900">{{ selectedCase.requirements.length }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">ROFR rounds</dt>
                                <dd class="font-semibold text-slate-900">{{ selectedCase.rofr_rounds.length }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
