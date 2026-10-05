<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PbrErrorSummary from '../ui/PbrErrorSummary.vue';
import PbrFormSection from '../ui/PbrFormSection.vue';
import { useI18n } from '../../i18n/useI18n';
import { humanErrorMessages } from '../../support/humanErrors';

type GenericRow = Record<string, any>;
type ShortfallResponse =
    | 'reduce_scope'
    | 'delay'
    | 'borrow'
    | 'capital_call';

const props = defineProps<{
    draft: GenericRow | null;
    readModel: GenericRow | null;
    currency: string;
    canManage: boolean;
}>();

const emit = defineEmits<{
    back: [];
}>();

const { uiLanguageMode } = useI18n();

const copy = {
    en: {
        title: 'Set the Capital Rule & Allocation',
        help:
            'Review the current server-calculated Capital position and record how this Business plans to respond if confirmed funding is still short.',
        boundary:
            'This is a planning rule only. Saving it is not Approval, Signature, an Effective decision, a Partner Contribution, Equity, Ownership or an executed Capital Call.',
        summaryTitle: 'Capital Allocation Summary',
        summaryHelp:
            'These amounts come directly from the current server-side Capital calculation. Step 6 does not store another copy of the totals.',
        preOpening: 'Pre-opening Costs',
        assets: 'Initial Assets / Opening Inventory',
        working: 'Working Capital',
        reserve: 'Contingency Reserve',
        total: 'Total Capital Requirement',
        funding: 'Confirmed Funding',
        gap: 'Funding Gap',
        surplus: 'Funding Surplus',
        funded: '% Funded',
        unavailable: 'Not available yet',
        priorInputsTitle: 'Complete the earlier Capital steps first',
        priorInputsHelp:
            'Step 6 cannot make a complete allocation plan while required Capital inputs are still missing.',
        missingPre: 'Startup Cost Plan',
        missingAssets: 'Initial Assets & Opening Inventory',
        missingWorking: 'Working Capital Forecast',
        missingReserve: 'Contingency Reserve',
        missingFunding: 'Confirmed Funding',
        needsReviewTitle: 'Review this rule again',
        needsReviewHelp:
            'The Capital numbers changed after this Step 6 rule was saved. Review the current allocation summary and save the rule again if it still fits.',
        preparedAgainst: 'Rule prepared against Capital revision',
        currentRevision: 'Current Capital revision',
        shortfallTitle: 'How should the Business respond to the funding gap?',
        shortfallHelp:
            'Choose one or more planning responses. The order shows priority. These are planning choices, not executed obligations.',
        noShortfallTitle: 'No shortfall action is currently required',
        noShortfallHelp:
            'The current server calculation shows no Funding Gap. You do not need to invent a shortfall response.',
        reduceScope: 'Reduce the startup scope',
        reduceScopeHelp:
            'Lower or postpone selected startup costs so the required Capital comes down.',
        delay: 'Delay the launch or selected spending',
        delayHelp:
            'Move timing rather than treating missing funding as already solved.',
        borrow: 'Consider borrowing',
        borrowHelp:
            'Record borrowing as a possible planning response only. This does not create a loan.',
        capitalCall: 'Consider a Capital Call later',
        capitalCallHelp:
            'Record that a future Capital Call may be considered for the unresolved gap. No partner amount or obligation is created here.',
        priority: 'Priority',
        moveUp: 'Move up',
        moveDown: 'Move down',
        allocationNotes: 'Allocation notes',
        allocationNotesHelp:
            'Optional notes about how the current Capital requirement is expected to be allocated across startup uses.',
        shortfallNotes: 'Shortfall rule notes',
        shortfallNotesHelp:
            'Optional context about why these responses were selected or when they should be revisited.',
        capitalCallNote: 'Capital Call planning note',
        capitalCallNoteHelp:
            'Optional human-readable rule only. Actual contributor commitments, accepted contribution value, ownership and equity effects are handled later.',
        readinessTitle: 'Step 6 planning readiness',
        ready: 'Current rule is recorded against the latest Capital numbers.',
        notSaved: 'The Step 6 rule has not been saved yet.',
        needsResponse: 'A Funding Gap exists. Record at least one shortfall response before Step 6 is ready.',
        needsInputs: 'Earlier Capital information is incomplete, so Step 6 is not ready.',
        reviewNeeded: 'The saved rule is based on older Capital numbers and needs review.',
        save: 'Save Capital Rule',
        saving: 'Saving…',
        saved: 'Capital Rule draft saved.',
        readOnly:
            'You can review this rule and allocation summary, but your access does not allow editing or saving it.',
        back: 'Back',
        reload: 'Reload latest saved rule',
        latestLoaded: 'Latest saved Capital Rule loaded.',
        errorTitle: 'The Capital Rule could not be saved',
        errorHelp:
            'Finish the current Capital calculation and review the visible Step 6 fields before saving.',
        revision: 'Rule revision',
        none: 'Not saved yet',
        planningOnly: 'Planning rule only',
    },
    my: {
        title: 'Capital Rule & Allocation ကို သတ်မှတ်ပါ',
        help:
            'Server ကတွက်ထားတဲ့ လက်ရှိ Capital Position ကိုကြည့်ပြီး Funding မလုံလောက်သေးရင် Business က ဘယ်လိုတုံ့ပြန်မလဲဆိုတာ Planning Rule အဖြစ်မှတ်ပါ။',
        boundary:
            'ဒီဟာက Planning Rule သာဖြစ်ပါတယ်။ Save လုပ်တာက Approval, Signature, Effective Decision, Partner Contribution, Equity, Ownership သို့မဟုတ် တကယ် execute လုပ်ထားတဲ့ Capital Call မဟုတ်ပါ။',
        summaryTitle: 'Capital Allocation Summary',
        summaryHelp:
            'ဒီငွေပမာဏတွေကို လက်ရှိ server-side Capital calculation ကနေ တိုက်ရိုက်ယူထားတာပါ။ Step 6 က totals ကို ထပ်ပြီး duplicate မသိမ်းပါ။',
        preOpening: 'Pre-opening Costs',
        assets: 'Initial Assets / Opening Inventory',
        working: 'Working Capital',
        reserve: 'Contingency Reserve',
        total: 'Total Capital Requirement',
        funding: 'Confirmed Funding',
        gap: 'Funding Gap',
        surplus: 'Funding Surplus',
        funded: '% Funded',
        unavailable: 'မရနိုင်သေး',
        priorInputsTitle: 'အရင် Capital Steps တွေကို အရင်ပြည့်စုံအောင်လုပ်ပါ',
        priorInputsHelp:
            'လိုအပ်တဲ့ Capital Inputs တွေမပြည့်သေးရင် Step 6 က complete allocation plan လို့မသတ်မှတ်ပါ။',
        missingPre: 'Startup Cost Plan',
        missingAssets: 'Initial Assets & Opening Inventory',
        missingWorking: 'Working Capital Forecast',
        missingReserve: 'Contingency Reserve',
        missingFunding: 'Confirmed Funding',
        needsReviewTitle: 'ဒီ Rule ကို ပြန်စစ်ရန်လိုပါတယ်',
        needsReviewHelp:
            'Step 6 Rule သိမ်းပြီးနောက် Capital numbers ပြောင်းထားပါတယ်။ လက်ရှိ Allocation Summary ကိုပြန်စစ်ပြီး Rule ကအခုလည်းသင့်တော်ရင် ပြန်သိမ်းပါ။',
        preparedAgainst: 'Rule ပြင်ဆင်ခဲ့သည့် Capital Revision',
        currentRevision: 'လက်ရှိ Capital Revision',
        shortfallTitle: 'Funding Gap ကို Business က ဘယ်လိုကိုင်တွယ်မလဲ?',
        shortfallHelp:
            'Planning responses တစ်ခု သို့မဟုတ် တစ်ခုထက်ပိုရွေးနိုင်ပါတယ်။ အစဉ်က Priority ကိုပြပါတယ်။ ဒါတွေက Planning Choices ပဲဖြစ်ပြီး တကယ် execute လုပ်ထားတဲ့ obligations မဟုတ်ပါ။',
        noShortfallTitle: 'လက်ရှိအခြေအနေမှာ Shortfall Action မလိုပါ',
        noShortfallHelp:
            'လက်ရှိ server calculation မှာ Funding Gap မရှိပါ။ မရှိတဲ့ Shortfall ကို အတင်းဖန်တီးဖို့မလိုပါ။',
        reduceScope: 'စတင်မယ့် Scope ကိုလျှော့မယ်',
        reduceScopeHelp:
            'Startup costs အချို့ကို လျှော့ခြင်း သို့မဟုတ်နောက်ရွှေ့ခြင်းနဲ့ လိုအပ်တဲ့ Capital ကိုလျှော့ဖို့ စဉ်းစားမယ်။',
        delay: 'Launch သို့မဟုတ် Spending အချို့ကို နောက်ရွှေ့မယ်',
        delayHelp:
            'Funding မပြည့်တာကို ဖြေရှင်းပြီးသားလို့မယူဘဲ Timing ကိုပြောင်းဖို့ စဉ်းစားမယ်။',
        borrow: 'Borrowing ကို စဉ်းစားမယ်',
        borrowHelp:
            'Borrowing ကို possible planning response အဖြစ်ပဲမှတ်ပါတယ်။ ဒီနေရာက Loan မဖန်တီးပါ။',
        capitalCall: 'နောက်ပိုင်း Capital Call စဉ်းစားမယ်',
        capitalCallHelp:
            'မပြည့်သေးတဲ့ Gap အတွက် Future Capital Call ကိုစဉ်းစားနိုင်တယ်လို့သာမှတ်ပါတယ်။ Partner amount နဲ့ obligation မဖန်တီးပါ။',
        priority: 'Priority',
        moveUp: 'အပေါ်ရွှေ့',
        moveDown: 'အောက်ရွှေ့',
        allocationNotes: 'Allocation မှတ်ချက်',
        allocationNotesHelp:
            'လက်ရှိ Capital requirement ကို Startup uses တွေကြား ဘယ်လိုသုံးမလဲဆိုတဲ့ optional planning notes ပါ။',
        shortfallNotes: 'Shortfall Rule မှတ်ချက်',
        shortfallNotesHelp:
            'ဒီ responses တွေကို ဘာကြောင့်ရွေးထားလဲ သို့မဟုတ် ဘယ်အချိန်ပြန်စစ်မလဲဆိုတဲ့ optional context ပါ။',
        capitalCallNote: 'Capital Call Planning Note',
        capitalCallNoteHelp:
            'Optional human-readable rule သာဖြစ်ပါတယ်။ Contributor commitments, Accepted Contribution Value, Ownership နဲ့ Equity effects တွေကို နောက် Chapter တွေမှာပဲကိုင်တွယ်မယ်။',
        readinessTitle: 'Step 6 Planning Readiness',
        ready: 'လက်ရှိ Rule ကို နောက်ဆုံး Capital numbers ပေါ်အခြေခံပြီး သိမ်းထားပါတယ်။',
        notSaved: 'Step 6 Rule ကို မသိမ်းရသေးပါ။',
        needsResponse: 'Funding Gap ရှိနေပါတယ်။ Step 6 ready ဖြစ်ဖို့ Shortfall Response အနည်းဆုံးတစ်ခုမှတ်ပါ။',
        needsInputs: 'အရင် Capital information မပြည့်သေးလို့ Step 6 ready မဖြစ်သေးပါ။',
        reviewNeeded: 'သိမ်းထားတဲ့ Rule က Capital numbers အဟောင်းပေါ်အခြေခံထားလို့ ပြန်စစ်ရန်လိုပါတယ်။',
        save: 'Capital Rule သိမ်းမည်',
        saving: 'သိမ်းနေသည်…',
        saved: 'Capital Rule Draft သိမ်းပြီးပါပြီ။',
        readOnly:
            'ဒီ Rule နဲ့ Allocation Summary ကိုကြည့်နိုင်ပေမယ့် Edit/Save လုပ်ခွင့်မရှိပါ။',
        back: 'နောက်ပြန်',
        reload: 'နောက်ဆုံးသိမ်းထားတဲ့ Rule ပြန်ယူမည်',
        latestLoaded: 'နောက်ဆုံးသိမ်းထားတဲ့ Capital Rule ကိုပြန်ယူပြီးပါပြီ။',
        errorTitle: 'Capital Rule ကို မသိမ်းနိုင်သေးပါ',
        errorHelp:
            'လက်ရှိ Capital Calculation ကိုပြည့်စုံအောင်လုပ်ပြီး Step 6 မှာမြင်နေရတဲ့ Fields ကိုပြန်စစ်ပါ။',
        revision: 'Rule Revision',
        none: 'မသိမ်းရသေး',
        planningOnly: 'Planning Rule သာဖြစ်သည်',
    },
    mixed: {
        title: 'Capital Rule & Allocation ကို set လုပ်ပါ',
        help:
            'Current server-calculated Capital position ကို review လုပ်ပြီး Funding short ဖြစ်နေသေးရင် Business က ဘယ်လို respond လုပ်မလဲ Planning Rule အဖြစ် record လုပ်ပါ။',
        boundary:
            'Planning Rule only. Save လုပ်တာက Approval, Signature, Effective Decision, Contribution, Equity, Ownership or executed Capital Call မဟုတ်ပါ။',
        summaryTitle: 'Capital Allocation Summary',
        summaryHelp:
            'Amounts အားလုံး current server Capital calculation ကနေယူပါတယ်။ Step 6 မှာ totals ကို duplicate persist မလုပ်ပါ။',
        preOpening: 'Pre-opening Costs',
        assets: 'Initial Assets / Opening Inventory',
        working: 'Working Capital',
        reserve: 'Contingency Reserve',
        total: 'Total Capital Requirement',
        funding: 'Confirmed Funding',
        gap: 'Funding Gap',
        surplus: 'Funding Surplus',
        funded: '% Funded',
        unavailable: 'Not available yet',
        priorInputsTitle: 'Complete earlier Capital steps first',
        priorInputsHelp:
            'Required Capital inputs မပြည့်သေးရင် Step 6 ကို complete allocation decision လို့မပြပါ။',
        missingPre: 'Startup Cost Plan',
        missingAssets: 'Initial Assets & Opening Inventory',
        missingWorking: 'Working Capital Forecast',
        missingReserve: 'Contingency Reserve',
        missingFunding: 'Confirmed Funding',
        needsReviewTitle: 'Review this Rule again',
        needsReviewHelp:
            'Rule save လုပ်ပြီးနောက် Capital numbers ပြောင်းသွားပါတယ်။ Current summary ကို review လုပ်ပြီး still fits ဆိုရင် save again လုပ်ပါ။',
        preparedAgainst: 'Rule prepared against Capital revision',
        currentRevision: 'Current Capital revision',
        shortfallTitle: 'How should the Business respond to the Funding Gap?',
        shortfallHelp:
            'One or more planning responses ကိုရွေးပါ။ Order က priority ဖြစ်ပြီး executed obligations မဟုတ်ပါ။',
        noShortfallTitle: 'No shortfall action is currently required',
        noShortfallHelp:
            'Current server calculation မှာ Funding Gap မရှိလို့ fake shortfall response ထည့်စရာမလိုပါ။',
        reduceScope: 'Reduce startup scope',
        reduceScopeHelp: 'Selected startup costs ကိုလျှော့/နောက်ရွှေ့ပြီး required Capital လျှော့ဖို့စဉ်းစားမယ်။',
        delay: 'Delay launch or selected spending',
        delayHelp: 'Timing ကိုပြောင်းဖို့စဉ်းစားမယ်; missing funding ကို solved လို့မယူပါ။',
        borrow: 'Consider borrowing',
        borrowHelp: 'Possible planning response only; no loan is created.',
        capitalCall: 'Consider a future Capital Call',
        capitalCallHelp: 'Future option only; no partner amount, contribution or ownership obligation is created here.',
        priority: 'Priority',
        moveUp: 'Move up',
        moveDown: 'Move down',
        allocationNotes: 'Allocation notes',
        allocationNotesHelp: 'Optional notes about how current required Capital may be allocated.',
        shortfallNotes: 'Shortfall rule notes',
        shortfallNotesHelp: 'Optional reason / timing context for selected responses.',
        capitalCallNote: 'Capital Call planning note',
        capitalCallNoteHelp:
            'Human-readable rule only. Actual commitments, Accepted Contribution Value, Ownership and Equity happen later.',
        readinessTitle: 'Step 6 planning readiness',
        ready: 'Rule is recorded against the latest Capital numbers.',
        notSaved: 'Step 6 Rule is not saved yet.',
        needsResponse: 'Funding Gap exists. Add at least one shortfall response before Step 6 is ready.',
        needsInputs: 'Earlier Capital information is incomplete, so Step 6 is not ready.',
        reviewNeeded: 'Saved Rule uses older Capital numbers and needs review.',
        save: 'Save Capital Rule',
        saving: 'Saving…',
        saved: 'Capital Rule draft saved.',
        readOnly: 'You can review but cannot edit/save this Rule.',
        back: 'Back',
        reload: 'Reload latest saved Rule',
        latestLoaded: 'Latest saved Capital Rule loaded.',
        errorTitle: 'Capital Rule could not be saved',
        errorHelp: 'Complete current Capital calculation and review visible Step 6 fields.',
        revision: 'Rule revision',
        none: 'Not saved yet',
        planningOnly: 'Planning Rule only',
    },
} as const;

const c = computed(() => copy[uiLanguageMode.value]);

const expectedRevision = ref(0);
const selectedResponses = ref<ShortfallResponse[]>([]);
const allocationNotes = ref('');
const shortfallRuleNotes = ref('');
const capitalCallRuleNote = ref('');
const busy = ref(false);
const errors = ref<string[]>([]);
const success = ref('');

const hydrate = (source: GenericRow | null): void => {
    expectedRevision.value = Number(source?.revision ?? 0);

    const input = (source?.input ?? null) as GenericRow | null;

    selectedResponses.value = Array.isArray(input?.shortfallResponses)
        ? input!.shortfallResponses.filter(
            (value: unknown): value is ShortfallResponse =>
                typeof value === 'string'
                && ['reduce_scope', 'delay', 'borrow', 'capital_call'].includes(
                    value,
                ),
        )
        : [];
    allocationNotes.value = String(input?.allocationNotes ?? '');
    shortfallRuleNotes.value = String(input?.shortfallRuleNotes ?? '');
    capitalCallRuleNote.value = String(input?.capitalCallRuleNote ?? '');
};

hydrate(props.draft);

const responseOptions = computed(() => [
    {
        code: 'reduce_scope' as const,
        label: c.value.reduceScope,
        help: c.value.reduceScopeHelp,
    },
    {
        code: 'delay' as const,
        label: c.value.delay,
        help: c.value.delayHelp,
    },
    {
        code: 'borrow' as const,
        label: c.value.borrow,
        help: c.value.borrowHelp,
    },
    {
        code: 'capital_call' as const,
        label: c.value.capitalCall,
        help: c.value.capitalCallHelp,
    },
]);

const selected = (code: ShortfallResponse): boolean =>
    selectedResponses.value.includes(code);

const toggleResponse = (
    code: ShortfallResponse,
    checked: boolean,
): void => {
    if (checked) {
        if (!selected(code)) selectedResponses.value.push(code);

        return;
    }

    selectedResponses.value = selectedResponses.value.filter(
        (current) => current !== code,
    );

    if (code === 'capital_call') {
        capitalCallRuleNote.value = '';
    }
};

const moveResponse = (
    code: ShortfallResponse,
    direction: -1 | 1,
): void => {
    const index = selectedResponses.value.indexOf(code);
    const target = index + direction;

    if (
        index < 0
        || target < 0
        || target >= selectedResponses.value.length
    ) {
        return;
    }

    const reordered = [...selectedResponses.value];
    [reordered[index], reordered[target]] = [
        reordered[target],
        reordered[index],
    ];
    selectedResponses.value = reordered;
};

const priority = (code: ShortfallResponse): number | null => {
    const index = selectedResponses.value.indexOf(code);

    return index < 0 ? null : index + 1;
};

const hasCapitalCall = computed(() =>
    selectedResponses.value.includes('capital_call'),
);

const summary = computed(
    () => (props.readModel?.allocationSummary ?? {}) as GenericRow,
);

const missingLabels = computed(() => {
    const map: Record<string, string> = {
        pre_opening: c.value.missingPre,
        initial_assets_inventory: c.value.missingAssets,
        working_capital: c.value.missingWorking,
        contingency_reserve: c.value.missingReserve,
        confirmed_funding: c.value.missingFunding,
    };

    const requirements = Array.isArray(props.readModel?.missingRequirements)
        ? props.readModel!.missingRequirements
        : [];

    return requirements
        .filter((value: unknown): value is string => typeof value === 'string')
        .map((value: string) => map[value] ?? '')
        .filter((value: string) => value !== '');
});

const readinessText = computed(() => {
    if (props.readModel?.status === 'needs_prior_capital_inputs') {
        return c.value.needsInputs;
    }

    if (props.readModel?.status === 'needs_review') {
        return c.value.reviewNeeded;
    }

    if (props.readModel?.status === 'needs_shortfall_rule') {
        return c.value.needsResponse;
    }

    if (props.readModel?.status === 'ready') {
        return c.value.ready;
    }

    return c.value.notSaved;
});

const showMoney = (value: unknown): string => {
    if (value === null || value === undefined || value === '') {
        return c.value.unavailable;
    }

    return String(value) + ' ' + props.currency;
};

const showPercent = (value: unknown): string => {
    if (value === null || value === undefined || value === '') {
        return c.value.unavailable;
    }

    return String(value) + '%';
};

const summaryCards = computed(() => [
    { key: 'pre', label: c.value.preOpening, value: summary.value.preOpening },
    { key: 'assets', label: c.value.assets, value: summary.value.initialAssetsInventory },
    { key: 'working', label: c.value.working, value: summary.value.workingCapital },
    { key: 'reserve', label: c.value.reserve, value: summary.value.contingencyReserve },
    { key: 'total', label: c.value.total, value: summary.value.totalCapitalRequirement, emphasis: true },
    { key: 'funding', label: c.value.funding, value: summary.value.confirmedFunding },
    { key: 'gap', label: c.value.gap, value: summary.value.fundingGap, emphasis: true },
    { key: 'surplus', label: c.value.surplus, value: summary.value.fundingSurplus },
]);

const draftFromPage = (page: GenericRow): GenericRow | null => {
    const formation = page.props?.formation as GenericRow | undefined;

    return (formation?.capital?.rule_draft ?? null) as GenericRow | null;
};

const saveRule = (): void => {
    if (
        !props.canManage
        || busy.value
        || props.readModel?.calculationComplete !== true
    ) {
        return;
    }

    busy.value = true;
    errors.value = [];
    success.value = '';

    router.put(
        '/formation/capital/rule-draft',
        {
            expected_revision: expectedRevision.value,
            input: {
                shortfallResponses:
                    props.readModel?.shortfallRequired === true
                        ? selectedResponses.value
                        : [],
                allocationNotes:
                    allocationNotes.value.trim() === ''
                        ? null
                        : allocationNotes.value.trim(),
                shortfallRuleNotes:
                    props.readModel?.shortfallRequired === true
                    && shortfallRuleNotes.value.trim() !== ''
                        ? shortfallRuleNotes.value.trim()
                        : null,
                capitalCallRuleNote:
                    props.readModel?.shortfallRequired === true
                    && hasCapitalCall.value
                    && capitalCallRuleNote.value.trim() !== ''
                        ? capitalCallRuleNote.value.trim()
                        : null,
            },
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                hydrate(draftFromPage(page as GenericRow));
                errors.value = [];
                success.value = c.value.saved;
            },
            onError: (serverErrors) => {
                errors.value = humanErrorMessages(serverErrors);
            },
            onFinish: () => {
                busy.value = false;
            },
        },
    );
};

const reloadLatest = (): void => {
    router.reload({
        only: ['formation'],
        onSuccess: (page) => {
            hydrate(draftFromPage(page as GenericRow));
            errors.value = [];
            success.value = c.value.latestLoaded;
        },
    });
};
</script>

<template>
    <PbrFormSection
        :title="c.title"
        :instruction="c.help"
        numbered="6"
    >
        <div
            data-testid="capital-rule-step"
            class="min-w-0"
        >
            <p class="pbr-safe-copy rounded-2xl border border-[#e7d8aa] bg-[#fffaf0] px-4 py-3 text-xs leading-5 text-[#665527]">
                {{ c.boundary }}
            </p>

            <div class="mt-4 flex flex-wrap gap-2 text-xs">
                <span class="rounded-full border border-[var(--pbr-line)] bg-white px-3 py-1.5 font-bold">
                    {{ c.revision }}:
                    {{ Number(draft?.revision ?? 0) > 0 ? draft?.revision : c.none }}
                </span>
                <span class="rounded-full border border-[#cfe0d4] bg-[#eef8f1] px-3 py-1.5 font-bold text-[var(--pbr-green-dark)]">
                    {{ c.planningOnly }}
                </span>
            </div>

            <section class="mt-5 min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4 sm:p-5">
                <h3 class="break-words text-base font-black">
                    {{ c.summaryTitle }}
                </h3>
                <p class="pbr-safe-copy mt-1 break-words text-xs leading-5 text-[var(--pbr-muted)]">
                    {{ c.summaryHelp }}
                </p>

                <dl class="mt-4 grid min-w-0 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div
                        v-for="card in summaryCards"
                        :key="card.key"
                        class="min-w-0 rounded-xl border p-3"
                        :class="card.emphasis ? 'border-[#c9ddcf] bg-[#eef7f0]' : 'border-[var(--pbr-line)] bg-[#fbfcfb]'"
                    >
                        <dt class="break-words text-xs font-semibold text-[var(--pbr-muted)]">
                            {{ card.label }}
                        </dt>
                        <dd class="mt-1 break-words text-base font-black">
                            {{ showMoney(card.value) }}
                        </dd>
                    </div>

                    <div class="min-w-0 rounded-xl border border-[var(--pbr-line)] bg-[#fbfcfb] p-3">
                        <dt class="break-words text-xs font-semibold text-[var(--pbr-muted)]">
                            {{ c.funded }}
                        </dt>
                        <dd class="mt-1 break-words text-base font-black">
                            {{ showPercent(summary.fundedPercentage) }}
                        </dd>
                    </div>
                </dl>
            </section>

            <div
                v-if="readModel?.calculationComplete !== true"
                class="mt-5 rounded-2xl border border-[#eadcb1] bg-[#fffaf0] p-4"
            >
                <h3 class="text-sm font-black text-[#665527]">
                    {{ c.priorInputsTitle }}
                </h3>
                <p class="pbr-safe-copy mt-1 text-sm leading-6 text-[#665527]">
                    {{ c.priorInputsHelp }}
                </p>
                <ul class="mt-2 space-y-1 text-sm leading-6 text-[#665527]">
                    <li v-for="label in missingLabels" :key="label">
                        • {{ label }}
                    </li>
                </ul>
            </div>

            <div
                v-if="readModel?.needsReview === true"
                class="mt-5 rounded-2xl border border-[#dfc98c] bg-[#fff8e8] p-4"
            >
                <h3 class="text-sm font-black text-[#6e5521]">
                    {{ c.needsReviewTitle }}
                </h3>
                <p class="pbr-safe-copy mt-1 text-sm leading-6 text-[#6e5521]">
                    {{ c.needsReviewHelp }}
                </p>
                <div class="mt-3 flex flex-wrap gap-2 text-xs font-bold text-[#6e5521]">
                    <span class="rounded-full border border-[#dfc98c] bg-white px-3 py-1.5">
                        {{ c.preparedAgainst }}:
                        {{ readModel?.rulePreparedAgainstCapitalRevision ?? '—' }}
                    </span>
                    <span class="rounded-full border border-[#dfc98c] bg-white px-3 py-1.5">
                        {{ c.currentRevision }}:
                        {{ readModel?.capitalPlanningRevision ?? '—' }}
                    </span>
                </div>
            </div>

            <section
                v-if="readModel?.calculationComplete === true"
                class="mt-5 min-w-0"
            >
                <div
                    v-if="readModel?.shortfallRequired === true"
                    class="rounded-2xl border border-[#ecd7aa] bg-[#fffaf0] p-4"
                >
                    <h3 class="text-base font-black text-[#665527]">
                        {{ c.shortfallTitle }}
                    </h3>
                    <p class="pbr-safe-copy mt-1 text-sm leading-6 text-[#665527]">
                        {{ c.shortfallHelp }}
                    </p>
                </div>

                <div
                    v-else
                    class="rounded-2xl border border-[#c8decf] bg-[#eff8f2] p-4"
                >
                    <h3 class="text-base font-black text-[var(--pbr-green-dark)]">
                        {{ c.noShortfallTitle }}
                    </h3>
                    <p class="pbr-safe-copy mt-1 text-sm leading-6 text-[var(--pbr-green-dark)]">
                        {{ c.noShortfallHelp }}
                    </p>
                </div>

                <div
                    v-if="readModel?.shortfallRequired === true"
                    class="mt-4 grid min-w-0 gap-3 lg:grid-cols-2"
                >
                    <article
                        v-for="option in responseOptions"
                        :key="option.code"
                        class="min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4"
                    >
                        <label class="flex min-w-0 items-start gap-3">
                            <input
                                :checked="selected(option.code)"
                                :disabled="!canManage"
                                :aria-label="option.label"
                                type="checkbox"
                                class="mt-1"
                                @change="toggleResponse(option.code, ($event.target as HTMLInputElement).checked)"
                            >
                            <span class="min-w-0">
                                <span class="block break-words text-sm font-black">
                                    {{ option.label }}
                                </span>
                                <span class="pbr-safe-copy mt-1 block break-words text-xs leading-5 text-[var(--pbr-muted)]">
                                    {{ option.help }}
                                </span>
                            </span>
                        </label>

                        <div
                            v-if="selected(option.code)"
                            class="mt-3 flex flex-wrap items-center gap-2"
                        >
                            <span class="rounded-full border border-[#cfe0d4] bg-[#eef8f1] px-3 py-1 text-xs font-black text-[var(--pbr-green-dark)]">
                                {{ c.priority }} {{ priority(option.code) }}
                            </span>
                            <button
                                v-if="canManage"
                                type="button"
                                class="min-h-8 rounded-lg border border-slate-300 px-3 text-xs font-bold"
                                :disabled="priority(option.code) === 1"
                                @click="moveResponse(option.code, -1)"
                            >
                                {{ c.moveUp }}
                            </button>
                            <button
                                v-if="canManage"
                                type="button"
                                class="min-h-8 rounded-lg border border-slate-300 px-3 text-xs font-bold"
                                :disabled="priority(option.code) === selectedResponses.length"
                                @click="moveResponse(option.code, 1)"
                            >
                                {{ c.moveDown }}
                            </button>
                        </div>
                    </article>
                </div>

                <label class="mt-5 block min-w-0 text-sm font-bold">
                    <span class="block">{{ c.allocationNotes }}</span>
                    <span class="mt-1 block text-xs font-normal leading-5 text-[var(--pbr-muted)]">
                        {{ c.allocationNotesHelp }}
                    </span>
                    <textarea
                        v-model="allocationNotes"
                        :disabled="!canManage"
                        :aria-label="c.allocationNotes"
                        maxlength="2000"
                        rows="3"
                        class="mt-2 w-full min-w-0 rounded-xl border border-slate-300 bg-white px-3 py-2 disabled:bg-slate-50"
                    />
                </label>

                <label
                    v-if="readModel?.shortfallRequired === true"
                    class="mt-4 block min-w-0 text-sm font-bold"
                >
                    <span class="block">{{ c.shortfallNotes }}</span>
                    <span class="mt-1 block text-xs font-normal leading-5 text-[var(--pbr-muted)]">
                        {{ c.shortfallNotesHelp }}
                    </span>
                    <textarea
                        v-model="shortfallRuleNotes"
                        :disabled="!canManage"
                        :aria-label="c.shortfallNotes"
                        maxlength="2000"
                        rows="3"
                        class="mt-2 w-full min-w-0 rounded-xl border border-slate-300 bg-white px-3 py-2 disabled:bg-slate-50"
                    />
                </label>

                <label
                    v-if="readModel?.shortfallRequired === true && hasCapitalCall"
                    class="mt-4 block min-w-0 rounded-2xl border border-[#d8e4da] bg-[#f7faf8] p-4 text-sm font-bold"
                >
                    <span class="block">{{ c.capitalCallNote }}</span>
                    <span class="pbr-safe-copy mt-1 block text-xs font-normal leading-5 text-[var(--pbr-muted)]">
                        {{ c.capitalCallNoteHelp }}
                    </span>
                    <textarea
                        v-model="capitalCallRuleNote"
                        :disabled="!canManage"
                        :aria-label="c.capitalCallNote"
                        maxlength="1000"
                        rows="3"
                        class="mt-2 w-full min-w-0 rounded-xl border border-slate-300 bg-white px-3 py-2 disabled:bg-slate-50"
                    />
                </label>
            </section>

            <section class="mt-5 rounded-2xl border border-[var(--pbr-line)] bg-[#f8faf8] p-4">
                <h3 class="text-sm font-black">
                    {{ c.readinessTitle }}
                </h3>
                <p class="pbr-safe-copy mt-1 text-sm leading-6 text-[var(--pbr-muted)]">
                    {{ readinessText }}
                </p>
            </section>

            <PbrErrorSummary
                class="mt-5"
                :title="c.errorTitle"
                :help="c.errorHelp"
                :errors="errors"
            />

            <p
                v-if="success"
                class="pbr-safe-copy mt-5 rounded-2xl border border-[#bcdcc6] bg-[#eef8f1] px-4 py-3 text-sm font-semibold text-[#155f39]"
            >
                {{ success }}
            </p>

            <p
                v-if="!canManage"
                class="pbr-safe-copy mt-5 rounded-2xl border border-[#d9e1da] bg-white px-4 py-3 text-sm leading-6 text-[var(--pbr-muted)]"
            >
                {{ c.readOnly }}
            </p>

            <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
                <button
                    type="button"
                    class="min-h-10 rounded-xl border border-slate-300 bg-white px-4 text-sm font-bold"
                    @click="emit('back')"
                >
                    {{ c.back }}
                </button>

                <div class="flex flex-wrap gap-2">
                    <button
                        v-if="errors.length > 0"
                        type="button"
                        class="min-h-10 rounded-xl border border-slate-300 bg-white px-4 text-sm font-bold"
                        @click="reloadLatest"
                    >
                        {{ c.reload }}
                    </button>

                    <button
                        v-if="canManage"
                        type="button"
                        class="min-h-11 rounded-xl bg-[var(--pbr-green-dark)] px-5 text-sm font-black text-white disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="busy || readModel?.calculationComplete !== true"
                        @click="saveRule"
                    >
                        {{ busy ? c.saving : c.save }}
                    </button>
                </div>
            </div>
        </div>
    </PbrFormSection>
</template>
