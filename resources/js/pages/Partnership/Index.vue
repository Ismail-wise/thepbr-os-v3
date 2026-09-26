<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PartnershipWorkflowPanel from '../../components/PartnershipWorkflowPanel.vue';
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import { useI18n } from '../../i18n/useI18n';

type Partner = {
    id: string;
    display_name: string;
    legal_name: string | null;
    email: string | null;
    status: string;
    revision: number;
};

type DueDiligence = {
    id: string;
    partner_id: string;
    status: string;
    risk_rating: string | null;
    revision: number;
};

type PartnerDynamics = {
    id: string;
    partner_id: string;
    source_assessment_id: string;
    source_url: string | null;
    assessment_version: string;
    primary_profile: string;
    secondary_profile: string | null;
    completed_at: string;
};

type Contribution = {
    id: string;
    partner_id: string;
    contribution_type: string;
    status: string;
    currency: string;
    description: string;
    proposed_value: string;
    reviewed_value: string | null;
    approved_value: string | null;
    accepted_value: string | null;
    revision: number;
};

type OwnershipScenario = {
    id: string;
    name: string;
    status: string;
    currency: string;
    share_value_minor_units: number;
    authorized_shares: string;
    issued_shares: string;
    reserved_unissued_shares: string;
    available_shares: string;
    revision: number;
};

type GovernanceSubmission = {
    id: string;
    contribution_id?: string;
    ownership_scenario_id?: string;
    phase?: string;
    formal_record_version_id: string;
    proposal_version_id: string;
};

type OwnershipScenarioShareClass = {
    id: string;
    ownership_scenario_id: string;
    name?: string;
    class_name?: string;
};

type OwnershipScenarioPosition = {
    id: string;
    ownership_scenario_id: string;
    partner_id: string;
    shares_issued?: string;
};

type CurrentRegister = {
    id: string;
    version_number: number;
    status: string;
    currency: string;
    authorized_shares: string;
    issued_shares: string;
    reserved_unissued_shares: string;
    available_shares: string;
    effective_from: string | null;
    effective_until: string | null;
    positions: Array<Record<string, unknown>>;
    share_classes: Array<Record<string, unknown>>;
};

const props = defineProps<{
    partnership: {
        business: {
            id: string;
            name: string;
        };
        permissions: Record<string, boolean>;
        partners: Partner[];
        due_diligence: DueDiligence[];
        partner_dynamics: PartnerDynamics[];
        contributions: Contribution[];
        contribution_submissions: GovernanceSubmission[];
        ownership_scenarios: OwnershipScenario[];
        ownership_scenario_share_classes: OwnershipScenarioShareClass[];
        ownership_scenario_positions: OwnershipScenarioPosition[];
        ownership_submissions: GovernanceSubmission[];
        current_ownership_register: CurrentRegister | null;
    };
}>();

const { t } = useI18n();

const activeSection = ref<
    'partners' | 'contributions' | 'ownership'
>('partners');

const partnerForm = useForm({
    display_name: '',
    legal_name: '',
    email: '',
    notes: '',
});

const partnerName = (partnerId: string): string =>
    props.partnership.partners.find(
        (partner) => partner.id === partnerId,
    )?.display_name ?? 'Unknown Partner';

const latestDueDiligence = computed(() => {
    const map = new Map<string, DueDiligence>();

    for (const row of props.partnership.due_diligence) {
        if (!map.has(row.partner_id)) {
            map.set(row.partner_id, row);
        }
    }

    return map;
});

const latestDynamics = computed(() => {
    const map = new Map<string, PartnerDynamics>();

    for (const row of props.partnership.partner_dynamics) {
        if (!map.has(row.partner_id)) {
            map.set(row.partner_id, row);
        }
    }

    return map;
});

const createPartner = () => {
    partnerForm.post('/partnership/partners', {
        preserveScroll: true,
        onSuccess: () => partnerForm.reset(),
    });
};
</script>

<template>
    <Head :title="t('partnership.title')" />

    <AuthenticatedLayout>
        <main class="mx-auto w-full max-w-[1500px] px-4 py-6 sm:px-6 lg:px-8">
            <div class="border-b border-slate-200 pb-5">
                <div
                    class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"
                >
                    <div>
                        <p
                            class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500"
                        >
                            {{ partnership.business.name }}
                        </p>

                        <h1
                            class="mt-2 text-2xl font-bold tracking-tight text-slate-950"
                        >
                            {{ t('partnership.title') }}
                        </h1>

                        <p class="mt-2 max-w-4xl text-sm leading-6 text-slate-600">
                            {{ t('partnership.description') }}
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Link
                            href="/records/documents"
                            class="inline-flex min-h-11 items-center border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-800 hover:bg-slate-50"
                        >
                            Document Vault
                        </Link>

                        <Link
                            href="/governance"
                            class="inline-flex min-h-11 items-center bg-slate-950 px-4 text-sm font-semibold text-white hover:bg-slate-800"
                        >
                            Governance
                        </Link>
                    </div>
                </div>
            </div>

            <div
                class="mt-5 border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-950"
            >
                {{ t('partnership.partnerDynamicsNotice') }}
            </div>

            <PartnershipWorkflowPanel
                :partnership="partnership"
            />

            <div
                class="mt-6 grid gap-6 xl:grid-cols-[220px_minmax(0,1fr)]"
            >
                <aside class="border-r border-slate-200 pr-4">
                    <nav aria-label="Partnership workspace sections">
                        <button
                            type="button"
                            class="flex min-h-11 w-full items-center border-l-2 px-3 text-left text-sm font-semibold"
                            :class="
                                activeSection === 'partners'
                                    ? 'border-slate-950 bg-slate-100 text-slate-950'
                                    : 'border-transparent text-slate-600'
                            "
                            @click="activeSection = 'partners'"
                        >
                            {{ t('partnership.partners') }}
                        </button>

                        <button
                            type="button"
                            class="flex min-h-11 w-full items-center border-l-2 px-3 text-left text-sm font-semibold"
                            :class="
                                activeSection === 'contributions'
                                    ? 'border-slate-950 bg-slate-100 text-slate-950'
                                    : 'border-transparent text-slate-600'
                            "
                            @click="activeSection = 'contributions'"
                        >
                            {{ t('partnership.contributions') }}
                        </button>

                        <button
                            type="button"
                            class="flex min-h-11 w-full items-center border-l-2 px-3 text-left text-sm font-semibold"
                            :class="
                                activeSection === 'ownership'
                                    ? 'border-slate-950 bg-slate-100 text-slate-950'
                                    : 'border-transparent text-slate-600'
                            "
                            @click="activeSection = 'ownership'"
                        >
                            {{ t('partnership.ownership') }}
                        </button>
                    </nav>
                </aside>

                <section v-if="activeSection === 'partners'">
                    <div
                        class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div>
                            <h2 class="text-lg font-bold text-slate-950">
                                {{ t('partnership.partners') }}
                            </h2>
                            <p class="mt-1 text-sm text-slate-600">
                                Partner identity is separate from Membership,
                                Ownership and Governance authority.
                            </p>
                        </div>
                    </div>

                    <form
                        v-if="partnership.permissions.partners_manage"
                        class="mt-5 grid gap-3 border border-slate-200 bg-white p-4 md:grid-cols-2 xl:grid-cols-4"
                        @submit.prevent="createPartner"
                    >
                        <label class="text-sm font-medium text-slate-700">
                            Display name
                            <input
                                v-model="partnerForm.display_name"
                                required
                                class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                            />
                        </label>

                        <label class="text-sm font-medium text-slate-700">
                            Legal name
                            <input
                                v-model="partnerForm.legal_name"
                                class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                            />
                        </label>

                        <label class="text-sm font-medium text-slate-700">
                            Email
                            <input
                                v-model="partnerForm.email"
                                type="email"
                                class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                            />
                        </label>

                        <div class="flex items-end">
                            <button
                                type="submit"
                                :disabled="partnerForm.processing"
                                class="min-h-11 w-full bg-slate-950 px-4 text-sm font-semibold text-white disabled:opacity-50"
                            >
                                {{ t('partnership.addPartner') }}
                            </button>
                        </div>
                    </form>

                    <div class="mt-5 overflow-x-auto border border-slate-200">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-slate-600">
                                <tr>
                                    <th class="px-4 py-3 font-semibold">
                                        Partner
                                    </th>
                                    <th class="px-4 py-3 font-semibold">
                                        Status
                                    </th>
                                    <th class="px-4 py-3 font-semibold">
                                        Due Diligence
                                    </th>
                                    <th class="px-4 py-3 font-semibold">
                                        PartnerDynamics
                                    </th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-slate-200 bg-white">
                                <tr
                                    v-for="partner in partnership.partners"
                                    :key="partner.id"
                                >
                                    <td class="px-4 py-4 align-top">
                                        <p class="font-semibold text-slate-950">
                                            {{ partner.display_name }}
                                        </p>
                                        <p class="mt-1 text-slate-500">
                                            {{ partner.email || '—' }}
                                        </p>
                                    </td>

                                    <td class="px-4 py-4 align-top">
                                        {{ partner.status }}
                                    </td>

                                    <td class="px-4 py-4 align-top">
                                        <template
                                            v-if="
                                                latestDueDiligence.get(
                                                    partner.id,
                                                )
                                            "
                                        >
                                            <p class="font-medium text-slate-900">
                                                {{
                                                    latestDueDiligence.get(
                                                        partner.id,
                                                    )?.status
                                                }}
                                            </p>
                                            <p class="mt-1 text-slate-500">
                                                Risk:
                                                {{
                                                    latestDueDiligence.get(
                                                        partner.id,
                                                    )?.risk_rating || '—'
                                                }}
                                            </p>
                                        </template>

                                        <span v-else class="text-slate-500">
                                            Not started
                                        </span>
                                    </td>

                                    <td class="px-4 py-4 align-top">
                                        <template
                                            v-if="
                                                latestDynamics.get(
                                                    partner.id,
                                                )
                                            "
                                        >
                                            <p class="font-medium text-slate-900">
                                                {{
                                                    latestDynamics.get(
                                                        partner.id,
                                                    )?.primary_profile
                                                }}
                                            </p>
                                            <p class="mt-1 text-slate-500">
                                                {{
                                                    latestDynamics.get(
                                                        partner.id,
                                                    )?.assessment_version
                                                }}
                                            </p>
                                        </template>

                                        <span v-else class="text-slate-500">
                                            No reference
                                        </span>
                                    </td>
                                </tr>

                                <tr v-if="partnership.partners.length === 0">
                                    <td
                                        colspan="4"
                                        class="px-4 py-8 text-center text-slate-500"
                                    >
                                        No Partner records yet.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section v-else-if="activeSection === 'contributions'">
                    <div>
                        <h2 class="text-lg font-bold text-slate-950">
                            {{ t('partnership.contributions') }}
                        </h2>
                        <p class="mt-1 text-sm text-slate-600">
                            Proposed, Reviewed, Approved, Delivered and Accepted
                            values remain distinct. Only Accepted value may feed
                            Ownership.
                        </p>
                    </div>

                    <div class="mt-5 overflow-x-auto border border-slate-200">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-slate-600">
                                <tr>
                                    <th class="px-4 py-3 font-semibold">
                                        Partner
                                    </th>
                                    <th class="px-4 py-3 font-semibold">
                                        Type
                                    </th>
                                    <th class="px-4 py-3 font-semibold">
                                        Description
                                    </th>
                                    <th class="px-4 py-3 font-semibold">
                                        Proposed
                                    </th>
                                    <th class="px-4 py-3 font-semibold">
                                        Accepted
                                    </th>
                                    <th class="px-4 py-3 font-semibold">
                                        Status
                                    </th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-slate-200 bg-white">
                                <tr
                                    v-for="row in partnership.contributions"
                                    :key="row.id"
                                >
                                    <td class="px-4 py-4">
                                        {{ partnerName(row.partner_id) }}
                                    </td>
                                    <td class="px-4 py-4">
                                        {{ row.contribution_type }}
                                    </td>
                                    <td class="px-4 py-4">
                                        {{ row.description }}
                                    </td>
                                    <td class="px-4 py-4">
                                        {{ row.proposed_value }}
                                        {{ row.currency }}
                                    </td>
                                    <td class="px-4 py-4">
                                        {{
                                            row.accepted_value
                                                ? `${row.accepted_value} ${row.currency}`
                                                : '—'
                                        }}
                                    </td>
                                    <td class="px-4 py-4 font-medium">
                                        {{ row.status }}
                                    </td>
                                </tr>

                                <tr
                                    v-if="
                                        partnership.contributions.length === 0
                                    "
                                >
                                    <td
                                        colspan="6"
                                        class="px-4 py-8 text-center text-slate-500"
                                    >
                                        No Contributions yet.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div
                        class="mt-4 flex flex-wrap items-center justify-between gap-3 border border-slate-200 bg-slate-50 p-4"
                    >
                        <p class="text-sm text-slate-600">
                            Evidence stays canonical in Document Vault and is
                            linked to the Contribution record.
                        </p>

                        <Link
                            href="/records/documents"
                            class="text-sm font-semibold text-slate-950 underline underline-offset-4"
                        >
                            Open Document Vault
                        </Link>
                    </div>
                </section>

                <section v-else>
                    <div>
                        <h2 class="text-lg font-bold text-slate-950">
                            {{ t('partnership.ownership') }}
                        </h2>
                        <p class="mt-1 text-sm text-slate-600">
                            {{ t('partnership.scenarioNotice') }}
                        </p>
                    </div>

                    <div
                        class="mt-5 grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]"
                    >
                        <div class="border border-slate-200 bg-white">
                            <div class="border-b border-slate-200 px-4 py-3">
                                <h3 class="font-bold text-slate-950">
                                    {{ t('partnership.scenarios') }}
                                </h3>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-slate-200 text-sm">
                                    <thead class="bg-slate-50 text-left">
                                        <tr>
                                            <th class="px-4 py-3">Name</th>
                                            <th class="px-4 py-3">Status</th>
                                            <th class="px-4 py-3">Issued</th>
                                        </tr>
                                    </thead>

                                    <tbody class="divide-y divide-slate-200">
                                        <tr
                                            v-for="scenario in partnership.ownership_scenarios"
                                            :key="scenario.id"
                                        >
                                            <td class="px-4 py-3 font-medium">
                                                {{ scenario.name }}
                                            </td>
                                            <td class="px-4 py-3">
                                                {{ scenario.status }}
                                            </td>
                                            <td class="px-4 py-3">
                                                {{ scenario.issued_shares }}
                                            </td>
                                        </tr>

                                        <tr
                                            v-if="
                                                partnership
                                                    .ownership_scenarios
                                                    .length === 0
                                            "
                                        >
                                            <td
                                                colspan="3"
                                                class="px-4 py-8 text-center text-slate-500"
                                            >
                                                No Ownership Scenario yet.
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="border border-slate-200 bg-white">
                            <div class="border-b border-slate-200 px-4 py-3">
                                <h3 class="font-bold text-slate-950">
                                    {{ t('partnership.currentRegister') }}
                                </h3>
                            </div>

                            <div
                                v-if="
                                    partnership.current_ownership_register
                                "
                                class="space-y-4 p-4"
                            >
                                <dl
                                    class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm"
                                >
                                    <div>
                                        <dt class="text-slate-500">
                                            Version
                                        </dt>
                                        <dd class="font-semibold">
                                            {{
                                                partnership
                                                    .current_ownership_register
                                                    .version_number
                                            }}
                                        </dd>
                                    </div>

                                    <div>
                                        <dt class="text-slate-500">
                                            Status
                                        </dt>
                                        <dd class="font-semibold">
                                            {{
                                                partnership
                                                    .current_ownership_register
                                                    .status
                                            }}
                                        </dd>
                                    </div>

                                    <div>
                                        <dt class="text-slate-500">
                                            Issued shares
                                        </dt>
                                        <dd class="font-semibold">
                                            {{
                                                partnership
                                                    .current_ownership_register
                                                    .issued_shares
                                            }}
                                        </dd>
                                    </div>

                                    <div>
                                        <dt class="text-slate-500">
                                            Available shares
                                        </dt>
                                        <dd class="font-semibold">
                                            {{
                                                partnership
                                                    .current_ownership_register
                                                    .available_shares
                                            }}
                                        </dd>
                                    </div>
                                </dl>

                                <p class="text-sm text-slate-600">
                                    This Effective Register is the canonical
                                    current ownership source.
                                </p>
                            </div>

                            <p
                                v-else
                                class="p-4 text-sm text-slate-500"
                            >
                                {{
                                    t(
                                        'partnership.noCurrentRegister',
                                    )
                                }}
                            </p>
                        </div>
                    </div>

                    <div
                        class="mt-5 flex flex-wrap items-center justify-between gap-3 border border-slate-200 bg-slate-50 p-4"
                    >
                        <p class="text-sm text-slate-600">
                            Ownership approval, decision and signature remain
                            separate governance actions.
                        </p>

                        <Link
                            href="/governance"
                            class="text-sm font-semibold text-slate-950 underline underline-offset-4"
                        >
                            Open Governance
                        </Link>
                    </div>
                </section>
            </div>
        </main>
    </AuthenticatedLayout>
</template>
