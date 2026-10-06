<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import GuidedJourneyStepper from './hybrid/GuidedJourneyStepper.vue';
import { useI18n } from '../i18n/useI18n';

type StepState = 'recorded' | 'current' | 'next' | 'available';
type Partner = { id: string; display_name: string; status: string };
type MemberOption = { id: string; name: string };
type Submission = {
    submissionId: string;
    phase: 'approval' | 'acceptance';
    sourceRevision: number;
    proposedAcceptedValue: string | null;
    formalState: string | null;
    decisionStatus: string | null;
    decisionOutcome: string | null;
    resolvedAt: string | null;
};
type ContributionRow = {
    id: string;
    partnerId: string;
    partnerName: string;
    type: string;
    typeLabel: string;
    status: string;
    statusLabel: string;
    terminal: boolean;
    currency: string;
    description: string;
    proposedValue: string;
    reviewedValue: string | null;
    approvedValue: string | null;
    acceptedValue: string | null;
    valuationMethod: string | null;
    conditions: string | null;
    revision: number;
    evidenceCount: number;
    verifiedEvidenceCount: number;
    deliveredTotal: string;
    governance: Submission[];
};
type Chapter = {
    setup: {
        configured: boolean;
        canManage: boolean;
        revision: number;
        setup: null | {
            valuationDate: string;
            currency: string;
            periodStart: string;
            periodEnd: string;
            valuationOwnerMembershipId: string;
            valuationOwner: string;
            approverMembershipIds: string[];
            approvers: string[];
        };
        defaults: null | {
            valuationDate: string;
            currency: string;
            periodStart: string;
            periodEnd: string;
        };
        memberOptions: MemberOption[];
    };
    register: {
        canManage: boolean;
        rows: ContributionRow[];
        counts: Record<string, number>;
        typeOptions: Array<{ key: string; label: string }>;
        intangibleSubtypes: Array<{ key: string; label: string }>;
        valuationMethods: Record<string, Array<{ key: string; label: string }>>;
    };
    acceptedRegister: {
        available: boolean;
        acceptedCount: number;
        decisionReady: boolean;
        warnings: string[];
        currencyTotals: Array<{ currency: string; total: string }>;
        matrix: Array<{
            partnerId: string;
            partnerName: string;
            currencies: Array<{
                currency: string;
                cash: string;
                timeSkill: string;
                propertyAsset: string;
                ipIntangible: string;
                total: string;
            }>;
        }>;
    };
    decisionRecord: null | {
        recorded: boolean;
        canCreate: boolean;
        stale: boolean;
        status: string;
        decisionOwnerOptions: MemberOption[];
        suggestedDecisionSummary: string;
        record: null | {
            isCurrent: boolean;
            currency: string;
            acceptedContributionCount: number;
            acceptedTotal: string;
            decisionOwner: string;
            effectiveDate: string;
            reviewDate: string;
            decisionSummary: string;
        };
    };
    actionPlan: null | {
        available: boolean;
        canManage: boolean;
        defaultOwnerMembershipId?: string | null;
        ownerOptions: MemberOption[];
        suggestions: Array<{
            key: string;
            title: string;
            description: string;
            dueDate: string | null;
        }>;
        actions: Array<{
            id: string;
            title: string;
            description: string | null;
            status: string;
            owner: string;
            dueDate: string | null;
            canUpdate: boolean;
        }>;
    };
    progress: {
        steps: Array<{ key: string; state: StepState }>;
        nextStep: string | null;
        chapterComplete: boolean;
    };
    routes: { documentVault: string; governance: string; ownership: string };
};

const props = defineProps<{
    chapter: Chapter;
    partners: Partner[];
    permissions: Record<string, boolean>;
}>();

const { uiLanguageMode } = useI18n();

const tx = (en: string, my: string): string => {
    if (uiLanguageMode.value === 'en') return en;
    if (uiLanguageMode.value === 'my') return my;
    return my + ' (' + en + ')';
};

const stepLabel = (key: string): string => ({
    setup: tx('Setup', 'အခြေခံသတ်မှတ်ချက်'),
    partners: tx('Partners', 'Partner များ'),
    contributions: tx('Contributions', 'Contribution များ'),
    valuation: tx('Valuation', 'တန်ဖိုးသတ်မှတ်ခြင်း'),
    evidence_conditions: tx('Evidence & Conditions', 'Evidence နှင့် Conditions'),
    approval: tx('Approval', 'Approval'),
    delivery: tx('Delivery', 'Delivery'),
    acceptance: tx('Acceptance', 'Acceptance'),
    matrix_register: tx('Matrix & Register', 'Matrix နှင့် Register'),
    decision_record: tx('Decision Record', 'Decision Record'),
    action_plan: tx('Action Plan', 'Action Plan'),
}[key] ?? key);

const journeySteps = computed(() =>
    props.chapter.progress.steps.map((step) => ({
        ...step,
        label: stepLabel(step.key),
    })),
);

const activeStep = ref(
    props.chapter.progress.nextStep
        ?? (props.chapter.progress.chapterComplete ? 'action_plan' : 'setup'),
);
watch(
    () => props.chapter.progress.nextStep,
    (next) => {
        if (next && !props.chapter.progress.chapterComplete) activeStep.value = next;
    },
);

const setupSource = computed(
    () => props.chapter.setup.setup ?? props.chapter.setup.defaults,
);
const setupForm = useForm({
    expected_revision: props.chapter.setup.revision,
    valuation_date: setupSource.value?.valuationDate ?? '',
    currency: setupSource.value?.currency ?? '',
    period_start: setupSource.value?.periodStart ?? '',
    period_end: setupSource.value?.periodEnd ?? '',
    valuation_owner_membership_id:
        props.chapter.setup.setup?.valuationOwnerMembershipId ?? '',
    approver_membership_ids:
        props.chapter.setup.setup?.approverMembershipIds ?? ([] as string[]),
});
watch(
    () => props.chapter.setup.revision,
    (revision) => (setupForm.expected_revision = revision),
);
const saveSetup = () =>
    setupForm.put('/partnership/contributions/setup', { preserveScroll: true });

const contributionForm = useForm({
    partner_id: '',
    contribution_type: 'cash',
    currency:
        props.chapter.setup.setup?.currency
        ?? props.chapter.setup.defaults?.currency
        ?? '',
    description: '',
    proposed_value: '',
    conditions: '',
    committed_date: '',
    due_date: '',
    amount_committed: '',
    amount_received: '',
    payment_date: '',
    role_work: '',
    hours_per_month: '',
    fair_market_rate: '',
    number_of_months: '',
    cash_compensation_received: '',
    start_date: '',
    end_date: '',
    performance_condition: '',
    vesting_rule: '',
    asset_description: '',
    asset_owner: '',
    ownership_transferred: false,
    usage_period: '',
    market_value: '',
    fair_rental_use_value: '',
    asset_valuation_method: '',
    intangible_kind: 'ip',
    intangible_description: '',
    legal_beneficial_owner: '',
    contribution_form: '',
    contribution_period: '',
    intangible_valuation_method: '',
});
const addContribution = () =>
    contributionForm.post('/partnership/contributions', {
        preserveScroll: true,
        onSuccess: () => {
            const type = contributionForm.contribution_type;
            const currency = contributionForm.currency;
            contributionForm.reset();
            contributionForm.contribution_type = type;
            contributionForm.currency = currency;
            contributionForm.intangible_kind = 'ip';
        },
    });

const reviewable = computed(() =>
    props.chapter.register.rows.filter((row) => row.status === 'proposed'),
);
const reviewForm = useForm({
    contribution_id: '',
    expected_revision: 1,
    reviewed_value: '',
    valuation_method: '',
    custom_method: '',
    note: '',
});
const selectedReview = computed(
    () => props.chapter.register.rows.find(
        (row) => row.id === reviewForm.contribution_id,
    ) ?? null,
);
watch(selectedReview, (row) => {
    if (!row) return;
    reviewForm.expected_revision = row.revision;
    reviewForm.reviewed_value = row.proposedValue;
    reviewForm.valuation_method = '';
    reviewForm.custom_method = '';
});
const valuationOptions = computed(() => {
    const type = selectedReview.value?.type;
    return type ? props.chapter.register.valuationMethods[type] ?? [] : [];
});
const submitReview = () => {
    if (!reviewForm.contribution_id) return;
    const method =
        reviewForm.valuation_method === 'custom'
            ? 'custom:' + reviewForm.custom_method.trim()
            : reviewForm.valuation_method;
    router.put(
        '/partnership/contributions/' + reviewForm.contribution_id + '/review',
        {
            expected_revision: reviewForm.expected_revision,
            reviewed_value: reviewForm.reviewed_value,
            valuation_method: method,
            note: reviewForm.note || null,
        },
        { preserveScroll: true },
    );
};

const latestGovernance = (
    row: ContributionRow,
    phase: 'approval' | 'acceptance',
): Submission | null =>
    [...row.governance].reverse().find((item) => item.phase === phase) ?? null;

const approvalCandidates = computed(() =>
    props.chapter.register.rows.filter((row) => row.status === 'reviewed'),
);
const approvalContributionId = ref('');
const acceptanceCandidates = computed(() =>
    props.chapter.register.rows.filter((row) => row.status === 'delivered'),
);
const acceptanceContributionId = ref('');
const acceptanceValue = ref('');
const submitGovernance = (
    id: string,
    phase: 'approval' | 'acceptance',
) => {
    const payload: Record<string, string> = { phase };
    if (phase === 'acceptance') payload.proposed_accepted_value = acceptanceValue.value;
    router.post(
        '/partnership/contributions/' + id + '/governance',
        payload,
        { preserveScroll: true },
    );
};
const advanceReview = (submission: Submission, target: string) =>
    router.post(
        '/partnership/contribution-submissions/'
            + submission.submissionId
            + '/content-review',
        { target },
        { preserveScroll: true },
    );
const syncDecision = (submission: Submission) =>
    router.post(
        '/partnership/contribution-submissions/'
            + submission.submissionId
            + '/sync-decision',
        {},
        { preserveScroll: true },
    );

const deliveryCandidates = computed(() =>
    props.chapter.register.rows.filter((row) =>
        ['approved', 'delivered'].includes(row.status),
    ),
);
const deliveryForm = useForm({
    contribution_id: '',
    expected_revision: 1,
    delivered_value: '',
    delivered_at: new Date().toISOString().slice(0, 10),
    delivery_extent: 'full',
    delivered_scope: '',
    adjustment_basis: '',
    notes: '',
    mark_delivered: true,
});
const selectedDelivery = computed(
    () => props.chapter.register.rows.find(
        (row) => row.id === deliveryForm.contribution_id,
    ) ?? null,
);
watch(selectedDelivery, (row) => {
    if (!row) return;
    deliveryForm.expected_revision = row.revision;
    const remaining =
        Number(row.approvedValue ?? 0) - Number(row.deliveredTotal ?? 0);
    deliveryForm.delivered_value = Math.max(remaining, 0).toFixed(2);
});
const recordDelivery = () => {
    if (!deliveryForm.contribution_id) return;
    deliveryForm.post(
        '/partnership/contributions/'
            + deliveryForm.contribution_id
            + '/delivery',
        { preserveScroll: true },
    );
};

const terminalForm = useForm({
    contribution_id: '',
    expected_revision: 1,
    target: 'cancelled',
    reason: '',
});
watch(
    () => terminalForm.contribution_id,
    (id) => {
        const row = props.chapter.register.rows.find((item) => item.id === id);
        if (row) terminalForm.expected_revision = row.revision;
    },
);
const applyTerminal = () => {
    if (!terminalForm.contribution_id) return;
    terminalForm.put(
        '/partnership/contributions/'
            + terminalForm.contribution_id
            + '/terminal',
        { preserveScroll: true },
    );
};

const decisionForm = useForm({
    decision_owner_membership_id:
        props.chapter.decisionRecord?.decisionOwnerOptions[0]?.id ?? '',
    effective_date: new Date().toISOString().slice(0, 10),
    review_date: '',
    decision_summary:
        props.chapter.decisionRecord?.suggestedDecisionSummary ?? '',
    evidence_references: [''] as string[],
});
const recordDecision = () =>
    decisionForm
        .transform((data) => ({
            ...data,
            evidence_references: data.evidence_references.filter(
                (value) => value.trim() !== '',
            ),
        }))
        .post('/partnership/contributions/decision-record', {
            preserveScroll: true,
        });

const suggestedOwner = ref(
    props.chapter.actionPlan?.defaultOwnerMembershipId ?? '',
);
const addSuggestedAction = (key: string) => {
    if (!suggestedOwner.value) return;
    router.post(
        '/partnership/contributions/actions/suggested',
        {
            suggestion_key: key,
            assigned_membership_id: suggestedOwner.value,
        },
        { preserveScroll: true },
    );
};
const customAction = useForm({
    assigned_membership_id:
        props.chapter.actionPlan?.defaultOwnerMembershipId ?? '',
    title: '',
    description: '',
    due_date: '',
});
const addCustomAction = () =>
    customAction.post('/partnership/contributions/actions/custom', {
        preserveScroll: true,
    });

const statusDraft = ref<Record<string, string>>({});
const blockedDraft = ref<Record<string, string>>({});
const updateAction = (id: string, current: string) =>
    router.put(
        '/partnership/contributions/actions/' + id,
        {
            status: statusDraft.value[id] ?? current,
            blocked_reason: blockedDraft.value[id] ?? null,
        },
        { preserveScroll: true },
    );

const tone = (status: string): string => {
    if (['accepted', 'completed'].includes(status)) {
        return 'border-emerald-200 bg-emerald-50 text-emerald-800';
    }
    if (['rejected', 'defaulted', 'blocked'].includes(status)) {
        return 'border-rose-200 bg-rose-50 text-rose-800';
    }
    if (['approved', 'delivered', 'in_progress'].includes(status)) {
        return 'border-amber-200 bg-amber-50 text-amber-800';
    }
    return 'border-slate-200 bg-slate-50 text-slate-700';
};
const acceptedTotals = computed(
    () =>
        props.chapter.acceptedRegister.currencyTotals
            .map((row) => row.total + ' ' + row.currency)
            .join(' · ') || '—',
);
</script>

<template>
    <section data-pbr-contribution-guided-journey class="space-y-5">
        <div
            class="overflow-hidden rounded-[26px] border border-[#cfddd2] bg-[linear-gradient(135deg,#0e3022_0%,#174b36_58%,#aa8430_160%)] text-white shadow-[0_18px_45px_rgb(12_45_32_/_16%)]"
        >
            <div class="grid gap-6 p-5 sm:p-6 lg:grid-cols-[minmax(0,1.6fr)_minmax(280px,0.7fr)] lg:p-7">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.2em] text-[#d9bd72]">
                        Chapter 2
                    </p>
                    <h2 class="mt-2 text-2xl font-black tracking-[-0.03em] sm:text-3xl">
                        {{ tx('Partner Contributions', 'Partner Contribution များ') }}
                    </h2>
                    <p class="mt-3 max-w-3xl text-sm leading-6 text-white/80">
                        {{
                            tx(
                                'Record each Partner contribution, value it with evidence, govern Approval and Acceptance separately, then freeze the Accepted Contribution Register before Ownership.',
                                'Partner တစ်ယောက်ချင်းစီရဲ့ Contribution ကို မှတ်တမ်းတင်၊ Evidence နဲ့ တန်ဖိုးသတ်မှတ်ပြီး Approval နဲ့ Acceptance ကို သီးခြား Governance လုပ်ပါ။ Accepted Contribution Register ပြီးမှ Ownership ကို ဆက်သွားပါ။',
                            )
                        }}
                    </p>
                    <p class="mt-4 inline-flex rounded-full border border-white/15 bg-white/10 px-3 py-1.5 text-xs font-bold text-white/85">
                        Proposed → Reviewed → Approved → Delivered → Accepted
                    </p>
                </div>
                <div class="rounded-[20px] border border-white/15 bg-white/10 p-4 backdrop-blur">
                    <p class="text-xs font-black uppercase tracking-[0.15em] text-[#dfc982]">
                        {{
                            chapter.progress.chapterComplete
                                ? tx('Chapter 2 complete', 'Chapter 2 ပြီးစီးပြီ')
                                : tx('Chapter 2 in progress', 'Chapter 2 လုပ်ဆောင်နေဆဲ')
                        }}
                    </p>
                    <p class="mt-2 text-sm leading-6 text-white/80">
                        {{
                            tx(
                                'Contribution is not Equity, Shares or Ownership. Only governed Accepted Contribution Value may feed later Ownership planning.',
                                'Contribution က Equity၊ Shares သို့မဟုတ် Ownership မဟုတ်ပါ။ Governance က Accepted လုပ်ပြီးသား Contribution Value ပဲ နောက်ပိုင်း Ownership planning မှာ သုံးနိုင်ပါတယ်။',
                            )
                        }}
                    </p>
                    <p v-if="chapter.progress.nextStep" class="mt-3 text-xs font-bold">
                        {{ tx('Next', 'နောက်တစ်ဆင့်') }}:
                        {{ stepLabel(chapter.progress.nextStep) }}
                    </p>
                </div>
            </div>
        </div>

        <GuidedJourneyStepper
            :steps="journeySteps"
            :label="tx('Partner Contribution Journey', 'Partner Contribution လုပ်ငန်းစဉ်')"
            @select="(key) => (activeStep = key)"
        />

        <section
            v-if="activeStep === 'setup'"
            data-contribution-step="setup"
            class="rounded-[24px] border border-[#d6e2d8] bg-white p-5 shadow-[0_10px_28px_rgb(16_35_26_/_4%)] sm:p-6"
        >
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]">
                01 · {{ stepLabel('setup') }}
            </p>
            <h3 class="mt-2 text-xl font-black">{{ stepLabel('setup') }}</h3>
            <p class="mt-2 max-w-4xl text-sm leading-6 text-[var(--pbr-muted)]">
                {{
                    tx(
                        'Set the valuation date, one Contribution currency, the covered period, a Valuation Owner and intended Approvers. These designations do not create Governance authority.',
                        'Valuation Date၊ Contribution Currency တစ်မျိုး၊ သတ်မှတ်ကာလ၊ Valuation တာဝန်ခံနဲ့ ရည်ရွယ်ထားတဲ့ Approver တွေကို သတ်မှတ်ပါ။ ဒီရွေးချယ်မှုက Governance Authority မပေးပါ။',
                    )
                }}
            </p>

            <form
                v-if="chapter.setup.canManage"
                class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3"
                @submit.prevent="saveSetup"
            >
                <label class="text-sm font-bold text-slate-700">
                    {{ tx('Valuation date', 'Valuation Date') }}
                    <input v-model="setupForm.valuation_date" type="date" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                </label>
                <label class="text-sm font-bold text-slate-700">
                    {{ tx('Currency', 'Currency') }}
                    <input v-model="setupForm.currency" maxlength="3" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3 uppercase" />
                </label>
                <label class="text-sm font-bold text-slate-700">
                    {{ tx('Period start', 'ကာလ စတင်ရက်') }}
                    <input v-model="setupForm.period_start" type="date" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                </label>
                <label class="text-sm font-bold text-slate-700">
                    {{ tx('Period end', 'ကာလ ပြီးဆုံးရက်') }}
                    <input v-model="setupForm.period_end" type="date" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                </label>
                <label class="text-sm font-bold text-slate-700">
                    {{ tx('Valuation owner', 'Valuation တာဝန်ခံ') }}
                    <select v-model="setupForm.valuation_owner_membership_id" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3">
                        <option value="" disabled>—</option>
                        <option v-for="member in chapter.setup.memberOptions" :key="member.id" :value="member.id">
                            {{ member.name }}
                        </option>
                    </select>
                </label>
                <fieldset>
                    <legend class="text-sm font-bold text-slate-700">
                        {{ tx('Intended approvers', 'ရည်ရွယ်ထားသော Approver များ') }}
                    </legend>
                    <div class="mt-1.5 max-h-36 space-y-2 overflow-y-auto rounded-xl border border-slate-300 p-3">
                        <label v-for="member in chapter.setup.memberOptions" :key="member.id" class="flex items-center gap-2 text-sm">
                            <input v-model="setupForm.approver_membership_ids" type="checkbox" :value="member.id" />
                            {{ member.name }}
                        </label>
                    </div>
                </fieldset>
                <p class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-900 md:col-span-2 xl:col-span-3">
                    {{
                        tx(
                            'Selecting an Approver here does not grant approval, voting, signing or system authority.',
                            'ဒီမှာ Approver အဖြစ် ရွေးထားတာက Approval၊ Vote၊ Signature သို့မဟုတ် System Authority မပေးပါ။',
                        )
                    }}
                </p>
                <p v-if="Object.keys(setupForm.errors).length" class="text-sm font-semibold text-rose-700 md:col-span-2 xl:col-span-3">
                    {{ Object.values(setupForm.errors)[0] }}
                </p>
                <button type="submit" :disabled="setupForm.processing" class="min-h-11 rounded-xl bg-[var(--pbr-green-dark)] px-5 text-sm font-black text-white disabled:opacity-50 md:w-max">
                    {{ tx('Save Contribution Setup', 'Contribution Setup သိမ်းရန်') }}
                </button>
            </form>
        </section>

        <section
            v-else-if="activeStep === 'partners'"
            data-contribution-step="partners"
            class="rounded-[24px] border border-[#d6e2d8] bg-white p-5 shadow-[0_10px_28px_rgb(16_35_26_/_4%)] sm:p-6"
        >
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]">02 · {{ stepLabel('partners') }}</p>
            <h3 class="mt-2 text-xl font-black">{{ stepLabel('partners') }}</h3>
            <p class="mt-2 max-w-4xl text-sm leading-6 text-[var(--pbr-muted)]">
                {{
                    tx(
                        'Reuse the Partner Register. Enter each Partner once and reference the same Partner throughout valuation, governance and later Ownership planning.',
                        'ရှိပြီးသား Partner Register ကို ပြန်သုံးပါ။ Partner တစ်ယောက်ကို တစ်ကြိမ်ပဲ ထည့်ပြီး Valuation၊ Governance နဲ့ နောက်ပိုင်း Ownership planning တစ်လျှောက် အဲဒီ Partner ကိုပဲ ပြန်သုံးပါတယ်။',
                    )
                }}
            </p>
            <div v-if="partners.length" class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="partner in partners" :key="partner.id" class="rounded-[18px] border border-[#dce6de] bg-[#f8faf8] p-4">
                    <p class="font-black">{{ partner.display_name }}</p>
                    <p class="mt-1 text-xs font-bold text-[var(--pbr-muted)]">{{ partner.status }}</p>
                </div>
            </div>
            <p v-else class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                {{ tx('Add a Partner in the Partner Register first.', 'အရင်ဆုံး Partner Register ထဲမှာ Partner ထည့်ပါ။') }}
            </p>
        </section>

        <section
            v-else-if="activeStep === 'contributions'"
            data-contribution-step="contributions"
            class="rounded-[24px] border border-[#d6e2d8] bg-white p-5 shadow-[0_10px_28px_rgb(16_35_26_/_4%)] sm:p-6"
        >
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]">03 · {{ stepLabel('contributions') }}</p>
            <h3 class="mt-2 text-xl font-black">{{ tx('Add Contribution', 'Contribution ထည့်ရန်') }}</h3>
            <p class="mt-2 max-w-4xl text-sm leading-6 text-[var(--pbr-muted)]">
                {{ tx('Record Cash, Time & Skill, Property / Asset, or IP / Intangible. Proposed Value is not Accepted Value.', 'Cash၊ Time & Skill၊ Property / Asset၊ IP / Intangible ကို မှတ်တမ်းတင်ပါ။ Proposed Value က Accepted Value မဟုတ်သေးပါ။') }}
            </p>
            <p class="mt-3 rounded-xl border border-[#e4d5a6] bg-[#fffaf0] p-3 text-xs leading-5 text-[#6f5b28]">
                {{ tx('Committed, Received and Accepted are separate facts. Leave Amount Received blank when nothing has actually been received.', 'Committed၊ Received နဲ့ Accepted က သီးခြားအချက်အလက်တွေပါ။ တကယ်မရသေးရင် Amount Received ကို မဖြည့်ပါနဲ့။') }}
            </p>

            <form v-if="chapter.register.canManage && partners.length" class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3" @submit.prevent="addContribution">
                <label class="text-sm font-bold text-slate-700">
                    Partner
                    <select v-model="contributionForm.partner_id" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3">
                        <option value="" disabled>—</option>
                        <option v-for="partner in partners" :key="partner.id" :value="partner.id">{{ partner.display_name }}</option>
                    </select>
                </label>
                <label class="text-sm font-bold text-slate-700">
                    {{ tx('Contribution type', 'Contribution အမျိုးအစား') }}
                    <select v-model="contributionForm.contribution_type" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3">
                        <option v-for="option in chapter.register.typeOptions" :key="option.key" :value="option.key">{{ option.label }}</option>
                    </select>
                </label>
                <label class="text-sm font-bold text-slate-700">
                    Currency
                    <input v-model="contributionForm.currency" maxlength="3" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 uppercase" />
                </label>
                <label class="text-sm font-bold text-slate-700 md:col-span-2">
                    {{ tx('Description', 'အကြောင်းအရာ') }}
                    <input v-model="contributionForm.description" required maxlength="300" class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                </label>
                <label class="text-sm font-bold text-slate-700">
                    Proposed Value
                    <input v-model="contributionForm.proposed_value" inputmode="decimal" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                </label>
                <label class="text-sm font-bold text-slate-700">
                    {{ tx('Committed date', 'ကတိပြုရက်') }}
                    <input v-model="contributionForm.committed_date" type="date" class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                </label>
                <label class="text-sm font-bold text-slate-700">
                    Due Date
                    <input v-model="contributionForm.due_date" type="date" class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                </label>
                <label class="text-sm font-bold text-slate-700 md:col-span-2 xl:col-span-3">
                    Conditions
                    <textarea v-model="contributionForm.conditions" rows="2" class="mt-1.5 w-full rounded-xl border border-slate-300 px-3 py-2" />
                </label>

                <template v-if="contributionForm.contribution_type === 'cash'">
                    <label class="text-sm font-bold text-slate-700">
                        {{ tx('Amount committed', 'ကတိပြုထားသော ပမာဏ') }}
                        <input v-model="contributionForm.amount_committed" inputmode="decimal" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                    </label>
                    <label class="text-sm font-bold text-slate-700">
                        {{ tx('Amount received', 'အမှန်တကယ် လက်ခံရရှိပြီးသော ပမာဏ') }}
                        <input v-model="contributionForm.amount_received" inputmode="decimal" class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                    </label>
                    <label class="text-sm font-bold text-slate-700">
                        Payment Date
                        <input v-model="contributionForm.payment_date" type="date" class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                    </label>
                </template>

                <template v-else-if="contributionForm.contribution_type === 'time_skill'">
                    <label class="text-sm font-bold text-slate-700 md:col-span-2">
                        {{ tx('Role / work', 'Role / လုပ်ငန်း') }}
                        <input v-model="contributionForm.role_work" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                    </label>
                    <label class="text-sm font-bold text-slate-700">
                        {{ tx('Hours per month', 'တစ်လ အလုပ်ချိန်') }}
                        <input v-model="contributionForm.hours_per_month" inputmode="decimal" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                    </label>
                    <label class="text-sm font-bold text-slate-700">
                        {{ tx('Fair market hourly rate', 'သင့်တော်သော Hourly Rate') }}
                        <input v-model="contributionForm.fair_market_rate" inputmode="decimal" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                    </label>
                    <label class="text-sm font-bold text-slate-700">
                        {{ tx('Number of months', 'လအရေအတွက်') }}
                        <input v-model="contributionForm.number_of_months" type="number" min="1" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                    </label>
                    <label class="text-sm font-bold text-slate-700">
                        {{ tx('Cash compensation received', 'ရရှိပြီးသား Cash Compensation') }}
                        <input v-model="contributionForm.cash_compensation_received" inputmode="decimal" class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                    </label>
                    <label class="text-sm font-bold text-slate-700">
                        Start Date
                        <input v-model="contributionForm.start_date" type="date" class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                    </label>
                    <label class="text-sm font-bold text-slate-700">
                        End Date
                        <input v-model="contributionForm.end_date" type="date" class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                    </label>
                    <label class="text-sm font-bold text-slate-700 md:col-span-2">
                        Performance Condition
                        <textarea v-model="contributionForm.performance_condition" rows="2" class="mt-1.5 w-full rounded-xl border border-slate-300 px-3 py-2" />
                    </label>
                    <label class="text-sm font-bold text-slate-700 md:col-span-2">
                        Contribution Vesting Condition
                        <textarea v-model="contributionForm.vesting_rule" rows="2" class="mt-1.5 w-full rounded-xl border border-slate-300 px-3 py-2" />
                        <span class="mt-1 block text-xs font-normal text-amber-700">
                            {{ tx('This is Contribution vesting only, not Share vesting.', 'ဒါက Contribution Vesting ပဲဖြစ်ပြီး Share Vesting မဟုတ်ပါ။') }}
                        </span>
                    </label>
                </template>

                <template v-else-if="contributionForm.contribution_type === 'property_asset'">
                    <label class="text-sm font-bold text-slate-700 md:col-span-2">
                        {{ tx('Asset description', 'Asset အကြောင်းအရာ') }}
                        <input v-model="contributionForm.asset_description" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                    </label>
                    <label class="text-sm font-bold text-slate-700">
                        {{ tx('Asset owner', 'Asset ပိုင်ရှင်') }}
                        <input v-model="contributionForm.asset_owner" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                    </label>
                    <label class="flex min-h-11 items-center gap-2 text-sm font-bold text-slate-700">
                        <input v-model="contributionForm.ownership_transferred" type="checkbox" />
                        {{ tx('Transfer asset ownership to Business', 'Asset Ownership ကို Business ထံ လွှဲမည်') }}
                    </label>
                    <label v-if="contributionForm.ownership_transferred" class="text-sm font-bold text-slate-700">
                        Market Value
                        <input v-model="contributionForm.market_value" inputmode="decimal" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                    </label>
                    <template v-else>
                        <label class="text-sm font-bold text-slate-700">
                            {{ tx('Right-to-use period', 'အသုံးပြုခွင့်ကာလ') }}
                            <input v-model="contributionForm.usage_period" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                        </label>
                        <label class="text-sm font-bold text-slate-700">
                            Fair Rental / Use Value
                            <input v-model="contributionForm.fair_rental_use_value" inputmode="decimal" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                        </label>
                    </template>
                </template>

                <template v-else>
                    <label class="text-sm font-bold text-slate-700">
                        IP / Intangible subtype
                        <select v-model="contributionForm.intangible_kind" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3">
                            <option v-for="option in chapter.register.intangibleSubtypes" :key="option.key" :value="option.key">{{ option.label }}</option>
                        </select>
                    </label>
                    <label class="text-sm font-bold text-slate-700">
                        Legal / Beneficial Owner
                        <input v-model="contributionForm.legal_beneficial_owner" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                    </label>
                    <label class="text-sm font-bold text-slate-700">
                        Contribution Form
                        <input v-model="contributionForm.contribution_form" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                    </label>
                    <label class="text-sm font-bold text-slate-700 md:col-span-2">
                        {{ tx('Intangible description', 'Intangible အကြောင်းအရာ') }}
                        <textarea v-model="contributionForm.intangible_description" rows="2" required class="mt-1.5 w-full rounded-xl border border-slate-300 px-3 py-2" />
                    </label>
                    <label class="text-sm font-bold text-slate-700">
                        Contribution Period
                        <input v-model="contributionForm.contribution_period" class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                    </label>
                    <label class="text-sm font-bold text-slate-700 md:col-span-2">
                        Valuation Method
                        <input v-model="contributionForm.intangible_valuation_method" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                    </label>
                </template>

                <p v-if="Object.keys(contributionForm.errors).length" class="text-sm font-semibold text-rose-700 md:col-span-2 xl:col-span-3">
                    {{ Object.values(contributionForm.errors)[0] }}
                </p>
                <button type="submit" :disabled="contributionForm.processing" class="min-h-11 rounded-xl bg-[var(--pbr-green-dark)] px-5 text-sm font-black text-white disabled:opacity-50 md:w-max">
                    {{ tx('Add Contribution', 'Contribution ထည့်ရန်') }}
                </button>
            </form>
        </section>

        <section
            v-else-if="activeStep === 'valuation'"
            data-contribution-step="valuation"
            class="rounded-[24px] border border-[#d6e2d8] bg-white p-5 shadow-[0_10px_28px_rgb(16_35_26_/_4%)] sm:p-6"
        >
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]">04 · {{ stepLabel('valuation') }}</p>
            <h3 class="mt-2 text-xl font-black">{{ tx('Review Contribution Value', 'Contribution Value ကို Review လုပ်ရန်') }}</h3>
            <p class="mt-2 max-w-4xl text-sm leading-6 text-[var(--pbr-muted)]">
                {{ tx('Review the proposed contribution and record the method used. Review is not Approval.', 'Proposed Contribution ကို ပြန်စစ်ပြီး အသုံးပြုထားတဲ့ Method ကို မှတ်တမ်းတင်ပါ။ Review က Approval မဟုတ်ပါ။') }}
            </p>

            <form v-if="chapter.register.canManage && reviewable.length" class="mt-6 grid gap-4 md:grid-cols-2" @submit.prevent="submitReview">
                <label class="text-sm font-bold text-slate-700 md:col-span-2">
                    Contribution
                    <select v-model="reviewForm.contribution_id" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3">
                        <option value="" disabled>—</option>
                        <option v-for="row in reviewable" :key="row.id" :value="row.id">
                            {{ row.partnerName }} · {{ row.typeLabel }} · {{ row.description }} · {{ row.proposedValue }} {{ row.currency }}
                        </option>
                    </select>
                </label>
                <label class="text-sm font-bold text-slate-700">
                    Reviewed Value
                    <input v-model="reviewForm.reviewed_value" inputmode="decimal" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                </label>
                <label class="text-sm font-bold text-slate-700">
                    Valuation Method
                    <select v-model="reviewForm.valuation_method" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3">
                        <option value="" disabled>—</option>
                        <option v-for="option in valuationOptions" :key="option.key" :value="option.key">{{ option.label }}</option>
                    </select>
                </label>
                <label v-if="reviewForm.valuation_method === 'custom'" class="text-sm font-bold text-slate-700 md:col-span-2">
                    {{ tx('Custom documented method', 'အခြား မှတ်တမ်းတင်ထားသော Method') }}
                    <input v-model="reviewForm.custom_method" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                </label>
                <label class="text-sm font-bold text-slate-700 md:col-span-2">
                    {{ tx('Review note', 'Review မှတ်ချက်') }}
                    <textarea v-model="reviewForm.note" rows="2" class="mt-1.5 w-full rounded-xl border border-slate-300 px-3 py-2" />
                </label>
                <button type="submit" class="min-h-11 rounded-xl bg-[var(--pbr-green-dark)] px-5 text-sm font-black text-white md:w-max">
                    {{ tx('Record Review', 'Review မှတ်တမ်းတင်ရန်') }}
                </button>
            </form>
            <p v-else class="mt-5 rounded-xl bg-[#f6f8f6] p-4 text-sm text-[var(--pbr-muted)]">
                {{ tx('No Proposed Contribution is waiting for review.', 'Review စောင့်နေတဲ့ Proposed Contribution မရှိပါ။') }}
            </p>
        </section>

        <section
            v-else-if="activeStep === 'evidence_conditions'"
            data-contribution-step="evidence"
            class="rounded-[24px] border border-[#d6e2d8] bg-white p-5 shadow-[0_10px_28px_rgb(16_35_26_/_4%)] sm:p-6"
        >
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]">05 · {{ stepLabel('evidence_conditions') }}</p>
            <h3 class="mt-2 text-xl font-black">{{ stepLabel('evidence_conditions') }}</h3>
            <p class="mt-2 max-w-4xl text-sm leading-6 text-[var(--pbr-muted)]">
                {{ tx('Use the existing Document Vault. Link Evidence to the Contribution; do not upload a second copy here.', 'ရှိပြီးသား Document Vault ကိုပဲ သုံးပါ။ Evidence ကို Contribution နဲ့ ချိတ်ပါ။ ဒီနေရာမှာ File System အသစ် ထပ်မဖန်တီးပါ။') }}
            </p>
            <div class="mt-5 space-y-3">
                <div v-for="row in chapter.register.rows" :key="row.id" class="flex flex-col gap-3 rounded-[18px] border border-[#dde7df] bg-[#f8faf8] p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="font-black">{{ row.partnerName }} · {{ row.description }}</p>
                        <p class="mt-1 text-xs text-[var(--pbr-muted)]">
                            Evidence: {{ row.evidenceCount }} · Verified: {{ row.verifiedEvidenceCount }}
                        </p>
                        <p v-if="row.conditions" class="mt-2 text-sm">Conditions: {{ row.conditions }}</p>
                    </div>
                    <Link :href="chapter.routes.documentVault" class="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 text-sm font-black">
                        {{ tx('Open Document Vault', 'Document Vault ဖွင့်ရန်') }}
                    </Link>
                </div>
            </div>
        </section>

        <section
            v-else-if="activeStep === 'approval'"
            data-contribution-step="approval"
            class="rounded-[24px] border border-[#d6e2d8] bg-white p-5 shadow-[0_10px_28px_rgb(16_35_26_/_4%)] sm:p-6"
        >
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]">06 · Approval</p>
            <h3 class="mt-2 text-xl font-black">Approval</h3>
            <p class="mt-2 max-w-4xl text-sm leading-6 text-[var(--pbr-muted)]">
                {{ tx('Freeze the Reviewed Contribution and send that exact version into Governance. Approval does not mean Delivered or Accepted.', 'Reviewed Contribution ကို freeze လုပ်ပြီး အတိအကျ အဲဒီ Version ကို Governance ထဲ ပို့ပါ။ Approval လုပ်ပြီးတာက Delivered သို့မဟုတ် Accepted ဖြစ်ပြီလို့ မဆိုလိုပါ။') }}
            </p>
            <div v-if="chapter.register.canManage && approvalCandidates.length" class="mt-6 flex flex-col gap-3 sm:flex-row">
                <select v-model="approvalContributionId" class="min-h-11 flex-1 rounded-xl border border-slate-300 px-3">
                    <option value="" disabled>—</option>
                    <option v-for="row in approvalCandidates" :key="row.id" :value="row.id">
                        {{ row.partnerName }} · {{ row.description }} · {{ row.reviewedValue }} {{ row.currency }}
                    </option>
                </select>
                <button type="button" :disabled="!approvalContributionId" class="min-h-11 rounded-xl bg-[var(--pbr-green-dark)] px-5 text-sm font-black text-white disabled:opacity-40" @click="submitGovernance(approvalContributionId, 'approval')">
                    {{ tx('Prepare Approval', 'Approval အတွက် ပြင်ဆင်ရန်') }}
                </button>
            </div>

            <div class="mt-6 space-y-3">
                <template v-for="row in chapter.register.rows" :key="row.id">
                    <div v-if="latestGovernance(row, 'approval')" class="rounded-[18px] border border-[#dce6de] p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="font-black">{{ row.partnerName }} · {{ row.description }}</p>
                                <p class="mt-1 text-xs text-[var(--pbr-muted)]">
                                    Content: {{ latestGovernance(row, 'approval')?.formalState ?? '—' }}
                                    · Decision: {{ latestGovernance(row, 'approval')?.decisionOutcome ?? 'Pending' }}
                                </p>
                            </div>
                            <span class="rounded-full border px-2.5 py-1 text-xs font-black" :class="tone(row.status)">
                                {{ row.statusLabel }}
                            </span>
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <button v-if="latestGovernance(row, 'approval')?.formalState === 'ready_for_review'" type="button" class="min-h-10 rounded-xl border border-slate-300 px-4 text-xs font-black" @click="advanceReview(latestGovernance(row, 'approval')!, 'under_review')">
                                {{ tx('Start content review', 'Content Review စရန်') }}
                            </button>
                            <button v-if="latestGovernance(row, 'approval')?.formalState === 'under_review'" type="button" class="min-h-10 rounded-xl border border-slate-300 px-4 text-xs font-black" @click="advanceReview(latestGovernance(row, 'approval')!, 'approved')">
                                {{ tx('Approve content', 'Content ကို Approve လုပ်ရန်') }}
                            </button>
                            <Link v-if="latestGovernance(row, 'approval')?.formalState === 'approved'" :href="chapter.routes.governance" class="inline-flex min-h-10 items-center rounded-xl bg-slate-950 px-4 text-xs font-black text-white">
                                {{ tx('Open Governance', 'Governance ဖွင့်ရန်') }}
                            </Link>
                            <button v-if="latestGovernance(row, 'approval')?.decisionOutcome === 'approved' && row.status === 'reviewed'" type="button" class="min-h-10 rounded-xl bg-[var(--pbr-green-dark)] px-4 text-xs font-black text-white" @click="syncDecision(latestGovernance(row, 'approval')!)">
                                {{ tx('Sync governed decision', 'Governance Decision ချိတ်ရန်') }}
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </section>

        <section
            v-else-if="activeStep === 'delivery'"
            data-contribution-step="delivery"
            class="rounded-[24px] border border-[#d6e2d8] bg-white p-5 shadow-[0_10px_28px_rgb(16_35_26_/_4%)] sm:p-6"
        >
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]">07 · Delivery</p>
            <h3 class="mt-2 text-xl font-black">{{ tx('Record Delivery', 'Delivery မှတ်တမ်းတင်ရန်') }}</h3>
            <p class="mt-2 max-w-4xl text-sm leading-6 text-[var(--pbr-muted)]">
                {{ tx('Record what was actually delivered. Partial delivery requires explicit scope and adjustment basis; the system never prorates automatically.', 'တကယ်ပေးအပ်ပြီးတာကို မှတ်တမ်းတင်ပါ။ Partial Delivery ဖြစ်ရင် Scope နဲ့ Adjustment Basis တိတိကျကျ လိုပြီး System က အလိုအလျောက် prorate မလုပ်ပါ။') }}
            </p>
            <form v-if="chapter.register.canManage && deliveryCandidates.length" class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3" @submit.prevent="recordDelivery">
                <label class="text-sm font-bold text-slate-700 md:col-span-2 xl:col-span-3">
                    Contribution
                    <select v-model="deliveryForm.contribution_id" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3">
                        <option value="" disabled>—</option>
                        <option v-for="row in deliveryCandidates" :key="row.id" :value="row.id">
                            {{ row.partnerName }} · {{ row.description }} · Approved {{ row.approvedValue }} {{ row.currency }} · Delivered {{ row.deliveredTotal }}
                        </option>
                    </select>
                </label>
                <label class="text-sm font-bold text-slate-700">
                    Delivered Value
                    <input v-model="deliveryForm.delivered_value" inputmode="decimal" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                </label>
                <label class="text-sm font-bold text-slate-700">
                    Delivered Date
                    <input v-model="deliveryForm.delivered_at" type="date" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                </label>
                <label class="text-sm font-bold text-slate-700">
                    {{ tx('Delivery extent', 'Delivery အတိုင်းအတာ') }}
                    <select v-model="deliveryForm.delivery_extent" class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3">
                        <option value="full">{{ tx('Full', 'အပြည့်') }}</option>
                        <option value="partial">{{ tx('Partial', 'တစ်စိတ်တစ်ပိုင်း') }}</option>
                    </select>
                </label>
                <template v-if="deliveryForm.delivery_extent === 'partial'">
                    <label class="text-sm font-bold text-slate-700 md:col-span-2">
                        {{ tx('Delivered scope', 'ပေးအပ်ပြီးသော Scope') }}
                        <textarea v-model="deliveryForm.delivered_scope" required rows="2" class="mt-1.5 w-full rounded-xl border border-slate-300 px-3 py-2" />
                    </label>
                    <label class="text-sm font-bold text-slate-700">
                        Adjustment Basis
                        <textarea v-model="deliveryForm.adjustment_basis" required rows="2" class="mt-1.5 w-full rounded-xl border border-slate-300 px-3 py-2" />
                    </label>
                </template>
                <label class="flex items-center gap-2 text-sm font-bold text-slate-700 md:col-span-2 xl:col-span-3">
                    <input v-model="deliveryForm.mark_delivered" type="checkbox" />
                    {{ tx('Mark as fully Delivered', 'အပြည့်အဝ Delivered အဖြစ် မှတ်ရန်') }}
                </label>
                <button type="submit" class="min-h-11 rounded-xl bg-[var(--pbr-green-dark)] px-5 text-sm font-black text-white md:w-max">
                    {{ tx('Record Delivery', 'Delivery မှတ်တမ်းတင်ရန်') }}
                </button>
            </form>
        </section>

        <section
            v-else-if="activeStep === 'acceptance'"
            data-contribution-step="acceptance"
            class="rounded-[24px] border border-[#d6e2d8] bg-white p-5 shadow-[0_10px_28px_rgb(16_35_26_/_4%)] sm:p-6"
        >
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]">08 · Acceptance</p>
            <h3 class="mt-2 text-xl font-black">Acceptance</h3>
            <p class="mt-2 max-w-4xl text-sm leading-6 text-[var(--pbr-muted)]">
                {{ tx('Governance separately accepts the final delivered Contribution Value. Only Accepted Value can feed later Ownership planning.', 'Delivery ပြီးမှ Governance က နောက်ဆုံး Contribution Value ကို သီးခြား Acceptance လုပ်ပါတယ်။ Accepted Value ပဲ နောက်ပိုင်း Ownership planning ဆီ သွားနိုင်ပါတယ်။') }}
            </p>

            <div v-if="chapter.register.canManage && acceptanceCandidates.length" class="mt-6 grid gap-3 md:grid-cols-[1fr_220px_auto]">
                <select v-model="acceptanceContributionId" class="min-h-11 rounded-xl border border-slate-300 px-3">
                    <option value="" disabled>—</option>
                    <option v-for="row in acceptanceCandidates" :key="row.id" :value="row.id">
                        {{ row.partnerName }} · {{ row.description }} · Delivered {{ row.deliveredTotal }} {{ row.currency }}
                    </option>
                </select>
                <input v-model="acceptanceValue" placeholder="Accepted Value" inputmode="decimal" class="min-h-11 rounded-xl border border-slate-300 px-3" />
                <button type="button" :disabled="!acceptanceContributionId || !acceptanceValue" class="min-h-11 rounded-xl bg-[var(--pbr-green-dark)] px-5 text-sm font-black text-white disabled:opacity-40" @click="submitGovernance(acceptanceContributionId, 'acceptance')">
                    {{ tx('Prepare Acceptance', 'Acceptance အတွက် ပြင်ဆင်ရန်') }}
                </button>
            </div>

            <div class="mt-6 space-y-3">
                <template v-for="row in chapter.register.rows" :key="row.id">
                    <div v-if="latestGovernance(row, 'acceptance')" class="rounded-[18px] border border-[#dce6de] p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="font-black">{{ row.partnerName }} · {{ row.description }}</p>
                                <p class="mt-1 text-xs text-[var(--pbr-muted)]">
                                    Proposed Accepted: {{ latestGovernance(row, 'acceptance')?.proposedAcceptedValue }} {{ row.currency }}
                                    · Content: {{ latestGovernance(row, 'acceptance')?.formalState ?? '—' }}
                                </p>
                            </div>
                            <span class="rounded-full border px-2.5 py-1 text-xs font-black" :class="tone(row.status)">{{ row.statusLabel }}</span>
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <button v-if="latestGovernance(row, 'acceptance')?.formalState === 'ready_for_review'" type="button" class="min-h-10 rounded-xl border border-slate-300 px-4 text-xs font-black" @click="advanceReview(latestGovernance(row, 'acceptance')!, 'under_review')">
                                {{ tx('Start content review', 'Content Review စရန်') }}
                            </button>
                            <button v-if="latestGovernance(row, 'acceptance')?.formalState === 'under_review'" type="button" class="min-h-10 rounded-xl border border-slate-300 px-4 text-xs font-black" @click="advanceReview(latestGovernance(row, 'acceptance')!, 'approved')">
                                {{ tx('Approve content', 'Content ကို Approve လုပ်ရန်') }}
                            </button>
                            <Link v-if="latestGovernance(row, 'acceptance')?.formalState === 'approved'" :href="chapter.routes.governance" class="inline-flex min-h-10 items-center rounded-xl bg-slate-950 px-4 text-xs font-black text-white">
                                {{ tx('Open Governance', 'Governance ဖွင့်ရန်') }}
                            </Link>
                            <button v-if="latestGovernance(row, 'acceptance')?.decisionOutcome === 'approved' && row.status === 'delivered'" type="button" class="min-h-10 rounded-xl bg-[var(--pbr-green-dark)] px-4 text-xs font-black text-white" @click="syncDecision(latestGovernance(row, 'acceptance')!)">
                                {{ tx('Sync governed decision', 'Governance Decision ချိတ်ရန်') }}
                            </button>
                        </div>
                    </div>
                </template>
            </div>

            <details v-if="chapter.register.canManage" class="mt-6 rounded-[18px] border border-slate-200 bg-slate-50 p-4">
                <summary class="cursor-pointer text-sm font-black text-slate-700">
                    {{ tx('Reject / Cancel / Default', 'Rejected / Cancelled / Defaulted ပြောင်းရန်') }}
                </summary>
                <form class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4" @submit.prevent="applyTerminal">
                    <select v-model="terminalForm.contribution_id" required class="min-h-11 rounded-xl border border-slate-300 px-3">
                        <option value="" disabled>—</option>
                        <option v-for="row in chapter.register.rows.filter((item) => !item.terminal)" :key="row.id" :value="row.id">
                            {{ row.partnerName }} · {{ row.description }} · {{ row.statusLabel }}
                        </option>
                    </select>
                    <select v-model="terminalForm.target" class="min-h-11 rounded-xl border border-slate-300 px-3">
                        <option value="rejected">Rejected</option>
                        <option value="cancelled">Cancelled</option>
                        <option value="defaulted">Defaulted</option>
                    </select>
                    <input v-model="terminalForm.reason" :placeholder="tx('Reason', 'အကြောင်းပြချက်')" required class="min-h-11 rounded-xl border border-slate-300 px-3" />
                    <button type="submit" class="min-h-11 rounded-xl border border-rose-300 bg-white px-4 text-sm font-black text-rose-700">
                        {{ tx('Apply status', 'Status ပြောင်းရန်') }}
                    </button>
                </form>
            </details>
        </section>

        <section
            v-else-if="activeStep === 'matrix_register'"
            data-contribution-step="register"
            class="rounded-[24px] border border-[#d6e2d8] bg-white p-5 shadow-[0_10px_28px_rgb(16_35_26_/_4%)] sm:p-6"
        >
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]">09 · {{ stepLabel('matrix_register') }}</p>
            <h3 class="mt-2 text-xl font-black">Accepted Contribution Register</h3>
            <p class="mt-2 max-w-4xl text-sm leading-6 text-[var(--pbr-muted)]">
                {{ tx('This matrix is calculated on the server from governed Accepted Contributions only. No FX conversion is inferred.', 'ဒီ Matrix ကို Server က Governance Accepted Contribution တွေကနေပဲ တွက်ထားပါတယ်။ FX Conversion ကို အလိုအလျောက် မခန့်မှန်းပါ။') }}
            </p>

            <p v-for="warning in chapter.acceptedRegister.warnings" :key="warning" class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs font-semibold text-amber-900">
                {{ warning }}
            </p>

            <div v-if="chapter.acceptedRegister.matrix.length" class="mt-6 overflow-x-auto rounded-[18px] border border-[#dce6de]">
                <table class="min-w-[820px] w-full text-left text-sm">
                    <thead class="bg-[#f5f8f5] text-slate-600">
                        <tr>
                            <th class="px-4 py-3">Partner</th>
                            <th class="px-4 py-3">Currency</th>
                            <th class="px-4 py-3">Cash</th>
                            <th class="px-4 py-3">Time & Skill</th>
                            <th class="px-4 py-3">Property / Asset</th>
                            <th class="px-4 py-3">IP / Intangible</th>
                            <th class="px-4 py-3">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#e2e9e3]">
                        <template v-for="partner in chapter.acceptedRegister.matrix" :key="partner.partnerId">
                            <tr v-for="currency in partner.currencies" :key="partner.partnerId + '-' + currency.currency">
                                <td class="px-4 py-3 font-black">{{ partner.partnerName }}</td>
                                <td class="px-4 py-3">{{ currency.currency }}</td>
                                <td class="px-4 py-3">{{ currency.cash }}</td>
                                <td class="px-4 py-3">{{ currency.timeSkill }}</td>
                                <td class="px-4 py-3">{{ currency.propertyAsset }}</td>
                                <td class="px-4 py-3">{{ currency.ipIntangible }}</td>
                                <td class="px-4 py-3 font-black text-[var(--pbr-green-dark)]">{{ currency.total }}</td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <p v-else class="mt-5 rounded-xl bg-[#f6f8f6] p-4 text-sm text-[var(--pbr-muted)]">
                {{ tx('No governed Accepted Contribution yet.', 'Governance Accepted Contribution မရှိသေးပါ။') }}
            </p>

            <div class="mt-5 rounded-[18px] border border-[#dce6de] bg-[#f8faf8] p-4">
                <p class="text-xs font-bold text-[var(--pbr-muted)]">Accepted Total</p>
                <p class="mt-1 text-xl font-black text-[var(--pbr-green-dark)]">{{ acceptedTotals }}</p>
            </div>

            <div class="mt-7 space-y-3">
                <h4 class="font-black">{{ tx('Contribution Register', 'Contribution Register') }}</h4>
                <div v-for="row in chapter.register.rows" :key="row.id" class="rounded-[18px] border border-[#dde7df] p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="font-black">{{ row.partnerName }} · {{ row.typeLabel }}</p>
                            <p class="mt-1 text-sm text-slate-700">{{ row.description }}</p>
                        </div>
                        <span class="rounded-full border px-2.5 py-1 text-xs font-black" :class="tone(row.status)">
                            {{ row.statusLabel }}
                        </span>
                    </div>
                    <div class="mt-4 grid gap-2 text-xs sm:grid-cols-5">
                        <div class="rounded-lg bg-slate-50 p-2">Proposed<br /><strong>{{ row.proposedValue }} {{ row.currency }}</strong></div>
                        <div class="rounded-lg bg-slate-50 p-2">Reviewed<br /><strong>{{ row.reviewedValue ?? '—' }}</strong></div>
                        <div class="rounded-lg bg-slate-50 p-2">Approved<br /><strong>{{ row.approvedValue ?? '—' }}</strong></div>
                        <div class="rounded-lg bg-slate-50 p-2">Delivered<br /><strong>{{ row.deliveredTotal }}</strong></div>
                        <div class="rounded-lg bg-emerald-50 p-2 text-emerald-800">Accepted<br /><strong>{{ row.acceptedValue ?? '—' }}</strong></div>
                    </div>
                </div>
            </div>
        </section>

        <section
            v-else-if="activeStep === 'decision_record'"
            data-contribution-step="decision-record"
            class="rounded-[24px] border border-[#d6e2d8] bg-white p-5 shadow-[0_10px_28px_rgb(16_35_26_/_4%)] sm:p-6"
        >
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]">10 · Decision Record</p>
            <h3 class="mt-2 text-xl font-black">Contribution Decision Record</h3>
            <p class="mt-2 max-w-4xl text-sm leading-6 text-[var(--pbr-muted)]">
                {{ tx('Freeze the current Accepted Contribution Register as the chapter decision source. This record does not create Signature, Effective lifecycle state, Equity or Ownership.', 'လက်ရှိ Accepted Contribution Register ကို Chapter Decision Source အဖြစ် မှတ်တမ်းတင်ပါ။ ဒီ Record က Signature၊ Effective Lifecycle State၊ Equity သို့မဟုတ် Ownership ကို မဖန်တီးပါ။') }}
            </p>

            <p v-if="chapter.decisionRecord?.stale" class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm font-semibold text-amber-900">
                {{ tx('The Accepted Register changed. The prior Decision is historical; record a new current Decision.', 'Accepted Register ပြောင်းသွားပါပြီ။ အရင် Decision က Historical ဖြစ်သွားပြီး Current Decision အသစ် မှတ်တမ်းတင်ရပါမယ်။') }}
            </p>

            <div v-if="chapter.decisionRecord?.record" class="mt-5 rounded-[20px] border border-[#cfe2d3] bg-[#f4f9f5] p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-bold text-[var(--pbr-muted)]">{{ tx('Recorded Decision', 'မှတ်တမ်းတင်ထားသော Decision') }}</p>
                        <p class="mt-1 text-lg font-black">{{ chapter.decisionRecord.record.acceptedTotal }} {{ chapter.decisionRecord.record.currency }}</p>
                    </div>
                    <span class="rounded-full border px-2.5 py-1 text-xs font-black" :class="chapter.decisionRecord.record.isCurrent ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-800'">
                        {{ chapter.decisionRecord.record.isCurrent ? tx('Current', 'လက်ရှိ') : 'Historical' }}
                    </span>
                </div>
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                    <div><dt class="text-xs text-slate-500">Decision Owner</dt><dd class="mt-1 font-black">{{ chapter.decisionRecord.record.decisionOwner }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Effective Date</dt><dd class="mt-1 font-black">{{ chapter.decisionRecord.record.effectiveDate }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Review Date</dt><dd class="mt-1 font-black">{{ chapter.decisionRecord.record.reviewDate }}</dd></div>
                </dl>
                <p class="mt-4 text-sm leading-6 text-slate-700">{{ chapter.decisionRecord.record.decisionSummary }}</p>
            </div>

            <form v-if="chapter.decisionRecord?.canCreate" class="mt-6 grid gap-4 md:grid-cols-2" @submit.prevent="recordDecision">
                <label class="text-sm font-bold text-slate-700">
                    Decision Owner
                    <select v-model="decisionForm.decision_owner_membership_id" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3">
                        <option value="" disabled>—</option>
                        <option v-for="member in chapter.decisionRecord.decisionOwnerOptions" :key="member.id" :value="member.id">{{ member.name }}</option>
                    </select>
                </label>
                <label class="text-sm font-bold text-slate-700">
                    Effective Date
                    <input v-model="decisionForm.effective_date" type="date" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                </label>
                <label class="text-sm font-bold text-slate-700">
                    Review Date
                    <input v-model="decisionForm.review_date" type="date" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                </label>
                <label class="text-sm font-bold text-slate-700 md:col-span-2">
                    Decision Summary
                    <textarea v-model="decisionForm.decision_summary" rows="3" required class="mt-1.5 w-full rounded-xl border border-slate-300 px-3 py-2" />
                </label>
                <label class="text-sm font-bold text-slate-700 md:col-span-2">
                    Evidence / Reference
                    <input v-model="decisionForm.evidence_references[0]" class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3" />
                </label>
                <button type="submit" :disabled="decisionForm.processing" class="min-h-11 rounded-xl bg-[var(--pbr-green-dark)] px-5 text-sm font-black text-white disabled:opacity-50 md:w-max">
                    {{ tx('Record Contribution Decision', 'Contribution Decision မှတ်တမ်းတင်ရန်') }}
                </button>
            </form>
        </section>

        <section
            v-else
            data-contribution-step="action-plan"
            class="rounded-[24px] border border-[#d6e2d8] bg-white p-5 shadow-[0_10px_28px_rgb(16_35_26_/_4%)] sm:p-6"
        >
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]">11 · Action Plan</p>
            <h3 class="mt-2 text-xl font-black">Contribution Action Plan</h3>
            <p class="mt-2 max-w-4xl text-sm leading-6 text-[var(--pbr-muted)]">
                {{ tx('Create follow-up work from the recorded Contribution Decision. Actions never change Accepted Value, and zero Actions is valid.', 'Recorded Contribution Decision ကနေ နောက်ဆက်တွဲ Action တွေ ဖန်တီးနိုင်ပါတယ်။ Action က Accepted Value ကို မပြောင်းပါဘူး။ Action သုညလည်း အဆင်ပြေပါတယ်။') }}
            </p>

            <p v-if="chapter.actionPlan?.available && !chapter.actionPlan.actions.length" class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-semibold text-emerald-900">
                {{ tx('No Action is required. The chapter can still be complete once the current Decision is recorded.', 'Action မရှိလည်း ရပါတယ်။ Current Decision မှတ်တမ်းတင်ပြီးရင် Chapter ကို ပြီးစီးနိုင်ပါတယ်။') }}
            </p>

            <div v-if="chapter.actionPlan?.canManage && chapter.actionPlan.suggestions.length" class="mt-6">
                <label class="block max-w-md text-sm font-bold text-slate-700">
                    Action Owner
                    <select v-model="suggestedOwner" class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3">
                        <option value="" disabled>—</option>
                        <option v-for="member in chapter.actionPlan.ownerOptions" :key="member.id" :value="member.id">{{ member.name }}</option>
                    </select>
                </label>
                <div class="mt-4 grid gap-3 lg:grid-cols-2">
                    <div v-for="suggestion in chapter.actionPlan.suggestions" :key="suggestion.key" :data-contribution-suggestion="suggestion.key" class="rounded-[18px] border border-[#dce6de] bg-[#f8faf8] p-4">
                        <p class="font-black">{{ suggestion.title }}</p>
                        <p class="mt-2 text-xs leading-5 text-[var(--pbr-muted)]">{{ suggestion.description }}</p>
                        <button type="button" :disabled="!suggestedOwner" class="mt-3 min-h-10 rounded-xl bg-[var(--pbr-green-dark)] px-4 text-xs font-black text-white disabled:opacity-40" @click="addSuggestedAction(suggestion.key)">
                            {{ tx('Add Action', 'Action ထည့်ရန်') }}
                        </button>
                    </div>
                </div>
            </div>

            <details v-if="chapter.actionPlan?.canManage" class="mt-5 rounded-[18px] border border-slate-200 p-4">
                <summary class="cursor-pointer text-sm font-black">{{ tx('Custom Action', 'ကိုယ်တိုင်သတ်မှတ်မည့် Action') }}</summary>
                <form class="mt-4 grid gap-3 md:grid-cols-2" @submit.prevent="addCustomAction">
                    <select v-model="customAction.assigned_membership_id" required class="min-h-11 rounded-xl border border-slate-300 px-3">
                        <option value="" disabled>—</option>
                        <option v-for="member in chapter.actionPlan.ownerOptions" :key="member.id" :value="member.id">{{ member.name }}</option>
                    </select>
                    <input v-model="customAction.due_date" type="date" class="min-h-11 rounded-xl border border-slate-300 px-3" />
                    <input v-model="customAction.title" :placeholder="tx('Action title', 'Action ခေါင်းစဉ်')" required class="min-h-11 rounded-xl border border-slate-300 px-3 md:col-span-2" />
                    <textarea v-model="customAction.description" :placeholder="tx('Action description', 'Action အကြောင်းအရာ')" rows="2" class="rounded-xl border border-slate-300 px-3 py-2 md:col-span-2" />
                    <button type="submit" class="min-h-11 rounded-xl bg-[var(--pbr-green-dark)] px-5 text-sm font-black text-white md:w-max">
                        {{ tx('Add Action', 'Action ထည့်ရန်') }}
                    </button>
                </form>
            </details>

            <div class="mt-6 space-y-3">
                <div v-for="action in chapter.actionPlan?.actions ?? []" :key="action.id" :data-contribution-action-id="action.id" class="rounded-[18px] border border-[#dce6de] p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="font-black">{{ action.title }}</p>
                            <p class="mt-1 text-xs text-[var(--pbr-muted)]">{{ action.owner }}<span v-if="action.dueDate"> · {{ action.dueDate }}</span></p>
                            <p v-if="action.description" class="mt-2 text-sm">{{ action.description }}</p>
                        </div>
                        <span class="rounded-full border px-2.5 py-1 text-xs font-black" :class="tone(action.status)">{{ action.status.replace('_', ' ') }}</span>
                    </div>
                    <div v-if="action.canUpdate" class="mt-4 grid gap-2 md:grid-cols-[180px_1fr_auto]">
                        <select v-model="statusDraft[action.id]" class="min-h-10 rounded-xl border border-slate-300 px-3 text-sm">
                            <option value="open">Open</option>
                            <option value="in_progress">In progress</option>
                            <option value="blocked">Blocked</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                        <input v-model="blockedDraft[action.id]" :placeholder="tx('Blocked reason', 'Blocked ဖြစ်ရသည့်အကြောင်း')" class="min-h-10 rounded-xl border border-slate-300 px-3 text-sm" />
                        <button type="button" class="min-h-10 rounded-xl border border-slate-300 px-4 text-xs font-black" @click="updateAction(action.id, action.status)">
                            {{ tx('Update', 'ပြောင်းရန်') }}
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="chapter.acceptedRegister.acceptedCount > 0" class="mt-7 rounded-[20px] border border-[#d8c680] bg-[linear-gradient(135deg,#fffaf0,#f5f9f5)] p-5">
                <p class="text-xs font-black uppercase tracking-[0.15em] text-[#796123]">
                    {{ tx('Continue to Ownership', 'Ownership သို့ ဆက်သွားရန်') }}
                </p>
                <p class="mt-2 text-sm leading-6 text-slate-700">
                    {{ tx('Accepted Contributions can now be used as input to later Ownership planning. This chapter itself creates no shares or ownership.', 'Accepted Contribution တွေကို နောက်ပိုင်း Ownership planning အတွက် input အဖြစ် သုံးနိုင်ပါပြီ။ ဒီ Chapter က Shares သို့မဟုတ် Ownership ကို မဖန်တီးပါ။') }}
                </p>
                <Link :href="chapter.routes.ownership" class="mt-4 inline-flex min-h-11 items-center rounded-xl bg-slate-950 px-5 text-sm font-black text-white">
                    {{ tx('Continue to Ownership', 'Ownership သို့ ဆက်သွားရန်') }}
                </Link>
            </div>
        </section>
    </section>
</template>
