<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PartnershipWorkflowPanel from '../../components/PartnershipWorkflowPanel.vue';
import GuidedJourneyStepper from '../../components/hybrid/GuidedJourneyStepper.vue';
import PartnerDynamicsWorkspacePanel from '../../components/partner-dynamics/PartnerDynamicsWorkspacePanel.vue';
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
    identity_legal_info: string | null;
    background_summary: string | null;
    business_experience: string | null;
    financial_capacity: string | null;
    reputation: string | null;
    existing_business_interests: string | null;
    conflict_of_interest: string | null;
    time_commitment: string | null;
    legal_regulatory_check: string | null;
    notes: string | null;
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

type PartnerDynamicsWorkspace = {
    progress: {
        completed: number;
        total: number;
        ready: boolean;
    };
    currentUser: {
        linked: boolean;
        completed: boolean;
        assessmentRoute: string;
    };
    participants: Array<{
        partnerId: string;
        displayName: string;
        partnerStatus: string;
        completionStatus: 'completed' | 'pending';
        primaryProfile: string | null;
        secondaryProfile: string | null;
        completedAt: string | null;
        isCurrentUser: boolean;
    }>;
    alignment: null | {
        summary: {
            participantCount: number;
            sharedStrengthCount: number;
            complementaryAreaCount: number;
            importantDifferenceCount: number;
            sharedBlindSpotCount: number;
            note: string;
        };
        sharedStrengths: Array<{
            dimension: string;
            label: string;
            averageScore?: number;
            gap?: number;
            message: string;
        }>;
        complementaryAreas: Array<{
            dimension: string;
            label: string;
            averageScore?: number;
            gap?: number;
            message: string;
        }>;
        importantDifferences: Array<{
            dimension: string;
            label: string;
            averageScore?: number;
            gap?: number;
            message: string;
        }>;
        sharedBlindSpots: Array<{
            dimension: string;
            label: string;
            averageScore?: number;
            gap?: number;
            message: string;
        }>;
        roleSuggestions: Array<{
            name: string;
            primaryProfile: string;
            secondaryProfile: string | null;
            suggestions: string[];
            note: string;
        }>;
        decisionRecommendations: Array<{
            title: string;
            message: string;
        }>;
        discussionPriorities: Array<{
            priority: string;
            topic: string;
            reason: string;
        }>;
    };
    advisoryOnly: boolean;
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
    valuation_method: string | null;
    conditions?: string | null;
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
    vested_shares?: string;
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
        partner_dynamics_workspace: PartnerDynamicsWorkspace | null;
        contributions: Contribution[];
        contribution_submissions: GovernanceSubmission[];
        ownership_scenarios: OwnershipScenario[];
        ownership_scenario_share_classes: OwnershipScenarioShareClass[];
        ownership_scenario_positions: OwnershipScenarioPosition[];
        ownership_submissions: GovernanceSubmission[];
        current_ownership_register: CurrentRegister | null;
    };
    partnerInvitationToken: string | null;
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

type StepState = 'recorded' | 'current' | 'next' | 'available';

const sectionState = (
    key: 'partners' | 'contributions' | 'ownership',
    hasData: boolean,
): StepState => {
    if (activeSection.value === key) {
        return 'current';
    }

    return hasData ? 'recorded' : 'available';
};

const partnershipSteps = computed(() => [
    {
        key: 'partners',
        label: t('partnership.partners'),
        state: sectionState('partners', props.partnership.partners.length > 0),
    },
    {
        key: 'contributions',
        label: t('partnership.contributions'),
        state: sectionState(
            'contributions',
            props.partnership.contributions.length > 0,
        ),
    },
    {
        key: 'ownership',
        label: t('partnership.ownership'),
        state: sectionState(
            'ownership',
            props.partnership.current_ownership_register !== null
                || props.partnership.ownership_scenarios.length > 0,
        ),
    },
]);

const selectPartnershipSection = (key: string): void => {
    if (
        key === 'partners'
        || key === 'contributions'
        || key === 'ownership'
    ) {
        activeSection.value = key;
    }
};

const contributionSteps = computed(() => {
    const hasContributions = props.partnership.contributions.length > 0;
    const hasReviewed = props.partnership.contributions.some(
        (row) => row.reviewed_value !== null,
    );
    const hasConditions = props.partnership.contributions.some(
        (row) => Boolean(row.conditions?.trim()),
    );
    const hasApproved = props.partnership.contributions.some(
        (row) => row.approved_value !== null || row.accepted_value !== null,
    );
    const hasAccepted = props.partnership.contributions.some(
        (row) => row.accepted_value !== null,
    );

    return [
        { key: 'setup', label: t('partnership.setup'), state: (hasContributions ? 'recorded' : 'current') as StepState },
        { key: 'partners', label: t('partnership.partners'), state: (props.partnership.partners.length > 0 ? 'recorded' : 'next') as StepState },
        { key: 'contributions', label: t('partnership.contributionsStep'), state: (hasContributions ? 'recorded' : 'available') as StepState },
        { key: 'valuation', label: t('partnership.valuation'), state: (hasReviewed ? 'recorded' : hasContributions ? 'current' : 'available') as StepState },
        { key: 'evidence', label: t('partnership.evidenceConditions'), state: (hasConditions ? 'recorded' : 'available') as StepState },
        { key: 'approval', label: t('partnership.approval'), state: (hasApproved ? 'recorded' : 'available') as StepState },
        { key: 'matrix', label: t('partnership.acceptedMatrix'), state: (hasAccepted ? 'recorded' : 'available') as StepState },
    ];
});

const ownershipSteps = computed(() => {
    const hasAccepted = props.partnership.contributions.some(
        (row) => row.accepted_value !== null,
    );
    const hasScenario = props.partnership.ownership_scenarios.length > 0;
    const hasClasses = props.partnership.ownership_scenario_share_classes.length > 0;
    const hasPositions = props.partnership.ownership_scenario_positions.length > 0;
    const hasVesting = props.partnership.ownership_scenario_positions.some(
        (row) => Number(row.vested_shares ?? 0) > 0,
    );
    const hasFrozen = props.partnership.ownership_scenarios.some(
        (row) => row.status === 'frozen',
    );
    const hasSubmission = props.partnership.ownership_submissions.length > 0;
    const hasEffective = props.partnership.current_ownership_register !== null;

    return [
        { key: 'accepted', label: t('partnership.acceptedContributions'), state: (hasAccepted ? 'recorded' : 'current') as StepState },
        { key: 'structure', label: t('partnership.shareStructure'), state: (hasScenario ? 'recorded' : hasAccepted ? 'current' : 'available') as StepState },
        { key: 'classes', label: t('partnership.shareClasses'), state: (hasClasses ? 'recorded' : 'available') as StepState },
        { key: 'allocation', label: t('partnership.allocation'), state: (hasPositions ? 'recorded' : 'available') as StepState },
        { key: 'vesting', label: t('partnership.vesting'), state: (hasVesting ? 'recorded' : 'available') as StepState },
        { key: 'scenario', label: t('partnership.scenario'), state: (hasFrozen ? 'recorded' : hasScenario ? 'current' : 'available') as StepState },
        { key: 'proposal', label: t('partnership.proposal'), state: (hasSubmission ? 'recorded' : 'available') as StepState },
        { key: 'governance', label: t('partnership.openGovernance'), state: (hasEffective ? 'recorded' : hasSubmission ? 'current' : 'available') as StepState },
        { key: 'effective', label: t('partnership.effectiveRegister'), state: (hasEffective ? 'recorded' : 'available') as StepState },
    ];
});

type MoneyBucket = Record<string, number>;

const addMoney = (
    bucket: MoneyBucket,
    currency: string,
    value: string | null,
): void => {
    if (value === null) {
        return;
    }

    const amount = Number(value);

    if (Number.isFinite(amount)) {
        bucket[currency] = (bucket[currency] ?? 0) + amount;
    }
};

const formatMoneyBucket = (bucket: MoneyBucket): string => {
    const entries = Object.entries(bucket);

    return entries.length === 0
        ? '—'
        : entries
              .map(([currency, amount]) => `${amount.toFixed(2)} ${currency}`)
              .join(' · ');
};

const acceptedMatrix = computed(() =>
    props.partnership.partners.map((partner) => {
        const buckets = {
            cash: {} as MoneyBucket,
            time_skill: {} as MoneyBucket,
            property_asset: {} as MoneyBucket,
            ip_intangible: {} as MoneyBucket,
            total: {} as MoneyBucket,
        };

        for (const row of props.partnership.contributions) {
            if (row.partner_id !== partner.id || row.accepted_value === null) {
                continue;
            }

            const key = row.contribution_type as keyof typeof buckets;

            if (key !== 'total' && key in buckets) {
                addMoney(buckets[key], row.currency, row.accepted_value);
            }

            addMoney(buckets.total, row.currency, row.accepted_value);
        }

        return {
            partner,
            cash: formatMoneyBucket(buckets.cash),
            timeSkill: formatMoneyBucket(buckets.time_skill),
            propertyAsset: formatMoneyBucket(buckets.property_asset),
            intangible: formatMoneyBucket(buckets.ip_intangible),
            total: formatMoneyBucket(buckets.total),
        };
    }),
);

const partnerFoundationSummary = computed(() => {
    const total = props.partnership.partners.length;
    const diligenceCompleted = props.partnership.partners.filter(
        (partner) => latestDueDiligence.value.get(partner.id)?.status === 'completed',
    ).length;
    const dynamicsReferenced = props.partnership.partners.filter(
        (partner) => latestDynamics.value.has(partner.id),
    ).length;

    return {
        total,
        diligenceCompleted,
        dynamicsReferenced,
    };
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
        <main class="min-h-screen bg-[radial-gradient(circle_at_88%_0%,rgb(210_167_67_/_8%),transparent_26rem),linear-gradient(180deg,#f7f9f6_0%,#f1f5f1_100%)] px-4 py-5 text-[var(--pbr-ink)] sm:px-6 sm:py-6 lg:px-8 lg:py-7">
            <div class="mx-auto w-full max-w-[1500px]">
            <div class="rounded-[24px] border border-[#d8e4da] bg-white/88 px-5 py-5 shadow-[0_14px_34px_rgb(16_35_26_/_5%)] sm:px-6">
                <div
                    class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"
                >
                    <div>
                        <p
                            class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]"
                        >
                            {{ partnership.business.name }}
                        </p>

                        <h1
                            class="mt-2 text-2xl font-black tracking-[-0.03em] text-[var(--pbr-ink)] sm:text-3xl"
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

            <div
                v-if="partnerInvitationToken"
                class="mt-4 border border-slate-300 bg-slate-50 p-4"
                role="status"
                aria-live="polite"
            >
                <p class="text-sm font-semibold text-slate-950">
                    {{ t('access.invitationCodeOnce') }}
                </p>
                <code
                    class="mt-2 block break-all bg-white px-3 py-3 font-mono text-sm text-slate-900"
                >
                    {{ partnerInvitationToken }}
                </code>
                <p class="mt-2 text-xs leading-5 text-slate-600">
                    {{ t('access.invitationCodeHelp') }}
                </p>
            </div>

            <section class="mt-6">
                <div class="mb-3">
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[#7d8a82]">
                        {{ t('partnership.journey') }}
                    </p>
                    <p class="mt-1 max-w-4xl text-sm leading-6 text-[var(--pbr-muted)]">
                        {{ t('partnership.journeyHelp') }}
                    </p>
                </div>

                <GuidedJourneyStepper
                    :steps="partnershipSteps"
                    :label="t('partnership.journey')"
                    @select="selectPartnershipSection"
                />
            </section>

            <PartnershipWorkflowPanel
                :partnership="partnership"
            />

            <div class="mt-6">
                <section v-if="activeSection === 'partners'">
                    <div class="rounded-[22px] border border-[#d8e4da] bg-white/88 p-5 shadow-[0_10px_28px_rgb(16_35_26_/_4%)] sm:p-6">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]">
                                {{ t('partnership.partnerFoundationTitle') }}
                            </p>
                            <h2 class="mt-2 text-xl font-black tracking-[-0.02em] text-[var(--pbr-ink)]">
                                {{ t('partnership.partners') }}
                            </h2>
                            <p class="mt-2 max-w-4xl text-sm leading-6 text-[var(--pbr-muted)]">
                                {{ t('partnership.partnerFoundationDescription') }}
                            </p>
                        </div>

                        <dl class="mt-5 grid gap-3 sm:grid-cols-3">
                            <div class="rounded-[18px] border border-[#dde7df] bg-[#f8faf8] p-4">
                                <dt class="text-xs font-bold text-[var(--pbr-muted)]">
                                    {{ t('partnership.partnersRecorded') }}
                                </dt>
                                <dd class="mt-2 text-2xl font-black text-[var(--pbr-ink)]">
                                    {{ partnerFoundationSummary.total }}
                                </dd>
                            </div>
                            <div class="rounded-[18px] border border-[#dde7df] bg-[#f8faf8] p-4">
                                <dt class="text-xs font-bold text-[var(--pbr-muted)]">
                                    {{ t('partnership.diligenceCompleted') }}
                                </dt>
                                <dd class="mt-2 text-2xl font-black text-[var(--pbr-ink)]">
                                    {{ partnerFoundationSummary.diligenceCompleted }}
                                    <span class="text-sm font-bold text-[var(--pbr-muted)]">
                                        / {{ partnerFoundationSummary.total }}
                                    </span>
                                </dd>
                            </div>
                            <div class="rounded-[18px] border border-[#e8d9ab] bg-[#fffaf0] p-4">
                                <dt class="text-xs font-bold text-[#7d672d]">
                                    {{ t('partnership.dynamicsReferenced') }}
                                </dt>
                                <dd class="mt-2 text-2xl font-black text-[#66531f]">
                                    {{ partnerFoundationSummary.dynamicsReferenced }}
                                </dd>
                                <p class="mt-2 text-[11px] leading-4 text-[#7d672d]">
                                    {{ t('partnership.referenceOnly') }}
                                </p>
                            </div>
                        </dl>
                    </div>

                    <PartnerDynamicsWorkspacePanel
                        v-if="partnership.partner_dynamics_workspace"
                        :workspace="partnership.partner_dynamics_workspace"
                    />

                    <form
                        v-if="partnership.permissions.partners_manage"
                        class="mt-5 grid gap-3 rounded-[20px] border border-[#d8e4da] bg-white/90 p-4 shadow-[0_8px_22px_rgb(16_35_26_/_3%)] md:grid-cols-2 xl:grid-cols-4"
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

                    <div class="mt-5 overflow-x-auto rounded-[20px] border border-[#d8e4da] bg-white shadow-[0_8px_22px_rgb(16_35_26_/_3%)]">
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
                                            <p
                                                v-if="
                                                    latestDynamics.get(
                                                        partner.id,
                                                    )?.secondary_profile
                                                "
                                                class="mt-1 text-slate-500"
                                            >
                                                {{
                                                    latestDynamics.get(
                                                        partner.id,
                                                    )?.secondary_profile
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

                    <div class="mt-5">
                        <GuidedJourneyStepper
                            :steps="contributionSteps"
                            :label="t('partnership.contributionJourney')"
                            @select="() => undefined"
                        />
                    </div>

                    <div class="mt-5 overflow-x-auto rounded-[18px] border border-[#dce6de]">
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
                                        {{ t('partnership.proposedValue') }}
                                    </th>
                                    <th class="px-4 py-3 font-semibold">
                                        {{ t('partnership.reviewedValue') }}
                                    </th>
                                    <th class="px-4 py-3 font-semibold">
                                        {{ t('partnership.approvedValue') }}
                                    </th>
                                    <th class="px-4 py-3 font-semibold">
                                        {{ t('partnership.acceptedValue') }}
                                    </th>
                                    <th class="px-4 py-3 font-semibold">
                                        {{ t('partnership.currentState') }}
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
                                    <td class="px-4 py-4 font-medium">
                                        {{ row.proposed_value }} {{ row.currency }}
                                    </td>
                                    <td class="px-4 py-4">
                                        {{
                                            row.reviewed_value
                                                ? `${row.reviewed_value} ${row.currency}`
                                                : '—'
                                        }}
                                    </td>
                                    <td class="px-4 py-4">
                                        {{
                                            row.approved_value
                                                ? `${row.approved_value} ${row.currency}`
                                                : '—'
                                        }}
                                    </td>
                                    <td class="px-4 py-4 font-black text-[var(--pbr-green-dark)]">
                                        {{
                                            row.accepted_value
                                                ? `${row.accepted_value} ${row.currency}`
                                                : '—'
                                        }}
                                    </td>
                                    <td class="px-4 py-4">
                                        <span class="inline-flex rounded-full bg-[#eef4ef] px-2.5 py-1 text-xs font-black text-[#476052]">
                                            {{ row.status }}
                                        </span>
                                    </td>
                                </tr>

                                <tr
                                    v-if="
                                        partnership.contributions.length === 0
                                    "
                                >
                                    <td
                                        colspan="8"
                                        class="px-4 py-8 text-center text-slate-500"
                                    >
                                        No Contributions yet.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <section class="mt-6">
                        <div class="mb-3">
                            <h3 class="text-base font-black text-[var(--pbr-ink)]">
                                {{ t('partnership.acceptedMatrix') }}
                            </h3>
                            <p class="mt-1 text-sm leading-6 text-[var(--pbr-muted)]">
                                {{ t('partnership.acceptedMatrixHelp') }}
                            </p>
                        </div>

                        <div class="overflow-x-auto rounded-[18px] border border-[#dce6de]">
                            <table class="min-w-[820px] w-full text-left text-sm">
                                <thead class="bg-[#f6f8f6] text-[#66736a]">
                                    <tr>
                                        <th class="px-4 py-3 font-black">Partner</th>
                                        <th class="px-4 py-3 font-black">Cash</th>
                                        <th class="px-4 py-3 font-black">Time & Skill</th>
                                        <th class="px-4 py-3 font-black">Property & Asset</th>
                                        <th class="px-4 py-3 font-black">IP & Intangible</th>
                                        <th class="px-4 py-3 font-black">
                                            {{ t('partnership.acceptedTotal') }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[#e4ebe5] bg-white">
                                    <tr v-for="row in acceptedMatrix" :key="row.partner.id">
                                        <td class="px-4 py-3 font-black text-[var(--pbr-ink)]">
                                            {{ row.partner.display_name }}
                                        </td>
                                        <td class="px-4 py-3">{{ row.cash }}</td>
                                        <td class="px-4 py-3">{{ row.timeSkill }}</td>
                                        <td class="px-4 py-3">{{ row.propertyAsset }}</td>
                                        <td class="px-4 py-3">{{ row.intangible }}</td>
                                        <td class="px-4 py-3 font-black text-[var(--pbr-green-dark)]">
                                            {{ row.total }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <div
                        class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-[18px] border border-[#dce6de] bg-[#f8faf8] p-4"
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

                    <div class="mt-5">
                        <GuidedJourneyStepper
                            :steps="ownershipSteps"
                            :label="t('partnership.ownershipJourney')"
                            @select="() => undefined"
                        />
                    </div>

                    <div class="mt-5 rounded-[18px] border border-[#cfe1d3] bg-[#f3f8f4] p-4">
                        <p class="text-sm font-black text-[var(--pbr-green-dark)]">
                            {{ t('partnership.governedOwnershipTitle') }}
                        </p>
                        <p class="mt-1 max-w-4xl text-sm leading-6 text-[var(--pbr-muted)]">
                            {{ t('partnership.governedOwnershipHelp') }}
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
                                            {{ t('partnership.effectiveSince') }}
                                        </dt>
                                        <dd class="font-semibold">
                                            {{
                                                partnership
                                                    .current_ownership_register
                                                    .effective_from || '—'
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
            </div>
        </main>
    </AuthenticatedLayout>
</template>
