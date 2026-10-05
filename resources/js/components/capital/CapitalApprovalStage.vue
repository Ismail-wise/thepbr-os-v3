<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PbrErrorSummary from '../ui/PbrErrorSummary.vue';
import { useI18n } from '../../i18n/useI18n';
import { humanErrorMessages } from '../../support/humanErrors';

type GenericRow = Record<string, any>;

const props = defineProps<{
    readModel: GenericRow | null;
    canManageCapital: boolean;
    canManageRecords: boolean;
    canManageGovernance: boolean;
}>();

const { uiLanguageMode } = useI18n();

const copy = {
    en: {
        eyebrow: 'Capital Approval',
        title: 'Final Plan for Approval',
        subtitle:
            'Review the exact Preferred Capital Plan, confirm its frozen review, then use the Business’s real Governance authority to approve it.',
        boundary:
            'Approval is not Signature, Effectivity, the final Capital Decision Record or an Action Plan.',
        unavailable: 'Capital Approval is not available for this account.',
        ready: 'Ready for Approval',
        needsReview: 'Needs Review',
        planChanged: 'Plan Changed — Prepare a New Approval Version',
        needsUpstream: 'Complete the Capital planning steps first.',
        authorityMissing: 'Capital approval authority has not been configured yet.',
        preferredPlan: 'Preferred Plan',
        capitalRequired: 'Capital Required',
        confirmedFunding: 'Confirmed Funding',
        fundingGap: 'Funding Gap',
        fundingSurplus: 'Funding Surplus',
        fundedPercent: 'Funding coverage',
        capitalRule: 'Capital Rule',
        noShortfall: 'No shortfall response is required.',
        prepare: 'Prepare for Approval',
        prepareNew: 'Prepare New Approval Version',
        preparing: 'Preparing…',
        prepared: 'Exact Capital Plan frozen for review.',
        reviewTitle: 'Review Final Plan',
        reviewer: 'Reviewer',
        chooseReviewer: 'Choose reviewer',
        startReview: 'Start review',
        reviewNotes: 'Review Notes',
        confirmReview: 'Confirm Review',
        requestChanges: 'Request Changes',
        reviewApproved: 'Final Plan review approved.',
        reviewChanges: 'Changes were requested. Prepare a new plan version if the content must change.',
        approvalRequirements: 'Approval Requirements',
        approvalRequired: 'Approval Required',
        approvalProgress: 'Approval Progress',
        authoritySource: 'Authority source',
        temporaryAuthority: 'Temporary Formation Authority',
        governanceCharter: 'Effective Governance Charter',
        approvalMethod: 'Approval method',
        directApproval: 'Direct Approval',
        voting: 'Voting',
        approvalAndVote: 'Approval + Voting',
        approvers: 'Approver(s) / voters',
        requiredApprovals: 'Required approvals',
        requiredVotes: 'Required supporting votes',
        quorum: 'Quorum',
        meetingRequired: 'Meeting Required',
        meetingHelp: 'Select a held Governance Meeting with quorum.',
        chooseMeeting: 'Choose meeting',
        openApproval: 'Start governed approval',
        approve: 'Approve',
        voteFor: 'Vote For',
        voteAgainst: 'Vote Against',
        abstain: 'Abstain',
        resolve: 'Confirm approval result',
        approved: 'Approved',
        notApproved: 'Not Yet Approved',
        approvedBy: 'Approved By',
        approvalDate: 'Approval Date',
        signatureRequired: 'Approved. Signature is required before this can become effective.',
        notSignedEffective: 'Approved, but not Signed and not Effective.',
        current: 'Current approval version',
        frozen: 'Frozen review version',
        stale: 'Historical frozen version preserved; it cannot continue forward because the Capital source changed.',
        noManualTotals: 'Capital totals come from the accepted server calculation and cannot be typed here.',
        reasonsTitle: 'Complete these before approval',
        reasonPlan: 'Save the Capital Plan.',
        reasonRule: 'Bring the Capital Rule up to date and ready.',
        reasonShortfall: 'Record the required Funding Gap response in the Capital Rule.',
        reasonComparison: 'Complete and refresh Lean / Base / Growth comparison.',
        reasonPreferred: 'Choose a complete Preferred Plan.',
        genericReason: 'Complete the current Capital planning requirements.',
        actionFailed: 'Capital Approval action could not be completed',
        actionHelp: 'Review the current plan, authority and approval progress, then try the permitted next action.',
        saving: 'Working…',
        approvalRecorded: 'Approval evidence recorded.',
        voteRecorded: 'Vote recorded.',
        approvalOpened: 'Governed approval started.',
        resolved: 'Capital Plan is Approved. It is not Signed or Effective.',
        reviewStarted: 'Final Plan review started.',
        sourcePlanning: 'Capital Plan revision',
        sourceRule: 'Capital Rule revision',
        sourceComparison: 'Comparison revision',
        explicitBoundary: 'Capital remains in progress after Approval. The Capital Decision Record is the next stage.',
    },
    my: {
        eyebrow: 'Capital Approval',
        title: 'Approval အတွက် နောက်ဆုံး Capital Plan',
        subtitle:
            'ရွေးထားတဲ့ Preferred Capital Plan အတိအကျကို စစ်ပြီး frozen review version တစ်ခု ပြင်ဆင်ကာ Business ရဲ့ တကယ်ရှိတဲ့ Governance authority နဲ့ပဲ Approval လုပ်ပါ။',
        boundary:
            'Approval က Signature မဟုတ်ပါ၊ Effective မဟုတ်ပါ၊ နောက်ဆုံး Capital Decision Record သို့မဟုတ် Action Plan မဟုတ်ပါ။',
        unavailable: 'ဒီ Account အတွက် Capital Approval မရနိုင်ပါ။',
        ready: 'Approval အတွက် အဆင်သင့်',
        needsReview: 'ပြန်စစ်ရန်လို',
        planChanged: 'Plan ပြောင်းသွားပြီ — Approval Version အသစ်ပြင်ဆင်ပါ',
        needsUpstream: 'Capital planning အဆင့်တွေကို အရင်ပြီးအောင်လုပ်ပါ။',
        authorityMissing: 'Capital Approval လုပ်မယ့် Authority ကို မသတ်မှတ်ရသေးပါ။',
        preferredPlan: 'ရွေးထားသော Preferred Plan',
        capitalRequired: 'လိုအပ်သော Capital',
        confirmedFunding: 'Confirmed Funding',
        fundingGap: 'Funding Gap',
        fundingSurplus: 'Funding Surplus',
        fundedPercent: 'Funding ပြည့်မီမှု',
        capitalRule: 'Capital Rule',
        noShortfall: 'Shortfall response မလိုပါ။',
        prepare: 'Approval အတွက်ပြင်ဆင်မည်',
        prepareNew: 'Approval Version အသစ်ပြင်ဆင်မည်',
        preparing: 'ပြင်ဆင်နေသည်…',
        prepared: 'Capital Plan အတိအကျကို review အတွက် freeze လုပ်ပြီးပါပြီ။',
        reviewTitle: 'Final Plan ကို Review လုပ်မည်',
        reviewer: 'Reviewer',
        chooseReviewer: 'Reviewer ရွေးပါ',
        startReview: 'Review စမည်',
        reviewNotes: 'Review မှတ်ချက်',
        confirmReview: 'Review အတည်ပြုမည်',
        requestChanges: 'ပြင်ဆင်ရန် တောင်းဆိုမည်',
        reviewApproved: 'Final Plan review အတည်ပြုပြီးပါပြီ။',
        reviewChanges: 'ပြင်ဆင်ရန်တောင်းဆိုထားပါတယ်။ Content ပြောင်းရမယ်ဆို Approval version အသစ်ပြင်ဆင်ရပါမယ်။',
        approvalRequirements: 'Approval လိုအပ်ချက်',
        approvalRequired: 'Approval လိုအပ်သည်',
        approvalProgress: 'Approval တိုးတက်မှု',
        authoritySource: 'Authority အရင်းအမြစ်',
        temporaryAuthority: 'ယာယီ Formation Authority',
        governanceCharter: 'Effective Governance Charter',
        approvalMethod: 'Approval နည်းလမ်း',
        directApproval: 'တိုက်ရိုက် Approval',
        voting: 'Voting',
        approvalAndVote: 'Approval + Voting',
        approvers: 'Approver(s) / voters',
        requiredApprovals: 'လိုအပ်သော Approvals',
        requiredVotes: 'လိုအပ်သော Supporting Votes',
        quorum: 'Quorum',
        meetingRequired: 'Meeting လိုအပ်သည်',
        meetingHelp: 'Quorum ပြည့်ပြီး ကျင်းပပြီးသား Governance Meeting ကိုရွေးပါ။',
        chooseMeeting: 'Meeting ရွေးပါ',
        openApproval: 'Governed Approval စမည်',
        approve: 'Approve လုပ်မည်',
        voteFor: 'ထောက်ခံမဲ',
        voteAgainst: 'ကန့်ကွက်မဲ',
        abstain: 'မဲမပေး',
        resolve: 'Approval Result အတည်ပြုမည်',
        approved: 'Approved',
        notApproved: 'မအတည်ပြုရသေး',
        approvedBy: 'Approved By',
        approvalDate: 'Approval Date',
        signatureRequired: 'Approved ဖြစ်ပါပြီ။ Effective မဖြစ်ခင် Signature လိုအပ်ပါတယ်။',
        notSignedEffective: 'Approved ဖြစ်ပေမယ့် Signed မဟုတ်သေး၊ Effective မဟုတ်သေးပါ။',
        current: 'လက်ရှိ Approval Version',
        frozen: 'Frozen Review Version',
        stale: 'အရင် Frozen Version ကို History အဖြစ်သိမ်းထားပေမယ့် Capital source ပြောင်းထားလို့ ရှေ့ဆက်မသုံးနိုင်ပါ။',
        noManualTotals: 'Capital စုစုပေါင်းက server calculation ကနေသာလာပြီး ဒီနေရာမှာ လက်နဲ့ပြင်လို့မရပါ။',
        reasonsTitle: 'Approval မတိုင်မီ ပြီးရမည့်အချက်များ',
        reasonPlan: 'Capital Plan ကို သိမ်းပါ။',
        reasonRule: 'Capital Rule ကို current ဖြစ်အောင်ပြန်စစ်ပြီး ready လုပ်ပါ။',
        reasonShortfall: 'Funding Gap response ကို Capital Rule ထဲမှတ်တမ်းတင်ပါ။',
        reasonComparison: 'Lean / Base / Growth comparison ကို ပြီးအောင်လုပ်ပြီး refresh လုပ်ပါ။',
        reasonPreferred: 'ပြည့်စုံတဲ့ Preferred Plan ကိုရွေးပါ။',
        genericReason: 'လက်ရှိ Capital planning လိုအပ်ချက်တွေကို ပြီးအောင်လုပ်ပါ။',
        actionFailed: 'Capital Approval action မပြီးမြောက်ပါ',
        actionHelp: 'လက်ရှိ Plan, Authority နဲ့ Approval progress ကိုစစ်ပြီး ခွင့်ပြုထားတဲ့ နောက်အဆင့်ကိုလုပ်ပါ။',
        saving: 'လုပ်ဆောင်နေသည်…',
        approvalRecorded: 'Approval evidence မှတ်တမ်းတင်ပြီးပါပြီ။',
        voteRecorded: 'Vote မှတ်တမ်းတင်ပြီးပါပြီ။',
        approvalOpened: 'Governed Approval စတင်ပြီးပါပြီ။',
        resolved: 'Capital Plan Approved ဖြစ်ပါပြီ။ Signed သို့မဟုတ် Effective မဟုတ်သေးပါ။',
        reviewStarted: 'Final Plan review စတင်ပြီးပါပြီ။',
        sourcePlanning: 'Capital Plan revision',
        sourceRule: 'Capital Rule revision',
        sourceComparison: 'Comparison revision',
        explicitBoundary: 'Approval ပြီးရင်လည်း Capital က IN PROGRESS ဖြစ်နေဆဲပါ။ နောက်အဆင့်က Capital Decision Record ဖြစ်ပါတယ်။',
    },
    mixed: {
        eyebrow: 'Capital Approval',
        title: 'Final Plan for Approval',
        subtitle:
            'Preferred Capital Plan အတိအကျကို review လုပ်၊ frozen version ပြင်ပြီး Business ရဲ့ real Governance authority နဲ့ approve လုပ်ပါ။',
        boundary:
            'Approval != Signature != Effective. ဒါက final Capital Decision Record / Action Plan မဟုတ်သေးပါ။',
        unavailable: 'ဒီ Account အတွက် Capital Approval မရနိုင်ပါ။',
        ready: 'Ready for Approval',
        needsReview: 'Needs Review',
        planChanged: 'Plan Changed — Approval Version အသစ်ပြင်ဆင်ပါ',
        needsUpstream: 'Capital planning steps ကိုအရင် complete လုပ်ပါ။',
        authorityMissing: 'Capital approval authority has not been configured yet.',
        preferredPlan: 'Preferred Plan',
        capitalRequired: 'Capital Required',
        confirmedFunding: 'Confirmed Funding',
        fundingGap: 'Funding Gap',
        fundingSurplus: 'Funding Surplus',
        fundedPercent: 'Funding coverage',
        capitalRule: 'Capital Rule',
        noShortfall: 'No shortfall response required.',
        prepare: 'Prepare for Approval',
        prepareNew: 'Prepare New Approval Version',
        preparing: 'Preparing…',
        prepared: 'Exact Capital Plan ကို review အတွက် freeze လုပ်ပြီးပါပြီ။',
        reviewTitle: 'Review Final Plan',
        reviewer: 'Reviewer',
        chooseReviewer: 'Choose reviewer',
        startReview: 'Start review',
        reviewNotes: 'Review Notes',
        confirmReview: 'Confirm Review',
        requestChanges: 'Request Changes',
        reviewApproved: 'Final Plan review approved.',
        reviewChanges: 'Changes requested. Content ပြောင်းရင် version အသစ် prepare လုပ်ပါ။',
        approvalRequirements: 'Approval Requirements',
        approvalRequired: 'Approval Required',
        approvalProgress: 'Approval Progress',
        authoritySource: 'Authority source',
        temporaryAuthority: 'Temporary Formation Authority',
        governanceCharter: 'Effective Governance Charter',
        approvalMethod: 'Approval method',
        directApproval: 'Direct Approval',
        voting: 'Voting',
        approvalAndVote: 'Approval + Voting',
        approvers: 'Approver(s) / voters',
        requiredApprovals: 'Required approvals',
        requiredVotes: 'Required supporting votes',
        quorum: 'Quorum',
        meetingRequired: 'Meeting Required',
        meetingHelp: 'Held + quorum-met Governance Meeting ကိုရွေးပါ။',
        chooseMeeting: 'Choose meeting',
        openApproval: 'Start governed approval',
        approve: 'Approve',
        voteFor: 'Vote For',
        voteAgainst: 'Vote Against',
        abstain: 'Abstain',
        resolve: 'Confirm approval result',
        approved: 'Approved',
        notApproved: 'Not Yet Approved',
        approvedBy: 'Approved By',
        approvalDate: 'Approval Date',
        signatureRequired: 'Approved. Effective မဖြစ်ခင် Signature လိုအပ်ပါတယ်။',
        notSignedEffective: 'Approved, but not Signed and not Effective.',
        current: 'Current approval version',
        frozen: 'Frozen review version',
        stale: 'Frozen history ကို preserve လုပ်ထားပေမယ့် Capital source changed ဖြစ်လို့ forward မလုပ်နိုင်ပါ။',
        noManualTotals: 'Capital totals က accepted server calculation ကလာတာဖြစ်ပြီး ဒီမှာ manually မပြင်နိုင်ပါ။',
        reasonsTitle: 'Complete these before approval',
        reasonPlan: 'Save the Capital Plan.',
        reasonRule: 'Capital Rule ကို current/ready ဖြစ်အောင်လုပ်ပါ။',
        reasonShortfall: 'Funding Gap response ကို Capital Rule ထဲ record လုပ်ပါ။',
        reasonComparison: 'Lean / Base / Growth comparison ကို complete/refresh လုပ်ပါ။',
        reasonPreferred: 'Complete Preferred Plan ကိုရွေးပါ။',
        genericReason: 'Current Capital planning requirements ကို complete လုပ်ပါ။',
        actionFailed: 'Capital Approval action could not be completed',
        actionHelp: 'Current plan, authority, approval progress ကို review လုပ်ပြီး permitted next action ကိုလုပ်ပါ။',
        saving: 'Working…',
        approvalRecorded: 'Approval evidence recorded.',
        voteRecorded: 'Vote recorded.',
        approvalOpened: 'Governed approval started.',
        resolved: 'Capital Plan Approved. Signed / Effective မဟုတ်သေးပါ။',
        reviewStarted: 'Final Plan review started.',
        sourcePlanning: 'Capital Plan revision',
        sourceRule: 'Capital Rule revision',
        sourceComparison: 'Comparison revision',
        explicitBoundary: 'Approval ပြီးလည်း Capital = IN PROGRESS. Next stage = Capital Decision Record.',
    },
} as const;

const c = computed(() => copy[uiLanguageMode.value]);
const busy = ref(false);
const errors = ref<string[]>([]);
const success = ref('');
const reviewer = ref('');
const reviewNotes = ref('');
const meeting = ref('');
const rationale = ref('');

const summary = computed(() => props.readModel?.summary ?? null);
const actions = computed(() => props.readModel?.actions ?? {});
const authority = computed(() => props.readModel?.authority ?? {});
const decision = computed(() => props.readModel?.decision ?? null);

const showMoney = (value: unknown): string => {
    if (value === null || value === undefined || value === '') return '—';
    const currency = summary.value?.baseCurrency ?? '';
    return `${String(value)} ${String(currency)}`.trim();
};

const showPercent = (value: unknown): string => {
    if (value === null || value === undefined || value === '') return '—';
    return `${String(value)}%`;
};

const preferredLabel = (value: unknown): string => {
    const key = String(value ?? '');
    if (key === 'lean') return 'Lean';
    if (key === 'base') return 'Base';
    if (key === 'growth') return 'Growth';
    return '—';
};

const authoritySourceLabel = (value: unknown): string =>
    value === 'governance_charter'
        ? c.value.governanceCharter
        : value === 'formation_authority'
          ? c.value.temporaryAuthority
          : '—';

const methodLabel = (value: unknown): string =>
    value === 'approval'
        ? c.value.directApproval
        : value === 'vote'
          ? c.value.voting
          : value === 'approval_and_vote'
            ? c.value.approvalAndVote
            : '—';

const reasonLabel = (reason: unknown): string => {
    const value = String(reason ?? '');

    if (value === 'capital_plan_required') return c.value.reasonPlan;
    if (
        value === 'capital_rule_not_ready'
        || value === 'capital_rule_needs_review'
    ) return c.value.reasonRule;
    if (value === 'capital_shortfall_rule_required') return c.value.reasonShortfall;
    if (
        value === 'capital_comparison_required'
        || value === 'capital_comparison_incomplete'
        || value === 'capital_comparison_needs_review'
    ) return c.value.reasonComparison;
    if (
        value === 'preferred_plan_required'
        || value === 'preferred_plan_incomplete'
    ) return c.value.reasonPreferred;

    return c.value.genericReason;
};

const capitalRuleText = computed(() => {
    const responses = summary.value?.shortfallResponses;
    if (!Array.isArray(responses) || responses.length === 0) {
        return c.value.noShortfall;
    }

    return responses
        .map((value: unknown) =>
            String(value).replaceAll('_', ' '),
        )
        .join(' · ');
});

const statusLabel = computed(() => {
    const status = props.readModel?.status;

    if (status === 'approved') return c.value.approved;
    if (status === 'plan_changed') return c.value.planChanged;
    if (status === 'authority_not_configured') return c.value.authorityMissing;
    if (status === 'needs_upstream') return c.value.needsUpstream;
    if (
        status === 'ready_to_prepare'
        || status === 'ready_for_review'
        || status === 'ready_for_approval'
    ) return c.value.ready;
    if (
        status === 'review_in_progress'
        || status === 'review_changes_required'
    ) return c.value.needsReview;

    return c.value.notApproved;
});

const post = (
    url: string,
    payload: GenericRow,
    message: string,
    method: 'post' | 'put' = 'post',
): void => {
    if (busy.value) return;

    busy.value = true;
    errors.value = [];
    success.value = '';

    const options = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            success.value = message;
            errors.value = [];
        },
        onError: (serverErrors: Record<string, string>) => {
            const specific = serverErrors.capital_approval;
            errors.value = typeof specific === 'string'
                ? [specific]
                : humanErrorMessages(serverErrors);
        },
        onFinish: () => {
            busy.value = false;
        },
    };

    if (method === 'put') {
        router.put(url, payload, options);
        return;
    }

    router.post(url, payload, options);
};

const prepare = (): void =>
    post(
        '/formation/capital/approval/prepare',
        {},
        c.value.prepared,
    );

const startReview = (): void => {
    if (reviewer.value === '') return;

    post(
        '/formation/capital/approval/review',
        { reviewer_membership_id: reviewer.value },
        c.value.reviewStarted,
    );
};

const completeReview = (
    outcome: 'approved' | 'changes_requested',
): void =>
    post(
        '/formation/capital/approval/review',
        {
            outcome,
            notes: reviewNotes.value === '' ? null : reviewNotes.value,
        },
        outcome === 'approved'
            ? c.value.reviewApproved
            : c.value.reviewChanges,
        'put',
    );

const openApproval = (): void =>
    post(
        '/formation/capital/approval/open',
        {
            meeting_id: meeting.value === '' ? null : meeting.value,
        },
        c.value.approvalOpened,
    );

const approve = (): void =>
    post(
        '/formation/capital/approval/approve',
        {
            rationale: rationale.value === '' ? null : rationale.value,
        },
        c.value.approvalRecorded,
    );

const vote = (
    choice: 'for' | 'against' | 'abstain',
): void =>
    post(
        '/formation/capital/approval/vote',
        {
            choice,
            rationale: rationale.value === '' ? null : rationale.value,
        },
        c.value.voteRecorded,
    );

const resolve = (): void =>
    post(
        '/formation/capital/approval/resolve',
        {},
        c.value.resolved,
    );
</script>

<template>
    <section
        data-testid="capital-approval-stage"
        class="mt-6 min-w-0 rounded-[24px] border border-[#d7c998] bg-[linear-gradient(145deg,#fffdf7_0%,#ffffff_50%,#f4f8f4_100%)] p-4 sm:p-6"
    >
        <template v-if="readModel">
            <header class="min-w-0">
                <div class="flex min-w-0 flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[#725d1f]">
                            {{ c.eyebrow }}
                        </p>
                        <h3 class="mt-2 break-words text-lg font-black tracking-[-0.02em] sm:text-xl">
                            {{ c.title }}
                        </h3>
                    </div>
                    <span
                        data-testid="capital-approval-status"
                        class="max-w-full rounded-full border px-3 py-1.5 text-xs font-black"
                        :class="readModel.approved
                            ? 'border-[#add2b8] bg-[#eef8f1] text-[#155f39]'
                            : readModel.planChanged
                              ? 'border-[#e3bcbc] bg-[#fff4f4] text-[#8a3131]'
                              : 'border-[#e3d7ad] bg-[#fffaf0] text-[#665527]'"
                    >
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

            <PbrErrorSummary
                class="mt-5"
                :title="c.actionFailed"
                :help="c.actionHelp"
                :errors="errors"
            />

            <p
                v-if="success"
                class="pbr-safe-copy mt-5 rounded-2xl border border-[#bcdcc6] bg-[#eef8f1] px-4 py-3 text-sm font-semibold text-[#155f39]"
            >
                {{ success }}
            </p>

            <div
                v-if="summary"
                class="mt-5 grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-4"
            >
                <div class="min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                    <p class="text-xs font-semibold text-[var(--pbr-muted)]">{{ c.preferredPlan }}</p>
                    <p class="mt-2 break-words text-lg font-black">{{ preferredLabel(summary.preferredPlan) }}</p>
                </div>
                <div class="min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                    <p class="text-xs font-semibold text-[var(--pbr-muted)]">{{ c.capitalRequired }}</p>
                    <p class="mt-2 break-words text-lg font-black">{{ showMoney(summary.totalCapitalRequirement) }}</p>
                </div>
                <div class="min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                    <p class="text-xs font-semibold text-[var(--pbr-muted)]">{{ c.confirmedFunding }}</p>
                    <p class="mt-2 break-words text-lg font-black">{{ showMoney(summary.confirmedFunding) }}</p>
                </div>
                <div class="min-w-0 rounded-2xl border border-[#d8c78e] bg-[#fffaf0] p-4">
                    <p class="text-xs font-semibold text-[#665527]">{{ c.fundingGap }}</p>
                    <p class="mt-2 break-words text-lg font-black text-[#665527]">{{ showMoney(summary.fundingGap) }}</p>
                </div>
                <div class="min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                    <p class="text-xs font-semibold text-[var(--pbr-muted)]">{{ c.fundingSurplus }}</p>
                    <p class="mt-2 break-words text-sm font-black">{{ showMoney(summary.fundingSurplus) }}</p>
                </div>
                <div class="min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                    <p class="text-xs font-semibold text-[var(--pbr-muted)]">{{ c.fundedPercent }}</p>
                    <p class="mt-2 break-words text-sm font-black">{{ showPercent(summary.fundedPercentage) }}</p>
                </div>
                <div class="min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4 sm:col-span-2">
                    <p class="text-xs font-semibold text-[var(--pbr-muted)]">{{ c.capitalRule }}</p>
                    <p class="pbr-safe-copy mt-2 break-words text-sm font-black">{{ capitalRuleText }}</p>
                    <p
                        v-if="summary.shortfallRuleNotes"
                        class="pbr-safe-copy mt-1 break-words text-xs leading-5 text-[var(--pbr-muted)]"
                    >
                        {{ summary.shortfallRuleNotes }}
                    </p>
                </div>
            </div>

            <p class="pbr-safe-copy mt-3 text-xs leading-5 text-[var(--pbr-muted)]">
                {{ c.noManualTotals }}
            </p>

            <div
                v-if="Array.isArray(readModel.readinessReasons) && readModel.readinessReasons.length > 0 && !readModel.hasPreparedVersion"
                class="mt-5 rounded-2xl border border-[#eadcb1] bg-[#fffaf0] p-4"
            >
                <h4 class="text-sm font-black text-[#665527]">{{ c.reasonsTitle }}</h4>
                <ul class="mt-2 space-y-1 text-sm leading-6 text-[#665527]">
                    <li v-for="reason in readModel.readinessReasons" :key="String(reason)">
                        • {{ reasonLabel(reason) }}
                    </li>
                </ul>
            </div>

            <div
                v-if="readModel.planChanged"
                class="mt-5 rounded-2xl border border-[#e3bcbc] bg-[#fff4f4] p-4"
            >
                <p class="pbr-safe-copy text-sm font-black leading-6 text-[#8a3131]">
                    {{ c.planChanged }}
                </p>
                <p class="pbr-safe-copy mt-1 text-xs leading-5 text-[#8a3131]">
                    {{ c.stale }}
                </p>
            </div>

            <div
                v-if="readModel.sourceRevisions"
                class="mt-4 flex flex-wrap gap-2 text-[11px] text-[var(--pbr-muted)]"
            >
                <span class="rounded-full border border-[var(--pbr-line)] bg-white px-3 py-1.5">
                    {{ c.sourcePlanning }}: {{ readModel.sourceRevisions.capitalPlanning }}
                </span>
                <span class="rounded-full border border-[var(--pbr-line)] bg-white px-3 py-1.5">
                    {{ c.sourceRule }}: {{ readModel.sourceRevisions.capitalRule }}
                </span>
                <span class="rounded-full border border-[var(--pbr-line)] bg-white px-3 py-1.5">
                    {{ c.sourceComparison }}: {{ readModel.sourceRevisions.capitalComparison }}
                </span>
            </div>

            <div
                v-if="actions.canPrepare"
                class="mt-5 rounded-2xl border border-[#d4e2d7] bg-white p-4"
            >
                <button
                    data-testid="capital-approval-prepare"
                    type="button"
                    class="min-h-11 rounded-xl bg-[var(--pbr-green-dark)] px-5 text-sm font-black text-white disabled:opacity-60"
                    :disabled="busy"
                    @click="prepare"
                >
                    {{ busy ? c.preparing : (readModel.planChanged ? c.prepareNew : c.prepare) }}
                </button>
            </div>

            <section
                v-if="readModel.hasPreparedVersion && readModel.preparedVersionCurrent"
                class="mt-5 rounded-2xl border border-[var(--pbr-line)] bg-white p-4 sm:p-5"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.12em] text-[var(--pbr-muted)]">
                            {{ c.reviewTitle }}
                        </p>
                        <p class="mt-1 text-sm font-black">{{ c.frozen }}</p>
                    </div>
                    <span class="rounded-full border border-[#c9ddcf] bg-[#f3faf5] px-3 py-1.5 text-xs font-black text-[#155f39]">
                        {{ c.current }}
                    </span>
                </div>

                <div v-if="actions.canCreateReview" class="mt-4">
                    <label class="block text-sm font-bold">
                        <span>{{ c.reviewer }}</span>
                        <select
                            v-model="reviewer"
                            data-testid="capital-approval-reviewer"
                            class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm sm:max-w-md"
                        >
                            <option value="">{{ c.chooseReviewer }}</option>
                            <option
                                v-for="item in readModel.reviewers"
                                :key="item.id"
                                :value="item.id"
                            >
                                {{ item.name }}
                            </option>
                        </select>
                    </label>
                    <button
                        data-testid="capital-approval-start-review"
                        type="button"
                        class="mt-3 min-h-10 rounded-xl border border-[var(--pbr-green)] bg-white px-4 text-sm font-black text-[var(--pbr-green-dark)] disabled:opacity-50"
                        :disabled="busy || reviewer === ''"
                        @click="startReview"
                    >
                        {{ c.startReview }}
                    </button>
                </div>

                <div v-if="readModel.review" class="mt-4 rounded-2xl bg-[#f7faf8] p-4">
                    <p class="text-sm font-black">
                        {{ c.reviewer }}: {{ readModel.review.reviewer }}
                    </p>
                    <p
                        v-if="readModel.review.outcome === 'approved'"
                        class="mt-2 text-sm font-semibold text-[#155f39]"
                    >
                        {{ c.reviewApproved }}
                    </p>
                    <p
                        v-else-if="readModel.review.outcome === 'changes_requested' || readModel.review.outcome === 'rejected'"
                        class="pbr-safe-copy mt-2 text-sm font-semibold text-[#8a3131]"
                    >
                        {{ c.reviewChanges }}
                    </p>

                    <div v-if="actions.canCompleteReview" class="mt-4">
                        <label class="block text-sm font-bold">
                            <span>{{ c.reviewNotes }}</span>
                            <textarea
                                v-model="reviewNotes"
                                rows="3"
                                class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm"
                            />
                        </label>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button
                                data-testid="capital-approval-confirm-review"
                                type="button"
                                class="min-h-10 rounded-xl bg-[var(--pbr-green-dark)] px-4 text-sm font-black text-white disabled:opacity-50"
                                :disabled="busy"
                                @click="completeReview('approved')"
                            >
                                {{ c.confirmReview }}
                            </button>
                            <button
                                type="button"
                                class="min-h-10 rounded-xl border border-[#d9b5b5] bg-white px-4 text-sm font-black text-[#8a3131] disabled:opacity-50"
                                :disabled="busy"
                                @click="completeReview('changes_requested')"
                            >
                                {{ c.requestChanges }}
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <section
                v-if="readModel.hasPreparedVersion && readModel.preparedVersionCurrent && readModel.review?.outcome === 'approved'"
                class="mt-5 rounded-2xl border border-[var(--pbr-line)] bg-white p-4 sm:p-5"
            >
                <h4 class="text-sm font-black">{{ c.approvalRequirements }}</h4>

                <div
                    v-if="authority.configured !== true"
                    class="pbr-safe-copy mt-3 rounded-xl border border-[#eadcb1] bg-[#fffaf0] p-3 text-sm font-semibold text-[#665527]"
                >
                    {{ c.authorityMissing }}
                </div>

                <template v-else>
                    <dl class="mt-3 grid min-w-0 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="min-w-0 rounded-xl bg-[#f7faf8] p-3">
                            <dt class="text-xs font-semibold text-[var(--pbr-muted)]">{{ c.authoritySource }}</dt>
                            <dd class="mt-1 break-words text-sm font-black">{{ authoritySourceLabel(authority.sourceKind) }}</dd>
                        </div>
                        <div class="min-w-0 rounded-xl bg-[#f7faf8] p-3">
                            <dt class="text-xs font-semibold text-[var(--pbr-muted)]">{{ c.approvalMethod }}</dt>
                            <dd class="mt-1 break-words text-sm font-black">{{ methodLabel(authority.method) }}</dd>
                        </div>
                        <div class="min-w-0 rounded-xl bg-[#f7faf8] p-3">
                            <dt class="text-xs font-semibold text-[var(--pbr-muted)]">{{ c.requiredApprovals }}</dt>
                            <dd class="mt-1 text-sm font-black">{{ authority.requiredApprovals }}</dd>
                        </div>
                        <div class="min-w-0 rounded-xl bg-[#f7faf8] p-3">
                            <dt class="text-xs font-semibold text-[var(--pbr-muted)]">{{ c.requiredVotes }}</dt>
                            <dd class="mt-1 text-sm font-black">{{ authority.requiredVotes }}</dd>
                        </div>
                    </dl>

                    <div class="mt-4">
                        <p class="text-xs font-black uppercase tracking-[0.1em] text-[var(--pbr-muted)]">
                            {{ c.approvers }}
                        </p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <span
                                v-for="actor in authority.actors"
                                :key="actor.name + actor.capacity"
                                class="max-w-full rounded-full border border-[var(--pbr-line)] bg-white px-3 py-1.5 text-xs font-semibold"
                            >
                                {{ actor.name }} · {{ actor.capacity }}
                            </span>
                        </div>
                    </div>

                    <div
                        v-if="authority.meetingRequired"
                        class="mt-4 rounded-xl border border-[#eadcb1] bg-[#fffaf0] p-3"
                    >
                        <p class="text-sm font-black text-[#665527]">{{ c.meetingRequired }}</p>
                        <p class="pbr-safe-copy mt-1 text-xs leading-5 text-[#665527]">{{ c.meetingHelp }}</p>
                        <select
                            v-model="meeting"
                            data-testid="capital-approval-meeting"
                            class="mt-3 min-h-10 w-full rounded-xl border border-[#d8c78e] bg-white px-3 text-sm sm:max-w-md"
                        >
                            <option value="">{{ c.chooseMeeting }}</option>
                            <option
                                v-for="item in readModel.eligibleMeetings"
                                :key="item.id"
                                :value="item.id"
                            >
                                {{ item.label }}
                            </option>
                        </select>
                    </div>

                    <button
                        v-if="actions.canOpenDecision"
                        data-testid="capital-approval-open"
                        type="button"
                        class="mt-4 min-h-11 rounded-xl bg-[var(--pbr-green-dark)] px-5 text-sm font-black text-white disabled:opacity-60"
                        :disabled="busy || (authority.meetingRequired && meeting === '')"
                        @click="openApproval"
                    >
                        {{ c.openApproval }}
                    </button>
                </template>
            </section>

            <section
                v-if="decision"
                class="mt-5 rounded-2xl border border-[var(--pbr-line)] bg-white p-4 sm:p-5"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h4 class="text-sm font-black">{{ c.approvalProgress }}</h4>
                    <span
                        class="rounded-full border px-3 py-1.5 text-xs font-black"
                        :class="readModel.approved
                            ? 'border-[#add2b8] bg-[#eef8f1] text-[#155f39]'
                            : 'border-[#e3d7ad] bg-[#fffaf0] text-[#665527]'"
                    >
                        {{ readModel.approved ? c.approved : c.approvalRequired }}
                    </span>
                </div>

                <div class="mt-3 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-xl bg-[#f7faf8] p-3">
                        <p class="text-xs text-[var(--pbr-muted)]">{{ c.requiredApprovals }}</p>
                        <p class="mt-1 text-sm font-black">
                            {{ decision.progress?.approvals ?? 0 }} / {{ decision.requiredApprovals ?? 0 }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-[#f7faf8] p-3">
                        <p class="text-xs text-[var(--pbr-muted)]">{{ c.requiredVotes }}</p>
                        <p class="mt-1 text-sm font-black">
                            {{ decision.progress?.supportingVotes ?? 0 }} / {{ decision.requiredVotes ?? 0 }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-[#f7faf8] p-3">
                        <p class="text-xs text-[var(--pbr-muted)]">{{ c.quorum }}</p>
                        <p class="mt-1 text-sm font-black">{{ decision.quorumCount ?? 0 }}</p>
                    </div>
                </div>

                <label
                    v-if="actions.canApprove || actions.canVote"
                    class="mt-4 block text-sm font-bold"
                >
                    <span>{{ c.reviewNotes }}</span>
                    <textarea
                        v-model="rationale"
                        rows="2"
                        class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm"
                    />
                </label>

                <div class="mt-4 flex flex-wrap gap-2">
                    <button
                        v-if="actions.canApprove"
                        data-testid="capital-approval-approve"
                        type="button"
                        class="min-h-11 rounded-xl bg-[var(--pbr-green-dark)] px-5 text-sm font-black text-white disabled:opacity-60"
                        :disabled="busy"
                        @click="approve"
                    >
                        {{ c.approve }}
                    </button>

                    <template v-if="actions.canVote">
                        <button
                            data-testid="capital-approval-vote-for"
                            type="button"
                            class="min-h-10 rounded-xl bg-[var(--pbr-green-dark)] px-4 text-sm font-black text-white disabled:opacity-60"
                            :disabled="busy"
                            @click="vote('for')"
                        >
                            {{ c.voteFor }}
                        </button>
                        <button
                            type="button"
                            class="min-h-10 rounded-xl border border-slate-300 bg-white px-4 text-sm font-black disabled:opacity-60"
                            :disabled="busy"
                            @click="vote('against')"
                        >
                            {{ c.voteAgainst }}
                        </button>
                        <button
                            type="button"
                            class="min-h-10 rounded-xl border border-slate-300 bg-white px-4 text-sm font-black disabled:opacity-60"
                            :disabled="busy"
                            @click="vote('abstain')"
                        >
                            {{ c.abstain }}
                        </button>
                    </template>

                    <button
                        v-if="actions.canResolve"
                        data-testid="capital-approval-resolve"
                        type="button"
                        class="min-h-10 rounded-xl border border-[var(--pbr-green)] bg-white px-4 text-sm font-black text-[var(--pbr-green-dark)] disabled:opacity-60"
                        :disabled="busy"
                        @click="resolve"
                    >
                        {{ c.resolve }}
                    </button>
                </div>
            </section>

            <section
                v-if="readModel.approved"
                data-testid="capital-approval-approved"
                class="mt-5 rounded-2xl border border-[#add2b8] bg-[#eef8f1] p-4 sm:p-5"
            >
                <h4 class="text-base font-black text-[#155f39]">{{ c.approved }}</h4>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div class="rounded-xl bg-white/80 p-3">
                        <p class="text-xs font-semibold text-[var(--pbr-muted)]">{{ c.approvedBy }}</p>
                        <p
                            v-for="item in readModel.approvedBy"
                            :key="item.name + item.recordedAt"
                            class="mt-1 break-words text-sm font-black"
                        >
                            {{ item.name }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-white/80 p-3">
                        <p class="text-xs font-semibold text-[var(--pbr-muted)]">{{ c.approvalDate }}</p>
                        <p class="mt-1 break-words text-sm font-black">{{ readModel.approvalDate ?? '—' }}</p>
                    </div>
                </div>
                <p
                    v-if="readModel.signatureRequired"
                    class="pbr-safe-copy mt-3 text-sm font-semibold leading-6 text-[#665527]"
                >
                    {{ c.signatureRequired }}
                </p>
                <p
                    v-else
                    class="pbr-safe-copy mt-3 text-sm font-semibold leading-6 text-[#155f39]"
                >
                    {{ c.notSignedEffective }}
                </p>
                <p class="pbr-safe-copy mt-2 text-xs leading-5 text-[var(--pbr-muted)]">
                    {{ c.explicitBoundary }}
                </p>
            </section>
        </template>

        <p
            v-else
            class="pbr-safe-copy text-sm leading-6 text-[var(--pbr-muted)]"
        >
            {{ c.unavailable }}
        </p>
    </section>
</template>
