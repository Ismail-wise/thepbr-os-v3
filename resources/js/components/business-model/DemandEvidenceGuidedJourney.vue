<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import OptionalTemporalInput from '../OptionalTemporalInput.vue';
import GuidedJourneyStepper from '../hybrid/GuidedJourneyStepper.vue';
import ChoiceOrCustom from '../ui/ChoiceOrCustom.vue';
import PbrButton from '../ui/PbrButton.vue';
import PbrFormSection from '../ui/PbrFormSection.vue';
import PbrTextInput from '../ui/PbrTextInput.vue';
import GuidedSuggestionTextarea from './GuidedSuggestionTextarea.vue';
import { useI18n } from '../../i18n/useI18n';

type GenericRow = Record<string, unknown>;
type StepKey = 'assumption' | 'test' | 'evidence' | 'review';
type PostData = NonNullable<Parameters<typeof router.post>[1]>;

const props = defineProps<{
    businessId: string;
    assumptions: GenericRow[];
    validations: GenericRow[];
    canManage: boolean;
}>();

const { uiLanguageMode } = useI18n();

const copy = {
    en: {
        eyebrow: 'Demand Evidence',
        title: 'Test whether customers actually want this',
        subtitle:
            'Start with one important belief, test it in the real world, then link supporting evidence. These records reuse the existing PBR validation system.',
        progressLabel: 'Demand evidence guided journey',
        stepAssumption: 'Assumption',
        stepTest: 'Validation test',
        stepEvidence: 'Evidence',
        stepReview: 'Review',
        assumptionTitle: 'What do you need to be true?',
        assumptionHelp:
            'Write one testable belief about the customer, market, price, channel or problem.',
        categoryLabel: 'What is this assumption about?',
        categoryInstruction:
            'Choose a useful category or write your own.',
        categoryCustom: '+ Add another category',
        statementLabel: 'What exactly do you believe?',
        statementInstruction:
            'Make it specific enough that evidence could prove it wrong.',
        statementExample:
            'Example: At least 5 of 10 target customers will agree to a paid pilot at 3,000 THB per month.',
        statementSuggestions: [
            'Customers have this problem often enough to pay for a solution.',
            'The target price is acceptable to the intended customer.',
            'The proposed sales channel can reach qualified buyers.',
            'Customers prefer this offer over their current alternative.',
        ],
        assumptionStatus: 'Current assumption status',
        saveAssumption: 'Save assumption',
        saving: 'Saving…',
        testTitle: 'How will you test it?',
        testHelp:
            'Link the test to an assumption so later Feasibility can reuse the evidence trail.',
        chooseAssumption: 'Choose the assumption you are testing',
        methodLabel: 'What will you do to test it?',
        methodInstruction:
            'Choose a realistic validation method or write your own.',
        methodCustom: '+ Add another method',
        validationStatus: 'Validation status',
        dateLabel: 'When did this happen?',
        resultLabel: 'What did you learn?',
        resultInstruction:
            'Record the observed result, including negative evidence. Do not rewrite the assumption just to make it look successful.',
        resultExample:
            'Example: 8 interviews completed; 5 requested a proposal, 2 declined because of price, 1 had no urgency.',
        saveValidation: 'Save validation activity',
        noAssumption:
            'Save at least one assumption before creating a validation activity.',
        evidenceTitle: 'Link supporting evidence',
        evidenceHelp:
            'Evidence stays in the Document Vault. This step only links an authorized Evidence record to the validation activity.',
        chooseValidation: 'Choose the validation activity',
        evidenceIdLabel: 'Evidence ID',
        evidenceIdInstruction:
            'Open the Document Vault, create or select evidence you are allowed to manage, then paste its Evidence ID here.',
        evidenceIdExample: 'Example: UUID from the Evidence record',
        openVault: 'Open Document Vault',
        linkEvidence: 'Link evidence',
        noValidation:
            'Save a validation activity before linking evidence.',
        reviewTitle: 'Demand evidence summary',
        reviewHelp:
            'These records remain editable planning evidence. Deep Feasibility will reuse them; it will not ask you to re-enter the same facts.',
        assumptions: 'Assumptions',
        validations: 'Validation activities',
        recorded: 'Recorded',
        noRows: 'Nothing recorded yet.',
        statusPlanned: 'Planned',
        statusTesting: 'Testing',
        statusValidated: 'Validated',
        statusInvalidated: 'Invalidated',
        statusInProgress: 'In progress',
        statusCompleted: 'Completed',
        previous: 'Previous',
        next: 'Next',
        localDraft:
            'Unsaved answers are preserved in this browser while you move between steps.',
        saveError:
            'This record could not be saved. Your local draft is still here.',
    },
    my: {
        eyebrow: 'Demand Evidence',
        title: 'Customer တကယ်လိုချင်သလား စမ်းသပ်ပါ',
        subtitle:
            'အရေးကြီးတဲ့ Assumption တစ်ခုကနေစပြီး တကယ့် Market မှာ Test လုပ်ပါ။ ပြီးရင် Supporting Evidence ကို link လုပ်ပါ။ ရှိပြီးသား PBR Validation System ကိုပဲ ပြန်သုံးပါတယ်။',
        progressLabel: 'Demand evidence guided journey',
        stepAssumption: 'Assumption',
        stepTest: 'Validation Test',
        stepEvidence: 'Evidence',
        stepReview: 'Review',
        assumptionTitle: 'ဘာအချက်က မှန်ဖို့လိုသလဲ?',
        assumptionHelp:
            'Customer, Market, Price, Channel ဒါမှမဟုတ် Problem အကြောင်း Test လုပ်လို့ရတဲ့ ယုံကြည်ချက်တစ်ခုရေးပါ။',
        categoryLabel: 'ဒီ Assumption က ဘာအကြောင်းလဲ?',
        categoryInstruction:
            'အသုံးဝင်တဲ့ Category တစ်ခုရွေးပါ ဒါမှမဟုတ် ကိုယ့်ဟာကိုယ်ရေးပါ။',
        categoryCustom: '+ အခြား Category ထည့်မည်',
        statementLabel: 'ဘာကို တိတိကျကျ ယုံကြည်ထားသလဲ?',
        statementInstruction:
            'Evidence က မှားတယ်ဆိုတာ သက်သေပြနိုင်လောက်အောင် တိတိကျကျရေးပါ။',
        statementExample:
            'ဥပမာ - Target Customer 10 ယောက်ထဲက အနည်းဆုံး 5 ယောက်က တစ်လ 3,000 THB နဲ့ Paid Pilot လုပ်မယ်။',
        statementSuggestions: [
            'Customer တွေမှာ ဒီ Problem မကြာခဏဖြစ်ပြီး ဖြေရှင်းချက်အတွက် ပေးချေမယ်။',
            'သတ်မှတ်ထားတဲ့ Price ကို Target Customer လက်ခံနိုင်မယ်။',
            'ရွေးထားတဲ့ Sales Channel က Qualified Buyer တွေဆီ ရောက်နိုင်မယ်။',
            'Customer က လက်ရှိ Alternative ထက် ဒီ Offer ကို ပိုရွေးမယ်။',
        ],
        assumptionStatus: 'Assumption Status',
        saveAssumption: 'Assumption သိမ်းမည်',
        saving: 'သိမ်းနေသည်…',
        testTitle: 'ဘယ်လို Test လုပ်မလဲ?',
        testHelp:
            'နောက် Deep Feasibility မှာ Evidence Trail ကို ပြန်သုံးနိုင်ဖို့ Test ကို Assumption နဲ့ link လုပ်ပါ။',
        chooseAssumption: 'Test လုပ်မယ့် Assumption ကိုရွေးပါ',
        methodLabel: 'ဘယ်လို စမ်းသပ်မလဲ?',
        methodInstruction:
            'လက်တွေ့ကျတဲ့ Validation Method ကိုရွေးပါ ဒါမှမဟုတ် ကိုယ့်ဟာကိုယ်ရေးပါ။',
        methodCustom: '+ အခြား Method ထည့်မည်',
        validationStatus: 'Validation Status',
        dateLabel: 'ဘယ်နေ့မှာ ဖြစ်ခဲ့သလဲ?',
        resultLabel: 'ဘာသိခဲ့ရသလဲ?',
        resultInstruction:
            'Negative Evidence ပါရင်ပါ ရိုးရိုးတင်မှတ်တမ်းတင်ပါ။ Successful ပုံပေါ်အောင် Assumption ကို ပြန်မရေးပါနဲ့။',
        resultExample:
            'ဥပမာ - Interview 8 ခုလုပ်ပြီး 5 ယောက် Proposal တောင်း၊ 2 ယောက် Price ကြောင့်ငြင်း၊ 1 ယောက်က Urgency မရှိ။',
        saveValidation: 'Validation Activity သိမ်းမည်',
        noAssumption:
            'Validation Activity မလုပ်ခင် Assumption တစ်ခု အရင်သိမ်းပါ။',
        evidenceTitle: 'Supporting Evidence ကို Link လုပ်ပါ',
        evidenceHelp:
            'Evidence က Document Vault ထဲမှာပဲ ရှိနေပါတယ်။ ဒီ Step က Authorized Evidence Record ကို Validation နဲ့ link လုပ်တာသာ ဖြစ်ပါတယ်။',
        chooseValidation: 'Validation Activity ကိုရွေးပါ',
        evidenceIdLabel: 'Evidence ID',
        evidenceIdInstruction:
            'Document Vault ကိုဖွင့်ပြီး သင် Manage လုပ်ခွင့်ရှိတဲ့ Evidence ကို ဖန်တီး/ရွေးပြီး Evidence ID ကို ဒီမှာထည့်ပါ။',
        evidenceIdExample: 'ဥပမာ - Evidence Record မှ UUID',
        openVault: 'Document Vault ဖွင့်မည်',
        linkEvidence: 'Evidence Link လုပ်မည်',
        noValidation:
            'Evidence Link မလုပ်ခင် Validation Activity တစ်ခု အရင်သိမ်းပါ။',
        reviewTitle: 'Demand Evidence အကျဉ်းချုပ်',
        reviewHelp:
            'ဒီ Records တွေက Editable Planning Evidence ဖြစ်ပါတယ်။ Deep Feasibility မှာ ပြန်သုံးပြီး အချက်တူကို ပြန်မမေးပါ။',
        assumptions: 'Assumptions',
        validations: 'Validation Activities',
        recorded: 'Recorded',
        noRows: 'မရှိသေးပါ။',
        statusPlanned: 'Planned',
        statusTesting: 'Testing',
        statusValidated: 'Validated',
        statusInvalidated: 'Invalidated',
        statusInProgress: 'In Progress',
        statusCompleted: 'Completed',
        previous: 'နောက်ပြန်',
        next: 'ရှေ့ဆက်',
        localDraft:
            'မသိမ်းရသေးတဲ့ အဖြေတွေကို Step ပြောင်းတဲ့အချိန် ဒီ Browser ထဲမှာ ယာယီသိမ်းထားပါတယ်။',
        saveError:
            'Record ကို မသိမ်းနိုင်သေးပါ။ Local Draft မပျောက်ပါ။',
    },
    mixed: {
        eyebrow: 'Demand Evidence',
        title: 'Customer တကယ် want လုပ်သလား test လုပ်ပါ',
        subtitle:
            'One important assumption ကနေစပြီး real market မှာ test လုပ်၊ supporting evidence link လုပ်ပါ။ Existing PBR validation records ကိုပဲ reuse လုပ်ပါတယ်။',
        progressLabel: 'Demand evidence guided journey',
        stepAssumption: 'Assumption',
        stepTest: 'Validation test',
        stepEvidence: 'Evidence',
        stepReview: 'Review',
        assumptionTitle: 'ဘာက true ဖြစ်ဖို့လိုသလဲ?',
        assumptionHelp:
            'Customer, market, price, channel or problem အကြောင်း testable belief တစ်ခုရေးပါ။',
        categoryLabel: 'ဒီ assumption က ဘာအကြောင်းလဲ?',
        categoryInstruction:
            'Useful category ရွေးပါ or write your own.',
        categoryCustom: '+ Add another category',
        statementLabel: 'What exactly do you believe?',
        statementInstruction:
            'Evidence က wrong ဖြစ်တယ်လို့ prove လုပ်နိုင်လောက်အောင် specific ရေးပါ။',
        statementExample:
            'Example: Target customers 10 ယောက်ထဲက 5 ယောက် paid pilot ကို 3,000 THB/month နဲ့ လက်ခံမယ်။',
        statementSuggestions: [
            'Customers have this problem often enough to pay.',
            'Target price is acceptable.',
            'Chosen sales channel can reach qualified buyers.',
            'Customers prefer this offer over the current alternative.',
        ],
        assumptionStatus: 'Current assumption status',
        saveAssumption: 'Save assumption',
        saving: 'Saving…',
        testTitle: 'How will you test it?',
        testHelp:
            'Later Deep Feasibility reuse လုပ်နိုင်ဖို့ test ကို assumption နဲ့ link လုပ်ပါ။',
        chooseAssumption: 'Choose the assumption you are testing',
        methodLabel: 'What will you do to test it?',
        methodInstruction:
            'Realistic validation method ကိုရွေး or write your own.',
        methodCustom: '+ Add another method',
        validationStatus: 'Validation status',
        dateLabel: 'When did this happen?',
        resultLabel: 'What did you learn?',
        resultInstruction:
            'Negative evidence ပါရင်ပါ record လုပ်ပါ။ Success ပုံပေါ်အောင် assumption ကို rewrite မလုပ်ပါနဲ့။',
        resultExample:
            'Example: 8 interviews; 5 requested proposal, 2 rejected price, 1 had no urgency.',
        saveValidation: 'Save validation activity',
        noAssumption:
            'Validation မလုပ်ခင် assumption တစ်ခု save လုပ်ပါ။',
        evidenceTitle: 'Link supporting evidence',
        evidenceHelp:
            'Evidence က Document Vault ထဲမှာပဲ ရှိပါတယ်။ Authorized Evidence record ကို validation နဲ့ link လုပ်တာသာဖြစ်ပါတယ်။',
        chooseValidation: 'Choose the validation activity',
        evidenceIdLabel: 'Evidence ID',
        evidenceIdInstruction:
            'Document Vault မှာ manage လုပ်ခွင့်ရှိတဲ့ evidence ကို create/select လုပ်ပြီး Evidence ID ကို ဒီမှာ paste လုပ်ပါ။',
        evidenceIdExample: 'Example: UUID from the Evidence record',
        openVault: 'Open Document Vault',
        linkEvidence: 'Link evidence',
        noValidation:
            'Evidence မlinkခင် validation activity တစ်ခု save လုပ်ပါ။',
        reviewTitle: 'Demand evidence summary',
        reviewHelp:
            'Records က editable planning evidence ဖြစ်ပါတယ်။ Deep Feasibility က reuse လုပ်ပြီး same facts ပြန်မမေးပါ။',
        assumptions: 'Assumptions',
        validations: 'Validation activities',
        recorded: 'Recorded',
        noRows: 'Nothing recorded yet.',
        statusPlanned: 'Planned',
        statusTesting: 'Testing',
        statusValidated: 'Validated',
        statusInvalidated: 'Invalidated',
        statusInProgress: 'In progress',
        statusCompleted: 'Completed',
        previous: 'Previous',
        next: 'Next',
        localDraft:
            'Unsaved answers ကို steps ကြား move လုပ်နေချိန် ဒီ browser ထဲမှာ preserve လုပ်ထားပါတယ်။',
        saveError:
            'Record could not be saved. Local draft is still here.',
    },
} as const;

const c = computed(() => copy[uiLanguageMode.value]);

const draft = reactive({
    assumptionCategory: 'market',
    assumptionStatement: '',
    assumptionStatus: 'planned',
    validationAssumptionId: '',
    validationMethod: '',
    validationStatus: 'planned',
    occurredOn: '',
    resultSummary: '',
    evidenceValidationId: '',
    evidenceId: '',
});

const storageKey = computed(
    () => 'pbr-demand-draft:' + props.businessId,
);

onMounted(() => {
    if (typeof window === 'undefined') {
        return;
    }

    const stored = window.sessionStorage.getItem(storageKey.value);

    if (!stored) {
        return;
    }

    try {
        Object.assign(
            draft,
            JSON.parse(stored) as Partial<typeof draft>,
        );
    } catch {
        window.sessionStorage.removeItem(storageKey.value);
    }
});

watch(
    draft,
    (value) => {
        if (typeof window === 'undefined') {
            return;
        }

        window.sessionStorage.setItem(
            storageKey.value,
            JSON.stringify(value),
        );
    },
    { deep: true },
);

const assumptionChoices = computed(() => [
    { value: 'market', label: 'Market / demand' },
    { value: 'customer', label: 'Customer / problem' },
    { value: 'pricing', label: 'Pricing / willingness to pay' },
    { value: 'channel', label: 'Sales channel' },
    { value: 'competition', label: 'Competition / alternative' },
]);

const methodChoices = computed(() => [
    { value: 'Customer interviews', label: 'Customer interviews' },
    { value: 'Paid pilot', label: 'Paid pilot' },
    { value: 'Pre-orders', label: 'Pre-orders' },
    {
        value: 'Landing page / inquiry test',
        label: 'Landing page / inquiry test',
    },
    {
        value: 'Prototype / usability test',
        label: 'Prototype / usability test',
    },
]);

const focus = ref<StepKey>(
    props.assumptions.length === 0
        ? 'assumption'
        : props.validations.length === 0
          ? 'test'
          : 'review',
);

const stepKeys: StepKey[] = [
    'assumption',
    'test',
    'evidence',
    'review',
];

const stepLabels = computed<Record<StepKey, string>>(() => ({
    assumption: c.value.stepAssumption,
    test: c.value.stepTest,
    evidence: c.value.stepEvidence,
    review: c.value.stepReview,
}));

const steps = computed(() =>
    stepKeys.map((key) => ({
        key,
        label: stepLabels.value[key],
        state:
            key === focus.value
                ? ('current' as const)
                : key === 'assumption' && props.assumptions.length > 0
                  ? ('recorded' as const)
                  : key === 'test' && props.validations.length > 0
                    ? ('recorded' as const)
                    : ('available' as const),
    })),
);

const busy = ref(false);
const errorMessage = ref('');

const post = (
    url: string,
    data: PostData,
    afterSuccess: () => void,
): void => {
    if (busy.value) {
        return;
    }

    errorMessage.value = '';
    busy.value = true;

    router.post(url, data, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: afterSuccess,
        onError: () => {
            errorMessage.value = c.value.saveError;
        },
        onFinish: () => {
            busy.value = false;
        },
    });
};

const saveAssumption = (): void => {
    if (
        draft.assumptionCategory.trim() === ''
        || draft.assumptionStatement.trim() === ''
    ) {
        return;
    }

    post(
        '/formation/new/assumptions',
        {
            category: draft.assumptionCategory,
            statement: draft.assumptionStatement,
            status: draft.assumptionStatus,
        },
        () => {
            draft.assumptionStatement = '';
            draft.assumptionStatus = 'planned';
            focus.value = 'test';
        },
    );
};

const saveValidation = (): void => {
    if (
        draft.validationAssumptionId.trim() === ''
        || draft.validationMethod.trim() === ''
    ) {
        return;
    }

    post(
        '/formation/new/validations',
        {
            assumption_id: draft.validationAssumptionId,
            method: draft.validationMethod,
            status: draft.validationStatus,
            occurred_on: draft.occurredOn || null,
            result_summary: draft.resultSummary || null,
        },
        () => {
            draft.validationMethod = '';
            draft.validationStatus = 'planned';
            draft.occurredOn = '';
            draft.resultSummary = '';
            focus.value = 'evidence';
        },
    );
};

const linkEvidence = (): void => {
    if (
        draft.evidenceValidationId.trim() === ''
        || draft.evidenceId.trim() === ''
    ) {
        return;
    }

    post(
        '/formation/new/validations/'
            + draft.evidenceValidationId
            + '/evidence',
        {
            evidence_id: draft.evidenceId,
        },
        () => {
            draft.evidenceId = '';
            focus.value = 'review';
        },
    );
};

const chooseStep = (key: string): void => {
    if (stepKeys.includes(key as StepKey)) {
        focus.value = key as StepKey;
    }
};

const assumptionLabel = (row: GenericRow): string =>
    String(row.category ?? '')
    + ' · '
    + String(row.statement ?? '');

const validationLabel = (row: GenericRow): string =>
    String(row.method ?? '')
    + ' · '
    + String(row.status ?? '');

const currentIndex = computed(() =>
    Math.max(0, stepKeys.indexOf(focus.value)),
);

const move = (direction: -1 | 1): void => {
    const next = currentIndex.value + direction;

    if (next >= 0 && next < stepKeys.length) {
        focus.value = stepKeys[next];
    }
};
</script>

<template>
    <div class="space-y-5">
        <header
            class="rounded-[26px] border border-[#d4e2d7] bg-[linear-gradient(145deg,#ffffff,#f4faf6)] p-5 shadow-[0_16px_38px_rgb(16_35_26_/_6%)] sm:p-7"
        >
            <p
                class="text-xs font-black uppercase tracking-[0.17em] text-[var(--pbr-green)]"
            >
                {{ c.eyebrow }}
            </p>
            <h2
                class="mt-2 text-2xl font-black tracking-[-0.03em] text-[var(--pbr-ink)]"
            >
                {{ c.title }}
            </h2>
            <p
                class="mt-2 max-w-3xl text-sm leading-7 text-[var(--pbr-muted)]"
            >
                {{ c.subtitle }}
            </p>
            <p
                class="mt-4 rounded-2xl border border-[#dce6de] bg-white/75 px-4 py-3 text-xs leading-5 text-[var(--pbr-muted)]"
            >
                {{ c.localDraft }}
            </p>
        </header>

        <GuidedJourneyStepper
            :steps="steps"
            :label="c.progressLabel"
            compact
            @select="chooseStep"
        />

        <p
            v-if="errorMessage"
            role="alert"
            class="rounded-xl border border-[#efc9c2] bg-[#fff5f3] px-4 py-3 text-sm font-bold text-[var(--pbr-red)]"
        >
            {{ errorMessage }}
        </p>

        <PbrFormSection
            v-if="focus === 'assumption'"
            numbered="1"
            :title="c.assumptionTitle"
            :instruction="c.assumptionHelp"
        >
            <ChoiceOrCustom
                v-model="draft.assumptionCategory"
                :label="c.categoryLabel"
                :instruction="c.categoryInstruction"
                :choices="assumptionChoices"
                :custom-label="c.categoryCustom"
            />

            <GuidedSuggestionTextarea
                v-if="draft.assumptionCategory.trim() !== ''"
                v-model="draft.assumptionStatement"
                :label="c.statementLabel"
                :instruction="c.statementInstruction"
                :example="c.statementExample"
                :suggestions="[...c.statementSuggestions]"
                :disabled="!canManage"
            />

            <label
                v-if="draft.assumptionStatement.trim() !== ''"
                class="block text-sm font-black text-[var(--pbr-ink-soft)]"
            >
                {{ c.assumptionStatus }}
                <select
                    v-model="draft.assumptionStatus"
                    class="pbr-input-control mt-2 min-h-11 bg-white px-3"
                    :disabled="!canManage"
                >
                    <option value="planned">{{ c.statusPlanned }}</option>
                    <option value="testing">{{ c.statusTesting }}</option>
                    <option value="validated">{{ c.statusValidated }}</option>
                    <option value="invalidated">{{ c.statusInvalidated }}</option>
                </select>
            </label>

            <template #actions>
                <div class="flex justify-end">
                    <PbrButton
                        type="button"
                        variant="primary"
                        :disabled="
                            !canManage
                            || draft.assumptionCategory.trim() === ''
                            || draft.assumptionStatement.trim() === ''
                        "
                        :busy="busy"
                        :busy-label="c.saving"
                        @click="saveAssumption"
                    >
                        {{ c.saveAssumption }}
                    </PbrButton>
                </div>
            </template>
        </PbrFormSection>

        <PbrFormSection
            v-else-if="focus === 'test'"
            numbered="2"
            :title="c.testTitle"
            :instruction="c.testHelp"
        >
            <p
                v-if="assumptions.length === 0"
                class="rounded-xl border border-[#ead9a5] bg-[#fffaf0] p-4 text-sm leading-6 text-[#6d5a22]"
            >
                {{ c.noAssumption }}
            </p>

            <template v-else>
                <label class="block text-sm font-black text-[var(--pbr-ink-soft)]">
                    {{ c.chooseAssumption }}
                    <select
                        v-model="draft.validationAssumptionId"
                        class="pbr-input-control mt-2 min-h-11 bg-white px-3"
                        :disabled="!canManage"
                    >
                        <option value="">—</option>
                        <option
                            v-for="row in assumptions"
                            :key="String(row.id)"
                            :value="String(row.id)"
                        >
                            {{ assumptionLabel(row) }}
                        </option>
                    </select>
                </label>

                <ChoiceOrCustom
                    v-if="draft.validationAssumptionId.trim() !== ''"
                    v-model="draft.validationMethod"
                    :label="c.methodLabel"
                    :instruction="c.methodInstruction"
                    :choices="methodChoices"
                    :custom-label="c.methodCustom"
                />

                <label
                    v-if="draft.validationMethod.trim() !== ''"
                    class="block text-sm font-black text-[var(--pbr-ink-soft)]"
                >
                    {{ c.validationStatus }}
                    <select
                        v-model="draft.validationStatus"
                        class="pbr-input-control mt-2 min-h-11 bg-white px-3"
                        :disabled="!canManage"
                    >
                        <option value="planned">{{ c.statusPlanned }}</option>
                        <option value="in_progress">{{ c.statusInProgress }}</option>
                        <option value="completed">{{ c.statusCompleted }}</option>
                    </select>
                </label>

                <label
                    v-if="draft.validationMethod.trim() !== ''"
                    class="block text-sm font-black text-[var(--pbr-ink-soft)]"
                >
                    {{ c.dateLabel }}
                    <OptionalTemporalInput
                        v-model="draft.occurredOn"
                        type="date"
                        class="pbr-input-control mt-2 min-h-11 px-3"
                        :disabled="!canManage"
                    />
                </label>

                <GuidedSuggestionTextarea
                    v-if="draft.validationMethod.trim() !== ''"
                    v-model="draft.resultSummary"
                    :label="c.resultLabel"
                    :instruction="c.resultInstruction"
                    :example="c.resultExample"
                    :disabled="!canManage"
                />
            </template>

            <template #actions>
                <div class="flex justify-end">
                    <PbrButton
                        type="button"
                        variant="primary"
                        :disabled="
                            !canManage
                            || draft.validationAssumptionId.trim() === ''
                            || draft.validationMethod.trim() === ''
                        "
                        :busy="busy"
                        :busy-label="c.saving"
                        @click="saveValidation"
                    >
                        {{ c.saveValidation }}
                    </PbrButton>
                </div>
            </template>
        </PbrFormSection>

        <PbrFormSection
            v-else-if="focus === 'evidence'"
            numbered="3"
            :title="c.evidenceTitle"
            :instruction="c.evidenceHelp"
        >
            <p
                v-if="validations.length === 0"
                class="rounded-xl border border-[#ead9a5] bg-[#fffaf0] p-4 text-sm leading-6 text-[#6d5a22]"
            >
                {{ c.noValidation }}
            </p>

            <template v-else>
                <label class="block text-sm font-black text-[var(--pbr-ink-soft)]">
                    {{ c.chooseValidation }}
                    <select
                        v-model="draft.evidenceValidationId"
                        class="pbr-input-control mt-2 min-h-11 bg-white px-3"
                        :disabled="!canManage"
                    >
                        <option value="">—</option>
                        <option
                            v-for="row in validations"
                            :key="String(row.id)"
                            :value="String(row.id)"
                        >
                            {{ validationLabel(row) }}
                        </option>
                    </select>
                </label>

                <div
                    v-if="draft.evidenceValidationId.trim() !== ''"
                    class="rounded-2xl border border-[#dce6de] bg-[#fafcfa] p-4"
                >
                    <PbrButton
                        href="/records/documents"
                        variant="secondary"
                    >
                        {{ c.openVault }}
                    </PbrButton>

                    <div class="mt-4">
                        <PbrTextInput
                            v-model="draft.evidenceId"
                            :label="c.evidenceIdLabel"
                            :instruction="c.evidenceIdInstruction"
                            :example="c.evidenceIdExample"
                            :disabled="!canManage"
                        />
                    </div>
                </div>
            </template>

            <template #actions>
                <div class="flex justify-end">
                    <PbrButton
                        type="button"
                        variant="primary"
                        :disabled="
                            !canManage
                            || draft.evidenceValidationId.trim() === ''
                            || draft.evidenceId.trim() === ''
                        "
                        :busy="busy"
                        :busy-label="c.saving"
                        @click="linkEvidence"
                    >
                        {{ c.linkEvidence }}
                    </PbrButton>
                </div>
            </template>
        </PbrFormSection>

        <PbrFormSection
            v-else-if="focus === 'review'"
            numbered="4"
            :title="c.reviewTitle"
            :instruction="c.reviewHelp"
        >
            <div class="grid gap-4 lg:grid-cols-2">
                <section
                    class="rounded-2xl border border-[#dce6de] bg-[#fafcfa] p-4"
                >
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-sm font-black text-[var(--pbr-ink)]">
                            {{ c.assumptions }}
                        </h3>
                        <span class="text-xs font-bold text-[var(--pbr-green)]">
                            {{ assumptions.length }} {{ c.recorded }}
                        </span>
                    </div>
                    <div class="mt-3 space-y-3">
                        <article
                            v-for="row in assumptions"
                            :key="String(row.id)"
                            class="rounded-xl border border-[#e1e9e3] bg-white p-3"
                        >
                            <p class="text-xs font-black uppercase tracking-[0.08em] text-[var(--pbr-green)]">
                                {{ row.category }} · {{ row.status }}
                            </p>
                            <p class="mt-1 text-sm leading-6 text-[var(--pbr-ink-soft)]">
                                {{ row.statement }}
                            </p>
                        </article>
                        <p
                            v-if="assumptions.length === 0"
                            class="text-sm text-[var(--pbr-muted)]"
                        >
                            {{ c.noRows }}
                        </p>
                    </div>
                </section>

                <section
                    class="rounded-2xl border border-[#dce6de] bg-[#fafcfa] p-4"
                >
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-sm font-black text-[var(--pbr-ink)]">
                            {{ c.validations }}
                        </h3>
                        <span class="text-xs font-bold text-[var(--pbr-green)]">
                            {{ validations.length }} {{ c.recorded }}
                        </span>
                    </div>
                    <div class="mt-3 space-y-3">
                        <article
                            v-for="row in validations"
                            :key="String(row.id)"
                            class="rounded-xl border border-[#e1e9e3] bg-white p-3"
                        >
                            <p class="text-sm font-black text-[var(--pbr-ink-soft)]">
                                {{ row.method }}
                            </p>
                            <p class="mt-1 text-xs font-bold uppercase tracking-[0.06em] text-[var(--pbr-green)]">
                                {{ row.status }}
                            </p>
                            <p class="mt-2 text-xs leading-5 text-[var(--pbr-muted)]">
                                {{ row.result_summary || '—' }}
                            </p>
                        </article>
                        <p
                            v-if="validations.length === 0"
                            class="text-sm text-[var(--pbr-muted)]"
                        >
                            {{ c.noRows }}
                        </p>
                    </div>
                </section>
            </div>
        </PbrFormSection>

        <div
            class="flex flex-col-reverse gap-3 rounded-[20px] border border-[#dce6de] bg-white p-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <PbrButton
                type="button"
                variant="secondary"
                :disabled="currentIndex === 0"
                @click="move(-1)"
            >
                {{ c.previous }}
            </PbrButton>

            <PbrButton
                v-if="currentIndex < stepKeys.length - 1"
                type="button"
                variant="primary"
                @click="move(1)"
            >
                {{ c.next }}
            </PbrButton>
        </div>
    </div>
</template>
