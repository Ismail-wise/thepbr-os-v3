<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import GuidedJourneyStepper from './hybrid/GuidedJourneyStepper.vue';
import { useI18n } from '../i18n/useI18n';

type StepState = 'recorded' | 'current' | 'next' | 'available';

const props = defineProps<{
    chapter: any;
    permissions: Record<string, boolean>;
}>();

const { t } = useI18n();
const selectedStep = ref<string | null>(props.chapter.progress?.nextStep ?? null);
const canManage = computed(
    () => props.chapter.canManage === true && props.permissions.ownership_manage === true,
);
const scenario = computed(() => props.chapter.scenario ?? null);
const currentRegister = computed(() => props.chapter.currentRegister ?? null);
const decision = computed(() => props.chapter.decisionRecord ?? null);
const actionPlan = computed(() => props.chapter.actionPlan ?? null);

const stepLabels: Record<string, string> = {
    accepted_contributions: t('ownership.step.accepted'),
    share_value: t('ownership.step.shareValue'),
    allocation: t('ownership.step.allocation'),
    share_classes_rights: t('ownership.step.rights'),
    vesting: t('ownership.step.vesting'),
    voting_profit_rights: t('ownership.step.votingProfit'),
    share_capacity: t('ownership.step.capacity'),
    new_share_rule: t('ownership.step.issuance'),
    review_approve: t('ownership.step.approval'),
    share_register: t('ownership.step.register'),
    decision_record: t('ownership.step.decision'),
    action_plan: t('ownership.step.actions'),
    continue_governance: t('ownership.step.continue'),
};

const steps = computed(() =>
    (props.chapter.progress?.steps ?? []).map((step: any) => ({
        key: step.key,
        label: stepLabels[step.key] ?? step.key,
        state: step.state as StepState,
    })),
);

const currentKey = computed(
    () =>
        selectedStep.value ??
        props.chapter.progress?.nextStep ??
        (props.chapter.progress?.chapterComplete
            ? 'continue_governance'
            : 'accepted_contributions'),
);

const chooseStep = (key: string) => {
    selectedStep.value = key;
};

const scenarioForm = useForm({
    name: 'Ownership & Equity',
    share_value: '',
    authorized_shares: '1000',
    reserved_unissued_shares: '0',
});

const submitScenario = () => {
    scenarioForm.post('/partnership/ownership/chapter/scenarios', {
        preserveScroll: true,
    });
};

const rightsForms = reactive<Record<string, any>>({});
const rightsForm = (shareClass: any) => {
    if (!rightsForms[shareClass.id]) {
        rightsForms[shareClass.id] = useForm({
            share_class_name: shareClass.name ?? 'Ordinary',
            voting_right_per_share: shareClass.votingRightPerShare ?? '1',
            profit_right_per_share: shareClass.profitRightPerShare ?? '1',
            transfer_allowed: shareClass.transferAllowed ?? true,
            restrictions: shareClass.restrictions ?? '',
            special_rights: shareClass.specialRights ?? '',
        });
    }
    return rightsForms[shareClass.id];
};
const saveRights = (shareClass: any) => {
    const form = rightsForm(shareClass);
    form.put(
        `/partnership/ownership/chapter/scenarios/${scenario.value.id}/share-classes/${shareClass.id}`,
        { preserveScroll: true },
    );
};

const vestingForms = reactive<Record<string, any>>({});
const vestingForm = (position: any) => {
    if (!vestingForms[position.id]) {
        vestingForms[position.id] = useForm({
            vesting_applies: position.vestingApplies ?? false,
            vested_shares:
                position.vestingApplies === true
                    ? position.sharesVested
                    : position.sharesIssued,
            vesting_start_date: position.vestingStartDate ?? '',
            vesting_period_months: position.vestingPeriodMonths ?? 48,
            vesting_cliff_months: position.vestingCliffMonths ?? 12,
            vesting_conditions: position.vestingConditions ?? '',
            early_exit_treatment: position.earlyExitTreatment ?? '',
        });
    }
    return vestingForms[position.id];
};
const saveVesting = (position: any) => {
    const form = vestingForm(position);
    form.transform((data: any) => ({
        ...data,
        vesting_applies: Boolean(data.vesting_applies),
        vested_shares: data.vesting_applies ? data.vested_shares : null,
        vesting_start_date: data.vesting_applies
            ? data.vesting_start_date
            : null,
        vesting_period_months: data.vesting_applies
            ? data.vesting_period_months
            : null,
        vesting_cliff_months: data.vesting_applies
            ? data.vesting_cliff_months
            : null,
        vesting_conditions: data.vesting_applies
            ? data.vesting_conditions
            : null,
        early_exit_treatment: data.vesting_applies
            ? data.early_exit_treatment
            : null,
    })).put(
        `/partnership/ownership/chapter/scenarios/${scenario.value.id}/positions/${position.id}/vesting`,
        { preserveScroll: true },
    );
};

const capacityForm = useForm({
    authorized_shares: scenario.value?.capacity?.authorized ?? '1000',
    reserved_unissued_shares: scenario.value?.capacity?.reserved ?? '0',
});
const saveCapacity = () => {
    capacityForm.put(
        `/partnership/ownership/chapter/scenarios/${scenario.value.id}/capacity`,
        { preserveScroll: true },
    );
};

const ruleForm = useForm({
    approval_rule: scenario.value?.issuanceRule?.approvalRule ?? '',
    approval_threshold_percent:
        scenario.value?.issuanceRule?.approvalThresholdPercent ?? '75',
    preemption_right: scenario.value?.issuanceRule?.preemptionRight ?? true,
    valuation_method: scenario.value?.issuanceRule?.valuationMethod ?? '',
    dilution_acknowledged:
        scenario.value?.issuanceRule?.dilutionAcknowledged ?? false,
});
const saveRule = () => {
    ruleForm.put(
        `/partnership/ownership/chapter/scenarios/${scenario.value.id}/issuance-rule`,
        { preserveScroll: true },
    );
};

const freezeForm = useForm({
    expected_revision: scenario.value?.revision ?? 1,
});
const freezeScenario = () => {
    freezeForm.expected_revision = scenario.value?.revision ?? 1;
    freezeForm.post(
        `/partnership/ownership/scenarios/${scenario.value.id}/freeze`,
        { preserveScroll: true },
    );
};

const today = new Date().toISOString().slice(0, 10);
const governanceForm = useForm({
    effective_from: today,
    effective_until: '',
});
const contentReviewForm = useForm({
    target: 'under_review',
});
const advanceContentReview = (target: 'under_review' | 'approved') => {
    const submissionId = scenario.value?.approval?.submissionId;
    if (!submissionId) return;
    contentReviewForm.target = target;
    contentReviewForm.post(
        `/partnership/ownership-submissions/${submissionId}/content-review`,
        { preserveScroll: true },
    );
};
const submitGovernance = () => {
    governanceForm.post(
        `/partnership/ownership/scenarios/${scenario.value.id}/governance`,
        { preserveScroll: true },
    );
};

const effectForm = useForm({});
const effectOwnership = () => {
    const submissionId = scenario.value?.approval?.submissionId;
    if (!submissionId) return;
    effectForm.post(
        `/partnership/ownership-submissions/${submissionId}/effect`,
        { preserveScroll: true },
    );
};

const decisionForm = useForm({
    decision_owner_membership_id:
        decision.value?.ownerOptions?.[0]?.id ?? '',
    review_date: decision.value?.defaultReviewDate ?? today,
    decision_summary: '',
    evidence_references_text: '',
});
const saveDecision = () => {
    decisionForm
        .transform((data: any) => ({
            decision_owner_membership_id:
                data.decision_owner_membership_id,
            review_date: data.review_date,
            decision_summary: data.decision_summary,
            evidence_references: String(data.evidence_references_text ?? '')
                .split('\n')
                .map((row) => row.trim())
                .filter(Boolean),
        }))
        .post('/partnership/ownership/chapter/decision-record', {
            preserveScroll: true,
        });
};

const actionForm = useForm({
    assigned_membership_id:
        actionPlan.value?.defaultOwnerMembershipId ??
        actionPlan.value?.ownerOptions?.[0]?.id ??
        '',
    title: '',
    description: '',
    due_date: '',
});

watch(
    () => decision.value?.ownerOptions?.[0]?.id ?? null,
    (ownerId) => {
        if (
            ownerId &&
            !decisionForm.decision_owner_membership_id
        ) {
            decisionForm.decision_owner_membership_id =
                ownerId;
        }
    },
    { immediate: true },
);

watch(
    () =>
        actionPlan.value?.defaultOwnerMembershipId ??
        actionPlan.value?.ownerOptions?.[0]?.id ??
        null,
    (ownerId) => {
        if (
            ownerId &&
            !actionForm.assigned_membership_id
        ) {
            actionForm.assigned_membership_id =
                ownerId;
        }
    },
    { immediate: true },
);
const saveCustomAction = () => {
    actionForm.post('/partnership/ownership/chapter/actions/custom', {
        preserveScroll: true,
    });
};

const suggestionForms = reactive<Record<string, any>>({});
const suggestionForm = (suggestion: any) => {
    if (!suggestionForms[suggestion.key]) {
        suggestionForms[suggestion.key] = useForm({
            suggestion_key: suggestion.key,
            assigned_membership_id:
                actionPlan.value?.defaultOwnerMembershipId ??
                actionPlan.value?.ownerOptions?.[0]?.id ??
                '',
        });
    }
    return suggestionForms[suggestion.key];
};
const addSuggestion = (suggestion: any) => {
    suggestionForm(suggestion).post(
        '/partnership/ownership/chapter/actions/suggested',
        { preserveScroll: true },
    );
};

const statusForm = (action: any, status: string) => {
    useForm({
        status,
        blocked_reason: '',
    }).put(
        `/partnership/ownership/chapter/actions/${action.id}`,
        { preserveScroll: true },
    );
};

const money = (value: unknown, currency: string | null | undefined) =>
    `${value ?? '—'} ${currency ?? ''}`.trim();

const scenarioEditable = computed(() => scenario.value?.status === 'draft');

const approvalLabel = computed(() => {
    const state = scenario.value?.approval?.state;
    const labels: Record<string, string> = {
        draft: t('ownership.status.draft'),
        ready_for_approval: t('ownership.status.ready'),
        approval_in_progress: t('ownership.status.inReview'),
        approved_signature_pending: t('ownership.status.signature'),
        approved_waiting_effective_date: t('ownership.status.waitingDate'),
        approved_ready_for_effect: t('ownership.status.readyEffect'),
        current_effective: t('ownership.status.current'),
    };
    return labels[state] ?? state ?? t('ownership.status.draft');
});
</script>

<template>
    <div
        data-pbr-ownership-equity-guided-journey
        class="space-y-6"
    >
        <header
            class="overflow-hidden rounded-[26px] border border-[#d6e4d9] bg-[linear-gradient(145deg,#ffffff_0%,#f4faf6_58%,#fbf7ec_100%)] p-5 shadow-[0_16px_44px_rgb(24_66_40_/_8%)] sm:p-6"
        >
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-[10px] font-black uppercase tracking-[0.2em] text-[var(--pbr-green)]">
                        {{ t('ownership.eyebrow') }}
                    </p>
                    <h2 class="mt-2 text-2xl font-black tracking-[-0.03em] text-slate-950 sm:text-3xl">
                        {{ t('ownership.title') }}
                    </h2>
                    <p class="mt-3 text-sm leading-6 text-slate-600">
                        {{ t('ownership.subtitle') }}
                    </p>
                </div>

                <div
                    class="rounded-2xl border border-[#d9e5db] bg-white/80 px-4 py-3 text-sm"
                >
                    <p class="font-black text-slate-900">
                        {{ t('ownership.officialTruth') }}
                    </p>
                    <p class="mt-1 max-w-sm leading-5 text-slate-600">
                        {{ t('ownership.officialTruthHelp') }}
                    </p>
                </div>
            </div>

            <div class="mt-6">
                <GuidedJourneyStepper
                    :steps="steps"
                    :label="t('ownership.journey')"
                    @select="chooseStep"
                />
            </div>
        </header>

        <div
            v-if="chapter.warnings?.sourceChangedReviewNeeded"
            class="rounded-2xl border border-amber-300 bg-amber-50 p-4"
        >
            <p class="font-black text-amber-950">
                {{ t('ownership.sourceChanged') }}
            </p>
            <p class="mt-1 text-sm leading-6 text-amber-900">
                {{ t('ownership.sourceChangedHelp') }}
            </p>
        </div>

        <div
            v-if="chapter.warnings?.legacyOfficialSourceBinding"
            class="rounded-2xl border border-slate-300 bg-slate-50 p-4 text-sm leading-6 text-slate-700"
        >
            {{ t('ownership.legacySource') }}
        </div>

        <section
            v-show="currentKey === 'accepted_contributions'"
            data-ownership-step="accepted_contributions"
            class="rounded-[24px] border border-slate-200 bg-white p-5 sm:p-6"
        >
            <h3 class="text-lg font-black text-slate-950">
                {{ t('ownership.acceptedTitle') }}
            </h3>
            <p class="mt-1 text-sm leading-6 text-slate-600">
                {{ t('ownership.acceptedHelp') }}
            </p>

            <div
                v-if="!chapter.source.ready"
                class="mt-5 rounded-2xl border border-amber-300 bg-amber-50 p-4"
            >
                <p class="font-black text-amber-950">
                    {{ t('ownership.sourceNotReady') }}
                </p>
                <p class="mt-1 text-sm leading-6 text-amber-900">
                    {{ t('ownership.sourceNotReadyHelp') }}
                </p>
                <Link
                    :href="chapter.routes.contributions"
                    class="mt-3 inline-flex min-h-11 items-center rounded-xl bg-slate-950 px-4 py-2 text-sm font-bold text-white"
                >
                    {{ t('ownership.backToContributions') }}
                </Link>
            </div>

            <div v-else class="mt-5 overflow-x-auto rounded-2xl border border-slate-200">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs font-black uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">{{ t('ownership.partner') }}</th>
                            <th class="px-4 py-3">{{ t('ownership.contributionType') }}</th>
                            <th class="px-4 py-3">{{ t('ownership.acceptedValue') }}</th>
                            <th class="px-4 py-3">{{ t('ownership.status') }}</th>
                            <th class="px-4 py-3">{{ t('ownership.evidenceNotes') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="row in chapter.source.rows" :key="row.key">
                            <td class="px-4 py-3 font-bold text-slate-900">{{ row.partner }}</td>
                            <td class="px-4 py-3">{{ row.type }}</td>
                            <td class="px-4 py-3">{{ money(row.acceptedValue, row.currency) }}</td>
                            <td class="px-4 py-3">{{ row.status }}</td>
                            <td class="px-4 py-3">
                                <p class="font-bold text-slate-800">
                                    {{ row.evidence?.length ?? 0 }} {{ t('ownership.evidenceItems') }}
                                </p>
                                <p class="mt-1 max-w-md text-xs leading-5 text-slate-500">
                                    {{ row.conditions ?? row.description ?? '—' }}
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <button
                v-if="chapter.source.ready"
                type="button"
                class="mt-5 min-h-11 rounded-xl bg-[var(--pbr-green)] px-4 py-2 text-sm font-black text-white"
                @click="chooseStep('share_value')"
            >
                {{ t('ownership.continue') }}
            </button>
        </section>

        <section
            v-show="currentKey === 'share_value'"
            data-ownership-step="share_value"
            class="rounded-[24px] border border-slate-200 bg-white p-5 sm:p-6"
        >
            <h3 class="text-lg font-black text-slate-950">{{ t('ownership.shareValueTitle') }}</h3>
            <p class="mt-1 text-sm leading-6 text-slate-600">{{ t('ownership.shareValueHelp') }}</p>

            <div
                v-if="scenario"
                class="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4"
            >
                <p class="text-sm font-black text-emerald-950">
                    {{ money(scenario.shareValue, scenario.currency) }} / {{ t('ownership.perShare') }}
                </p>
                <p class="mt-1 text-sm text-emerald-900">{{ t('ownership.shareValueRecorded') }}</p>
            </div>

            <form
                v-else-if="chapter.source.ready && canManage"
                class="mt-5 grid gap-4 md:grid-cols-2"
                @submit.prevent="submitScenario"
            >
                <label class="block">
                    <span class="text-sm font-black text-slate-800">{{ t('ownership.scenarioName') }}</span>
                    <input v-model="scenarioForm.name" class="mt-1 w-full rounded-xl border-slate-300" />
                </label>
                <div class="block">
                    <label
                        for="ownership-share-value"
                        class="text-sm font-black text-slate-800"
                    >
                        {{ t('ownership.shareValue') }}
                    </label>
                    <input
                        id="ownership-share-value"
                        v-model="scenarioForm.share_value"
                        inputmode="decimal"
                        placeholder="100000.00"
                        class="mt-1 w-full rounded-xl border-slate-300"
                    />
                    <span class="mt-1 block text-xs text-slate-500">
                        {{ chapter.source.currency }} — {{ t('ownership.noFx') }}
                    </span>
                </div>
                <label class="block">
                    <span class="text-sm font-black text-slate-800">{{ t('ownership.authorized') }}</span>
                    <input v-model="scenarioForm.authorized_shares" inputmode="decimal" class="mt-1 w-full rounded-xl border-slate-300" />
                </label>
                <label class="block">
                    <span class="text-sm font-black text-slate-800">{{ t('ownership.reserved') }}</span>
                    <input v-model="scenarioForm.reserved_unissued_shares" inputmode="decimal" class="mt-1 w-full rounded-xl border-slate-300" />
                </label>
                <p
                    v-if="Object.keys(scenarioForm.errors).length"
                    class="md:col-span-2 text-sm font-bold text-red-700"
                >
                    {{ Object.values(scenarioForm.errors)[0] }}
                </p>
                <button
                    type="submit"
                    :disabled="scenarioForm.processing"
                    class="md:col-span-2 min-h-11 rounded-xl bg-[var(--pbr-green)] px-4 py-2 text-sm font-black text-white disabled:opacity-50"
                >
                    {{ t('ownership.createAllocation') }}
                </button>
            </form>

            <button
                v-if="scenario"
                type="button"
                class="mt-5 min-h-11 rounded-xl bg-[var(--pbr-green)] px-4 py-2 text-sm font-black text-white"
                @click="chooseStep('allocation')"
            >
                {{ t('ownership.continue') }}
            </button>
        </section>

        <section
            v-show="currentKey === 'allocation'"
            data-ownership-step="allocation"
            class="rounded-[24px] border border-slate-200 bg-white p-5 sm:p-6"
        >
            <h3 class="text-lg font-black text-slate-950">{{ t('ownership.allocationTitle') }}</h3>
            <p class="mt-1 text-sm leading-6 text-slate-600">{{ t('ownership.allocationHelp') }}</p>

            <div v-if="scenario" class="mt-5 overflow-x-auto rounded-2xl border border-slate-200">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs font-black uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">{{ t('ownership.partner') }}</th>
                            <th class="px-4 py-3">{{ t('ownership.acceptedValue') }}</th>
                            <th class="px-4 py-3">{{ t('ownership.shares') }}</th>
                            <th class="px-4 py-3">{{ t('ownership.ownershipPercent') }}</th>
                            <th class="px-4 py-3">{{ t('ownership.shareClass') }}</th>
                            <th class="px-4 py-3">{{ t('ownership.status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="row in scenario.positions" :key="row.id">
                            <td class="px-4 py-3 font-bold">{{ row.partner }}</td>
                            <td class="px-4 py-3">{{ money(row.acceptedValue, scenario.currency) }}</td>
                            <td class="px-4 py-3 font-black">{{ row.sharesIssued }}</td>
                            <td class="px-4 py-3">{{ row.ownershipPercentage }}%</td>
                            <td class="px-4 py-3">{{ row.shareClass }}</td>
                            <td class="px-4 py-3">{{ approvalLabel }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="mt-4 rounded-xl bg-slate-50 p-3 text-xs leading-5 text-slate-600">
                {{ t('ownership.shareCountTruth') }}
            </p>

            <button
                v-if="scenario"
                type="button"
                class="mt-5 min-h-11 rounded-xl bg-[var(--pbr-green)] px-4 py-2 text-sm font-black text-white"
                @click="chooseStep('share_classes_rights')"
            >
                {{ t('ownership.continue') }}
            </button>
        </section>

        <section
            v-show="currentKey === 'share_classes_rights'"
            data-ownership-step="share_classes_rights"
            class="rounded-[24px] border border-slate-200 bg-white p-5 sm:p-6"
        >
            <h3 class="text-lg font-black text-slate-950">{{ t('ownership.rightsTitle') }}</h3>
            <p class="mt-1 text-sm leading-6 text-slate-600">{{ t('ownership.rightsHelp') }}</p>

            <div v-if="scenario" class="mt-5 space-y-5">
                <form
                    v-for="shareClass in scenario.shareClasses"
                    :key="shareClass.id"
                    class="rounded-2xl border border-slate-200 p-4"
                    @submit.prevent="saveRights(shareClass)"
                >
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="font-black text-slate-950">{{ shareClass.name }}</p>
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">
                            {{ t('ownership.classDefault') }}
                        </span>
                    </div>

                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <label class="md:col-span-2">
                            <span class="text-sm font-bold">{{ t('ownership.shareClassName') }}</span>
                            <input v-model="rightsForm(shareClass).share_class_name" class="mt-1 w-full rounded-xl border-slate-300" />
                        </label>
                        <label>
                            <span class="text-sm font-bold">{{ t('ownership.votingPerShare') }}</span>
                            <input v-model="rightsForm(shareClass).voting_right_per_share" class="mt-1 w-full rounded-xl border-slate-300" />
                        </label>
                        <label>
                            <span class="text-sm font-bold">{{ t('ownership.profitPerShare') }}</span>
                            <input v-model="rightsForm(shareClass).profit_right_per_share" class="mt-1 w-full rounded-xl border-slate-300" />
                        </label>
                        <label class="flex items-center gap-3 md:col-span-2">
                            <input v-model="rightsForm(shareClass).transfer_allowed" type="checkbox" />
                            <span class="text-sm font-bold">{{ t('ownership.transferAllowed') }}</span>
                        </label>
                        <label>
                            <span class="text-sm font-bold">{{ t('ownership.restrictions') }}</span>
                            <textarea v-model="rightsForm(shareClass).restrictions" rows="3" class="mt-1 w-full rounded-xl border-slate-300" />
                        </label>
                        <label>
                            <span class="text-sm font-bold">{{ t('ownership.specialRights') }}</span>
                            <textarea v-model="rightsForm(shareClass).special_rights" rows="3" class="mt-1 w-full rounded-xl border-slate-300" />
                        </label>
                    </div>
                    <button
                        v-if="canManage && scenarioEditable"
                        type="submit"
                        class="mt-4 min-h-11 rounded-xl bg-slate-950 px-4 py-2 text-sm font-black text-white"
                    >
                        {{ t('ownership.saveRights') }}
                    </button>
                </form>
            </div>

            <div class="mt-4 space-y-2">
                <p class="rounded-xl bg-amber-50 p-3 text-xs leading-5 text-amber-900">
                    {{ t('ownership.votingBoundary') }}
                </p>
                <p class="rounded-xl bg-slate-50 p-3 text-xs leading-5 text-slate-600">
                    {{ t('ownership.economicBoundary') }}
                </p>
            </div>

            <button
                v-if="scenario?.rightsReviewed"
                type="button"
                class="mt-5 min-h-11 rounded-xl bg-[var(--pbr-green)] px-4 py-2 text-sm font-black text-white"
                @click="chooseStep('vesting')"
            >
                {{ t('ownership.continue') }}
            </button>
        </section>

        <section
            v-show="currentKey === 'vesting'"
            data-ownership-step="vesting"
            class="rounded-[24px] border border-slate-200 bg-white p-5 sm:p-6"
        >
            <h3 class="text-lg font-black text-slate-950">{{ t('ownership.vestingTitle') }}</h3>
            <p class="mt-1 text-sm leading-6 text-slate-600">{{ t('ownership.vestingHelp') }}</p>

            <div v-if="scenario" class="mt-5 space-y-5">
                <form
                    v-for="position in scenario.positions"
                    :key="position.id"
                    class="rounded-2xl border border-slate-200 p-4"
                    @submit.prevent="saveVesting(position)"
                >
                    <p class="font-black text-slate-950">{{ position.partner }}</p>
                    <p class="mt-1 text-sm text-slate-600">
                        {{ t('ownership.sharesGranted') }}: {{ position.sharesIssued }}
                    </p>
                    <p
                        v-if="position.vestingApplies !== null"
                        class="mt-1 text-xs font-bold text-slate-500"
                    >
                        {{ t('ownership.vestedShares') }}: {{ position.sharesVested }}
                        · {{ t('ownership.unvestedShares') }}: {{ position.sharesUnvested }}
                    </p>

                    <div class="mt-4 flex flex-wrap gap-5">
                        <label class="flex items-center gap-2">
                            <input
                                v-model="vestingForm(position).vesting_applies"
                                type="radio"
                                :value="false"
                            />
                            <span class="text-sm font-bold">{{ t('ownership.noVesting') }}</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input
                                v-model="vestingForm(position).vesting_applies"
                                type="radio"
                                :value="true"
                            />
                            <span class="text-sm font-bold">{{ t('ownership.yesVesting') }}</span>
                        </label>
                    </div>

                    <div
                        v-if="vestingForm(position).vesting_applies"
                        class="mt-4 grid gap-4 md:grid-cols-2"
                    >
                        <label>
                            <span class="text-sm font-bold">{{ t('ownership.vestedShares') }}</span>
                            <input v-model="vestingForm(position).vested_shares" class="mt-1 w-full rounded-xl border-slate-300" />
                        </label>
                        <label>
                            <span class="text-sm font-bold">{{ t('ownership.vestingStart') }}</span>
                            <input v-model="vestingForm(position).vesting_start_date" type="date" class="mt-1 w-full rounded-xl border-slate-300" />
                        </label>
                        <label>
                            <span class="text-sm font-bold">{{ t('ownership.vestingPeriod') }}</span>
                            <input v-model="vestingForm(position).vesting_period_months" type="number" min="1" class="mt-1 w-full rounded-xl border-slate-300" />
                        </label>
                        <label>
                            <span class="text-sm font-bold">{{ t('ownership.cliff') }}</span>
                            <input v-model="vestingForm(position).vesting_cliff_months" type="number" min="0" class="mt-1 w-full rounded-xl border-slate-300" />
                        </label>
                        <label>
                            <span class="text-sm font-bold">{{ t('ownership.conditions') }}</span>
                            <textarea v-model="vestingForm(position).vesting_conditions" rows="3" class="mt-1 w-full rounded-xl border-slate-300" />
                        </label>
                        <label>
                            <span class="text-sm font-bold">{{ t('ownership.earlyExit') }}</span>
                            <textarea v-model="vestingForm(position).early_exit_treatment" rows="3" class="mt-1 w-full rounded-xl border-slate-300" />
                        </label>
                    </div>

                    <button
                        v-if="canManage && scenarioEditable"
                        type="submit"
                        class="mt-4 min-h-11 rounded-xl bg-slate-950 px-4 py-2 text-sm font-black text-white"
                    >
                        {{ t('ownership.saveVesting') }}
                    </button>
                </form>
            </div>

            <button
                v-if="scenario?.vestingReviewed"
                type="button"
                class="mt-5 min-h-11 rounded-xl bg-[var(--pbr-green)] px-4 py-2 text-sm font-black text-white"
                @click="chooseStep('voting_profit_rights')"
            >
                {{ t('ownership.continue') }}
            </button>
        </section>

        <section
            v-show="currentKey === 'voting_profit_rights'"
            data-ownership-step="voting_profit_rights"
            class="rounded-[24px] border border-slate-200 bg-white p-5 sm:p-6"
        >
            <h3 class="text-lg font-black text-slate-950">
                {{ t('ownership.votingProfitTitle') }}
            </h3>
            <p class="mt-1 text-sm leading-6 text-slate-600">
                {{ t('ownership.votingProfitHelp') }}
            </p>

            <div
                v-if="scenario?.positions?.length"
                class="mt-5 overflow-x-auto rounded-2xl border border-slate-200"
            >
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs font-black uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">{{ t('ownership.partner') }}</th>
                            <th class="px-4 py-3">{{ t('ownership.sharesIssued') }}</th>
                            <th class="px-4 py-3">{{ t('ownership.votingRights') }}</th>
                            <th class="px-4 py-3">{{ t('ownership.profitRights') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="position in scenario.positions" :key="position.id">
                            <td class="px-4 py-3 font-bold text-slate-900">{{ position.partner }}</td>
                            <td class="px-4 py-3">{{ position.sharesIssued }}</td>
                            <td class="px-4 py-3">{{ position.votingRights }}</td>
                            <td class="px-4 py-3">{{ position.profitRights }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-4 grid gap-2 sm:grid-cols-2">
                <p class="rounded-xl bg-amber-50 p-3 text-xs leading-5 text-amber-900">
                    {{ t('ownership.votingBoundary') }}
                </p>
                <p class="rounded-xl bg-slate-50 p-3 text-xs leading-5 text-slate-600">
                    {{ t('ownership.economicBoundary') }}
                </p>
            </div>

            <button
                v-if="scenario?.rightsReviewed && scenario?.vestingReviewed"
                type="button"
                class="mt-5 min-h-11 rounded-xl bg-[var(--pbr-green)] px-4 py-2 text-sm font-black text-white"
                @click="chooseStep('share_capacity')"
            >
                {{ t('ownership.continue') }}
            </button>
        </section>

        <section
            v-show="currentKey === 'share_capacity'"
            data-ownership-step="share_capacity"
            class="rounded-[24px] border border-slate-200 bg-white p-5 sm:p-6"
        >
            <h3 class="text-lg font-black text-slate-950">{{ t('ownership.capacityTitle') }}</h3>
            <p class="mt-1 text-sm leading-6 text-slate-600">{{ t('ownership.capacityHelp') }}</p>

            <div v-if="scenario" class="mt-5 grid gap-3 sm:grid-cols-4">
                <div class="rounded-2xl bg-slate-50 p-4">
                    <p class="text-xs font-bold text-slate-500">{{ t('ownership.authorized') }}</p>
                    <p class="mt-1 text-lg font-black">{{ scenario.capacity.authorized }}</p>
                </div>
                <div class="rounded-2xl bg-slate-50 p-4">
                    <p class="text-xs font-bold text-slate-500">{{ t('ownership.issued') }}</p>
                    <p class="mt-1 text-lg font-black">{{ scenario.capacity.issued }}</p>
                </div>
                <div class="rounded-2xl bg-slate-50 p-4">
                    <p class="text-xs font-bold text-slate-500">{{ t('ownership.reserved') }}</p>
                    <p class="mt-1 text-lg font-black">{{ scenario.capacity.reserved }}</p>
                </div>
                <div class="rounded-2xl bg-slate-50 p-4">
                    <p class="text-xs font-bold text-slate-500">{{ t('ownership.available') }}</p>
                    <p class="mt-1 text-lg font-black">{{ scenario.capacity.available }}</p>
                </div>
            </div>

            <form
                v-if="scenario && canManage && scenarioEditable"
                class="mt-5 grid gap-4 md:grid-cols-2"
                @submit.prevent="saveCapacity"
            >
                <label>
                    <span class="text-sm font-bold">{{ t('ownership.authorized') }}</span>
                    <input v-model="capacityForm.authorized_shares" class="mt-1 w-full rounded-xl border-slate-300" />
                </label>
                <label>
                    <span class="text-sm font-bold">{{ t('ownership.reserved') }}</span>
                    <input v-model="capacityForm.reserved_unissued_shares" class="mt-1 w-full rounded-xl border-slate-300" />
                </label>
                <button type="submit" class="md:col-span-2 min-h-11 rounded-xl bg-slate-950 px-4 py-2 text-sm font-black text-white">
                    {{ t('ownership.reviewCapacity') }}
                </button>
            </form>

            <button
                v-if="scenario?.capacity?.reviewed"
                type="button"
                class="mt-5 min-h-11 rounded-xl bg-[var(--pbr-green)] px-4 py-2 text-sm font-black text-white"
                @click="chooseStep('new_share_rule')"
            >
                {{ t('ownership.continue') }}
            </button>
        </section>

        <section
            v-show="currentKey === 'new_share_rule'"
            data-ownership-step="new_share_rule"
            class="rounded-[24px] border border-slate-200 bg-white p-5 sm:p-6"
        >
            <h3 class="text-lg font-black text-slate-950">{{ t('ownership.ruleTitle') }}</h3>
            <p class="mt-1 text-sm leading-6 text-slate-600">{{ t('ownership.ruleHelp') }}</p>

            <form
                v-if="scenario && canManage && scenarioEditable"
                class="mt-5 grid gap-4 md:grid-cols-2"
                @submit.prevent="saveRule"
            >
                <label class="md:col-span-2">
                    <span class="text-sm font-bold">{{ t('ownership.whoApproves') }}</span>
                    <input v-model="ruleForm.approval_rule" :placeholder="t('ownership.whoApprovesExample')" class="mt-1 w-full rounded-xl border-slate-300" />
                </label>
                <label>
                    <span class="text-sm font-bold">{{ t('ownership.threshold') }}</span>
                    <input v-model="ruleForm.approval_threshold_percent" inputmode="decimal" class="mt-1 w-full rounded-xl border-slate-300" />
                </label>
                <label class="flex items-center gap-3 pt-7">
                    <input v-model="ruleForm.preemption_right" type="checkbox" />
                    <span class="text-sm font-bold">{{ t('ownership.preemption') }}</span>
                </label>
                <label class="md:col-span-2">
                    <span class="text-sm font-bold">{{ t('ownership.valuationMethod') }}</span>
                    <input v-model="ruleForm.valuation_method" :placeholder="t('ownership.valuationExample')" class="mt-1 w-full rounded-xl border-slate-300" />
                </label>
                <label class="flex items-start gap-3 md:col-span-2 rounded-xl bg-amber-50 p-4">
                    <input v-model="ruleForm.dilution_acknowledged" type="checkbox" class="mt-1" />
                    <span class="text-sm leading-6 text-amber-950">{{ t('ownership.dilutionAck') }}</span>
                </label>
                <button type="submit" class="md:col-span-2 min-h-11 rounded-xl bg-slate-950 px-4 py-2 text-sm font-black text-white">
                    {{ t('ownership.saveRule') }}
                </button>
            </form>

            <div
                v-else-if="scenario?.issuanceRule"
                class="mt-5 rounded-2xl border border-slate-200 p-4 text-sm leading-6"
            >
                <p><strong>{{ t('ownership.whoApproves') }}:</strong> {{ scenario.issuanceRule.approvalRule }}</p>
                <p><strong>{{ t('ownership.threshold') }}:</strong> {{ scenario.issuanceRule.approvalThresholdPercent }}%</p>
                <p><strong>{{ t('ownership.preemption') }}:</strong> {{ scenario.issuanceRule.preemptionRight ? t('ownership.yes') : t('ownership.no') }}</p>
                <p><strong>{{ t('ownership.valuationMethod') }}:</strong> {{ scenario.issuanceRule.valuationMethod }}</p>
            </div>

            <p class="mt-4 rounded-xl bg-slate-50 p-3 text-xs leading-5 text-slate-600">
                {{ t('ownership.ruleBoundary') }}
            </p>

            <button
                v-if="scenario?.issuanceRuleComplete"
                type="button"
                class="mt-5 min-h-11 rounded-xl bg-[var(--pbr-green)] px-4 py-2 text-sm font-black text-white"
                @click="chooseStep('review_approve')"
            >
                {{ t('ownership.continue') }}
            </button>
        </section>

        <section
            v-show="currentKey === 'review_approve'"
            data-ownership-step="review_approve"
            class="rounded-[24px] border border-slate-200 bg-white p-5 sm:p-6"
        >
            <h3 class="text-lg font-black text-slate-950">{{ t('ownership.approvalTitle') }}</h3>
            <p class="mt-1 text-sm leading-6 text-slate-600">{{ t('ownership.approvalHelp') }}</p>

            <div v-if="scenario" class="mt-5 rounded-2xl border border-slate-200 p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="font-black text-slate-950">{{ scenario.name }}</p>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-black text-slate-700">{{ approvalLabel }}</span>
                </div>
                <p v-if="scenario.sourceStale" class="mt-3 text-sm font-bold text-amber-800">
                    {{ t('ownership.sourceChanged') }}
                </p>
            </div>

            <div v-if="scenario?.status === 'draft' && canManage" class="mt-5">
                <button
                    type="button"
                    :disabled="freezeForm.processing"
                    class="min-h-11 rounded-xl bg-slate-950 px-4 py-2 text-sm font-black text-white disabled:opacity-50"
                    @click="freezeScenario"
                >
                    {{ t('ownership.freezeReview') }}
                </button>
                <p v-if="Object.keys(freezeForm.errors).length" class="mt-2 text-sm font-bold text-red-700">
                    {{ Object.values(freezeForm.errors)[0] }}
                </p>
            </div>

            <form
                v-if="scenario?.status === 'frozen' && !scenario?.approval?.submissionId && canManage"
                class="mt-5 grid gap-4 md:grid-cols-2"
                @submit.prevent="submitGovernance"
            >
                <label>
                    <span class="text-sm font-bold">{{ t('ownership.effectiveFrom') }}</span>
                    <input v-model="governanceForm.effective_from" type="date" class="mt-1 w-full rounded-xl border-slate-300" />
                </label>
                <label>
                    <span class="text-sm font-bold">{{ t('ownership.effectiveUntil') }}</span>
                    <input v-model="governanceForm.effective_until" type="date" class="mt-1 w-full rounded-xl border-slate-300" />
                </label>
                <button type="submit" class="md:col-span-2 min-h-11 rounded-xl bg-[var(--pbr-green)] px-4 py-2 text-sm font-black text-white">
                    {{ t('ownership.submitApproval') }}
                </button>
            </form>

            <div
                v-if="scenario?.approval?.submissionId"
                class="mt-5 rounded-2xl border border-[#d6e4d9] bg-[#f4faf6] p-4"
            >
                <p class="font-black text-slate-950">{{ t('ownership.approvalRecord') }}</p>
                <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-slate-500">{{ t('ownership.status') }}</dt>
                        <dd class="font-bold">{{ approvalLabel }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">{{ t('ownership.effectiveFrom') }}</dt>
                        <dd class="font-bold">{{ scenario.approval.effectiveFrom ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">{{ t('ownership.signatureStatus') }}</dt>
                        <dd class="font-bold">
                            {{ scenario.approval.signatureRequired ? (scenario.approval.signatureStatus ?? t('ownership.pending')) : t('ownership.notRequired') }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">{{ t('ownership.approvedBy') }}</dt>
                        <dd class="font-bold">
                            {{ scenario.approval.approvedBy?.length ? scenario.approval.approvedBy.join(', ') : '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">{{ t('ownership.approvalDate') }}</dt>
                        <dd class="font-bold">{{ scenario.approval.approvedAt ?? '—' }}</dd>
                    </div>
                </dl>

                <button
                    v-if="scenario.approval.formalState === 'ready_for_review' && canManage"
                    type="button"
                    class="mt-4 min-h-11 rounded-xl bg-slate-950 px-4 py-2 text-sm font-black text-white"
                    @click="advanceContentReview('under_review')"
                >
                    {{ t('ownership.startReview') }}
                </button>

                <button
                    v-else-if="scenario.approval.formalState === 'under_review' && canManage"
                    type="button"
                    class="mt-4 min-h-11 rounded-xl bg-slate-950 px-4 py-2 text-sm font-black text-white"
                    @click="advanceContentReview('approved')"
                >
                    {{ t('ownership.confirmReview') }}
                </button>

                <Link
                    v-else-if="scenario.approval.state === 'approval_in_progress'"
                    href="/governance"
                    class="mt-4 inline-flex min-h-11 items-center rounded-xl bg-slate-950 px-4 py-2 text-sm font-black text-white"
                >
                    {{ t('ownership.openGovernance') }}
                </Link>

                <button
                    v-if="scenario.approval.state === 'approved_ready_for_effect'"
                    type="button"
                    class="mt-4 min-h-11 rounded-xl bg-[var(--pbr-green)] px-4 py-2 text-sm font-black text-white"
                    @click="effectOwnership"
                >
                    {{ t('ownership.makeCurrent') }}
                </button>
                <p
                    v-else-if="scenario.approval.state === 'approved_waiting_effective_date'"
                    class="mt-4 rounded-xl bg-slate-50 p-3 text-sm font-bold text-slate-600"
                >
                    {{ t('ownership.waitForEffectiveDate') }}
                </p>
            </div>

            <p class="mt-4 rounded-xl bg-amber-50 p-3 text-xs leading-5 text-amber-900">
                {{ t('ownership.approvalBoundary') }}
            </p>
        </section>

        <section
            v-show="currentKey === 'share_register'"
            data-ownership-step="share_register"
            class="rounded-[24px] border border-slate-200 bg-white p-5 sm:p-6"
        >
            <h3 class="text-lg font-black text-slate-950">{{ t('ownership.registerTitle') }}</h3>
            <p class="mt-1 text-sm leading-6 text-slate-600">{{ t('ownership.registerHelp') }}</p>

            <div v-if="currentRegister" class="mt-5">
                <div class="grid gap-3 sm:grid-cols-4">
                    <div class="rounded-2xl bg-emerald-50 p-4">
                        <p class="text-xs font-bold text-emerald-700">{{ t('ownership.authorized') }}</p>
                        <p class="mt-1 text-lg font-black text-emerald-950">{{ currentRegister.capacity.authorized }}</p>
                    </div>
                    <div class="rounded-2xl bg-emerald-50 p-4">
                        <p class="text-xs font-bold text-emerald-700">{{ t('ownership.issued') }}</p>
                        <p class="mt-1 text-lg font-black text-emerald-950">{{ currentRegister.capacity.issued }}</p>
                    </div>
                    <div class="rounded-2xl bg-emerald-50 p-4">
                        <p class="text-xs font-bold text-emerald-700">{{ t('ownership.reserved') }}</p>
                        <p class="mt-1 text-lg font-black text-emerald-950">{{ currentRegister.capacity.reserved }}</p>
                    </div>
                    <div class="rounded-2xl bg-emerald-50 p-4">
                        <p class="text-xs font-bold text-emerald-700">{{ t('ownership.available') }}</p>
                        <p class="mt-1 text-lg font-black text-emerald-950">{{ currentRegister.capacity.available }}</p>
                    </div>
                </div>

                <div class="mt-4 overflow-x-auto rounded-2xl border border-slate-200">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs font-black uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">{{ t('ownership.partner') }}</th>
                                <th class="px-4 py-3">{{ t('ownership.shareClass') }}</th>
                                <th class="px-4 py-3">{{ t('ownership.sharesIssued') }}</th>
                                <th class="px-4 py-3">{{ t('ownership.sharesVested') }}</th>
                                <th class="px-4 py-3">{{ t('ownership.votingRights') }}</th>
                                <th class="px-4 py-3">{{ t('ownership.profitRights') }}</th>
                                <th class="px-4 py-3">{{ t('ownership.issueDate') }}</th>
                                <th class="px-4 py-3">{{ t('ownership.status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="row in currentRegister.positions" :key="row.key">
                                <td class="px-4 py-3 font-bold">{{ row.partner }}</td>
                                <td class="px-4 py-3">{{ row.shareClass }}</td>
                                <td class="px-4 py-3">{{ row.sharesIssued }}</td>
                                <td class="px-4 py-3">{{ row.sharesVested }}</td>
                                <td class="px-4 py-3">{{ row.votingRights }}</td>
                                <td class="px-4 py-3">{{ row.profitRights }}</td>
                                <td class="px-4 py-3">{{ row.issueDate ?? '—' }}</td>
                                <td class="px-4 py-3 font-bold text-emerald-800">{{ row.status }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <p v-else class="mt-5 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">
                {{ t('ownership.noRegister') }}
            </p>

            <details
                v-if="chapter.scenarioHistory?.length || chapter.approvalHistory?.length || chapter.registerHistory?.length"
                class="mt-5 rounded-2xl border border-slate-200"
            >
                <summary class="cursor-pointer p-4 font-black text-slate-900">{{ t('ownership.history') }}</summary>
                <div class="space-y-5 border-t border-slate-200 p-4">
                    <section v-if="chapter.scenarioHistory?.length">
                        <h4 class="text-sm font-black text-slate-900">{{ t('ownership.scenarioHistory') }}</h4>
                        <div class="mt-2 space-y-2">
                            <div v-for="row in chapter.scenarioHistory" :key="row.id" class="text-sm text-slate-700">
                                <strong>{{ row.name }}</strong>
                                — {{ row.status }}
                                — {{ t('ownership.revision') }} {{ row.revision }}
                            </div>
                        </div>
                    </section>

                    <section v-if="chapter.approvalHistory?.length">
                        <h4 class="text-sm font-black text-slate-900">{{ t('ownership.approvalHistory') }}</h4>
                        <div class="mt-2 space-y-2">
                            <div v-for="row in chapter.approvalHistory" :key="row.id" class="text-sm text-slate-700">
                                <strong>{{ row.scenarioName }}</strong>
                                — {{ row.decisionOutcome ?? row.decisionStatus ?? t('ownership.pending') }}
                                — {{ row.approvalDate ?? row.createdAt }}
                            </div>
                        </div>
                    </section>

                    <section v-if="chapter.registerHistory?.length">
                        <h4 class="text-sm font-black text-slate-900">{{ t('ownership.registerHistory') }}</h4>
                        <div class="mt-2 space-y-2">
                            <div v-for="row in chapter.registerHistory" :key="row.id" class="text-sm text-slate-700">
                                <strong>#{{ row.versionNumber }}</strong>
                                — {{ row.status }}
                                — {{ row.effectiveFrom ?? '—' }}
                            </div>
                        </div>
                    </section>
                </div>
            </details>

            <button
                v-if="currentRegister"
                type="button"
                class="mt-5 min-h-11 rounded-xl bg-[var(--pbr-green)] px-4 py-2 text-sm font-black text-white"
                @click="chooseStep('decision_record')"
            >
                {{ t('ownership.continue') }}
            </button>
        </section>

        <section
            v-show="currentKey === 'decision_record'"
            data-ownership-step="decision_record"
            class="rounded-[24px] border border-slate-200 bg-white p-5 sm:p-6"
        >
            <h3 class="text-lg font-black text-slate-950">{{ t('ownership.decisionTitle') }}</h3>
            <p class="mt-1 text-sm leading-6 text-slate-600">{{ t('ownership.decisionHelp') }}</p>

            <div
                v-if="decision?.current"
                class="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4"
            >
                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-emerald-700">{{ t('ownership.owner') }}</dt><dd class="font-bold">{{ decision.current.owner }}</dd></div>
                    <div><dt class="text-emerald-700">{{ t('ownership.status') }}</dt><dd class="font-bold">{{ decision.current.status }}</dd></div>
                    <div><dt class="text-emerald-700">{{ t('ownership.effectiveFrom') }}</dt><dd class="font-bold">{{ decision.current.effectiveDate }}</dd></div>
                    <div><dt class="text-emerald-700">{{ t('ownership.reviewDate') }}</dt><dd class="font-bold">{{ decision.current.reviewDate }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-emerald-700">{{ t('ownership.decisionSummary') }}</dt><dd class="font-bold">{{ decision.current.decisionSummary }}</dd></div>
                    <div v-if="decision.current.evidenceReferences?.length" class="sm:col-span-2">
                        <dt class="text-emerald-700">{{ t('ownership.references') }}</dt>
                        <dd class="mt-1 space-y-1 font-bold">
                            <p v-for="reference in decision.current.evidenceReferences" :key="reference">{{ reference }}</p>
                        </dd>
                    </div>
                    <div><dt class="text-emerald-700">{{ t('ownership.approvedBy') }}</dt><dd class="font-bold">{{ decision.current.approvedBy.join(', ') }}</dd></div>
                    <div><dt class="text-emerald-700">{{ t('ownership.approvalDate') }}</dt><dd class="font-bold">{{ decision.current.approvalDate ?? '—' }}</dd></div>
                </dl>
            </div>

            <form
                v-else-if="decision?.available && decision?.canManage"
                class="mt-5 grid gap-4 md:grid-cols-2"
                @submit.prevent="saveDecision"
            >
                <label>
                    <span class="text-sm font-bold">{{ t('ownership.owner') }}</span>
                    <select v-model="decisionForm.decision_owner_membership_id" class="mt-1 w-full rounded-xl border-slate-300">
                        <option value="" disabled>{{ t('ownership.chooseOwner') }}</option>
                        <option v-for="owner in decision.ownerOptions" :key="owner.id" :value="owner.id">{{ owner.name }}</option>
                    </select>
                </label>
                <label>
                    <span class="text-sm font-bold">{{ t('ownership.reviewDate') }}</span>
                    <input v-model="decisionForm.review_date" type="date" class="mt-1 w-full rounded-xl border-slate-300" />
                </label>
                <label class="md:col-span-2">
                    <span class="text-sm font-bold">{{ t('ownership.decisionSummary') }}</span>
                    <textarea v-model="decisionForm.decision_summary" rows="4" class="mt-1 w-full rounded-xl border-slate-300" />
                </label>
                <label class="md:col-span-2">
                    <span class="text-sm font-bold">{{ t('ownership.references') }}</span>
                    <textarea v-model="decisionForm.evidence_references_text" rows="3" :placeholder="t('ownership.referencesHelp')" class="mt-1 w-full rounded-xl border-slate-300" />
                </label>
                <button type="submit" class="md:col-span-2 min-h-11 rounded-xl bg-[var(--pbr-green)] px-4 py-2 text-sm font-black text-white">
                    {{ t('ownership.saveDecision') }}
                </button>
            </form>

            <button
                v-if="decision?.recorded"
                type="button"
                class="mt-5 min-h-11 rounded-xl bg-[var(--pbr-green)] px-4 py-2 text-sm font-black text-white"
                @click="chooseStep('action_plan')"
            >
                {{ t('ownership.continue') }}
            </button>
        </section>

        <section
            v-show="currentKey === 'action_plan'"
            data-ownership-step="action_plan"
            class="rounded-[24px] border border-slate-200 bg-white p-5 sm:p-6"
        >
            <h3 class="text-lg font-black text-slate-950">{{ t('ownership.actionsTitle') }}</h3>
            <p class="mt-1 text-sm leading-6 text-slate-600">{{ t('ownership.actionsHelp') }}</p>

            <div v-if="actionPlan?.available" class="mt-5 space-y-4">
                <div
                    v-if="actionPlan.actions.length === 0"
                    class="rounded-2xl bg-slate-50 p-4 text-sm text-slate-600"
                >
                    {{ t('ownership.zeroActions') }}
                </div>

                <div
                    v-for="action in actionPlan.actions"
                    :key="action.id"
                    class="rounded-2xl border border-slate-200 p-4"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="font-black text-slate-950">{{ action.title }}</p>
                            <p class="mt-1 text-sm text-slate-600">{{ action.description }}</p>
                            <p class="mt-2 text-xs font-bold text-slate-500">{{ action.owner }} · {{ action.dueDate ?? '—' }}</p>
                        </div>
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold">{{ action.status }}</span>
                    </div>
                    <div v-if="action.canUpdate && !['completed','cancelled'].includes(action.status)" class="mt-3 flex flex-wrap gap-2">
                        <button type="button" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-bold" @click="statusForm(action, 'in_progress')">{{ t('ownership.inProgress') }}</button>
                        <button type="button" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-bold" @click="statusForm(action, 'completed')">{{ t('ownership.completeAction') }}</button>
                        <button type="button" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-bold" @click="statusForm(action, 'cancelled')">{{ t('ownership.cancelAction') }}</button>
                    </div>
                </div>

                <div v-if="actionPlan.canManage && actionPlan.suggestions.length" class="rounded-2xl border border-slate-200 p-4">
                    <p class="font-black">{{ t('ownership.suggestions') }}</p>
                    <div v-for="suggestion in actionPlan.suggestions" :key="suggestion.key" class="mt-3">
                        <p class="font-bold">{{ suggestion.title }}</p>
                        <p class="mt-1 text-sm text-slate-600">{{ suggestion.description }}</p>
                        <select v-model="suggestionForm(suggestion).assigned_membership_id" class="mt-2 w-full rounded-xl border-slate-300 sm:max-w-sm">
                            <option v-for="owner in actionPlan.ownerOptions" :key="owner.id" :value="owner.id">{{ owner.name }}</option>
                        </select>
                        <button type="button" class="mt-2 min-h-10 rounded-xl bg-slate-950 px-4 py-2 text-sm font-black text-white" @click="addSuggestion(suggestion)">
                            {{ t('ownership.addAction') }}
                        </button>
                    </div>
                </div>

                <details v-if="actionPlan.canManage" class="rounded-2xl border border-slate-200">
                    <summary class="cursor-pointer p-4 font-black">{{ t('ownership.customAction') }}</summary>
                    <form class="grid gap-4 border-t border-slate-200 p-4 md:grid-cols-2" @submit.prevent="saveCustomAction">
                        <label><span class="text-sm font-bold">{{ t('ownership.owner') }}</span><select v-model="actionForm.assigned_membership_id" class="mt-1 w-full rounded-xl border-slate-300"><option v-for="owner in actionPlan.ownerOptions" :key="owner.id" :value="owner.id">{{ owner.name }}</option></select></label>
                        <label><span class="text-sm font-bold">{{ t('ownership.dueDate') }}</span><input v-model="actionForm.due_date" type="date" class="mt-1 w-full rounded-xl border-slate-300" /></label>
                        <label class="md:col-span-2"><span class="text-sm font-bold">{{ t('ownership.actionTitle') }}</span><input v-model="actionForm.title" class="mt-1 w-full rounded-xl border-slate-300" /></label>
                        <label class="md:col-span-2"><span class="text-sm font-bold">{{ t('ownership.description') }}</span><textarea v-model="actionForm.description" rows="3" class="mt-1 w-full rounded-xl border-slate-300" /></label>
                        <button type="submit" class="md:col-span-2 min-h-11 rounded-xl bg-slate-950 px-4 py-2 text-sm font-black text-white">{{ t('ownership.addAction') }}</button>
                    </form>
                </details>
            </div>

            <p class="mt-4 rounded-xl bg-slate-50 p-3 text-xs leading-5 text-slate-600">
                {{ t('ownership.actionBoundary') }}
            </p>

            <button
                v-if="decision?.recorded"
                type="button"
                class="mt-5 min-h-11 rounded-xl bg-[var(--pbr-green)] px-4 py-2 text-sm font-black text-white"
                @click="chooseStep('continue_governance')"
            >
                {{ t('ownership.continue') }}
            </button>
        </section>

        <section
            v-show="currentKey === 'continue_governance'"
            data-ownership-step="continue_governance"
            class="rounded-[24px] border border-[#cfe1d3] bg-[linear-gradient(145deg,#f3f9f5_0%,#fffaf0_100%)] p-5 sm:p-6"
        >
            <h3 class="text-lg font-black text-slate-950">{{ t('ownership.completeTitle') }}</h3>
            <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">
                {{ t('ownership.completeHelp') }}
            </p>
            <div class="mt-5 flex flex-wrap gap-3">
                <Link
                    :href="chapter.routes.continue"
                    class="inline-flex min-h-11 items-center rounded-xl bg-[var(--pbr-green)] px-4 py-2 text-sm font-black text-white"
                >
                    {{ t('ownership.continueGovernance') }}
                </Link>
                <Link
                    :href="chapter.routes.tools"
                    class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-black text-slate-900"
                >
                    {{ t('ownership.openSimulator') }}
                </Link>
            </div>
        </section>
    </div>
</template>
