<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from '../../i18n/useI18n';
import { humanErrorMessages } from '../../support/humanErrors';
import PbrErrorSummary from '../ui/PbrErrorSummary.vue';

type GenericRow = Record<string, any>;

const props = defineProps<{
    readModel: GenericRow | null;
}>();

const { uiLanguageMode } = useI18n();

const copy = {
    en: {
        eyebrow: 'Record',
        title: 'Capital Decision Record',
        subtitle: 'Record the approved Capital decision without re-entering the Capital calculation.',
        boundary: 'The Effective Date below is the intended business date. It does not make the Formal Record Effective.',
        approvedPlan: 'Approved Capital Plan',
        preferredPlan: 'Preferred Plan',
        totalCapital: 'Total Capital Requirement',
        workingCapital: 'Working Capital',
        workingMonths: 'Working Capital Months',
        contingency: 'Contingency',
        contingencyPct: 'Contingency %',
        funding: 'Confirmed Funding',
        gap: 'Funding Gap',
        surplus: 'Funding Surplus',
        funded: '% Funded',
        capitalRule: 'Capital shortfall response',
        approvedBy: 'Approved By',
        approvalDate: 'Approval Date',
        owner: 'Decision Owner',
        chooseOwner: 'Choose Decision Owner',
        effectiveDate: 'Effective Date',
        reviewDate: 'Review Date',
        summary: 'Decision Summary',
        evidence: 'Evidence / References',
        evidenceHelp: 'Optional. Add one reference per line. Do not enter internal system IDs.',
        record: 'Record Decision',
        saving: 'Recording…',
        saved: 'Capital Decision Record saved.',
        lastUpdated: 'Last Updated',
        status: 'Status',
        readyToRecord: 'Approved — Ready to Record',
        recorded: 'Approved — Recorded',
        signatureRequired: 'Approved — Signature Required',
        awaitingEffectivity: 'Approved — Awaiting Effectivity',
        stale: 'Capital planning has changed since this approval. This record documents the approved version; a new approval is required for the changed plan.',
        systemEvidence: 'System Evidence',
        sources: 'Source revisions',
        planning: 'Capital Plan',
        rule: 'Capital Rule',
        comparison: 'Comparison',
        hash: 'Approved content hash',
        noRule: 'No shortfall response recorded.',
        errorTitle: 'Capital Decision Record could not be saved',
        errorHelp: 'Check the required fields and use the approved Capital version shown here.',
        notAvailable: 'The Capital Decision Record becomes available after governed Capital Approval.',
        semantics: 'Recorded does not mean Signed, Effective or Action Complete.',
    },
    my: {
        eyebrow: 'Record',
        title: 'Capital Decision Record',
        subtitle: 'အတည်ပြုပြီးသား Capital decision ကို Capital amount တွေပြန်မဖြည့်ဘဲ မှတ်တမ်းတင်ပါ။',
        boundary: 'အောက်က Effective Date က စီးပွားရေးအရ သတ်မှတ်ထားတဲ့ ရက်စွဲပါ။ Formal Record ကို Effective မဖြစ်စေပါ။',
        approvedPlan: 'Approved Capital Plan',
        preferredPlan: 'Preferred Plan',
        totalCapital: 'လိုအပ်သော Capital စုစုပေါင်း',
        workingCapital: 'Working Capital',
        workingMonths: 'Working Capital Months',
        contingency: 'Contingency',
        contingencyPct: 'Contingency %',
        funding: 'Confirmed Funding',
        gap: 'Funding Gap',
        surplus: 'Funding Surplus',
        funded: '% Funded',
        capitalRule: 'Capital shortfall response',
        approvedBy: 'Approved By',
        approvalDate: 'Approval Date',
        owner: 'Decision Owner',
        chooseOwner: 'Decision Owner ရွေးပါ',
        effectiveDate: 'Effective Date',
        reviewDate: 'Review Date',
        summary: 'Decision Summary',
        evidence: 'Evidence / References',
        evidenceHelp: 'Optional ဖြစ်ပါတယ်။ Reference တစ်ခုစီကို တစ်ကြောင်းစီရေးပါ။ Internal system ID မရေးပါနဲ့။',
        record: 'Decision Record မှတ်တမ်းတင်မည်',
        saving: 'မှတ်တမ်းတင်နေသည်…',
        saved: 'Capital Decision Record သိမ်းပြီးပါပြီ။',
        lastUpdated: 'Last Updated',
        status: 'Status',
        readyToRecord: 'Approved — Record လုပ်ရန် အဆင်သင့်',
        recorded: 'Approved — Recorded',
        signatureRequired: 'Approved — Signature Required',
        awaitingEffectivity: 'Approved — Awaiting Effectivity',
        stale: 'ဒီ Approval ပြီးနောက် Capital planning ပြောင်းထားပါတယ်။ ဒီ Record က အတည်ပြုခဲ့တဲ့ version ကို မှတ်တမ်းတင်တာဖြစ်ပြီး ပြောင်းထားတဲ့ plan အတွက် Approval အသစ်လိုပါတယ်။',
        systemEvidence: 'System Evidence',
        sources: 'Source revisions',
        planning: 'Capital Plan',
        rule: 'Capital Rule',
        comparison: 'Comparison',
        hash: 'Approved content hash',
        noRule: 'Shortfall response မမှတ်တမ်းတင်ထားပါ။',
        errorTitle: 'Capital Decision Record မသိမ်းနိုင်ပါ',
        errorHelp: 'လိုအပ်တဲ့ field တွေကို စစ်ပြီး ဒီနေရာမှာပြထားတဲ့ approved Capital version ကိုပဲ သုံးပါ။',
        notAvailable: 'Governed Capital Approval ပြီးမှ Capital Decision Record ရပါမယ်။',
        semantics: 'Recorded ဖြစ်တာက Signed, Effective သို့မဟုတ် Action Complete ဖြစ်တာမဟုတ်ပါ။',
    },
    mixed: {
        eyebrow: 'Record',
        title: 'Capital Decision Record',
        subtitle: 'Approved Capital decision ကို Capital figures ပြန်မထည့်ဘဲ record လုပ်ပါ။',
        boundary: 'Effective Date field က intended business date ပဲဖြစ်ပြီး Formal Record ကို Effective မလုပ်ပါ။',
        approvedPlan: 'Approved Capital Plan',
        preferredPlan: 'Preferred Plan',
        totalCapital: 'Total Capital Requirement',
        workingCapital: 'Working Capital',
        workingMonths: 'Working Capital Months',
        contingency: 'Contingency',
        contingencyPct: 'Contingency %',
        funding: 'Confirmed Funding',
        gap: 'Funding Gap',
        surplus: 'Funding Surplus',
        funded: '% Funded',
        capitalRule: 'Capital shortfall response',
        approvedBy: 'Approved By',
        approvalDate: 'Approval Date',
        owner: 'Decision Owner',
        chooseOwner: 'Choose Decision Owner',
        effectiveDate: 'Effective Date',
        reviewDate: 'Review Date',
        summary: 'Decision Summary',
        evidence: 'Evidence / References',
        evidenceHelp: 'Optional. Reference တစ်ခုကို တစ်ကြောင်းစီရေးပါ။ Internal IDs မရေးပါနဲ့။',
        record: 'Record Decision',
        saving: 'Recording…',
        saved: 'Capital Decision Record saved.',
        lastUpdated: 'Last Updated',
        status: 'Status',
        readyToRecord: 'Approved — Ready to Record',
        recorded: 'Approved — Recorded',
        signatureRequired: 'Approved — Signature Required',
        awaitingEffectivity: 'Approved — Awaiting Effectivity',
        stale: 'Capital planning changed after this approval. ဒီ Record က approved historical version ကိုပြတာဖြစ်ပြီး changed plan အတွက် new approval လိုပါတယ်။',
        systemEvidence: 'System Evidence',
        sources: 'Source revisions',
        planning: 'Capital Plan',
        rule: 'Capital Rule',
        comparison: 'Comparison',
        hash: 'Approved content hash',
        noRule: 'No shortfall response recorded.',
        errorTitle: 'Capital Decision Record could not be saved',
        errorHelp: 'Required fields နဲ့ approved Capital version ကို ပြန်စစ်ပါ။',
        notAvailable: 'Governed Capital Approval ပြီးမှ Decision Record ရပါမယ်။',
        semantics: 'Recorded != Signed != Effective != Action Complete.',
    },
} as const;

const c = computed(() => copy[uiLanguageMode.value]);
const busy = ref(false);
const errors = ref<string[]>([]);
const success = ref('');
const owner = ref('');
const effectiveDate = ref('');
const reviewDate = ref('');
const summary = ref(props.readModel?.suggestedDecisionSummary ?? '');
const evidenceText = ref('');

watch(
    () => props.readModel?.suggestedDecisionSummary,
    (value) => {
        if (!props.readModel?.recorded && summary.value.trim() === '' && typeof value === 'string') {
            summary.value = value;
        }
    },
);

const plan = computed(() => props.readModel?.approvedPlan ?? {});
const approval = computed(() => props.readModel?.governedApproval ?? {});
const record = computed(() => props.readModel?.record ?? null);

const money = (value: unknown): string => {
    if (value === null || value === undefined || value === '') return '—';
    const currency = plan.value?.baseCurrency ?? '';
    return `${String(value)} ${String(currency)}`.trim();
};

const percent = (value: unknown): string =>
    value === null || value === undefined || value === ''
        ? '—'
        : `${String(value)}%`;

const preferred = computed(() => {
    const value = String(plan.value?.preferredPlan ?? '');
    if (value === 'lean') return 'Lean';
    if (value === 'base') return 'Base';
    if (value === 'growth') return 'Growth';
    return '—';
});

const statusLabel = computed(() => {
    if (!props.readModel?.recorded) return c.value.readyToRecord;
    if (props.readModel?.status === 'approved_signature_required') {
        return c.value.signatureRequired;
    }
    return c.value.awaitingEffectivity;
});

const capitalRuleText = computed(() => {
    const responses = plan.value?.capitalRule?.shortfallResponses;
    if (!Array.isArray(responses) || responses.length === 0) return c.value.noRule;
    return responses.map((value: unknown) => String(value).replaceAll('_', ' ')).join(' · ');
});

const submit = (): void => {
    if (busy.value) return;

    busy.value = true;
    errors.value = [];
    success.value = '';

    const evidenceReferences = evidenceText.value
        .split('\n')
        .map((value) => value.trim())
        .filter((value) => value !== '');

    router.post(
        '/formation/capital/decision-record',
        {
            decision_owner_membership_id: owner.value,
            effective_date: effectiveDate.value,
            review_date: reviewDate.value,
            decision_summary: summary.value,
            evidence_references: evidenceReferences,
        },
        {
            preserveScroll: true,
            preserveState: false,
            onSuccess: () => {
                success.value = c.value.saved;
            },
            onError: (serverErrors: Record<string, string>) => {
                const specific = serverErrors.capital_decision_record;
                errors.value = typeof specific === 'string'
                    ? [specific]
                    : humanErrorMessages(serverErrors);
            },
            onFinish: () => {
                busy.value = false;
            },
        },
    );
};
</script>

<template>
    <section
        data-testid="capital-decision-record-stage"
        class="mt-6 min-w-0 rounded-[24px] border border-[#cadccf] bg-[linear-gradient(145deg,#ffffff_0%,#f5faf6_58%,#fffaf0_100%)] p-4 sm:p-6"
    >
        <template v-if="readModel?.available">
            <header class="min-w-0">
                <div class="flex min-w-0 flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]">
                            {{ c.eyebrow }}
                        </p>
                        <h3 class="mt-2 break-words text-lg font-black tracking-[-0.02em] sm:text-xl">
                            {{ c.title }}
                        </h3>
                    </div>
                    <span class="max-w-full rounded-full border border-[#bcd8c4] bg-[#eef8f1] px-3 py-1.5 text-xs font-black text-[#155f39]">
                        {{ statusLabel }}
                    </span>
                </div>
                <p class="pbr-safe-copy mt-2 max-w-4xl break-words text-sm leading-6 text-[var(--pbr-muted)]">
                    {{ c.subtitle }}
                </p>
                <p class="pbr-safe-copy mt-3 rounded-2xl border border-[#eadcb1] bg-[#fffaf0] px-4 py-3 text-xs leading-5 text-[#665527]">
                    {{ c.boundary }}
                </p>
            </header>

            <div
                v-if="readModel.planningChangedSinceApproval"
                data-testid="capital-decision-record-stale-warning"
                class="pbr-safe-copy mt-5 rounded-2xl border border-[#e6c7a0] bg-[#fff8ec] p-4 text-sm font-semibold leading-6 text-[#755420]"
            >
                {{ c.stale }}
            </div>

            <section class="mt-5">
                <h4 class="text-sm font-black">{{ c.approvedPlan }}</h4>
                <div class="mt-3 grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4"><p class="text-xs text-[var(--pbr-muted)]">{{ c.preferredPlan }}</p><p class="mt-1 font-black">{{ preferred }}</p></div>
                    <div class="min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4"><p class="text-xs text-[var(--pbr-muted)]">{{ c.totalCapital }}</p><p class="mt-1 break-words font-black">{{ money(plan.totalCapitalRequirement) }}</p></div>
                    <div class="min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4"><p class="text-xs text-[var(--pbr-muted)]">{{ c.workingCapital }}</p><p class="mt-1 break-words font-black">{{ money(plan.workingCapital) }}</p><p v-if="plan.workingCapitalMonthsApplicable" class="mt-1 text-xs text-[var(--pbr-muted)]">{{ c.workingMonths }}: {{ plan.workingCapitalMonths }}</p></div>
                    <div class="min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4"><p class="text-xs text-[var(--pbr-muted)]">{{ c.contingency }}</p><p class="mt-1 break-words font-black">{{ money(plan.contingency) }}</p><p v-if="plan.contingencyPercentageApplicable" class="mt-1 text-xs text-[var(--pbr-muted)]">{{ c.contingencyPct }}: {{ percent(plan.contingencyPercentage) }}</p></div>
                    <div class="min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4"><p class="text-xs text-[var(--pbr-muted)]">{{ c.funding }}</p><p class="mt-1 break-words font-black">{{ money(plan.confirmedFunding) }}</p></div>
                    <div class="min-w-0 rounded-2xl border border-[#e2d4a4] bg-[#fffaf0] p-4"><p class="text-xs text-[#665527]">{{ c.gap }}</p><p class="mt-1 break-words font-black text-[#665527]">{{ money(plan.fundingGap) }}</p></div>
                    <div class="min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4"><p class="text-xs text-[var(--pbr-muted)]">{{ c.surplus }}</p><p class="mt-1 break-words font-black">{{ money(plan.fundingSurplus) }}</p></div>
                    <div class="min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4"><p class="text-xs text-[var(--pbr-muted)]">{{ c.funded }}</p><p class="mt-1 font-black">{{ percent(plan.fundedPercentage) }}</p></div>
                </div>

                <div class="mt-3 min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                    <p class="text-xs font-semibold text-[var(--pbr-muted)]">{{ c.capitalRule }}</p>
                    <p class="pbr-safe-copy mt-1 break-words text-sm font-black">{{ capitalRuleText }}</p>
                    <p v-if="plan.capitalRule?.shortfallRuleNotes" class="pbr-safe-copy mt-1 break-words text-xs leading-5 text-[var(--pbr-muted)]">{{ plan.capitalRule.shortfallRuleNotes }}</p>
                </div>
            </section>

            <section class="mt-5 grid min-w-0 gap-3 sm:grid-cols-2">
                <div class="min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                    <p class="text-xs font-semibold text-[var(--pbr-muted)]">{{ c.approvedBy }}</p>
                    <div class="mt-2 space-y-1">
                        <p v-for="item in approval.approvedBy" :key="item.name + item.evidenceKind + item.recordedAt" class="break-words text-sm font-black">
                            {{ item.name }}
                        </p>
                    </div>
                </div>
                <div class="min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                    <p class="text-xs font-semibold text-[var(--pbr-muted)]">{{ c.approvalDate }}</p>
                    <p class="mt-2 break-words text-sm font-black">{{ approval.approvalDate ?? '—' }}</p>
                </div>
            </section>

            <PbrErrorSummary
                class="mt-5"
                :title="c.errorTitle"
                :help="c.errorHelp"
                :errors="errors"
            />

            <p v-if="success" class="pbr-safe-copy mt-5 rounded-2xl border border-[#bcdcc6] bg-[#eef8f1] px-4 py-3 text-sm font-semibold text-[#155f39]">{{ success }}</p>

            <section
                v-if="!readModel.recorded && readModel.canCreate"
                class="mt-5 rounded-2xl border border-[var(--pbr-line)] bg-white p-4 sm:p-5"
            >
                <div class="grid min-w-0 gap-4 sm:grid-cols-2">
                    <label class="min-w-0 text-sm font-bold">
                        <span>{{ c.owner }}</span>
                        <select v-model="owner" data-testid="capital-decision-owner" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm">
                            <option value="">{{ c.chooseOwner }}</option>
                            <option v-for="item in readModel.decisionOwnerOptions" :key="item.id" :value="item.id">{{ item.name }}</option>
                        </select>
                    </label>
                    <label class="min-w-0 text-sm font-bold">
                        <span>{{ c.effectiveDate }}</span>
                        <input v-model="effectiveDate" data-testid="capital-decision-effective-date" type="date" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm" />
                    </label>
                    <label class="min-w-0 text-sm font-bold">
                        <span>{{ c.reviewDate }}</span>
                        <input v-model="reviewDate" data-testid="capital-decision-review-date" type="date" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm" />
                    </label>
                    <label class="min-w-0 text-sm font-bold sm:col-span-2">
                        <span>{{ c.summary }}</span>
                        <textarea v-model="summary" data-testid="capital-decision-summary" rows="3" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm" />
                    </label>
                    <label class="min-w-0 text-sm font-bold sm:col-span-2">
                        <span>{{ c.evidence }}</span>
                        <textarea v-model="evidenceText" data-testid="capital-decision-evidence" rows="3" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm" />
                        <span class="pbr-safe-copy mt-1 block text-xs font-normal leading-5 text-[var(--pbr-muted)]">{{ c.evidenceHelp }}</span>
                    </label>
                </div>

                <button
                    data-testid="capital-decision-record-submit"
                    type="button"
                    class="mt-4 min-h-11 rounded-xl bg-[var(--pbr-green-dark)] px-5 text-sm font-black text-white disabled:opacity-60"
                    :disabled="busy || owner === '' || effectiveDate === '' || reviewDate === '' || summary.trim() === ''"
                    @click="submit"
                >
                    {{ busy ? c.saving : c.record }}
                </button>
            </section>

            <section
                v-if="readModel.recorded && record"
                data-testid="capital-decision-record-summary"
                class="mt-5 rounded-2xl border border-[#bcd8c4] bg-white p-4 sm:p-5"
            >
                <div class="grid min-w-0 gap-3 sm:grid-cols-2">
                    <div><p class="text-xs text-[var(--pbr-muted)]">{{ c.owner }}</p><p class="mt-1 break-words text-sm font-black">{{ record.decisionOwner }}</p></div>
                    <div><p class="text-xs text-[var(--pbr-muted)]">{{ c.status }}</p><p class="mt-1 text-sm font-black">{{ statusLabel }}</p></div>
                    <div><p class="text-xs text-[var(--pbr-muted)]">{{ c.effectiveDate }}</p><p class="mt-1 text-sm font-black">{{ record.effectiveDate }}</p></div>
                    <div><p class="text-xs text-[var(--pbr-muted)]">{{ c.reviewDate }}</p><p class="mt-1 text-sm font-black">{{ record.reviewDate }}</p></div>
                    <div class="sm:col-span-2"><p class="text-xs text-[var(--pbr-muted)]">{{ c.summary }}</p><p class="pbr-safe-copy mt-1 break-words text-sm font-semibold leading-6">{{ record.decisionSummary }}</p></div>
                    <div class="sm:col-span-2"><p class="text-xs text-[var(--pbr-muted)]">{{ c.evidence }}</p><ul class="mt-1 space-y-1 text-sm"><li v-for="item in record.evidenceReferences" :key="item" class="break-words">• {{ item }}</li></ul></div>
                    <div><p class="text-xs text-[var(--pbr-muted)]">{{ c.lastUpdated }}</p><p class="mt-1 break-words text-sm font-black">{{ record.lastUpdated }}</p></div>
                </div>

                <details class="mt-4 rounded-xl border border-[var(--pbr-line)] bg-[#f8faf8] p-3">
                    <summary class="cursor-pointer text-xs font-black">{{ c.systemEvidence }}</summary>
                    <div class="mt-3 space-y-2 text-xs text-[var(--pbr-muted)]">
                        <p v-for="item in readModel.systemReferences" :key="item.label">{{ item.label }} — {{ item.detail }}</p>
                        <p>{{ c.sources }}: {{ c.planning }} {{ plan.sourceRevisions.capitalPlanning }} · {{ c.rule }} {{ plan.sourceRevisions.capitalRule }} · {{ c.comparison }} {{ plan.sourceRevisions.capitalComparison }}</p>
                        <p class="break-all">{{ c.hash }}: {{ plan.approvedContentHash }}</p>
                    </div>
                </details>

                <p class="pbr-safe-copy mt-4 text-xs leading-5 text-[var(--pbr-muted)]">{{ c.semantics }}</p>
            </section>
        </template>

        <p v-else class="pbr-safe-copy text-sm leading-6 text-[var(--pbr-muted)]">
            {{ c.notAvailable }}
        </p>
    </section>
</template>
