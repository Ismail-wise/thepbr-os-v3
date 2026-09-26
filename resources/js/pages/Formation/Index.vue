<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';

type LanguageMode = 'en' | 'my' | 'mixed';
type Journey = 'new' | 'existing';
type BmcKey =
    | 'customer_segments'
    | 'value_propositions'
    | 'channels'
    | 'customer_relationships'
    | 'revenue_streams'
    | 'key_resources'
    | 'key_activities'
    | 'key_partnerships'
    | 'cost_structure';

type GenericRow = Record<string, any>;

type FormationWorkspace = {
    business: {
        id: string;
        name: string;
        origin_type: string;
        business_stage: string;
        setup_phase: string | null;
        base_currency: string;
    };
    journey: Journey;
    bmc: GenericRow | null;
    permissions: {
        can_manage_formation: boolean;
        can_manage_bmc: boolean;
        can_manage_capital: boolean;
    };
    new_business: null | {
        idea: GenericRow | null;
        assumptions: GenericRow[];
        validations: GenericRow[];
        feasibility: GenericRow[];
        partnership_fit: GenericRow | null;
        directions: GenericRow[];
    };
    existing_business: null | {
        profile: GenericRow | null;
        financial_snapshots: GenericRow[];
        assets: GenericRow[];
        liabilities: GenericRow[];
        owner_positions: GenericRow[];
        obligations: GenericRow[];
        risks: GenericRow[];
        constraints: GenericRow[];
        gap_assessment: GenericRow | null;
        conversion_plan: GenericRow | null;
        valuations: GenericRow[];
    };
    capital: {
        scenarios: GenericRow[];
        promotions: GenericRow[];
        current_effective: GenericRow | null;
        formula: string;
        scenario_notice: string;
    };
};

const props = defineProps<{
    formation: FormationWorkspace;
}>();

const page = usePage();
const mode = computed(
    () =>
        ((page.props.uiLanguageMode as LanguageMode | undefined) ??
            'en') as LanguageMode,
);

const copy = {
    en: {
        title: 'Formation & Capital',
        description:
            'Build the business baseline, validate assumptions and move Capital from editable scenarios into the governed official-record flow.',
        notice:
            'Planning remains editable. A scenario never changes live truth. Official Capital uses Proposal → frozen version → Governance → Effective Record.',
        newJourney: 'New Business Formation',
        existingJourney: 'Existing Business Baseline',
        overview: 'Overview',
        bmc: 'Business Model Canvas',
        validation: 'Validation',
        feasibility: 'Feasibility',
        fit: 'Partnership Fit',
        baseline: 'Baseline',
        capital: 'Capital',
        save: 'Save',
        add: 'Add',
        close: 'Close',
        idea: 'Business Idea',
        summary: 'Summary',
        problem: 'Problem',
        targetCustomer: 'Target customer',
        proposedSolution: 'Proposed solution',
        assumptions: 'Market Assumptions',
        category: 'Category',
        statement: 'Statement',
        status: 'Status',
        validationActivities: 'Validation Activities',
        method: 'Method',
        result: 'Result summary',
        evidenceId: 'Evidence ID from Document Vault',
        linkEvidence: 'Link Evidence',
        monthlyRevenue: 'Projected monthly revenue',
        monthlyCost: 'Projected monthly cost',
        scenarioName: 'Scenario name',
        goals: 'Goals alignment',
        roles: 'Role expectations',
        decisions: 'Decision process',
        riskTolerance: 'Risk tolerance',
        openQuestions: 'Unresolved questions',
        direction: 'Human direction decision',
        rationale: 'Rationale',
        profile: 'Business Profile',
        operatingSince: 'Operating since',
        financialSnapshot: 'Financial Snapshot',
        assets: 'Assets',
        liabilities: 'Liabilities',
        ownerPositions: 'Existing Owner Position',
        obligations: 'Current Obligations',
        risks: 'Current Risks / Controls',
        constraints: 'Agreements / Constraints',
        gapAssessment: 'PBR Gap Assessment',
        conversionPlan: 'Partnership Conversion Plan',
        valuations: 'Valuation',
        valuationNotice:
            'Reviewed valuation is a baseline review state, not governance approval or ownership truth.',
        name: 'Name',
        amount: 'Amount',
        notes: 'Notes',
        asOf: 'As of',
        revenue: 'Revenue',
        expenses: 'Expenses',
        cash: 'Cash',
        receivables: 'Receivables',
        payables: 'Payables',
        percent: 'Baseline %',
        controlStatus: 'Control status',
        priorities: 'Priorities',
        plan: 'Plan',
        reviewState: 'Review state',
        capitalFormula: 'PBR Capital Formula',
        preOpening: 'Pre-opening Costs',
        initialAssets: 'Initial Assets / Inventory',
        workingCapital: 'Working Capital',
        contingency: 'Contingency Reserve',
        availableFunding: 'Available Funding',
        total: 'Total Capital Requirement',
        gap: 'Funding Gap',
        scenarioPlanningOnly: 'Planning scenario only',
        promote: 'Promote to frozen Proposal',
        startContentReview: 'Start content review',
        approveContent: 'Approve content for Governance',
        governance: 'Continue in Governance',
        export: 'Export CSV',
        official: 'Current Effective Capital Plan',
        none: 'None',
        version: 'Revision',
        history: 'Promotion History',
        noRows: 'No records yet.',
    },
    my: {
        title: 'လုပ်ငန်းဖွဲ့စည်းမှုနှင့် အရင်းအနှီး',
        description:
            'လုပ်ငန်းအခြေခံအချက်အလက်၊ စမ်းသပ်အတည်ပြုမှုနှင့် Capital scenario များကို governed official record flow သို့ တိတိကျကျ ပြောင်းရွှေ့ပါ။',
        notice:
            'Planning data ကို ပြင်ဆင်နိုင်ပါတယ်။ Scenario က live truth ကို မပြောင်းပါ။ Official Capital က Proposal → frozen version → Governance → Effective Record flow ကိုသုံးပါတယ်။',
        newJourney: 'လုပ်ငန်းအသစ် ဖွဲ့စည်းမှု',
        existingJourney: 'လက်ရှိလုပ်ငန်း အခြေခံမှတ်တမ်း',
        overview: 'အနှစ်ချုပ်',
        bmc: 'Business Model Canvas',
        validation: 'စမ်းသပ်အတည်ပြုမှု',
        feasibility: 'ဖြစ်နိုင်ခြေ',
        fit: 'Partnership Fit',
        baseline: 'အခြေခံမှတ်တမ်း',
        capital: 'အရင်းအနှီး',
        save: 'သိမ်းမည်',
        add: 'ထည့်မည်',
        close: 'ပိတ်မည်',
        idea: 'လုပ်ငန်းအကြံ',
        summary: 'အနှစ်ချုပ်',
        problem: 'ပြဿနာ',
        targetCustomer: 'ပစ်မှတ်ဖောက်သည်',
        proposedSolution: 'အဆိုပြုဖြေရှင်းချက်',
        assumptions: 'Market Assumptions',
        category: 'အမျိုးအစား',
        statement: 'ယူဆချက်',
        status: 'အခြေအနေ',
        validationActivities: 'Validation Activities',
        method: 'စမ်းသပ်နည်း',
        result: 'ရလဒ်အနှစ်ချုပ်',
        evidenceId: 'Document Vault မှ Evidence ID',
        linkEvidence: 'Evidence ချိတ်မည်',
        monthlyRevenue: 'ခန့်မှန်း လစဉ်ဝင်ငွေ',
        monthlyCost: 'ခန့်မှန်း လစဉ်ကုန်ကျစရိတ်',
        scenarioName: 'Scenario အမည်',
        goals: 'ရည်မှန်းချက် ကိုက်ညီမှု',
        roles: 'Role မျှော်မှန်းချက်',
        decisions: 'ဆုံးဖြတ်ပုံ',
        riskTolerance: 'Risk ခံနိုင်ရည်',
        openQuestions: 'မရှင်းသေးသောမေးခွန်းများ',
        direction: 'လူကဆုံးဖြတ်သော လမ်းကြောင်း',
        rationale: 'အကြောင်းပြချက်',
        profile: 'Business Profile',
        operatingSince: 'စတင်လည်ပတ်သည့်နေ့',
        financialSnapshot: 'Financial Snapshot',
        assets: 'Assets',
        liabilities: 'Liabilities',
        ownerPositions: 'Existing Owner Position',
        obligations: 'Current Obligations',
        risks: 'Current Risks / Controls',
        constraints: 'Agreements / Constraints',
        gapAssessment: 'PBR Gap Assessment',
        conversionPlan: 'Partnership Conversion Plan',
        valuations: 'Valuation',
        valuationNotice:
            'Reviewed valuation သည် baseline review state သာဖြစ်ပြီး Governance approval သို့မဟုတ် Ownership truth မဟုတ်ပါ။',
        name: 'အမည်',
        amount: 'ပမာဏ',
        notes: 'မှတ်ချက်',
        asOf: 'ရက်စွဲ',
        revenue: 'ဝင်ငွေ',
        expenses: 'ကုန်ကျစရိတ်',
        cash: 'Cash',
        receivables: 'Receivables',
        payables: 'Payables',
        percent: 'Baseline %',
        controlStatus: 'Control status',
        priorities: 'ဦးစားပေးများ',
        plan: 'အစီအစဉ်',
        reviewState: 'Review state',
        capitalFormula: 'PBR Capital Formula',
        preOpening: 'Pre-opening Costs',
        initialAssets: 'Initial Assets / Inventory',
        workingCapital: 'Working Capital',
        contingency: 'Contingency Reserve',
        availableFunding: 'Available Funding',
        total: 'Total Capital Requirement',
        gap: 'Funding Gap',
        scenarioPlanningOnly: 'Planning scenario သာဖြစ်သည်',
        promote: 'Frozen Proposal အဖြစ်တင်မည်',
        startContentReview: 'Content review စမည်',
        approveContent: 'Governance အတွက် content approve မည်',
        governance: 'Governance သို့ဆက်မည်',
        export: 'CSV ထုတ်မည်',
        official: 'Current Effective Capital Plan',
        none: 'မရှိ',
        version: 'Revision',
        history: 'Promotion History',
        noRows: 'မှတ်တမ်း မရှိသေးပါ။',
    },
    mixed: {
        title: 'Formation & Capital · လုပ်ငန်းဖွဲ့စည်းမှုနှင့် အရင်းအနှီး',
        description:
            'Business baseline၊ validation နဲ့ Capital scenario တွေကို governed official-record flow သို့ ပြောင်းရွှေ့ပါ။',
        notice:
            'Planning remains editable. Scenario က live truth ကို မပြောင်းပါ။ Official Capital = Proposal → frozen version → Governance → Effective Record.',
        newJourney: 'New Business Formation',
        existingJourney: 'Existing Business Baseline',
        overview: 'Overview',
        bmc: 'Business Model Canvas',
        validation: 'Validation',
        feasibility: 'Feasibility',
        fit: 'Partnership Fit',
        baseline: 'Baseline',
        capital: 'Capital',
        save: 'Save',
        add: 'Add',
        close: 'Close',
        idea: 'Business Idea',
        summary: 'Summary',
        problem: 'Problem',
        targetCustomer: 'Target customer',
        proposedSolution: 'Proposed solution',
        assumptions: 'Market Assumptions',
        category: 'Category',
        statement: 'Statement',
        status: 'Status',
        validationActivities: 'Validation Activities',
        method: 'Method',
        result: 'Result summary',
        evidenceId: 'Document Vault Evidence ID',
        linkEvidence: 'Link Evidence',
        monthlyRevenue: 'Projected monthly revenue',
        monthlyCost: 'Projected monthly cost',
        scenarioName: 'Scenario name',
        goals: 'Goals alignment',
        roles: 'Role expectations',
        decisions: 'Decision process',
        riskTolerance: 'Risk tolerance',
        openQuestions: 'Unresolved questions',
        direction: 'Human direction decision',
        rationale: 'Rationale',
        profile: 'Business Profile',
        operatingSince: 'Operating since',
        financialSnapshot: 'Financial Snapshot',
        assets: 'Assets',
        liabilities: 'Liabilities',
        ownerPositions: 'Existing Owner Position',
        obligations: 'Current Obligations',
        risks: 'Current Risks / Controls',
        constraints: 'Agreements / Constraints',
        gapAssessment: 'PBR Gap Assessment',
        conversionPlan: 'Partnership Conversion Plan',
        valuations: 'Valuation',
        valuationNotice:
            'Reviewed valuation = baseline review state only; Governance approval / Ownership truth မဟုတ်ပါ။',
        name: 'Name',
        amount: 'Amount',
        notes: 'Notes',
        asOf: 'As of',
        revenue: 'Revenue',
        expenses: 'Expenses',
        cash: 'Cash',
        receivables: 'Receivables',
        payables: 'Payables',
        percent: 'Baseline %',
        controlStatus: 'Control status',
        priorities: 'Priorities',
        plan: 'Plan',
        reviewState: 'Review state',
        capitalFormula: 'PBR Capital Formula',
        preOpening: 'Pre-opening Costs',
        initialAssets: 'Initial Assets / Inventory',
        workingCapital: 'Working Capital',
        contingency: 'Contingency Reserve',
        availableFunding: 'Available Funding',
        total: 'Total Capital Requirement',
        gap: 'Funding Gap',
        scenarioPlanningOnly: 'Planning scenario only',
        promote: 'Promote to frozen Proposal',
        startContentReview: 'Start content review',
        approveContent: 'Approve content for Governance',
        governance: 'Continue in Governance',
        export: 'Export CSV',
        official: 'Current Effective Capital Plan',
        none: 'None',
        version: 'Revision',
        history: 'Promotion History',
        noRows: 'No records yet.',
    },
} as const;

const c = computed(() => copy[mode.value]);
const active = ref<'overview' | 'bmc' | 'validation' | 'feasibility' | 'fit' | 'baseline' | 'capital'>(
    props.formation.journey === 'new' ? 'overview' : 'baseline',
);

const field = (row: GenericRow | null | undefined, key: string): string =>
    row?.[key] == null ? '' : String(row[key]);

const revision = (row: GenericRow | null | undefined): number =>
    row?.revision == null ? 0 : Number(row.revision);

const idea = reactive({
    summary: field(props.formation.new_business?.idea, 'summary'),
    problem: field(props.formation.new_business?.idea, 'problem'),
    target_customer: field(props.formation.new_business?.idea, 'target_customer'),
    proposed_solution: field(props.formation.new_business?.idea, 'proposed_solution'),
});

const bmcKeys: BmcKey[] = [
    'customer_segments',
    'value_propositions',
    'channels',
    'customer_relationships',
    'revenue_streams',
    'key_resources',
    'key_activities',
    'key_partnerships',
    'cost_structure',
];

const bmcLabels: Record<BmcKey, string> = {
    customer_segments: 'Customer Segments',
    value_propositions: 'Value Propositions',
    channels: 'Channels',
    customer_relationships: 'Customer Relationships',
    revenue_streams: 'Revenue Streams',
    key_resources: 'Key Resources',
    key_activities: 'Key Activities',
    key_partnerships: 'Key Partnerships',
    cost_structure: 'Cost Structure',
};

const bmcDraft = reactive(
    Object.fromEntries(
        bmcKeys.map((key) => [
            key,
            field(props.formation.bmc, key),
        ]),
    ) as Record<BmcKey, string>,
);

const selectedBmc = ref<BmcKey | null>(null);
const validationEvidenceIds = reactive<Record<string, string>>({});

const fit = reactive({
    goals_alignment: field(
        props.formation.new_business?.partnership_fit,
        'goals_alignment',
    ),
    role_expectations: field(
        props.formation.new_business?.partnership_fit,
        'role_expectations',
    ),
    decision_process: field(
        props.formation.new_business?.partnership_fit,
        'decision_process',
    ),
    risk_tolerance: field(
        props.formation.new_business?.partnership_fit,
        'risk_tolerance',
    ),
    unresolved_questions: field(
        props.formation.new_business?.partnership_fit,
        'unresolved_questions',
    ),
});

const assumption = reactive({
    category: 'market',
    statement: '',
    status: 'planned',
});

const validation = reactive({
    assumption_id: '',
    method: '',
    status: 'planned',
    result_summary: '',
    occurred_on: '',
});

const feasibility = reactive({
    name: 'Base feasibility',
    projected_monthly_revenue: '0.00',
    projected_monthly_cost: '0.00',
    notes: '',
});

const direction = reactive({
    direction: 'go',
    rationale: '',
});

const existingProfile = reactive({
    operating_since: field(
        props.formation.existing_business?.profile,
        'operating_since',
    ),
    summary: field(props.formation.existing_business?.profile, 'summary'),
    notes: field(props.formation.existing_business?.profile, 'notes'),
});

const financial = reactive({
    as_of_date: new Date().toISOString().slice(0, 10),
    revenue: '0.00',
    expenses: '0.00',
    cash: '0.00',
    receivables: '0.00',
    payables: '0.00',
    notes: '',
});

const asset = reactive({
    name: '',
    estimated_value: '0.00',
    notes: '',
});

const liability = reactive({
    name: '',
    outstanding_amount: '0.00',
    notes: '',
});

const owner = reactive({
    owner_name: '',
    baseline_percent: '',
    notes: '',
});

const obligation = reactive({
    title: '',
    details: '',
    due_date: '',
});

const risk = reactive({
    risk: '',
    control_status: '',
    notes: '',
});

const constraint = reactive({
    title: '',
    details: '',
});

const gapAssessment = reactive({
    gaps: field(props.formation.existing_business?.gap_assessment, 'gaps'),
    priorities: field(
        props.formation.existing_business?.gap_assessment,
        'priorities',
    ),
});

const conversion = reactive({
    plan: field(props.formation.existing_business?.conversion_plan, 'plan'),
});

const valuation = reactive({
    as_of_date: new Date().toISOString().slice(0, 10),
    amount: '0.00',
    method: '',
    review_state: 'draft',
    notes: '',
});

const scenarioKinds = ['lean', 'base', 'growth'] as const;
type ScenarioKind = (typeof scenarioKinds)[number];

const findScenario = (kind: ScenarioKind): GenericRow | undefined =>
    props.formation.capital.scenarios.find(
        (row) => row.scenario_kind === kind,
    );

const capitalForms = reactive(
    Object.fromEntries(
        scenarioKinds.map((kind) => {
            const row = findScenario(kind);
            return [
                kind,
                {
                    name:
                        field(row, 'name') ||
                        kind.charAt(0).toUpperCase() + kind.slice(1),
                    pre_opening_costs:
                        field(row, 'pre_opening_costs') || '0.00',
                    initial_assets_inventory:
                        field(row, 'initial_assets_inventory') || '0.00',
                    working_capital:
                        field(row, 'working_capital') || '0.00',
                    contingency_reserve:
                        field(row, 'contingency_reserve') || '0.00',
                    available_funding:
                        field(row, 'available_funding') || '0.00',
                    notes: field(row, 'notes'),
                },
            ];
        }),
    ) as Record<
        ScenarioKind,
        {
            name: string;
            pre_opening_costs: string;
            initial_assets_inventory: string;
            working_capital: string;
            contingency_reserve: string;
            available_funding: string;
            notes: string;
        }
    >,
);

const post = (url: string, data: Record<string, unknown>) =>
    router.post(url, data, { preserveScroll: true });

const put = (url: string, data: Record<string, unknown>) =>
    router.put(url, data, { preserveScroll: true });

const saveIdea = () =>
    put('/formation/new/idea', {
        expected_revision: revision(props.formation.new_business?.idea),
        ...idea,
    });

const saveBmc = () =>
    put('/formation/bmc', {
        expected_revision: revision(props.formation.bmc),
        ...bmcDraft,
    });

const linkValidationEvidence = (validationId: string) => {
    const evidenceId = (validationEvidenceIds[validationId] ?? '').trim();

    if (!evidenceId) {
        return;
    }

    post(`/formation/new/validations/${validationId}/evidence`, {
        evidence_id: evidenceId,
    });
};

const saveFit = () =>
    put('/formation/new/partnership-fit', {
        expected_revision: revision(
            props.formation.new_business?.partnership_fit,
        ),
        ...fit,
    });

const saveExistingProfile = () =>
    put('/formation/existing/profile', {
        expected_revision: revision(
            props.formation.existing_business?.profile,
        ),
        ...existingProfile,
    });

const saveGap = () =>
    put('/formation/existing/gap', {
        expected_revision: revision(
            props.formation.existing_business?.gap_assessment,
        ),
        ...gapAssessment,
    });

const saveConversion = () =>
    put('/formation/existing/conversion', {
        expected_revision: revision(
            props.formation.existing_business?.conversion_plan,
        ),
        ...conversion,
    });

const saveCapital = (kind: ScenarioKind) =>
    put(`/formation/capital/scenarios/${kind}`, {
        expected_revision: revision(findScenario(kind)),
        ...capitalForms[kind],
    });

const sectionButton = (key: typeof active.value) =>
    [
        'min-h-11 border-b-2 px-3 text-sm font-semibold',
        active.value === key
            ? 'border-slate-950 text-slate-950'
            : 'border-transparent text-slate-500',
    ];
</script>

<template>
    <AuthenticatedLayout>
        <main class="min-h-screen bg-white px-4 py-6 text-slate-950 sm:px-6 lg:px-8">
            <section class="mx-auto max-w-7xl">
                <header class="border-b border-slate-200 pb-5">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                                {{ formation.journey === 'new' ? c.newJourney : c.existingJourney }}
                            </p>
                            <h1 class="mt-2 text-2xl font-semibold tracking-tight">
                                {{ c.title }}
                            </h1>
                            <p class="mt-2 max-w-4xl text-sm leading-6 text-slate-600">
                                {{ c.description }}
                            </p>
                        </div>
                        <div class="text-right text-xs text-slate-500">
                            <p class="font-semibold text-slate-800">{{ formation.business.name }}</p>
                            <p>{{ formation.business.base_currency }} · {{ formation.business.business_stage }}</p>
                        </div>
                    </div>
                    <p class="mt-4 border-l-4 border-slate-800 bg-slate-50 px-4 py-3 text-sm leading-6">
                        {{ c.notice }}
                    </p>
                </header>

                <nav class="mt-4 flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="Formation sections">
                    <button v-if="formation.journey === 'new'" type="button" :class="sectionButton('overview')" @click="active = 'overview'">
                        {{ c.overview }}
                    </button>
                    <button type="button" :class="sectionButton('bmc')" @click="active = 'bmc'">
                        {{ c.bmc }}
                    </button>
                    <button v-if="formation.journey === 'new'" type="button" :class="sectionButton('validation')" @click="active = 'validation'">
                        {{ c.validation }}
                    </button>
                    <button v-if="formation.journey === 'new'" type="button" :class="sectionButton('feasibility')" @click="active = 'feasibility'">
                        {{ c.feasibility }}
                    </button>
                    <button v-if="formation.journey === 'new'" type="button" :class="sectionButton('fit')" @click="active = 'fit'">
                        {{ c.fit }}
                    </button>
                    <button v-if="formation.journey === 'existing'" type="button" :class="sectionButton('baseline')" @click="active = 'baseline'">
                        {{ c.baseline }}
                    </button>
                    <button type="button" :class="sectionButton('capital')" @click="active = 'capital'">
                        {{ c.capital }}
                    </button>
                </nav>

                <section v-if="formation.journey === 'new' && active === 'overview'" class="py-6">
                    <div class="grid gap-6 lg:grid-cols-2">
                        <form class="space-y-4" @submit.prevent="saveIdea">
                            <h2 class="text-lg font-semibold">{{ c.idea }}</h2>
                            <label class="block text-sm font-semibold">
                                {{ c.summary }}
                                <textarea v-model="idea.summary" class="mt-2 min-h-24 w-full border border-slate-300 p-3 font-normal" />
                            </label>
                            <label class="block text-sm font-semibold">
                                {{ c.problem }}
                                <textarea v-model="idea.problem" class="mt-2 min-h-24 w-full border border-slate-300 p-3 font-normal" />
                            </label>
                            <label class="block text-sm font-semibold">
                                {{ c.targetCustomer }}
                                <textarea v-model="idea.target_customer" class="mt-2 min-h-24 w-full border border-slate-300 p-3 font-normal" />
                            </label>
                            <label class="block text-sm font-semibold">
                                {{ c.proposedSolution }}
                                <textarea v-model="idea.proposed_solution" class="mt-2 min-h-24 w-full border border-slate-300 p-3 font-normal" />
                            </label>
                            <button v-if="formation.permissions.can_manage_formation" class="min-h-11 bg-slate-950 px-4 text-sm font-semibold text-white" type="submit">
                                {{ c.save }}
                            </button>
                        </form>

                        <div>
                            <h2 class="text-lg font-semibold">{{ c.direction }}</h2>
                            <p class="mt-2 text-sm text-slate-600">
                                The OS supports evidence-based human judgment; it does not choose Go / Revise / Hold / No-Go for the owners.
                            </p>
                            <form class="mt-4 space-y-3" @submit.prevent="post('/formation/new/direction', direction)">
                                <select v-model="direction.direction" class="min-h-11 w-full border border-slate-300 bg-white px-3">
                                    <option value="go">Go</option>
                                    <option value="revise">Revise</option>
                                    <option value="hold">Hold</option>
                                    <option value="no_go">No-Go</option>
                                </select>
                                <textarea v-model="direction.rationale" :placeholder="c.rationale" class="min-h-28 w-full border border-slate-300 p-3" required />
                                <button v-if="formation.permissions.can_manage_formation" class="min-h-11 border border-slate-950 px-4 text-sm font-semibold" type="submit">
                                    {{ c.add }}
                                </button>
                            </form>
                            <table class="mt-6 w-full text-left text-sm">
                                <thead class="border-b border-slate-300 text-slate-500">
                                    <tr><th class="py-2">Decision</th><th class="py-2">{{ c.rationale }}</th></tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in formation.new_business?.directions ?? []" :key="row.id" class="border-b border-slate-200">
                                        <td class="py-3 font-semibold">{{ row.direction }}</td>
                                        <td class="py-3">{{ row.rationale }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <section v-if="active === 'bmc'" class="py-6">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold">{{ c.bmc }}</h2>
                            <p class="mt-1 text-sm text-slate-600">Exactly nine canonical blocks. Working BMC remains editable planning data.</p>
                        </div>
                        <span class="text-xs font-semibold text-slate-500">
                            {{ c.version }} {{ formation.bmc?.revision ?? 0 }}
                        </span>
                    </div>

                    <div class="mt-5 grid gap-3 md:grid-cols-3">
                        <button
                            v-for="key in bmcKeys"
                            :key="key"
                            type="button"
                            class="min-h-36 border border-slate-300 bg-white p-4 text-left hover:border-slate-950"
                            @click="selectedBmc = key"
                        >
                            <span class="text-sm font-semibold">{{ bmcLabels[key] }}</span>
                            <span class="mt-3 block line-clamp-4 whitespace-pre-wrap text-xs leading-5 text-slate-600">
                                {{ bmcDraft[key] || '—' }}
                            </span>
                        </button>
                    </div>

                    <div
                        v-if="selectedBmc"
                        class="fixed inset-0 z-50 flex justify-end bg-slate-950/30"
                        @click.self="selectedBmc = null"
                    >
                        <aside class="h-full w-full max-w-xl overflow-y-auto bg-white p-6 shadow-xl">
                            <div class="flex items-center justify-between gap-4">
                                <h3 class="text-lg font-semibold">{{ bmcLabels[selectedBmc] }}</h3>
                                <button type="button" class="min-h-11 border border-slate-300 px-4 text-sm font-semibold" @click="selectedBmc = null">
                                    {{ c.close }}
                                </button>
                            </div>
                            <textarea v-model="bmcDraft[selectedBmc]" class="mt-5 min-h-72 w-full border border-slate-300 p-3" />
                            <button
                                v-if="formation.permissions.can_manage_bmc"
                                type="button"
                                class="mt-4 min-h-11 bg-slate-950 px-4 text-sm font-semibold text-white"
                                @click="saveBmc"
                            >
                                {{ c.save }}
                            </button>
                        </aside>
                    </div>
                </section>

                <section v-if="formation.journey === 'new' && active === 'validation'" class="py-6">
                    <div class="grid gap-8 lg:grid-cols-2">
                        <div>
                            <h2 class="text-lg font-semibold">{{ c.assumptions }}</h2>
                            <form class="mt-4 grid gap-3" @submit.prevent="post('/formation/new/assumptions', assumption)">
                                <input v-model="assumption.category" class="min-h-11 border border-slate-300 px-3" :placeholder="c.category" required>
                                <textarea v-model="assumption.statement" class="min-h-24 border border-slate-300 p-3" :placeholder="c.statement" required />
                                <select v-model="assumption.status" class="min-h-11 border border-slate-300 bg-white px-3">
                                    <option value="planned">planned</option>
                                    <option value="testing">testing</option>
                                    <option value="validated">validated</option>
                                    <option value="invalidated">invalidated</option>
                                </select>
                                <button v-if="formation.permissions.can_manage_formation" class="min-h-11 border border-slate-950 px-4 text-sm font-semibold">{{ c.add }}</button>
                            </form>
                            <table class="mt-5 w-full text-left text-sm">
                                <tbody>
                                    <tr v-for="row in formation.new_business?.assumptions ?? []" :key="row.id" class="border-b border-slate-200">
                                        <td class="py-3 font-semibold">{{ row.category }}</td>
                                        <td class="py-3">{{ row.statement }}</td>
                                        <td class="py-3">{{ row.status }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div>
                            <h2 class="text-lg font-semibold">{{ c.validationActivities }}</h2>
                            <form class="mt-4 grid gap-3" @submit.prevent="post('/formation/new/validations', validation)">
                                <select v-model="validation.assumption_id" class="min-h-11 border border-slate-300 bg-white px-3">
                                    <option value="">No linked assumption</option>
                                    <option v-for="row in formation.new_business?.assumptions ?? []" :key="row.id" :value="row.id">
                                        {{ row.category }} · {{ row.statement }}
                                    </option>
                                </select>
                                <input v-model="validation.method" class="min-h-11 border border-slate-300 px-3" :placeholder="c.method" required>
                                <select v-model="validation.status" class="min-h-11 border border-slate-300 bg-white px-3">
                                    <option value="planned">planned</option>
                                    <option value="in_progress">in_progress</option>
                                    <option value="completed">completed</option>
                                </select>
                                <input v-model="validation.occurred_on" type="date" class="min-h-11 border border-slate-300 px-3">
                                <textarea v-model="validation.result_summary" class="min-h-24 border border-slate-300 p-3" :placeholder="c.result" />
                                <button v-if="formation.permissions.can_manage_formation" class="min-h-11 border border-slate-950 px-4 text-sm font-semibold">{{ c.add }}</button>
                            </form>
                            <div class="mt-5 divide-y divide-slate-200 border-t border-slate-200">
                                <div v-for="row in formation.new_business?.validations ?? []" :key="row.id" class="py-4">
                                    <p class="font-semibold">{{ row.method }} · {{ row.status }}</p>
                                    <p class="mt-1 text-sm text-slate-600">{{ row.result_summary || '—' }}</p>
                                    <form
                                        v-if="formation.permissions.can_manage_formation"
                                        class="mt-3 flex gap-2"
                                        @submit.prevent="linkValidationEvidence(row.id)"
                                    >
                                        <input v-model="validationEvidenceIds[row.id]" class="min-h-10 min-w-0 flex-1 border border-slate-300 px-3 text-xs" :placeholder="c.evidenceId" required>
                                        <button class="border border-slate-300 px-3 text-xs font-semibold">{{ c.linkEvidence }}</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section v-if="formation.journey === 'new' && active === 'feasibility'" class="py-6">
                    <h2 class="text-lg font-semibold">{{ c.feasibility }}</h2>
                    <p class="mt-1 text-sm text-slate-600">This is decision support only. The OS does not decide viability for the owners.</p>
                    <form class="mt-5 grid gap-3 md:grid-cols-2" @submit.prevent="post('/formation/new/feasibility', feasibility)">
                        <input v-model="feasibility.name" class="min-h-11 border border-slate-300 px-3" :placeholder="c.scenarioName" required>
                        <input v-model="feasibility.projected_monthly_revenue" class="min-h-11 border border-slate-300 px-3" :placeholder="c.monthlyRevenue" required>
                        <input v-model="feasibility.projected_monthly_cost" class="min-h-11 border border-slate-300 px-3" :placeholder="c.monthlyCost" required>
                        <textarea v-model="feasibility.notes" class="min-h-24 border border-slate-300 p-3 md:col-span-2" :placeholder="c.notes" />
                        <button v-if="formation.permissions.can_manage_formation" class="min-h-11 bg-slate-950 px-4 text-sm font-semibold text-white md:col-span-2">{{ c.add }}</button>
                    </form>
                    <table class="mt-6 w-full text-left text-sm">
                        <thead class="border-b border-slate-300 text-slate-500">
                            <tr><th class="py-2">{{ c.name }}</th><th>{{ c.monthlyRevenue }}</th><th>{{ c.monthlyCost }}</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in formation.new_business?.feasibility ?? []" :key="row.id" class="border-b border-slate-200">
                                <td class="py-3 font-semibold">{{ row.name }}</td>
                                <td>{{ row.projected_monthly_revenue }}</td>
                                <td>{{ row.projected_monthly_cost }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section v-if="formation.journey === 'new' && active === 'fit'" class="py-6">
                    <h2 class="text-lg font-semibold">{{ c.fit }}</h2>
                    <form class="mt-5 grid gap-4 lg:grid-cols-2" @submit.prevent="saveFit">
                        <textarea v-model="fit.goals_alignment" class="min-h-28 border border-slate-300 p-3" :placeholder="c.goals" />
                        <textarea v-model="fit.role_expectations" class="min-h-28 border border-slate-300 p-3" :placeholder="c.roles" />
                        <textarea v-model="fit.decision_process" class="min-h-28 border border-slate-300 p-3" :placeholder="c.decisions" />
                        <textarea v-model="fit.risk_tolerance" class="min-h-28 border border-slate-300 p-3" :placeholder="c.riskTolerance" />
                        <textarea v-model="fit.unresolved_questions" class="min-h-28 border border-slate-300 p-3 lg:col-span-2" :placeholder="c.openQuestions" />
                        <button v-if="formation.permissions.can_manage_formation" class="min-h-11 bg-slate-950 px-4 text-sm font-semibold text-white lg:col-span-2">{{ c.save }}</button>
                    </form>
                </section>

                <section v-if="formation.journey === 'existing' && active === 'baseline'" class="py-6">
                    <header>
                        <h2 class="text-lg font-semibold">{{ c.existingJourney }}</h2>
                        <p class="mt-1 text-sm text-slate-600">Valuation and existing-owner baseline are required before partner admission or dilution modeling later in the PBR journey.</p>
                    </header>

                    <div class="mt-6 grid gap-8 xl:grid-cols-2">
                        <form class="space-y-3" @submit.prevent="saveExistingProfile">
                            <h3 class="font-semibold">{{ c.profile }}</h3>
                            <input v-model="existingProfile.operating_since" type="date" class="min-h-11 w-full border border-slate-300 px-3">
                            <textarea v-model="existingProfile.summary" class="min-h-24 w-full border border-slate-300 p-3" :placeholder="c.summary" />
                            <textarea v-model="existingProfile.notes" class="min-h-20 w-full border border-slate-300 p-3" :placeholder="c.notes" />
                            <button v-if="formation.permissions.can_manage_formation" class="min-h-11 border border-slate-950 px-4 text-sm font-semibold">{{ c.save }}</button>
                        </form>

                        <form class="grid gap-3" @submit.prevent="post('/formation/existing/financial-snapshots', financial)">
                            <h3 class="font-semibold">{{ c.financialSnapshot }}</h3>
                            <input v-model="financial.as_of_date" type="date" class="min-h-11 border border-slate-300 px-3" required>
                            <div class="grid grid-cols-2 gap-2">
                                <input v-model="financial.revenue" class="min-h-11 border border-slate-300 px-3" :placeholder="c.revenue" required>
                                <input v-model="financial.expenses" class="min-h-11 border border-slate-300 px-3" :placeholder="c.expenses" required>
                                <input v-model="financial.cash" class="min-h-11 border border-slate-300 px-3" :placeholder="c.cash" required>
                                <input v-model="financial.receivables" class="min-h-11 border border-slate-300 px-3" :placeholder="c.receivables" required>
                                <input v-model="financial.payables" class="min-h-11 border border-slate-300 px-3" :placeholder="c.payables" required>
                            </div>
                            <button v-if="formation.permissions.can_manage_formation" class="min-h-11 border border-slate-950 px-4 text-sm font-semibold">{{ c.add }}</button>
                        </form>

                        <div class="space-y-4 border-t border-slate-200 pt-5">
                            <h3 class="font-semibold">{{ c.assets }} / {{ c.liabilities }}</h3>
                            <form class="grid grid-cols-[1fr_10rem_auto] gap-2" @submit.prevent="post('/formation/existing/assets', asset)">
                                <input v-model="asset.name" class="min-h-10 border border-slate-300 px-2" :placeholder="c.assets" required>
                                <input v-model="asset.estimated_value" class="min-h-10 border border-slate-300 px-2" :placeholder="c.amount" required>
                                <button class="border border-slate-300 px-3 text-xs font-semibold">{{ c.add }}</button>
                            </form>
                            <form class="grid grid-cols-[1fr_10rem_auto] gap-2" @submit.prevent="post('/formation/existing/liabilities', liability)">
                                <input v-model="liability.name" class="min-h-10 border border-slate-300 px-2" :placeholder="c.liabilities" required>
                                <input v-model="liability.outstanding_amount" class="min-h-10 border border-slate-300 px-2" :placeholder="c.amount" required>
                                <button class="border border-slate-300 px-3 text-xs font-semibold">{{ c.add }}</button>
                            </form>
                            <p class="text-xs text-slate-500">
                                {{ (formation.existing_business?.assets ?? []).length }} assets ·
                                {{ (formation.existing_business?.liabilities ?? []).length }} liabilities
                            </p>
                        </div>

                        <div class="space-y-4 border-t border-slate-200 pt-5">
                            <h3 class="font-semibold">{{ c.ownerPositions }}</h3>
                            <form class="grid gap-2 sm:grid-cols-[1fr_8rem_auto]" @submit.prevent="post('/formation/existing/owner-positions', owner)">
                                <input v-model="owner.owner_name" class="min-h-10 border border-slate-300 px-2" :placeholder="c.name" required>
                                <input v-model="owner.baseline_percent" class="min-h-10 border border-slate-300 px-2" :placeholder="c.percent">
                                <button class="border border-slate-300 px-3 text-xs font-semibold">{{ c.add }}</button>
                            </form>
                            <div v-for="row in formation.existing_business?.owner_positions ?? []" :key="row.id" class="text-sm">
                                <span class="font-semibold">{{ row.owner_name }}</span>
                                <span class="ml-2 text-slate-500">{{ row.baseline_percent ?? '—' }}%</span>
                            </div>
                        </div>

                        <div class="space-y-4 border-t border-slate-200 pt-5">
                            <h3 class="font-semibold">{{ c.obligations }}</h3>
                            <form class="grid gap-2" @submit.prevent="post('/formation/existing/obligations', obligation)">
                                <input v-model="obligation.title" class="min-h-10 border border-slate-300 px-2" :placeholder="c.name" required>
                                <textarea v-model="obligation.details" class="min-h-20 border border-slate-300 p-2" :placeholder="c.notes" />
                                <button class="min-h-10 border border-slate-300 px-3 text-xs font-semibold">{{ c.add }}</button>
                            </form>
                        </div>

                        <div class="space-y-4 border-t border-slate-200 pt-5">
                            <h3 class="font-semibold">{{ c.risks }}</h3>
                            <form class="grid gap-2" @submit.prevent="post('/formation/existing/risks', risk)">
                                <input v-model="risk.risk" class="min-h-10 border border-slate-300 px-2" :placeholder="c.risks" required>
                                <input v-model="risk.control_status" class="min-h-10 border border-slate-300 px-2" :placeholder="c.controlStatus" required>
                                <button class="min-h-10 border border-slate-300 px-3 text-xs font-semibold">{{ c.add }}</button>
                            </form>
                        </div>

                        <div class="space-y-4 border-t border-slate-200 pt-5">
                            <h3 class="font-semibold">{{ c.constraints }}</h3>
                            <form class="grid gap-2" @submit.prevent="post('/formation/existing/constraints', constraint)">
                                <input v-model="constraint.title" class="min-h-10 border border-slate-300 px-2" :placeholder="c.name" required>
                                <textarea v-model="constraint.details" class="min-h-20 border border-slate-300 p-2" :placeholder="c.notes" />
                                <button class="min-h-10 border border-slate-300 px-3 text-xs font-semibold">{{ c.add }}</button>
                            </form>
                        </div>

                        <form class="space-y-3 border-t border-slate-200 pt-5" @submit.prevent="saveGap">
                            <h3 class="font-semibold">{{ c.gapAssessment }}</h3>
                            <textarea v-model="gapAssessment.gaps" class="min-h-24 w-full border border-slate-300 p-3" :placeholder="c.gapAssessment" />
                            <textarea v-model="gapAssessment.priorities" class="min-h-24 w-full border border-slate-300 p-3" :placeholder="c.priorities" />
                            <button class="min-h-10 border border-slate-950 px-3 text-xs font-semibold">{{ c.save }}</button>
                        </form>

                        <form class="space-y-3 border-t border-slate-200 pt-5" @submit.prevent="saveConversion">
                            <h3 class="font-semibold">{{ c.conversionPlan }}</h3>
                            <textarea v-model="conversion.plan" class="min-h-40 w-full border border-slate-300 p-3" :placeholder="c.plan" required />
                            <button class="min-h-10 border border-slate-950 px-3 text-xs font-semibold">{{ c.save }}</button>
                        </form>

                        <div class="space-y-4 border-t border-slate-200 pt-5 xl:col-span-2">
                            <h3 class="font-semibold">{{ c.valuations }}</h3>
                            <p class="text-sm text-slate-600">{{ c.valuationNotice }}</p>
                            <form class="grid gap-2 lg:grid-cols-[10rem_12rem_1fr_9rem_auto]" @submit.prevent="post('/formation/existing/valuations', valuation)">
                                <input v-model="valuation.as_of_date" type="date" class="min-h-10 border border-slate-300 px-2" required>
                                <input v-model="valuation.amount" class="min-h-10 border border-slate-300 px-2" :placeholder="c.amount" required>
                                <input v-model="valuation.method" class="min-h-10 border border-slate-300 px-2" :placeholder="c.method" required>
                                <select v-model="valuation.review_state" class="min-h-10 border border-slate-300 bg-white px-2">
                                    <option value="draft">draft</option>
                                    <option value="reviewed">reviewed</option>
                                </select>
                                <button class="border border-slate-300 px-3 text-xs font-semibold">{{ c.add }}</button>
                            </form>
                            <table class="w-full text-left text-sm">
                                <tbody>
                                    <tr v-for="row in formation.existing_business?.valuations ?? []" :key="row.id" class="border-b border-slate-200">
                                        <td class="py-3">{{ row.as_of_date }}</td>
                                        <td>{{ row.amount }} {{ formation.business.base_currency }}</td>
                                        <td>{{ row.method }}</td>
                                        <td class="font-semibold">{{ row.review_state }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <section v-if="active === 'capital'" class="py-6">
                    <header class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold">{{ c.capital }}</h2>
                            <p class="mt-1 text-sm text-slate-600">
                                {{ c.capitalFormula }}:
                                <strong>{{ formation.capital.formula }}</strong>
                            </p>
                            <p class="mt-1 text-sm font-semibold text-slate-700">{{ formation.capital.scenario_notice }}</p>
                        </div>
                        <div class="text-right text-sm">
                            <p class="font-semibold">{{ c.official }}</p>
                            <p class="mt-1 text-slate-600">
                                {{ formation.capital.current_effective?.formal_record_version_id ?? c.none }}
                            </p>
                        </div>
                    </header>

                    <div class="mt-6 overflow-x-auto">
                        <table class="min-w-[1050px] w-full border-collapse text-left text-sm">
                            <thead class="border-b border-slate-300 text-slate-500">
                                <tr>
                                    <th class="px-2 py-3">Scenario</th>
                                    <th class="px-2 py-3">{{ c.preOpening }}</th>
                                    <th class="px-2 py-3">{{ c.initialAssets }}</th>
                                    <th class="px-2 py-3">{{ c.workingCapital }}</th>
                                    <th class="px-2 py-3">{{ c.contingency }}</th>
                                    <th class="px-2 py-3">{{ c.availableFunding }}</th>
                                    <th class="px-2 py-3">{{ c.total }}</th>
                                    <th class="px-2 py-3">{{ c.gap }}</th>
                                    <th class="px-2 py-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="kind in scenarioKinds" :key="kind" class="border-b border-slate-200 align-top">
                                    <td class="px-2 py-3">
                                        <input v-model="capitalForms[kind].name" class="min-h-10 w-32 border border-slate-300 px-2 font-semibold">
                                        <p class="mt-1 text-xs uppercase text-slate-500">{{ kind }}</p>
                                    </td>
                                    <td class="px-2 py-3"><input v-model="capitalForms[kind].pre_opening_costs" class="min-h-10 w-28 border border-slate-300 px-2"></td>
                                    <td class="px-2 py-3"><input v-model="capitalForms[kind].initial_assets_inventory" class="min-h-10 w-28 border border-slate-300 px-2"></td>
                                    <td class="px-2 py-3"><input v-model="capitalForms[kind].working_capital" class="min-h-10 w-28 border border-slate-300 px-2"></td>
                                    <td class="px-2 py-3"><input v-model="capitalForms[kind].contingency_reserve" class="min-h-10 w-28 border border-slate-300 px-2"></td>
                                    <td class="px-2 py-3"><input v-model="capitalForms[kind].available_funding" class="min-h-10 w-28 border border-slate-300 px-2"></td>
                                    <td class="px-2 py-3 font-semibold">{{ findScenario(kind)?.total_requirement ?? '—' }}</td>
                                    <td class="px-2 py-3 font-semibold">{{ findScenario(kind)?.funding_gap ?? '—' }}</td>
                                    <td class="px-2 py-3">
                                        <div class="flex flex-col gap-2">
                                            <button
                                                v-if="formation.permissions.can_manage_capital"
                                                type="button"
                                                class="min-h-9 border border-slate-950 px-2 text-xs font-semibold"
                                                @click="saveCapital(kind)"
                                            >
                                                {{ c.save }}
                                            </button>
                                            <button
                                                v-if="formation.permissions.can_manage_capital && findScenario(kind)"
                                                type="button"
                                                class="min-h-9 bg-slate-950 px-2 text-xs font-semibold text-white"
                                                @click="post(`/formation/capital/scenarios/${kind}/promote`, {})"
                                            >
                                                {{ c.promote }}
                                            </button>
                                            <a
                                                v-if="findScenario(kind)"
                                                :href="`/formation/capital/scenarios/${kind}/export`"
                                                class="inline-flex min-h-9 items-center justify-center border border-slate-300 px-2 text-xs font-semibold"
                                            >
                                                {{ c.export }}
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <section class="mt-8 border-t border-slate-200 pt-6">
                        <div class="flex items-center justify-between gap-4">
                            <h3 class="font-semibold">{{ c.history }}</h3>
                            <Link href="/governance" class="text-sm font-semibold underline">
                                {{ c.governance }}
                            </Link>
                        </div>

                        <table class="mt-4 w-full text-left text-sm">
                            <thead class="border-b border-slate-300 text-slate-500">
                                <tr>
                                    <th class="py-2">Proposal Version</th>
                                    <th>{{ c.version }}</th>
                                    <th>{{ c.status }}</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in formation.capital.promotions" :key="row.id" class="border-b border-slate-200">
                                    <td class="py-3 font-mono text-xs">{{ row.proposal_version_id }}</td>
                                    <td>{{ row.scenario_revision }}</td>
                                    <td class="font-semibold">{{ row.state }}</td>
                                    <td class="py-2">
                                        <button
                                            v-if="formation.permissions.can_manage_capital && row.state === 'ready_for_review'"
                                            type="button"
                                            class="mr-2 min-h-9 border border-slate-300 px-2 text-xs font-semibold"
                                            @click="post(`/formation/capital/promotions/${row.id}/content-review`, { target: 'under_review' })"
                                        >
                                            {{ c.startContentReview }}
                                        </button>
                                        <button
                                            v-if="formation.permissions.can_manage_capital && row.state === 'under_review'"
                                            type="button"
                                            class="min-h-9 border border-slate-950 bg-slate-950 px-2 text-xs font-semibold text-white"
                                            @click="post(`/formation/capital/promotions/${row.id}/content-review`, { target: 'approved' })"
                                        >
                                            {{ c.approveContent }}
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="formation.capital.promotions.length === 0">
                                    <td colspan="4" class="py-5 text-slate-500">{{ c.noRows }}</td>
                                </tr>
                            </tbody>
                        </table>

                        <p class="mt-4 text-xs leading-5 text-slate-500">
                            After content review is Approved, continue in Governance. Proposal Review and Governance Decision remain separate. Only the existing governed effectivity flow may establish the Current Effective Capital Plan. Evidence can be uploaded in Document Vault and linked to the exact Formal Record Version shown in Governance.
                        </p>
                    </section>
                </section>
            </section>
        </main>
    </AuthenticatedLayout>
</template>
