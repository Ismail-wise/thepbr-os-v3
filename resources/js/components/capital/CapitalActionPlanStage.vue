<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
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
        eyebrow: 'Act',
        title: 'Capital Action Plan',
        subtitle:
            'Turn the recorded Capital decision into clear follow-up Actions without changing the approved Capital truth.',
        unavailable:
            'Record the approved Capital Decision before creating its Action Plan.',
        chapterComplete:
            'Chapter 1 Capital setup is complete. Actions may still remain Open or In Progress.',
        boundary:
            'Action Plan is not Signature, Effectivity, funding received, Contribution accepted, Equity assigned or Ownership changed.',
        approvedPlan: 'Approved Plan',
        capitalRequired: 'Capital Required',
        fundingGap: 'Funding Gap',
        fundingSurplus: 'Funding Surplus',
        capitalRule: 'Capital Rule / Shortfall Response',
        reduceScopeRule: 'Reduce startup scope',
        delayRule: 'Delay selected Capital items',
        borrowRule: 'Prepare borrowing / financing',
        capitalCallRule: 'Prepare a later Capital Call',
        noShortfallRule: 'No shortfall response recorded',
        decisionOwner: 'Decision Owner',
        reviewDate: 'Review Date',
        signatureRequired: 'Signature Required Before Effectivity',
        signatureHelp:
            'This Action Plan does not create a Signature Request or make the Capital record Effective.',
        planningChanged: 'Current Planning Has Changed',
        planningChangedHelp:
            'These Actions remain linked to the recorded approved Capital Decision. Changed planning needs its own new approval.',
        suggested: 'Suggested Next Actions',
        suggestionHelp:
            'Suggestions come from the recorded approved Capital decision. Nothing is created until you choose Add Action.',
        owner: 'Owner',
        addAction: 'Add Action',
        adding: 'Adding…',
        custom: 'Add Custom Action',
        action: 'Action',
        details: 'Details / Description',
        dueDate: 'Due Date',
        optional: 'Optional',
        currentActions: 'Current Actions',
        noActions: 'No Capital Actions have been created yet.',
        status: 'Status',
        open: 'Open',
        inProgress: 'In Progress',
        blocked: 'Blocked',
        completed: 'Completed',
        cancelled: 'Cancelled',
        blockedReason: 'Blocked Reason',
        markOpen: 'Mark Open',
        markInProgress: 'Mark In Progress',
        markBlocked: 'Mark Blocked',
        markCompleted: 'Mark Completed',
        cancelAction: 'Cancel Action',
        completedAt: 'Completed',
        outstanding: 'Outstanding',
        completedCount: 'Completed',
        actionPlanEstablished: 'Action Plan Established',
        actionPlanNotEstablished: 'Action Plan Not Yet Established',
        saveFailed: 'Capital Action could not be saved',
        saveHelp:
            'Check the Action owner, required fields and your Capital / Governance Action permissions.',
        statusFailed: 'Capital Action status could not be updated',
        created: 'Capital Action added.',
        updated: 'Capital Action status updated.',
        selectOwner: 'Choose owner',
        reviewSuggestion: 'Review Approved Capital Decision',
        reduceScopeSuggestion: 'Prepare Scope-Reduction Changes for Review',
        delaySuggestion: 'Plan Delayed Capital Items and Revised Timing',
        borrowSuggestion: 'Prepare Borrowing / Financing Option for Review',
        capitalCallSuggestion: 'Prepare Capital Call / Contribution Process',
        signatureSuggestion: 'Complete Required Capital Approval Signature Before Effectivity',
        reviewSuggestionHelp:
            'Review the recorded Capital decision on the agreed Review Date.',
        reduceScopeSuggestionHelp:
            'Prepare proposed scope changes for review. Completing this Action does not modify the approved Capital Plan.',
        delaySuggestionHelp:
            'Prepare revised timing without silently changing the approved Opening Date or Capital Plan.',
        borrowSuggestionHelp:
            'Prepare financing options for review. This does not create debt or record funding received.',
        capitalCallSuggestionHelp:
            'Prepare the later Capital Call / Contribution workflow. This does not execute a Capital Call.',
        signatureSuggestionHelp:
            'Prepare the required signature step. This does not create or complete a signature.',
        semantics:
            'Completing an Action only changes Action execution status. It does not change the Capital Plan, Decision Record or downstream business truth.',
    },
    my: {
        eyebrow: 'Act',
        title: 'Capital Action Plan',
        subtitle:
            'မှတ်တမ်းတင်ပြီးသား Capital Decision ကို မပြောင်းဘဲ လက်တွေ့ဆက်လုပ်ရမယ့် Actions တွေအဖြစ် ပြောင်းပါ။',
        unavailable:
            'Approved Capital Decision ကို Record လုပ်ပြီးမှ Action Plan ပြုလုပ်နိုင်ပါတယ်။',
        chapterComplete:
            'Chapter 1 Capital setup ပြီးပါပြီ။ Actions တွေ Open / In Progress အဖြစ် ဆက်ရှိနေနိုင်ပါတယ်။',
        boundary:
            'Action Plan က Signature, Effectivity, funding received, Contribution accepted, Equity assigned သို့မဟုတ် Ownership changed မဟုတ်ပါ။',
        approvedPlan: 'Approved Plan',
        capitalRequired: 'Capital Required',
        fundingGap: 'Funding Gap',
        fundingSurplus: 'Funding Surplus',
        capitalRule: 'Capital Rule / Shortfall Response',
        reduceScopeRule: 'Startup scope ကို လျှော့မည်',
        delayRule: 'ရွေးထားသော Capital items ကို delay လုပ်မည်',
        borrowRule: 'Borrowing / financing ကို ပြင်ဆင်မည်',
        capitalCallRule: 'နောက်ပိုင်း Capital Call ကို ပြင်ဆင်မည်',
        noShortfallRule: 'Shortfall response မရှိပါ',
        decisionOwner: 'Decision Owner',
        reviewDate: 'Review Date',
        signatureRequired: 'Effectivity မတိုင်မီ Signature လိုအပ်သည်',
        signatureHelp:
            'ဒီ Action Plan က Signature Request မဖန်တီးပါ၊ Capital Record ကို Effective မလုပ်ပါ။',
        planningChanged: 'Current Planning ပြောင်းထားသည်',
        planningChangedHelp:
            'ဒီ Actions တွေက recorded approved Capital Decision နဲ့ပဲ ချိတ်ထားပါတယ်။ ပြောင်းထားတဲ့ planning အတွက် Approval အသစ်လိုပါတယ်။',
        suggested: 'Suggested Next Actions',
        suggestionHelp:
            'Suggestions တွေက recorded approved Capital decision ကနေ ထွက်လာတာပါ။ Add Action ကိုရွေးမှသာ Action တကယ်ဖန်တီးပါမယ်။',
        owner: 'Owner',
        addAction: 'Action ထည့်မည်',
        adding: 'ထည့်နေသည်…',
        custom: 'Custom Action ထည့်မည်',
        action: 'Action',
        details: 'Details / Description',
        dueDate: 'Due Date',
        optional: 'Optional',
        currentActions: 'Current Actions',
        noActions: 'Capital Action မရှိသေးပါ။',
        status: 'Status',
        open: 'Open',
        inProgress: 'In Progress',
        blocked: 'Blocked',
        completed: 'Completed',
        cancelled: 'Cancelled',
        blockedReason: 'Blocked Reason',
        markOpen: 'Open ပြန်လုပ်မည်',
        markInProgress: 'In Progress လုပ်မည်',
        markBlocked: 'Blocked လုပ်မည်',
        markCompleted: 'Completed လုပ်မည်',
        cancelAction: 'Cancel Action',
        completedAt: 'Completed',
        outstanding: 'Outstanding',
        completedCount: 'Completed',
        actionPlanEstablished: 'Action Plan ပြုလုပ်ပြီး',
        actionPlanNotEstablished: 'Action Plan မပြုလုပ်ရသေး',
        saveFailed: 'Capital Action မသိမ်းနိုင်ပါ',
        saveHelp:
            'Action owner, required fields နဲ့ Capital / Governance Action permission တွေကို စစ်ပါ။',
        statusFailed: 'Capital Action status မပြောင်းနိုင်ပါ',
        created: 'Capital Action ထည့်ပြီးပါပြီ။',
        updated: 'Capital Action status ပြောင်းပြီးပါပြီ။',
        selectOwner: 'Owner ရွေးပါ',
        reviewSuggestion: 'Approved Capital Decision ကို Review လုပ်မည်',
        reduceScopeSuggestion: 'Scope လျှော့မယ့် Changes တွေကို Review အတွက် ပြင်ဆင်မည်',
        delaySuggestion: 'Delay လုပ်မယ့် Capital Items နဲ့ Timing ကို စီစဉ်မည်',
        borrowSuggestion: 'Borrowing / Financing Option ကို Review အတွက် ပြင်ဆင်မည်',
        capitalCallSuggestion: 'Capital Call / Contribution Process ကို ပြင်ဆင်မည်',
        signatureSuggestion: 'Effectivity မတိုင်မီ လိုအပ်တဲ့ Capital Approval Signature ကို ပြင်ဆင်မည်',
        reviewSuggestionHelp:
            'သတ်မှတ်ထားတဲ့ Review Date မှာ recorded Capital Decision ကို ပြန်စစ်ရန်ပါ။',
        reduceScopeSuggestionHelp:
            'Scope ပြောင်းလဲမှုအကြံပြုချက်ကို Review အတွက်ပြင်ဆင်ပါ။ ဒီ Action ပြီးတာနဲ့ Approved Plan ကို မပြောင်းပါ။',
        delaySuggestionHelp:
            'Approved Opening Date / Capital Plan ကို တိတ်တဆိတ်မပြောင်းဘဲ Timing အသစ်ကို ပြင်ဆင်ပါ။',
        borrowSuggestionHelp:
            'Financing options ကို Review အတွက်ပြင်ဆင်ပါ။ Debt သို့မဟုတ် funding received truth မဖန်တီးပါ။',
        capitalCallSuggestionHelp:
            'နောက်ပိုင်း Capital Call / Contribution workflow အတွက်ပြင်ဆင်ပါ။ Capital Call ကို အခုမ execute လုပ်ပါ။',
        signatureSuggestionHelp:
            'လိုအပ်တဲ့ signature step အတွက်ပြင်ဆင်ပါ။ Signature Request / Signed truth မဖန်တီးပါ။',
        semantics:
            'Action Completed လုပ်တာက Action status ကိုပဲပြောင်းပါတယ်။ Capital Plan, Decision Record သို့မဟုတ် downstream business truth မပြောင်းပါ။',
    },
    mixed: {
        eyebrow: 'Act',
        title: 'Capital Action Plan',
        subtitle:
            'Recorded Capital Decision ကို မပြောင်းဘဲ follow-up Actions တွေဖန်တီးပြီး track လုပ်ပါ။',
        unavailable:
            'Approved Capital Decision ကို Record လုပ်ပြီးမှ Action Plan ရပါမယ်။',
        chapterComplete:
            'Chapter 1 Capital setup complete. Actions က Open / In Progress အဖြစ် ဆက်ရှိနိုင်ပါတယ်။',
        boundary:
            'Action Plan != Signature != Effective. Funding / Contribution / Equity / Ownership truth ကို မပြောင်းပါ။',
        approvedPlan: 'Approved Plan',
        capitalRequired: 'Capital Required',
        fundingGap: 'Funding Gap',
        fundingSurplus: 'Funding Surplus',
        capitalRule: 'Capital Rule / Shortfall Response',
        reduceScopeRule: 'Reduce startup scope',
        delayRule: 'Delay selected Capital items',
        borrowRule: 'Prepare borrowing / financing',
        capitalCallRule: 'Prepare a later Capital Call',
        noShortfallRule: 'No shortfall response recorded',
        decisionOwner: 'Decision Owner',
        reviewDate: 'Review Date',
        signatureRequired: 'Signature Required Before Effectivity',
        signatureHelp:
            'Action Plan က Signature Request မဖန်တီးဘဲ Formal Record ကို Effective မလုပ်ပါ။',
        planningChanged: 'Current Planning Has Changed',
        planningChangedHelp:
            'Actions က recorded approved version နဲ့ပဲ linked ဖြစ်ပါတယ်။ Changed planning အတွက် new approval လိုပါတယ်။',
        suggested: 'Suggested Next Actions',
        suggestionHelp:
            'Suggestions တွေကို Add Action နှိပ်မှသာ တကယ် create လုပ်ပါမယ်။',
        owner: 'Owner',
        addAction: 'Add Action',
        adding: 'Adding…',
        custom: 'Add Custom Action',
        action: 'Action',
        details: 'Details / Description',
        dueDate: 'Due Date',
        optional: 'Optional',
        currentActions: 'Current Actions',
        noActions: 'No Capital Actions yet.',
        status: 'Status',
        open: 'Open',
        inProgress: 'In Progress',
        blocked: 'Blocked',
        completed: 'Completed',
        cancelled: 'Cancelled',
        blockedReason: 'Blocked Reason',
        markOpen: 'Mark Open',
        markInProgress: 'Mark In Progress',
        markBlocked: 'Mark Blocked',
        markCompleted: 'Mark Completed',
        cancelAction: 'Cancel Action',
        completedAt: 'Completed',
        outstanding: 'Outstanding',
        completedCount: 'Completed',
        actionPlanEstablished: 'Action Plan Established',
        actionPlanNotEstablished: 'Action Plan Not Yet Established',
        saveFailed: 'Capital Action could not be saved',
        saveHelp:
            'Owner, required fields နဲ့ Capital / Governance Action permissions ကိုစစ်ပါ။',
        statusFailed: 'Capital Action status could not be updated',
        created: 'Capital Action added.',
        updated: 'Capital Action status updated.',
        selectOwner: 'Choose owner',
        reviewSuggestion: 'Review Approved Capital Decision',
        reduceScopeSuggestion: 'Prepare Scope-Reduction Changes for Review',
        delaySuggestion: 'Plan Delayed Capital Items and Revised Timing',
        borrowSuggestion: 'Prepare Borrowing / Financing Option for Review',
        capitalCallSuggestion: 'Prepare Capital Call / Contribution Process',
        signatureSuggestion: 'Complete Required Capital Approval Signature Before Effectivity',
        reviewSuggestionHelp:
            'Recorded Capital Decision ကို agreed Review Date မှာ review လုပ်ရန်ပါ။',
        reduceScopeSuggestionHelp:
            'Scope changes ကို review အတွက်ပြင်ဆင်ပါ။ Approved Plan ကို auto-change မလုပ်ပါ။',
        delaySuggestionHelp:
            'Approved plan ကိုမပြောင်းဘဲ timing update ကို prepare လုပ်ပါ။',
        borrowSuggestionHelp:
            'Financing options ကို review အတွက် prepare လုပ်ပါ။ Debt/funding truth မဖန်တီးပါ။',
        capitalCallSuggestionHelp:
            'Later Capital Call / Contribution process ကို prepare လုပ်ပါ။ Capital Call execute မလုပ်ပါ။',
        signatureSuggestionHelp:
            'Required signature step ကို prepare လုပ်ပါ။ Signed truth မဖန်တီးပါ။',
        semantics:
            'Action completion က Action status ကိုပဲ update လုပ်ပြီး Capital / downstream truth ကိုမပြောင်းပါ။',
    },
} as const;

const c = computed(() => copy[uiLanguageMode.value]);
const busy = ref(false);
const errors = ref<string[]>([]);
const success = ref('');
const suggestedOwner = ref(
    String(props.readModel?.defaultOwnerMembershipId ?? ''),
);
const customOwner = ref(
    String(props.readModel?.defaultOwnerMembershipId ?? ''),
);
const customTitle = ref('');
const customDescription = ref('');
const customDueDate = ref('');
const blockedReasons = reactive<Record<string, string>>({});

watch(
    () => props.readModel?.defaultOwnerMembershipId,
    (value) => {
        const next = String(value ?? '');
        if (suggestedOwner.value === '') suggestedOwner.value = next;
        if (customOwner.value === '') customOwner.value = next;
    },
);

const source = computed(() => props.readModel?.sourceDecisionRecord ?? {});
const actions = computed(() => props.readModel?.actions ?? []);
const suggestions = computed(() => props.readModel?.suggestions ?? []);

const money = (value: unknown): string => {
    if (value === null || value === undefined || value === '') return '—';
    const currency = String(source.value?.baseCurrency ?? '');
    return `${String(value)} ${currency}`.trim();
};

const preferredPlan = computed(() => {
    const value = String(source.value?.preferredPlan ?? '');
    if (value === 'lean') return 'Lean';
    if (value === 'base') return 'Base';
    if (value === 'growth') return 'Growth';
    return '—';
});

const shortfallResponseText = computed(() => {
    const responses = source.value?.shortfallResponses;

    if (!Array.isArray(responses) || responses.length === 0) {
        return c.value.noShortfallRule;
    }

    return responses
        .map((response: unknown) => {
            const value = String(response ?? '');
            if (value === 'reduce_scope') return c.value.reduceScopeRule;
            if (value === 'delay') return c.value.delayRule;
            if (value === 'borrow') return c.value.borrowRule;
            if (value === 'capital_call') return c.value.capitalCallRule;
            return '';
        })
        .filter((value: string) => value !== '')
        .join(' · ');
});

const statusLabel = (status: unknown): string => {
    const value = String(status ?? '');
    if (value === 'open') return c.value.open;
    if (value === 'in_progress') return c.value.inProgress;
    if (value === 'blocked') return c.value.blocked;
    if (value === 'completed') return c.value.completed;
    if (value === 'cancelled') return c.value.cancelled;
    return '—';
};

const suggestionTitle = (key: unknown): string => {
    const value = String(key ?? '');
    if (value === 'review_approved_decision') return c.value.reviewSuggestion;
    if (value === 'prepare_scope_reduction') return c.value.reduceScopeSuggestion;
    if (value === 'plan_delayed_items') return c.value.delaySuggestion;
    if (value === 'prepare_borrowing_review') return c.value.borrowSuggestion;
    if (value === 'prepare_capital_call_process') return c.value.capitalCallSuggestion;
    if (value === 'complete_required_signature') return c.value.signatureSuggestion;
    return c.value.action;
};

const suggestionHelp = (key: unknown): string => {
    const value = String(key ?? '');
    if (value === 'review_approved_decision') return c.value.reviewSuggestionHelp;
    if (value === 'prepare_scope_reduction') return c.value.reduceScopeSuggestionHelp;
    if (value === 'plan_delayed_items') return c.value.delaySuggestionHelp;
    if (value === 'prepare_borrowing_review') return c.value.borrowSuggestionHelp;
    if (value === 'prepare_capital_call_process') return c.value.capitalCallSuggestionHelp;
    if (value === 'complete_required_signature') return c.value.signatureSuggestionHelp;
    return '';
};

const actionTitle = (action: GenericRow): string =>
    action.suggestionKey
        ? suggestionTitle(action.suggestionKey)
        : String(action.title ?? '');

const actionDescription = (action: GenericRow): string =>
    action.suggestionKey
        ? suggestionHelp(action.suggestionKey)
        : String(action.description ?? '');

const request = (
    method: 'post' | 'put',
    url: string,
    payload: GenericRow,
    message: string,
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
            const specific = serverErrors.capital_action_plan;
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

const addSuggestion = (key: string): void => {
    if (suggestedOwner.value === '') return;

    request(
        'post',
        '/formation/capital/action-plan/suggested',
        {
            suggestion_key: key,
            assigned_membership_id: suggestedOwner.value,
        },
        c.value.created,
    );
};

const addCustom = (): void => {
    if (customOwner.value === '' || customTitle.value.trim() === '') return;

    request(
        'post',
        '/formation/capital/action-plan/custom',
        {
            assigned_membership_id: customOwner.value,
            title: customTitle.value,
            description:
                customDescription.value.trim() === ''
                    ? null
                    : customDescription.value,
            due_date:
                customDueDate.value === ''
                    ? null
                    : customDueDate.value,
        },
        c.value.created,
    );
};

const updateStatus = (
    actionId: string,
    status: 'open' | 'in_progress' | 'blocked' | 'completed' | 'cancelled',
): void => {
    const blockedReason = status === 'blocked'
        ? String(blockedReasons[actionId] ?? '').trim()
        : null;

    request(
        'put',
        `/formation/capital/action-plan/actions/${actionId}/status`,
        {
            status,
            blocked_reason: blockedReason === '' ? null : blockedReason,
        },
        c.value.updated,
    );
};
</script>

<template>
    <section
        data-testid="capital-action-plan-stage"
        class="mt-6 min-w-0 rounded-[24px] border border-[#c8d8ce] bg-[linear-gradient(145deg,#ffffff_0%,#f2f8f4_58%,#f9fbf7_100%)] p-4 sm:p-6"
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
                    <span
                        data-testid="capital-action-plan-status"
                        class="max-w-full rounded-full border px-3 py-1.5 text-xs font-black"
                        :class="readModel.established
                            ? 'border-[#b9d6c0] bg-[#eef8f1] text-[#155f39]'
                            : 'border-[#e2d4a4] bg-[#fffaf0] text-[#665527]'"
                    >
                        {{ readModel.established
                            ? c.actionPlanEstablished
                            : c.actionPlanNotEstablished }}
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
                v-if="readModel.established"
                data-testid="capital-action-plan-established"
                class="pbr-safe-copy mt-5 rounded-2xl border border-[#b9d6c0] bg-[#eef8f1] p-4 text-sm font-semibold leading-6 text-[#155f39]"
            >
                {{ c.chapterComplete }}
            </div>

            <div
                v-if="readModel.planningChangedSinceApproval"
                data-testid="capital-action-plan-stale-warning"
                class="mt-5 rounded-2xl border border-[#e6c7a0] bg-[#fff8ec] p-4"
            >
                <p class="font-black text-[#755420]">{{ c.planningChanged }}</p>
                <p class="pbr-safe-copy mt-1 text-sm leading-6 text-[#755420]">
                    {{ c.planningChangedHelp }}
                </p>
            </div>

            <div
                v-if="readModel.signatureRequired"
                class="mt-5 rounded-2xl border border-[#eadcb1] bg-[#fffaf0] p-4"
            >
                <p class="font-black text-[#665527]">{{ c.signatureRequired }}</p>
                <p class="pbr-safe-copy mt-1 text-sm leading-6 text-[#665527]">
                    {{ c.signatureHelp }}
                </p>
            </div>

            <section class="mt-5 grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                    <p class="text-xs text-[var(--pbr-muted)]">{{ c.approvedPlan }}</p>
                    <p class="mt-1 break-words font-black">{{ preferredPlan }}</p>
                </div>
                <div class="min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                    <p class="text-xs text-[var(--pbr-muted)]">{{ c.capitalRequired }}</p>
                    <p class="mt-1 break-words font-black">{{ money(source.totalCapitalRequirement) }}</p>
                </div>
                <div class="min-w-0 rounded-2xl border border-[#e2d4a4] bg-[#fffaf0] p-4">
                    <p class="text-xs text-[#665527]">{{ c.fundingGap }}</p>
                    <p class="mt-1 break-words font-black text-[#665527]">{{ money(source.fundingGap) }}</p>
                </div>
                <div class="min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                    <p class="text-xs text-[var(--pbr-muted)]">{{ c.fundingSurplus }}</p>
                    <p class="mt-1 break-words font-black">{{ money(source.fundingSurplus) }}</p>
                </div>
                <div class="min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4 sm:col-span-2">
                    <p class="text-xs text-[var(--pbr-muted)]">{{ c.capitalRule }}</p>
                    <p class="pbr-safe-copy mt-1 break-words text-sm font-black">{{ shortfallResponseText }}</p>
                </div>
                <div class="min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                    <p class="text-xs text-[var(--pbr-muted)]">{{ c.decisionOwner }}</p>
                    <p class="mt-1 break-words font-black">{{ source.decisionOwner ?? '—' }}</p>
                </div>
                <div class="min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                    <p class="text-xs text-[var(--pbr-muted)]">{{ c.reviewDate }}</p>
                    <p class="mt-1 break-words font-black">{{ source.reviewDate ?? '—' }}</p>
                </div>
            </section>

            <PbrErrorSummary
                class="mt-5"
                :title="c.saveFailed"
                :help="c.saveHelp"
                :errors="errors"
            />

            <p
                v-if="success"
                class="pbr-safe-copy mt-5 rounded-2xl border border-[#bcdcc6] bg-[#eef8f1] px-4 py-3 text-sm font-semibold text-[#155f39]"
            >
                {{ success }}
            </p>

            <template v-if="readModel.canManage">
                <section
                    v-if="suggestions.length > 0"
                    class="mt-5 rounded-2xl border border-[var(--pbr-line)] bg-white p-4 sm:p-5"
                >
                    <h4 class="text-sm font-black">{{ c.suggested }}</h4>
                    <p class="pbr-safe-copy mt-1 text-xs leading-5 text-[var(--pbr-muted)]">
                        {{ c.suggestionHelp }}
                    </p>

                    <label class="mt-4 block max-w-md text-sm font-bold">
                        <span>{{ c.owner }}</span>
                        <select
                            v-model="suggestedOwner"
                            data-testid="capital-action-suggested-owner"
                            class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm"
                        >
                            <option value="">{{ c.selectOwner }}</option>
                            <option
                                v-for="item in readModel.ownerOptions"
                                :key="item.id"
                                :value="item.id"
                            >
                                {{ item.name }}
                            </option>
                        </select>
                    </label>

                    <div class="mt-4 grid gap-3 lg:grid-cols-2">
                        <article
                            v-for="suggestion in suggestions"
                            :key="suggestion.key"
                            class="min-w-0 rounded-2xl border border-[#d9e4dc] bg-[#f8fbf9] p-4"
                        >
                            <h5 class="break-words text-sm font-black">
                                {{ suggestionTitle(suggestion.key) }}
                            </h5>
                            <p class="pbr-safe-copy mt-1 break-words text-xs leading-5 text-[var(--pbr-muted)]">
                                {{ suggestionHelp(suggestion.key) }}
                            </p>
                            <p
                                v-if="suggestion.dueDate"
                                class="mt-2 text-xs font-semibold text-[var(--pbr-muted)]"
                            >
                                {{ c.dueDate }}: {{ suggestion.dueDate }}
                            </p>
                            <button
                                :data-testid="`capital-action-suggestion-${suggestion.key}`"
                                type="button"
                                class="mt-3 min-h-10 rounded-xl bg-[var(--pbr-green-dark)] px-4 text-sm font-black text-white disabled:opacity-50"
                                :disabled="busy || suggestedOwner === ''"
                                @click="addSuggestion(String(suggestion.key))"
                            >
                                {{ busy ? c.adding : c.addAction }}
                            </button>
                        </article>
                    </div>
                </section>

                <section class="mt-5 rounded-2xl border border-[var(--pbr-line)] bg-white p-4 sm:p-5">
                    <h4 class="text-sm font-black">{{ c.custom }}</h4>

                    <div class="mt-4 grid min-w-0 gap-4 sm:grid-cols-2">
                        <label class="min-w-0 text-sm font-bold">
                            <span>{{ c.action }}</span>
                            <input
                                v-model="customTitle"
                                data-testid="capital-action-custom-title"
                                type="text"
                                maxlength="240"
                                class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm"
                            />
                        </label>

                        <label class="min-w-0 text-sm font-bold">
                            <span>{{ c.owner }}</span>
                            <select
                                v-model="customOwner"
                                data-testid="capital-action-custom-owner"
                                class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm"
                            >
                                <option value="">{{ c.selectOwner }}</option>
                                <option
                                    v-for="item in readModel.ownerOptions"
                                    :key="item.id"
                                    :value="item.id"
                                >
                                    {{ item.name }}
                                </option>
                            </select>
                        </label>

                        <label class="min-w-0 text-sm font-bold sm:col-span-2">
                            <span>{{ c.details }} <span class="font-normal text-[var(--pbr-muted)]">({{ c.optional }})</span></span>
                            <textarea
                                v-model="customDescription"
                                data-testid="capital-action-custom-description"
                                rows="3"
                                maxlength="4000"
                                class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm"
                            />
                        </label>

                        <label class="min-w-0 text-sm font-bold">
                            <span>{{ c.dueDate }} <span class="font-normal text-[var(--pbr-muted)]">({{ c.optional }})</span></span>
                            <input
                                v-model="customDueDate"
                                data-testid="capital-action-custom-due-date"
                                type="date"
                                class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm"
                            />
                        </label>
                    </div>

                    <button
                        data-testid="capital-action-custom-submit"
                        type="button"
                        class="mt-4 min-h-11 rounded-xl border border-[var(--pbr-green)] bg-white px-5 text-sm font-black text-[var(--pbr-green-dark)] disabled:opacity-50"
                        :disabled="busy || customOwner === '' || customTitle.trim() === ''"
                        @click="addCustom"
                    >
                        {{ busy ? c.adding : c.addAction }}
                    </button>
                </section>
            </template>

            <section class="mt-5 rounded-2xl border border-[var(--pbr-line)] bg-white p-4 sm:p-5">
                <div class="flex min-w-0 flex-wrap items-center justify-between gap-3">
                    <h4 class="text-sm font-black">{{ c.currentActions }}</h4>
                    <div class="flex flex-wrap gap-2 text-xs font-bold">
                        <span class="rounded-full border border-[var(--pbr-line)] px-3 py-1.5">
                            {{ c.outstanding }}: {{ readModel.outstandingCount }}
                        </span>
                        <span class="rounded-full border border-[#b9d6c0] bg-[#eef8f1] px-3 py-1.5 text-[#155f39]">
                            {{ c.completedCount }}: {{ readModel.completedCount }}
                        </span>
                    </div>
                </div>

                <p
                    v-if="actions.length === 0"
                    class="pbr-safe-copy mt-4 text-sm text-[var(--pbr-muted)]"
                >
                    {{ c.noActions }}
                </p>

                <div v-else class="mt-4 space-y-3">
                    <article
                        v-for="actionItem in actions"
                        :key="actionItem.id"
                        class="min-w-0 rounded-2xl border border-[#d9e4dc] bg-[#f8fbf9] p-4"
                    >
                        <div class="flex min-w-0 flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h5 class="break-words text-sm font-black">
                                    {{ actionTitle(actionItem) }}
                                </h5>
                                <p
                                    v-if="actionDescription(actionItem)"
                                    class="pbr-safe-copy mt-1 break-words text-xs leading-5 text-[var(--pbr-muted)]"
                                >
                                    {{ actionDescription(actionItem) }}
                                </p>
                            </div>
                            <span
                                class="rounded-full border border-[var(--pbr-line)] bg-white px-3 py-1.5 text-xs font-black"
                            >
                                {{ statusLabel(actionItem.status) }}
                            </span>
                        </div>

                        <div class="mt-3 grid min-w-0 gap-2 text-xs sm:grid-cols-2 lg:grid-cols-4">
                            <p class="break-words"><span class="font-bold">{{ c.owner }}:</span> {{ actionItem.owner }}</p>
                            <p><span class="font-bold">{{ c.dueDate }}:</span> {{ actionItem.dueDate ?? '—' }}</p>
                            <p v-if="actionItem.completedAt" class="break-words"><span class="font-bold">{{ c.completedAt }}:</span> {{ actionItem.completedAt }}</p>
                            <p v-if="actionItem.blockedReason" class="break-words text-[#8a5a25]"><span class="font-bold">{{ c.blockedReason }}:</span> {{ actionItem.blockedReason }}</p>
                        </div>

                        <template
                            v-if="readModel.canManage && actionItem.canUpdate && !['completed', 'cancelled'].includes(String(actionItem.status))"
                        >
                            <label
                                v-if="actionItem.status !== 'blocked'"
                                class="mt-3 block max-w-xl text-xs font-bold"
                            >
                                <span>{{ c.blockedReason }}</span>
                                <input
                                    v-model="blockedReasons[actionItem.id]"
                                    :data-testid="`capital-action-blocked-reason-${actionItem.id}`"
                                    type="text"
                                    maxlength="2000"
                                    class="mt-1 min-h-10 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm"
                                />
                            </label>

                            <div class="mt-3 flex flex-wrap gap-2">
                                <button
                                    v-if="actionItem.status === 'blocked'"
                                    type="button"
                                    class="min-h-9 rounded-xl border border-slate-300 bg-white px-3 text-xs font-black"
                                    :disabled="busy"
                                    @click="updateStatus(String(actionItem.id), 'open')"
                                >
                                    {{ c.markOpen }}
                                </button>
                                <button
                                    v-if="actionItem.status !== 'in_progress'"
                                    :data-testid="`capital-action-in-progress-${actionItem.id}`"
                                    type="button"
                                    class="min-h-9 rounded-xl border border-[var(--pbr-green)] bg-white px-3 text-xs font-black text-[var(--pbr-green-dark)]"
                                    :disabled="busy"
                                    @click="updateStatus(String(actionItem.id), 'in_progress')"
                                >
                                    {{ c.markInProgress }}
                                </button>
                                <button
                                    v-if="actionItem.status !== 'blocked'"
                                    type="button"
                                    class="min-h-9 rounded-xl border border-[#d7bf9e] bg-white px-3 text-xs font-black text-[#755420]"
                                    :disabled="busy || String(blockedReasons[actionItem.id] ?? '').trim() === ''"
                                    @click="updateStatus(String(actionItem.id), 'blocked')"
                                >
                                    {{ c.markBlocked }}
                                </button>
                                <button
                                    :data-testid="`capital-action-complete-${actionItem.id}`"
                                    type="button"
                                    class="min-h-9 rounded-xl bg-[var(--pbr-green-dark)] px-3 text-xs font-black text-white"
                                    :disabled="busy"
                                    @click="updateStatus(String(actionItem.id), 'completed')"
                                >
                                    {{ c.markCompleted }}
                                </button>
                                <button
                                    type="button"
                                    class="min-h-9 rounded-xl border border-[#d9b5b5] bg-white px-3 text-xs font-black text-[#8a3131]"
                                    :disabled="busy"
                                    @click="updateStatus(String(actionItem.id), 'cancelled')"
                                >
                                    {{ c.cancelAction }}
                                </button>
                            </div>
                        </template>
                    </article>
                </div>
            </section>

            <p class="pbr-safe-copy mt-4 text-xs leading-5 text-[var(--pbr-muted)]">
                {{ c.semantics }}
            </p>
        </template>

        <p v-else class="pbr-safe-copy text-sm leading-6 text-[var(--pbr-muted)]">
            {{ c.unavailable }}
        </p>
    </section>
</template>
