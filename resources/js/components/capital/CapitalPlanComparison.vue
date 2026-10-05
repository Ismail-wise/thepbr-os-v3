<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PbrErrorSummary from '../ui/PbrErrorSummary.vue';
import { useI18n } from '../../i18n/useI18n';
import { humanErrorMessages } from '../../support/humanErrors';

type GenericRow = Record<string, any>;
type ScenarioKey = 'lean' | 'base' | 'growth';

const scenarioKeys: ScenarioKey[] = ['lean', 'base', 'growth'];

const props = defineProps<{
    draft: GenericRow | null;
    readModel: GenericRow | null;
    canonicalInput: GenericRow | null;
    currency: string;
    canManage: boolean;
}>();

const { uiLanguageMode } = useI18n();

const copy = {
    en: {
        eyebrow: 'Capital Plan Comparison',
        title: 'Compare Lean, Base and Growth before Capital Approval',
        subtitle:
            'Start once from the current Capital plan, then adjust only the assumptions that make each alternative meaningfully different. PBR recalculates every scenario on the server.',
        boundary:
            'Planning comparison only. A Preferred Plan is not Approval, Signature, Effective truth, a funding commitment, Partner Contribution, Equity or Ownership.',
        prepare: 'Prepare comparison from current Capital plan',
        refresh: 'Refresh all scenarios from current Capital plan',
        refreshWarning:
            'The current Capital plan changed after this comparison was prepared. Refresh before treating any scenario as ready for the next stage.',
        save: 'Save comparison',
        saving: 'Saving…',
        saved: 'Capital comparison saved.',
        prepared: 'Comparison prepared from the current Capital plan.',
        revision: 'Comparison revision',
        preparedAgainst: 'Prepared from Capital revision',
        currentRevision: 'Current Capital revision',
        readOnly: 'You can review the comparison but cannot change the scenarios.',
        needCapital:
            'Save the Guided Capital plan first. Lean, Base and Growth are initialized from that saved input.',
        lean: 'Lean',
        base: 'Base',
        growth: 'Growth',
        leanHelp: 'Minimum practical Capital approach.',
        baseHelp: 'Realistic expected Capital approach.',
        growthHelp: 'Higher-capacity or expansion-ready Capital approach.',
        notBest:
            'No scenario is automatically the best. Review the trade-offs for this Business.',
        notPrepared: 'Not Prepared',
        markNotPrepared: 'Mark as not prepared',
        restoreCanonical: 'Copy current Capital input',
        inherited:
            'Startup costs and initial assets stay copied from the current Capital input. Adjust the scenario assumptions below.',
        workingCapital: 'Working Capital',
        workingMethod: 'Working Capital method',
        workingMonths: 'Working Capital months',
        monthlyBurn: 'Monthly burn',
        fixedWorkingCapital: 'Fixed Working Capital',
        contingency: 'Contingency',
        contingencyPercent: 'Contingency %',
        contingencyAmount: 'Contingency amount',
        confirmedFunding: 'Confirmed Funding',
        totalRequired: 'Total Capital Requirement',
        fundingGap: 'Funding Gap',
        fundingSurplus: 'Funding Surplus',
        fundedPercent: '% Funded',
        readiness: 'Comparison Readiness',
        preferred: 'Preferred planning candidate',
        preferredHelp:
            'Choose only after all three scenarios are ready. This prepares the next Capital Approval stage but does not approve anything.',
        selectPreferred: 'Mark as preferred',
        preferredSelected: 'Preferred planning candidate',
        overallReady: 'Ready for comparison',
        incomplete: 'Incomplete',
        needsReview: 'Needs review',
        notStarted: 'Not started',
        unavailable: 'Not available',
        na: 'N/A',
        reasons: 'What is still needed',
        preOpening: 'Startup Cost Plan is incomplete.',
        assets: 'Initial Assets & Opening Inventory is incomplete.',
        working: 'Working Capital is incomplete.',
        reserve: 'Contingency Reserve is incomplete.',
        total: 'Total Capital Requirement is not calculable yet.',
        funding: 'Confirmed Funding is missing or incomplete.',
        scenarioMissing: 'This scenario is not prepared.',
        comparisonMissing: 'Prepare the comparison first.',
        shortfallReminder:
            'One or more scenarios still have a Funding Gap. Review the existing Capital Rule / shortfall plan when choosing a preferred candidate.',
        methodCanonical: 'Use existing Business Model numbers',
        methodMonthlyCosts: 'Monthly cost items',
        methodMonthlyBurn: 'Monthly burn × months',
        methodFixed: 'Fixed amount',
        methodPercentage: 'Percentage',
        methodMissing: 'Not entered',
        staleError:
            'A newer comparison revision exists. Reload the latest Formation data before saving again.',
        invalidError:
            'Check the visible scenario assumptions. Amounts must be non-negative and method-specific values must stay valid.',
        prepareError:
            'Save the current Capital planning draft before preparing Lean, Base and Growth.',
        reload: 'Reload latest comparison',
        errorTitle: 'Capital comparison could not be saved',
        errorHelp:
            'Review the visible Lean, Base and Growth inputs. Missing is different from an explicit zero.',
    },
    my: {
        eyebrow: 'Capital Plan နှိုင်းယှဉ်ချက်',
        title: 'Capital Approval မတိုင်မီ Lean, Base, Growth ကို နှိုင်းယှဉ်ပါ',
        subtitle:
            'လက်ရှိ Capital plan ကို တစ်ကြိမ်ပဲ အခြေခံပြီး scenario တစ်ခုချင်းစီအတွက် ကွာခြားစေမယ့် assumption တွေကိုပဲ ပြင်ပါ။ Scenario တိုင်းကို server က ပြန်တွက်ပေးပါတယ်။',
        boundary:
            'ဒီအပိုင်းက Planning Comparison သာဖြစ်ပါတယ်။ Preferred Plan ရွေးထားတာက Approval, Signature, Effective truth, Funding Commitment, Partner Contribution, Equity သို့မဟုတ် Ownership မဟုတ်ပါ။',
        prepare: 'လက်ရှိ Capital plan မှ comparison ပြင်ဆင်မည်',
        refresh: 'လက်ရှိ Capital plan မှ scenario အားလုံးကို ပြန်စမည်',
        refreshWarning:
            'Comparison ပြင်ဆင်ပြီးနောက် လက်ရှိ Capital plan ပြောင်းထားပါတယ်။ နောက်အဆင့်အတွက် ready လို့ မယူဆခင် refresh လုပ်ပါ။',
        save: 'Comparison သိမ်းမည်',
        saving: 'သိမ်းနေသည်…',
        saved: 'Capital comparison သိမ်းပြီးပါပြီ။',
        prepared: 'လက်ရှိ Capital plan ကနေ comparison ပြင်ဆင်ပြီးပါပြီ။',
        revision: 'Comparison revision',
        preparedAgainst: 'စတင်ယူထားသော Capital revision',
        currentRevision: 'လက်ရှိ Capital revision',
        readOnly: 'Comparison ကိုကြည့်နိုင်ပေမယ့် scenario တွေကို ပြင်လို့မရပါ။',
        needCapital:
            'Guided Capital plan ကို အရင်သိမ်းပါ။ Lean, Base, Growth ကို အဲဒီ saved input ကနေ စတင်ပေးပါမယ်။',
        lean: 'Lean',
        base: 'Base',
        growth: 'Growth',
        leanHelp: 'အနည်းဆုံး လက်တွေ့အသုံးချနိုင်မယ့် Capital ပုံစံ။',
        baseHelp: 'လက်တွေ့မျှော်မှန်းထားတဲ့ Capital ပုံစံ။',
        growthHelp: 'တိုးချဲ့ရန် အသင့်ဖြစ်စေမယ့် ပိုမြင့်သော Capital ပုံစံ။',
        notBest:
            'Scenario တစ်ခုကို အလိုအလျောက် အကောင်းဆုံးလို့ မရွေးပါ။ ဒီ Business အတွက် ကွာခြားချက်တွေကို စစ်ပြီးရွေးပါ။',
        notPrepared: 'မပြင်ဆင်ရသေး',
        markNotPrepared: 'မပြင်ဆင်ရသေးအဖြစ်ထားမည်',
        restoreCanonical: 'လက်ရှိ Capital input ကို ကူးယူမည်',
        inherited:
            'Startup cost နဲ့ Initial Assets ကို လက်ရှိ Capital input ကနေ ကူးထားပါတယ်။ အောက်က scenario assumption တွေကို ပြင်ပါ။',
        workingCapital: 'Working Capital',
        workingMethod: 'Working Capital နည်းလမ်း',
        workingMonths: 'Working Capital လများ',
        monthlyBurn: 'Monthly burn',
        fixedWorkingCapital: 'Fixed Working Capital',
        contingency: 'Contingency',
        contingencyPercent: 'Contingency %',
        contingencyAmount: 'Contingency ပမာဏ',
        confirmedFunding: 'အတည်ပြုထားသော Funding',
        totalRequired: 'စုစုပေါင်းလိုအပ်သော Capital',
        fundingGap: 'Funding Gap',
        fundingSurplus: 'Funding Surplus',
        fundedPercent: 'Funding ပြည့်မီမှု %',
        readiness: 'နှိုင်းယှဉ်ရန်အဆင်သင့်မှု',
        preferred: 'ဦးစားပေး Planning Candidate',
        preferredHelp:
            'Scenario သုံးခုလုံး ready ဖြစ်ပြီးမှ ရွေးပါ။ နောက် Capital Approval အဆင့်အတွက် ပြင်ဆင်တာသာဖြစ်ပြီး Approval မဟုတ်ပါ။',
        selectPreferred: 'Preferred အဖြစ်ရွေးမည်',
        preferredSelected: 'ရွေးထားသော Planning Candidate',
        overallReady: 'နှိုင်းယှဉ်ရန် အဆင်သင့်',
        incomplete: 'မပြည့်စုံသေး',
        needsReview: 'ပြန်စစ်ရန်လို',
        notStarted: 'မစရသေး',
        unavailable: 'မရနိုင်သေး',
        na: 'မသက်ဆိုင်',
        reasons: 'လိုအပ်နေသေးသောအချက်',
        preOpening: 'Startup Cost Plan မပြည့်စုံသေးပါ။',
        assets: 'Initial Assets & Opening Inventory မပြည့်စုံသေးပါ။',
        working: 'Working Capital မပြည့်စုံသေးပါ။',
        reserve: 'Contingency Reserve မပြည့်စုံသေးပါ။',
        total: 'Total Capital Requirement ကို မတွက်နိုင်သေးပါ။',
        funding: 'Confirmed Funding မပြည့်စုံသေးပါ။',
        scenarioMissing: 'ဒီ scenario ကို မပြင်ဆင်ရသေးပါ။',
        comparisonMissing: 'Comparison ကို အရင်ပြင်ဆင်ပါ။',
        shortfallReminder:
            'Scenario တစ်ခု သို့မဟုတ် တစ်ခုထက်ပိုပြီး Funding Gap ရှိနေပါတယ်။ Preferred candidate ရွေးရာမှာ ရှိပြီးသား Capital Rule / shortfall plan ကို ပြန်စစ်ပါ။',
        methodCanonical: 'Business Model ရှိပြီးသားကိန်းဂဏန်းကို သုံးမည်',
        methodMonthlyCosts: 'Monthly cost items',
        methodMonthlyBurn: 'Monthly burn × months',
        methodFixed: 'Fixed amount',
        methodPercentage: 'ရာခိုင်နှုန်း',
        methodMissing: 'မထည့်ရသေး',
        staleError:
            'Comparison revision အသစ်ရှိနေပါတယ်။ Latest Formation data ကို reload လုပ်ပြီးမှ ပြန်သိမ်းပါ။',
        invalidError:
            'မြင်နေရတဲ့ scenario assumption တွေကို စစ်ပါ။ Amount တွေက non-negative ဖြစ်ရပြီး method-specific value တွေ မှန်ရပါမယ်။',
        prepareError:
            'Lean, Base, Growth ပြင်ဆင်မတိုင်မီ လက်ရှိ Capital planning draft ကို အရင်သိမ်းပါ။',
        reload: 'နောက်ဆုံး Comparison ကို ပြန်တင်မည်',
        errorTitle: 'Capital comparison ကို မသိမ်းနိုင်ပါ',
        errorHelp:
            'Lean, Base, Growth input တွေကို စစ်ပါ။ Missing နဲ့ explicit zero က မတူပါ။',
    },
    mixed: {
        eyebrow: 'Capital Plan Comparison',
        title: 'Capital Approval မတိုင်မီ Lean / Base / Growth ကို compare လုပ်ပါ',
        subtitle:
            'Current Capital plan ကို once-only starting point အဖြစ် reuse လုပ်ပြီး scenario တစ်ခုချင်းစီရဲ့ assumption တွေကိုပဲ adjust လုပ်ပါ။ Calculation အားလုံးကို server ကလုပ်ပါတယ်။',
        boundary:
            'Planning comparison only. Preferred Plan က Approval, Signature, Effective truth, Funding Commitment, Contribution, Equity or Ownership မဟုတ်ပါ။',
        prepare: 'Current Capital plan ကနေ comparison ပြင်ဆင်မည်',
        refresh: 'Current Capital plan ကနေ scenarios အားလုံး refresh လုပ်မည်',
        refreshWarning:
            'Current Capital plan revision ပြောင်းထားပါတယ်။ Next stage ready လို့မယူဆခင် comparison ကို refresh လုပ်ပါ။',
        save: 'Save comparison',
        saving: 'Saving…',
        saved: 'Capital comparison saved.',
        prepared: 'Current Capital plan ကနေ comparison prepared ဖြစ်ပါပြီ။',
        revision: 'Comparison revision',
        preparedAgainst: 'Prepared from Capital revision',
        currentRevision: 'Current Capital revision',
        readOnly: 'Comparison ကို review လုပ်နိုင်ပေမယ့် edit မလုပ်နိုင်ပါ။',
        needCapital:
            'Guided Capital plan ကို အရင် save လုပ်ပါ။ Lean / Base / Growth ကို saved input ကနေ initialize လုပ်ပါမယ်။',
        lean: 'Lean',
        base: 'Base',
        growth: 'Growth',
        leanHelp: 'Minimum practical Capital approach.',
        baseHelp: 'Realistic expected Capital approach.',
        growthHelp: 'Higher-capacity / expansion-ready Capital approach.',
        notBest: 'Scenario တစ်ခုကို auto-winner မရွေးပါ။ Trade-offs ကို review လုပ်ပါ။',
        notPrepared: 'Not Prepared',
        markNotPrepared: 'Mark as not prepared',
        restoreCanonical: 'Copy current Capital input',
        inherited:
            'Startup costs နဲ့ Initial Assets ကို current Capital input ကနေ reuse လုပ်ထားပါတယ်။ Scenario assumptions ကိုပဲ adjust လုပ်ပါ။',
        workingCapital: 'Working Capital',
        workingMethod: 'Working Capital method',
        workingMonths: 'Working Capital months',
        monthlyBurn: 'Monthly burn',
        fixedWorkingCapital: 'Fixed Working Capital',
        contingency: 'Contingency',
        contingencyPercent: 'Contingency %',
        contingencyAmount: 'Contingency amount',
        confirmedFunding: 'Confirmed Funding',
        totalRequired: 'Total Capital Requirement',
        fundingGap: 'Funding Gap',
        fundingSurplus: 'Funding Surplus',
        fundedPercent: '% Funded',
        readiness: 'Comparison Readiness',
        preferred: 'Preferred planning candidate',
        preferredHelp:
            'Scenario သုံးခုလုံး ready ဖြစ်ပြီးမှ select လုပ်ပါ။ ဒါက next Capital Approval stage ကို prepare လုပ်တာပဲဖြစ်ပါတယ်။',
        selectPreferred: 'Mark as preferred',
        preferredSelected: 'Preferred planning candidate',
        overallReady: 'Ready for comparison',
        incomplete: 'Incomplete',
        needsReview: 'Needs review',
        notStarted: 'Not started',
        unavailable: 'Not available',
        na: 'N/A',
        reasons: 'What is still needed',
        preOpening: 'Startup Cost Plan is incomplete.',
        assets: 'Initial Assets & Opening Inventory is incomplete.',
        working: 'Working Capital is incomplete.',
        reserve: 'Contingency Reserve is incomplete.',
        total: 'Total Capital Requirement is not calculable yet.',
        funding: 'Confirmed Funding is missing or incomplete.',
        scenarioMissing: 'This scenario is not prepared.',
        comparisonMissing: 'Prepare the comparison first.',
        shortfallReminder:
            'Funding Gap ရှိတဲ့ scenario ရှိနေပါတယ်။ Preferred candidate ရွေးချိန် existing Capital Rule / shortfall plan ကို review လုပ်ပါ။',
        methodCanonical: 'Use existing Business Model numbers',
        methodMonthlyCosts: 'Monthly cost items',
        methodMonthlyBurn: 'Monthly burn × months',
        methodFixed: 'Fixed amount',
        methodPercentage: 'Percentage',
        methodMissing: 'Not entered',
        staleError:
            'Newer comparison revision ရှိနေပါတယ်။ Latest Formation data ကို reload လုပ်ပြီးမှ save ပြန်လုပ်ပါ။',
        invalidError:
            'Visible scenario assumptions ကိုစစ်ပါ။ Amounts must be non-negative and method-specific values must stay valid.',
        prepareError:
            'Lean / Base / Growth prepare မလုပ်ခင် current Capital planning draft ကို save လုပ်ပါ။',
        reload: 'Reload latest comparison',
        errorTitle: 'Capital comparison could not be saved',
        errorHelp:
            'Lean / Base / Growth inputs ကိုစစ်ပါ။ Missing နဲ့ explicit zero က မတူပါ။',
    },
} as const;

const c = computed(() => copy[uiLanguageMode.value]);

const expectedRevision = ref(0);
const scenarios = ref<Record<ScenarioKey, GenericRow | null>>({
    lean: null,
    base: null,
    growth: null,
});
const preferredPlan = ref<ScenarioKey | null>(null);
const busy = ref(false);
const errors = ref<string[]>([]);
const success = ref('');

const clone = <T>(value: T): T =>
    JSON.parse(JSON.stringify(value)) as T;

const hydrate = (source: GenericRow | null): void => {
    expectedRevision.value = Number(source?.revision ?? 0);
    const input = (source?.input ?? null) as GenericRow | null;
    const stored = (input?.scenarios ?? null) as GenericRow | null;

    scenarios.value = {
        lean: stored?.lean ? clone(stored.lean) : null,
        base: stored?.base ? clone(stored.base) : null,
        growth: stored?.growth ? clone(stored.growth) : null,
    };

    preferredPlan.value =
        input?.preferredPlan === 'lean'
        || input?.preferredPlan === 'base'
        || input?.preferredPlan === 'growth'
            ? input.preferredPlan
            : null;
};

hydrate(props.draft);

const scenarioLabel = (key: ScenarioKey): string => c.value[key];

const scenarioHelp = (key: ScenarioKey): string =>
    key === 'lean'
        ? c.value.leanHelp
        : key === 'base'
          ? c.value.baseHelp
          : c.value.growthHelp;

const scenarioRead = (key: ScenarioKey): GenericRow | null => {
    const rows = props.readModel?.scenarios;

    if (!Array.isArray(rows)) return null;

    return (rows.find((row) => row?.scenarioKey === key) ?? null) as
        | GenericRow
        | null;
};

const statusLabel = (status: unknown): string => {
    if (status === 'ready_for_comparison') return c.value.overallReady;
    if (status === 'needs_review') return c.value.needsReview;
    if (status === 'incomplete') return c.value.incomplete;

    return c.value.notStarted;
};

const reasonLabel = (reason: unknown): string => {
    const value = String(reason ?? '');

    if (value === 'pre_opening') return c.value.preOpening;
    if (value === 'initial_assets_inventory') return c.value.assets;
    if (value === 'working_capital') return c.value.working;
    if (value === 'contingency_reserve') return c.value.reserve;
    if (value === 'total_capital_requirement') return c.value.total;
    if (value === 'confirmed_funding') return c.value.funding;
    if (value === 'scenario_not_prepared') return c.value.scenarioMissing;

    return c.value.comparisonMissing;
};

const methodLabel = (method: unknown): string => {
    if (method === 'canonical_operating_profile') return c.value.methodCanonical;
    if (method === 'monthly_costs') return c.value.methodMonthlyCosts;
    if (method === 'monthly_burn') return c.value.methodMonthlyBurn;
    if (method === 'fixed_amount') return c.value.methodFixed;
    if (method === 'percentage') return c.value.methodPercentage;

    return c.value.methodMissing;
};

const showMoney = (value: unknown): string => {
    if (value === null || value === undefined || value === '') {
        return c.value.unavailable;
    }

    return `${String(value)} ${props.currency}`;
};

const showPercent = (value: unknown): string => {
    if (value === null || value === undefined || value === '') {
        return c.value.unavailable;
    }

    return `${String(value)}%`;
};

const showMonths = (row: GenericRow | null): string => {
    if (row?.workingCapitalMonthsApplicable !== true) return c.value.na;

    const value = row?.workingCapitalMonths;

    return value === null || value === undefined
        ? c.value.unavailable
        : String(value);
};

const showContingencyPercent = (row: GenericRow | null): string => {
    if (row?.contingencyPercentageApplicable !== true) return c.value.na;

    return showPercent(row?.contingencyPercentage);
};

const prepared = (key: ScenarioKey): boolean =>
    scenarios.value[key] !== null;

const working = (key: ScenarioKey): GenericRow | null => {
    const value = scenarios.value[key]?.workingCapital;

    return value && typeof value === 'object'
        ? (value as GenericRow)
        : null;
};

const contingency = (key: ScenarioKey): GenericRow | null => {
    const value = scenarios.value[key]?.contingency;

    return value && typeof value === 'object'
        ? (value as GenericRow)
        : null;
};

const inputValue = (
    value: unknown,
): string =>
    value === null || value === undefined ? '' : String(value);

const eventValue = (event: Event): string =>
    (event.target as HTMLInputElement).value;

const setWorkingValue = (
    key: ScenarioKey,
    field: 'months' | 'monthlyBurn' | 'amount',
    raw: string,
): void => {
    const current = working(key);

    if (current === null) return;

    if (field === 'months') {
        current[field] = raw === '' ? null : Number(raw);

        return;
    }

    current[field] = raw === '' ? null : raw;
};

const setContingencyValue = (
    key: ScenarioKey,
    field: 'percentage' | 'amount',
    raw: string,
): void => {
    const current = contingency(key);

    if (current === null) return;

    current[field] = raw === '' ? null : raw;
};

const setFunding = (
    key: ScenarioKey,
    raw: string,
): void => {
    const scenario = scenarios.value[key];

    if (scenario === null) return;

    scenario.confirmedFunding = raw === '' ? null : raw;
};

const markNotPrepared = (key: ScenarioKey): void => {
    scenarios.value[key] = null;

    if (preferredPlan.value === key) {
        preferredPlan.value = null;
    }
};

const restoreCanonical = (key: ScenarioKey): void => {
    if (props.canonicalInput === null) return;

    scenarios.value[key] = clone(props.canonicalInput);
};

const formationComparisonDraft = (
    page: GenericRow,
): GenericRow | null => {
    const formation = page.props?.formation as GenericRow | undefined;

    return (formation?.capital?.comparison_draft ?? null) as
        | GenericRow
        | null;
};

const prepareOrRefresh = (): void => {
    if (!props.canManage || busy.value) return;

    busy.value = true;
    errors.value = [];
    success.value = '';

    router.post(
        '/formation/capital/comparison-draft/refresh',
        {
            expected_revision: expectedRevision.value,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                hydrate(formationComparisonDraft(page as GenericRow));
                success.value =
                    expectedRevision.value > 1
                        ? c.value.prepared
                        : c.value.prepared;
            },
            onError: (serverErrors) => {
                const message = serverErrors.capital_comparison;

                if (typeof message === 'string') {
                    errors.value = [
                        message.includes('changed after you opened')
                            ? c.value.staleError
                            : c.value.prepareError,
                    ];

                    return;
                }

                errors.value = humanErrorMessages(serverErrors);
            },
            onFinish: () => {
                busy.value = false;
            },
        },
    );
};

const moneyPattern = /^\d{1,12}(?:\.\d{1,2})?$/;

const validateScenario = (
    key: ScenarioKey,
): string[] => {
    const scenario = scenarios.value[key];

    if (scenario === null) return [];

    const messages: string[] = [];
    const workingInput = working(key);
    const contingencyInput = contingency(key);

    if (workingInput?.method === 'fixed_amount') {
        const amount = inputValue(workingInput.amount);

        if (amount !== '' && !moneyPattern.test(amount)) {
            messages.push(c.value.invalidError);
        }
    }

    if (workingInput?.method === 'monthly_burn') {
        const burn = inputValue(workingInput.monthlyBurn);

        if (burn !== '' && !moneyPattern.test(burn)) {
            messages.push(c.value.invalidError);
        }
    }

    if (
        ['monthly_burn', 'monthly_costs', 'canonical_operating_profile'].includes(
            String(workingInput?.method ?? ''),
        )
    ) {
        const months = workingInput?.months;

        if (
            months !== null
            && months !== undefined
            && (
                !Number.isInteger(Number(months))
                || Number(months) < 0
                || Number(months) > 24
            )
        ) {
            messages.push(c.value.invalidError);
        }
    }

    if (contingencyInput?.method === 'percentage') {
        const percentage = inputValue(contingencyInput.percentage);
        const value = Number(percentage);

        if (
            percentage !== ''
            && (
                !/^\d{1,3}(?:\.\d{1,2})?$/.test(percentage)
                || !Number.isFinite(value)
                || value < 0
                || value > 100
            )
        ) {
            messages.push(c.value.invalidError);
        }
    }

    if (contingencyInput?.method === 'fixed_amount') {
        const amount = inputValue(contingencyInput.amount);

        if (amount !== '' && !moneyPattern.test(amount)) {
            messages.push(c.value.invalidError);
        }
    }

    const funding = inputValue(scenario.confirmedFunding);

    if (funding !== '' && !moneyPattern.test(funding)) {
        messages.push(c.value.invalidError);
    }

    return messages;
};

const saveComparison = (): void => {
    if (!props.canManage || busy.value || expectedRevision.value < 1) {
        return;
    }

    errors.value = [
        ...new Set(
            scenarioKeys.flatMap((key) => validateScenario(key)),
        ),
    ];
    success.value = '';

    if (errors.value.length > 0) return;

    busy.value = true;

    router.put(
        '/formation/capital/comparison-draft',
        {
            expected_revision: expectedRevision.value,
            input: {
                scenarios: scenarios.value,
                preferredPlan: preferredPlan.value,
            },
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                hydrate(formationComparisonDraft(page as GenericRow));
                errors.value = [];
                success.value = c.value.saved;
            },
            onError: (serverErrors) => {
                const message = serverErrors.capital_comparison;

                if (typeof message === 'string') {
                    errors.value = [
                        message.includes('changed after you opened')
                            ? c.value.staleError
                            : c.value.invalidError,
                    ];

                    return;
                }

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
            hydrate(formationComparisonDraft(page as GenericRow));
            errors.value = [];
            success.value = '';
        },
    });
};

const comparisonReady = computed(
    () => props.readModel?.ready === true,
);

const hasShortfall = computed(() => {
    const rows = props.readModel?.scenarios;

    if (!Array.isArray(rows)) return false;

    return rows.some(
        (row) =>
            row?.fundingGap !== null
            && row?.fundingGap !== undefined
            && String(row.fundingGap) !== '0.00',
    );
});
</script>

<template>
    <section
        data-testid="capital-plan-comparison"
        class="mt-6 min-w-0 rounded-[24px] border border-[#c9ddcf] bg-[linear-gradient(145deg,#f8fbf9_0%,#ffffff_52%,#fbf7eb_100%)] p-4 sm:p-6"
    >
        <header class="min-w-0">
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]">
                {{ c.eyebrow }}
            </p>
            <h3 class="mt-2 break-words text-lg font-black tracking-[-0.02em] sm:text-xl">
                {{ c.title }}
            </h3>
            <p class="pbr-safe-copy mt-2 max-w-4xl break-words text-sm leading-6 text-[var(--pbr-muted)]">
                {{ c.subtitle }}
            </p>
            <p class="pbr-safe-copy mt-3 rounded-2xl border border-[#e7d8aa] bg-[#fffaf0] px-4 py-3 text-xs leading-5 text-[#665527]">
                {{ c.boundary }}
            </p>
            <p class="pbr-safe-copy mt-3 text-xs leading-5 text-[var(--pbr-muted)]">
                {{ c.notBest }}
            </p>
        </header>

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

        <div
            v-if="Number(draft?.revision ?? 0) === 0"
            class="mt-5 rounded-2xl border border-[var(--pbr-line)] bg-white p-4"
        >
            <p class="pbr-safe-copy text-sm leading-6 text-[var(--pbr-muted)]">
                {{ c.needCapital }}
            </p>
            <button
                v-if="canManage"
                data-testid="comparison-prepare"
                type="button"
                class="mt-4 min-h-11 rounded-xl bg-[var(--pbr-green-dark)] px-5 text-sm font-black text-white disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="busy || readModel?.canInitialize !== true"
                @click="prepareOrRefresh"
            >
                {{ busy ? c.saving : c.prepare }}
            </button>
        </div>

        <template v-else>
            <div class="mt-5 flex flex-wrap items-center gap-2 text-xs text-[var(--pbr-muted)]">
                <span class="rounded-full border border-[var(--pbr-line)] bg-white px-3 py-1.5 font-bold">
                    {{ c.revision }}: {{ draft?.revision }}
                </span>
                <span class="rounded-full border border-[var(--pbr-line)] bg-white px-3 py-1.5 font-bold">
                    {{ c.preparedAgainst }}: {{ readModel?.preparedAgainstCapitalRevision ?? '—' }}
                </span>
                <span class="rounded-full border border-[var(--pbr-line)] bg-white px-3 py-1.5 font-bold">
                    {{ c.currentRevision }}: {{ readModel?.capitalPlanningRevision ?? '—' }}
                </span>
                <span
                    class="rounded-full border px-3 py-1.5 font-black"
                    :class="readModel?.status === 'ready_for_comparison'
                        ? 'border-[#bcdcc6] bg-[#eef8f1] text-[#155f39]'
                        : readModel?.status === 'needs_review'
                          ? 'border-[#e7d8aa] bg-[#fffaf0] text-[#665527]'
                          : 'border-[var(--pbr-line)] bg-white text-[var(--pbr-muted)]'"
                >
                    {{ statusLabel(readModel?.status) }}
                </span>
            </div>

            <div
                v-if="readModel?.needsReview === true"
                class="mt-4 rounded-2xl border border-[#e7d8aa] bg-[#fffaf0] p-4"
            >
                <p class="pbr-safe-copy text-sm font-semibold leading-6 text-[#665527]">
                    {{ c.refreshWarning }}
                </p>
                <button
                    v-if="canManage"
                    data-testid="comparison-refresh"
                    type="button"
                    class="mt-3 min-h-10 rounded-xl border border-[#b99f5e] bg-white px-4 text-sm font-black text-[#665527] disabled:opacity-50"
                    :disabled="busy"
                    @click="prepareOrRefresh"
                >
                    {{ c.refresh }}
                </button>
            </div>

            <div class="mt-5 grid min-w-0 gap-4 xl:grid-cols-3">
                <article
                    v-for="key in scenarioKeys"
                    :key="key"
                    :data-testid="'comparison-card-' + key"
                    class="min-w-0 rounded-[20px] border border-[var(--pbr-line)] bg-white p-4 shadow-[0_10px_24px_rgb(16_35_26_/_4%)]"
                >
                    <div class="flex min-w-0 flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h4 class="break-words text-base font-black">
                                {{ scenarioLabel(key) }}
                            </h4>
                            <p class="pbr-safe-copy mt-1 break-words text-xs leading-5 text-[var(--pbr-muted)]">
                                {{ scenarioHelp(key) }}
                            </p>
                        </div>
                        <span
                            class="rounded-full border px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.08em]"
                            :class="scenarioRead(key)?.readiness === 'ready_for_comparison'
                                ? 'border-[#bcdcc6] bg-[#eef8f1] text-[#155f39]'
                                : scenarioRead(key)?.readiness === 'needs_review'
                                  ? 'border-[#e7d8aa] bg-[#fffaf0] text-[#665527]'
                                  : 'border-slate-200 bg-slate-50 text-slate-500'"
                        >
                            {{ statusLabel(scenarioRead(key)?.readiness) }}
                        </span>
                    </div>

                    <template v-if="prepared(key)">
                        <p class="pbr-safe-copy mt-3 rounded-xl bg-[#f7faf8] px-3 py-2 text-xs leading-5 text-[var(--pbr-muted)]">
                            {{ c.inherited }}
                        </p>

                        <div class="mt-4 space-y-3">
                            <div class="rounded-2xl border border-[var(--pbr-line)] p-3">
                                <p class="text-xs font-black text-[var(--pbr-muted)]">
                                    {{ c.workingMethod }}
                                </p>
                                <p class="mt-1 break-words text-sm font-bold">
                                    {{ methodLabel(working(key)?.method) }}
                                </p>

                                <label
                                    v-if="scenarioRead(key)?.workingCapitalMonthsApplicable === true"
                                    class="mt-3 block text-xs font-bold"
                                >
                                    <span class="block">{{ c.workingMonths }}</span>
                                    <input
                                        :value="inputValue(working(key)?.months)"
                                        :disabled="!canManage"
                                        type="number"
                                        min="0"
                                        max="24"
                                        step="1"
                                        inputmode="numeric"
                                        class="mt-1 min-h-10 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm disabled:bg-slate-50"
                                        @input="setWorkingValue(key, 'months', eventValue($event))"
                                    >
                                </label>

                                <label
                                    v-if="working(key)?.method === 'monthly_burn'"
                                    class="mt-3 block text-xs font-bold"
                                >
                                    <span class="block">{{ c.monthlyBurn }} ({{ currency }})</span>
                                    <input
                                        :value="inputValue(working(key)?.monthlyBurn)"
                                        :disabled="!canManage"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        inputmode="decimal"
                                        class="mt-1 min-h-10 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm disabled:bg-slate-50"
                                        @input="setWorkingValue(key, 'monthlyBurn', eventValue($event))"
                                    >
                                </label>

                                <label
                                    v-if="working(key)?.method === 'fixed_amount'"
                                    class="mt-3 block text-xs font-bold"
                                >
                                    <span class="block">{{ c.fixedWorkingCapital }} ({{ currency }})</span>
                                    <input
                                        :value="inputValue(working(key)?.amount)"
                                        :disabled="!canManage"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        inputmode="decimal"
                                        class="mt-1 min-h-10 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm disabled:bg-slate-50"
                                        @input="setWorkingValue(key, 'amount', eventValue($event))"
                                    >
                                </label>
                            </div>

                            <div class="rounded-2xl border border-[var(--pbr-line)] p-3">
                                <p class="text-xs font-black text-[var(--pbr-muted)]">
                                    {{ c.contingency }}
                                </p>
                                <p class="mt-1 break-words text-sm font-bold">
                                    {{ methodLabel(contingency(key)?.method === 'percentage' ? 'percentage' : contingency(key)?.method) }}
                                </p>

                                <label
                                    v-if="contingency(key)?.method === 'percentage'"
                                    class="mt-3 block text-xs font-bold"
                                >
                                    <span class="block">{{ c.contingencyPercent }}</span>
                                    <input
                                        :value="inputValue(contingency(key)?.percentage)"
                                        :disabled="!canManage"
                                        type="number"
                                        min="0"
                                        max="100"
                                        step="0.01"
                                        inputmode="decimal"
                                        class="mt-1 min-h-10 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm disabled:bg-slate-50"
                                        @input="setContingencyValue(key, 'percentage', eventValue($event))"
                                    >
                                </label>

                                <label
                                    v-if="contingency(key)?.method === 'fixed_amount'"
                                    class="mt-3 block text-xs font-bold"
                                >
                                    <span class="block">{{ c.contingencyAmount }} ({{ currency }})</span>
                                    <input
                                        :value="inputValue(contingency(key)?.amount)"
                                        :disabled="!canManage"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        inputmode="decimal"
                                        class="mt-1 min-h-10 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm disabled:bg-slate-50"
                                        @input="setContingencyValue(key, 'amount', eventValue($event))"
                                    >
                                </label>
                            </div>

                            <label class="block text-xs font-bold">
                                <span class="block">{{ c.confirmedFunding }} ({{ currency }})</span>
                                <input
                                    :value="inputValue(scenarios[key]?.confirmedFunding)"
                                    :disabled="!canManage"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    inputmode="decimal"
                                    class="mt-1 min-h-10 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm disabled:bg-slate-50"
                                    @input="setFunding(key, eventValue($event))"
                                >
                            </label>
                        </div>

                        <dl class="mt-4 grid min-w-0 gap-2 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
                            <div class="min-w-0 rounded-xl bg-[#f7faf8] p-3">
                                <dt class="break-words text-[11px] font-semibold text-[var(--pbr-muted)]">{{ c.totalRequired }}</dt>
                                <dd class="mt-1 break-words text-sm font-black">{{ showMoney(scenarioRead(key)?.totalCapitalRequirement) }}</dd>
                            </div>
                            <div class="min-w-0 rounded-xl bg-[#f7faf8] p-3">
                                <dt class="break-words text-[11px] font-semibold text-[var(--pbr-muted)]">{{ c.workingCapital }}</dt>
                                <dd class="mt-1 break-words text-sm font-black">{{ showMoney(scenarioRead(key)?.workingCapital) }}</dd>
                            </div>
                            <div class="min-w-0 rounded-xl bg-[#f7faf8] p-3">
                                <dt class="break-words text-[11px] font-semibold text-[var(--pbr-muted)]">{{ c.workingMonths }}</dt>
                                <dd class="mt-1 break-words text-sm font-black">{{ showMonths(scenarioRead(key)) }}</dd>
                            </div>
                            <div class="min-w-0 rounded-xl bg-[#f7faf8] p-3">
                                <dt class="break-words text-[11px] font-semibold text-[var(--pbr-muted)]">{{ c.contingencyAmount }}</dt>
                                <dd class="mt-1 break-words text-sm font-black">{{ showMoney(scenarioRead(key)?.contingencyAmount) }}</dd>
                            </div>
                            <div class="min-w-0 rounded-xl bg-[#f7faf8] p-3">
                                <dt class="break-words text-[11px] font-semibold text-[var(--pbr-muted)]">{{ c.contingencyPercent }}</dt>
                                <dd class="mt-1 break-words text-sm font-black">{{ showContingencyPercent(scenarioRead(key)) }}</dd>
                            </div>
                            <div class="min-w-0 rounded-xl bg-[#f7faf8] p-3">
                                <dt class="break-words text-[11px] font-semibold text-[var(--pbr-muted)]">{{ c.confirmedFunding }}</dt>
                                <dd class="mt-1 break-words text-sm font-black">{{ showMoney(scenarioRead(key)?.confirmedFunding) }}</dd>
                            </div>
                            <div class="min-w-0 rounded-xl border border-[#d4e2d7] bg-[#eef7f0] p-3">
                                <dt class="break-words text-[11px] font-semibold text-[var(--pbr-muted)]">{{ c.fundingGap }}</dt>
                                <dd class="mt-1 break-words text-sm font-black">{{ showMoney(scenarioRead(key)?.fundingGap) }}</dd>
                            </div>
                            <div class="min-w-0 rounded-xl bg-[#f7faf8] p-3">
                                <dt class="break-words text-[11px] font-semibold text-[var(--pbr-muted)]">{{ c.fundingSurplus }}</dt>
                                <dd class="mt-1 break-words text-sm font-black">{{ showMoney(scenarioRead(key)?.fundingSurplus) }}</dd>
                            </div>
                            <div class="min-w-0 rounded-xl bg-[#f7faf8] p-3">
                                <dt class="break-words text-[11px] font-semibold text-[var(--pbr-muted)]">{{ c.fundedPercent }}</dt>
                                <dd class="mt-1 break-words text-sm font-black">{{ showPercent(scenarioRead(key)?.fundedPercentage) }}</dd>
                            </div>
                        </dl>

                        <div
                            v-if="Array.isArray(scenarioRead(key)?.missingRequirements) && scenarioRead(key)?.missingRequirements.length > 0"
                            class="mt-3 rounded-xl border border-[#eadcb1] bg-[#fffaf0] p-3"
                        >
                            <p class="text-xs font-black text-[#665527]">{{ c.reasons }}</p>
                            <ul class="mt-1 space-y-1 text-xs leading-5 text-[#665527]">
                                <li
                                    v-for="reason in scenarioRead(key)?.missingRequirements"
                                    :key="String(reason)"
                                >
                                    • {{ reasonLabel(reason) }}
                                </li>
                            </ul>
                        </div>

                        <div v-if="canManage" class="mt-4 flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="min-h-9 rounded-xl border border-slate-300 bg-white px-3 text-xs font-bold"
                                @click="markNotPrepared(key)"
                            >
                                {{ c.markNotPrepared }}
                            </button>
                            <button
                                type="button"
                                class="min-h-9 rounded-xl border border-slate-300 bg-white px-3 text-xs font-bold"
                                :disabled="canonicalInput === null"
                                @click="restoreCanonical(key)"
                            >
                                {{ c.restoreCanonical }}
                            </button>
                        </div>
                    </template>

                    <div
                        v-else
                        class="mt-4 rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-4"
                    >
                        <p class="text-sm font-black text-slate-600">{{ c.notPrepared }}</p>
                        <button
                            v-if="canManage"
                            type="button"
                            class="mt-3 min-h-9 rounded-xl border border-slate-300 bg-white px-3 text-xs font-bold"
                            :disabled="canonicalInput === null"
                            @click="restoreCanonical(key)"
                        >
                            {{ c.restoreCanonical }}
                        </button>
                    </div>
                </article>
            </div>

            <div
                v-if="hasShortfall"
                class="pbr-safe-copy mt-4 rounded-2xl border border-[#e7d8aa] bg-[#fffaf0] p-4 text-sm leading-6 text-[#665527]"
            >
                {{ c.shortfallReminder }}
            </div>

            <section class="mt-5 rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                <h4 class="text-sm font-black">{{ c.preferred }}</h4>
                <p class="pbr-safe-copy mt-1 text-xs leading-5 text-[var(--pbr-muted)]">
                    {{ c.preferredHelp }}
                </p>

                <div class="mt-3 grid gap-2 sm:grid-cols-3">
                    <button
                        v-for="key in scenarioKeys"
                        :key="'preferred-' + key"
                        :data-testid="'comparison-preferred-' + key"
                        type="button"
                        class="min-h-11 rounded-xl border px-3 text-sm font-black disabled:cursor-not-allowed disabled:opacity-50"
                        :class="preferredPlan === key
                            ? 'border-[var(--pbr-green)] bg-[#eef8f1] text-[var(--pbr-green-dark)]'
                            : 'border-slate-300 bg-white'"
                        :disabled="!canManage || !comparisonReady"
                        @click="preferredPlan = key"
                    >
                        {{ scenarioLabel(key) }}
                    </button>
                </div>

                <p
                    v-if="preferredPlan"
                    class="mt-3 text-sm font-semibold text-[var(--pbr-green-dark)]"
                >
                    {{ c.preferredSelected }}: {{ scenarioLabel(preferredPlan) }}
                </p>
            </section>

            <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-[var(--pbr-line)] pt-5">
                <button
                    v-if="errors.length > 0"
                    type="button"
                    class="min-h-10 rounded-xl border border-slate-300 bg-white px-4 text-sm font-bold"
                    @click="reloadLatest"
                >
                    {{ c.reload }}
                </button>
                <span v-else />

                <button
                    v-if="canManage"
                    data-testid="comparison-save"
                    type="button"
                    class="min-h-11 rounded-xl bg-[var(--pbr-green-dark)] px-5 text-sm font-black text-white disabled:cursor-wait disabled:opacity-60"
                    :disabled="busy"
                    @click="saveComparison"
                >
                    {{ busy ? c.saving : c.save }}
                </button>
            </div>
        </template>
    </section>
</template>
