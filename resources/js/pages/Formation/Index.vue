<script setup lang="ts">
import OptionalTemporalInput from '../../components/OptionalTemporalInput.vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import GuidedJourneyStepper from '../../components/hybrid/GuidedJourneyStepper.vue';
import BusinessModelGuidedJourney from '../../components/business-model/BusinessModelGuidedJourney.vue';
import DemandEvidenceGuidedJourney from '../../components/business-model/DemandEvidenceGuidedJourney.vue';
import BusinessValuationGuidedJourney from '../../components/business-valuation/BusinessValuationGuidedJourney.vue';
import DeepFeasibilityGuidedJourney from '../../components/deep-feasibility/DeepFeasibilityGuidedJourney.vue';
import CapitalGuidedJourney from '../../components/capital/CapitalGuidedJourney.vue';
import type { BusinessValuationReadModel } from '../../types/businessValuation';

type LanguageMode = 'en' | 'my' | 'mixed';
type Journey = 'new' | 'existing';
type GenericRow = Record<string, any>;

type DeepFeasibilityHistorySummary = {
    sequence: number;
    createdAt: string | null;
    confidenceLevel: string;
    evidenceQuality: string;
    assessedDimensions: number;
    evidenceGaps: number;
    unavailableDependencies: number;
    blockers: number;
    strengths: number;
    risks: number;
    requiredActions: number;
    recommendationAvailable: boolean;
    recommendation: string | null;
    historicalSnapshot: boolean;
    readOnly: boolean;
};

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
    business_model_foundation: null | {
        operating_profile: GenericRow | null;
        economics: {
            status: string;
            contributionMarginPerUnit: string | null;
            grossMarginPercent: number | null;
            breakEvenUnits: number | null;
            breakEvenRevenue: string | null;
            expectedMonthlyRevenue: string | null;
            expectedMonthlyGrossProfit: string | null;
            expectedMonthlyOperatingProfit: string | null;
        };
        demand: {
            status: string;
            assumptions: number;
            validated_assumptions: number;
            invalidated_assumptions: number;
            validation_activities: number;
            completed_validations: number;
            evidence_links: number;
        };
    };
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
        deep_feasibility_foundation: GenericRow | null;
        deep_feasibility_history: DeepFeasibilityHistorySummary[];
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
        business_valuation: BusinessValuationReadModel | null;
    };
    capital: {
        planning_draft: GenericRow | null;
        planning_calculation: GenericRow | null;
        rule_draft: GenericRow | null;
        rule_read_model: GenericRow | null;
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
            'Build the business baseline, validate assumptions and calculate the Capital this Business needs from reusable planning evidence.',
        notice:
            'Capital stays editable planning truth in this stage. Saving a draft is not Approval, Signature, Equity, Ownership or an Effective record.',
        newJourney: 'New Business Formation',
        existingJourney: 'Existing Business Baseline',
        overview: 'Overview',
        bmc: 'Business Model Canvas',
        validation: 'Validation',
        feasibility: 'Feasibility',
        feasibilityUnavailable:
            'Deep Feasibility is not available for this account in the current Business. Required Business Model access is not available.',
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
        evidenceAdvanced: 'Advanced: link existing evidence',
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
        capitalJourney: 'Capital Planning Workflow',
        capitalJourneyHelp: 'Calculate the full requirement, compare Lean / Base / Growth, then promote the chosen plan into the governed approval flow.',
        startupCostPlan: 'Startup Cost Plan',
        initialAssetsOpening: 'Initial Assets & Opening Inventory',
        workingCapitalForecast: 'Working Capital Forecast',
        contingencyReserve: 'Contingency Reserve',
        fundingPositionGap: 'Funding Position & Gap',
        capitalRuleAllocation: 'Capital Rule & Allocation',
        liveCapitalPosition: 'Live Capital Position',
        confirmedFunding: 'Confirmed Funding',
        fundedPercent: '% Funded',
        planComparison: 'Capital Plan Comparison',
        editPlan: 'Edit plan',
        savedPlan: 'Saved planning scenario',
        unsavedPlan: 'Unsaved planning draft',
        capitalRuleNotes: 'Capital rule / allocation notes',
        noRows: 'No records yet.',
        journeyTitle: 'Guided setup journey',
        journeyHelp: 'Work from left to right. A check means information has been recorded; it does not mean Governance approval.',
        stepIdea: 'Idea',
        stepCurrentBmc: 'Current BMC',
        stepFinancial: 'Financial Baseline',
        stepAssets: 'Assets & Liabilities',
        stepValuation: 'Valuation',
        stepOwners: 'Existing Owners',
        stepObligationsRisks: 'Obligations & Risks',
        stepGap: 'PBR Gap',
        stepConversion: 'Conversion Plan',
        stepDirection: 'Go / Revise / Hold / No-Go',
        stepPartnerSetup: 'Partner Setup',
        recorded: 'Information recorded',
        continueSetup: 'Continue setup',
    },
    my: {
        title: 'လုပ်ငန်းဖွဲ့စည်းမှုနှင့် အရင်းအနှီး',
        description:
            'လုပ်ငန်းအခြေခံအချက်အလက်နဲ့ စမ်းသပ်အတည်ပြုထားတဲ့ assumptions တွေကိုပြန်သုံးပြီး ဒီလုပ်ငန်းစဖို့လိုတဲ့ Capital ကို အဆင့်လိုက်တွက်ပါ။',
        notice:
            'ဒီအဆင့်က editable Capital Planning သာဖြစ်ပါတယ်။ Draft သိမ်းတာက Approval, Signature, Equity, Ownership သို့မဟုတ် Effective Record မဟုတ်ပါ။',
        newJourney: 'လုပ်ငန်းအသစ် ဖွဲ့စည်းမှု',
        existingJourney: 'လက်ရှိလုပ်ငန်း အခြေခံမှတ်တမ်း',
        overview: 'အနှစ်ချုပ်',
        bmc: 'Business Model Canvas',
        validation: 'စမ်းသပ်အတည်ပြုမှု',
        feasibility: 'ဖြစ်နိုင်ခြေ',
        feasibilityUnavailable:
            'ဒီ Business အတွက် Deep Feasibility ကို ဒီ account နဲ့ မကြည့်နိုင်သေးပါ။ လိုအပ်တဲ့ Business Model access မရှိသေးပါ။',
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
        evidenceAdvanced: 'အဆင့်မြင့်: ရှိပြီးသား Evidence ချိတ်ရန်',
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
        capitalJourney: 'အရင်းအနှီး စီမံကိန်း အဆင့်လိုက်လမ်းကြောင်း',
        capitalJourneyHelp: 'လိုအပ်သော Capital ပမာဏကိုတွက်၊ Lean / Base / Growth ကိုနှိုင်းယှဉ်ပြီး ရွေးထားသော plan ကို governed approval flow သို့ တင်ပါ။',
        startupCostPlan: 'Startup Cost Plan',
        initialAssetsOpening: 'Initial Assets & Opening Inventory',
        workingCapitalForecast: 'Working Capital Forecast',
        contingencyReserve: 'Contingency Reserve',
        fundingPositionGap: 'Funding Position & Gap',
        capitalRuleAllocation: 'Capital Rule & Allocation',
        liveCapitalPosition: 'လက်ရှိ Capital Position',
        confirmedFunding: 'အတည်ပြုရရှိထားသော Funding',
        fundedPercent: 'Funding ပြည့်မီမှု %',
        planComparison: 'Capital Plan နှိုင်းယှဉ်ချက်',
        editPlan: 'Plan ကိုပြင်မည်',
        savedPlan: 'သိမ်းပြီး Planning Scenario',
        unsavedPlan: 'မသိမ်းရသေးသော Planning Draft',
        capitalRuleNotes: 'Capital rule / allocation မှတ်ချက်',
        noRows: 'မှတ်တမ်း မရှိသေးပါ။',
        journeyTitle: 'အဆင့်လိုက် Setup လမ်းကြောင်း',
        journeyHelp: 'ဘယ်မှညာသို့ အဆင့်လိုက်လုပ်ပါ။ Check သင်္ကေတသည် အချက်အလက်မှတ်တမ်းရှိပြီးဖြစ်သည်ကိုသာ ဆိုလိုပြီး Governance approval မဟုတ်ပါ။',
        stepIdea: 'လုပ်ငန်းအကြံ',
        stepCurrentBmc: 'လက်ရှိ BMC',
        stepFinancial: 'ဘဏ္ဍာရေးအခြေခံ',
        stepAssets: 'ပိုင်ဆိုင်မှုနှင့် ပေးဆပ်ရန်များ',
        stepValuation: 'တန်ဖိုးသတ်မှတ်မှု',
        stepOwners: 'လက်ရှိပိုင်ရှင်များ',
        stepObligationsRisks: 'တာဝန်များနှင့် Risk များ',
        stepGap: 'PBR Gap',
        stepConversion: 'Conversion Plan',
        stepDirection: 'Go / Revise / Hold / No-Go',
        stepPartnerSetup: 'Partner Setup',
        recorded: 'အချက်အလက် မှတ်တမ်းရှိပြီး',
        continueSetup: 'Setup ဆက်လုပ်ရန်',
    },
    mixed: {
        title: 'Formation & Capital · လုပ်ငန်းဖွဲ့စည်းမှုနှင့် အရင်းအနှီး',
        description:
            'Business baseline နဲ့ validated assumptions ကို reuse လုပ်ပြီး Business စဖို့လိုတဲ့ Capital ကို guided steps နဲ့ calculate လုပ်ပါ။',
        notice:
            'This is editable Capital Planning. Save Draft က Approval, Signature, Equity, Ownership or Effective truth မဟုတ်ပါ။',
        newJourney: 'New Business Formation',
        existingJourney: 'Existing Business Baseline',
        overview: 'Overview',
        bmc: 'Business Model Canvas',
        validation: 'Validation',
        feasibility: 'Feasibility',
        feasibilityUnavailable:
            'ဒီ Business အတွက် Deep Feasibility မရသေးပါ။ Required Business Model access မရှိသေးပါ။',
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
        evidenceAdvanced: 'Advanced: existing Evidence link',
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
        capitalJourney: 'Capital Planning Workflow',
        capitalJourneyHelp: 'Capital requirement ကို calculate လုပ်၊ Lean / Base / Growth ကို compare လုပ်ပြီး chosen plan ကို governed approval flow သို့ promote လုပ်ပါ။',
        startupCostPlan: 'Startup Cost Plan',
        initialAssetsOpening: 'Initial Assets & Opening Inventory',
        workingCapitalForecast: 'Working Capital Forecast',
        contingencyReserve: 'Contingency Reserve',
        fundingPositionGap: 'Funding Position & Gap',
        capitalRuleAllocation: 'Capital Rule & Allocation',
        liveCapitalPosition: 'Live Capital Position',
        confirmedFunding: 'Confirmed Funding',
        fundedPercent: '% Funded',
        planComparison: 'Capital Plan Comparison',
        editPlan: 'Edit plan',
        savedPlan: 'Saved planning scenario',
        unsavedPlan: 'Unsaved planning draft',
        capitalRuleNotes: 'Capital rule / allocation notes',
        noRows: 'No records yet.',
        journeyTitle: 'Guided Setup Journey',
        journeyHelp: 'ဘယ်မှညာသို့ step-by-step လုပ်ပါ။ Check က information recorded ဖြစ်တာကိုသာပြပြီး Governance approval မဟုတ်ပါ။',
        stepIdea: 'Idea',
        stepCurrentBmc: 'Current BMC',
        stepFinancial: 'Financial Baseline',
        stepAssets: 'Assets & Liabilities',
        stepValuation: 'Valuation',
        stepOwners: 'Existing Owners',
        stepObligationsRisks: 'Obligations & Risks',
        stepGap: 'PBR Gap',
        stepConversion: 'Conversion Plan',
        stepDirection: 'Go / Revise / Hold / No-Go',
        stepPartnerSetup: 'Partner Setup',
        recorded: 'Information recorded',
        continueSetup: 'Continue setup',
    },
} as const;

const c = computed(() => copy[mode.value]);

type FormationStep =
    | 'overview'
    | 'bmc'
    | 'validation'
    | 'feasibility'
    | 'fit'
    | 'direction'
    | 'baseline'
    | 'capital';

const requestedStep = new URLSearchParams(
    String(page.url).split('?')[1] ?? '',
).get('step');

const newBusinessSteps: FormationStep[] = [
    'overview',
    'bmc',
    'validation',
    'feasibility',
    'fit',
    'direction',
    'capital',
];

const active = ref<FormationStep>(
    props.formation.journey === 'new'
        && requestedStep !== null
        && newBusinessSteps.includes(requestedStep as FormationStep)
        ? (requestedStep as FormationStep)
        : props.formation.journey === 'new'
          ? 'overview'
          : 'baseline',
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

type PostData = NonNullable<Parameters<typeof router.post>[1]>;

const post = (url: string, data: PostData = {}) =>
    router.post(url, data, { preserveScroll: true });

const put = (url: string, data: PostData = {}) =>
    router.put(url, data, { preserveScroll: true });

const saveIdea = () =>
    put('/formation/new/idea', {
        expected_revision: revision(props.formation.new_business?.idea),
        ...idea,
    });

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

const sectionButton = (key: typeof active.value) =>
    [
        'min-h-11 border-b-2 px-3 text-sm font-semibold',
        active.value === key
            ? 'border-slate-950 text-slate-950'
            : 'border-transparent text-slate-500',
    ];

const existingFocus = ref('profile');

const newStepTarget: Record<string, typeof active.value | 'partnership'> = {
    idea: 'overview',
    bmc: 'bmc',
    validation: 'validation',
    feasibility: 'feasibility',
    fit: 'fit',
    direction: 'direction',
    partner_setup: 'partnership',
};

const formationSteps = computed(() => {
    if (props.formation.journey === 'new') {
        const sources: Record<string, boolean> = {
            idea: props.formation.new_business?.idea !== null,
            bmc: props.formation.bmc !== null,
            validation:
                (props.formation.new_business?.validations ?? []).length > 0,
            feasibility:
                (props.formation.new_business?.deep_feasibility_history ?? [])
                    .length > 0,
            fit: props.formation.new_business?.partnership_fit !== null,
            direction:
                (props.formation.new_business?.directions ?? []).length > 0,
            partner_setup: false,
        };
        const labels: Record<string, string> = {
            idea: c.value.stepIdea,
            bmc: c.value.bmc,
            validation: c.value.validation,
            feasibility: c.value.feasibility,
            fit: c.value.fit,
            direction: c.value.stepDirection,
            partner_setup: c.value.stepPartnerSetup,
        };

        return Object.keys(labels).map((key) => {
            const target = newStepTarget[key];
            const isCurrent =
                target !== 'partnership' && active.value === target;

            return {
                key,
                label: labels[key],
                helper: sources[key]
                    ? c.value.recorded
                    : c.value.continueSetup,
                state: isCurrent
                    ? 'current' as const
                    : sources[key]
                      ? 'recorded' as const
                      : key === 'partner_setup'
                        ? 'next' as const
                        : 'available' as const,
            };
        });
    }

    const sources: Record<string, boolean> = {
        profile: props.formation.existing_business?.profile !== null,
        bmc: props.formation.bmc !== null,
        financial:
            (props.formation.existing_business?.financial_snapshots ?? [])
                .length > 0,
        assets:
            (props.formation.existing_business?.assets ?? []).length > 0
            || (props.formation.existing_business?.liabilities ?? []).length > 0,
        valuation:
            props.formation.existing_business?.business_valuation !== null
            || (props.formation.existing_business?.valuations ?? []).length > 0,
        owners:
            (props.formation.existing_business?.owner_positions ?? []).length > 0,
        obligations_risks:
            (props.formation.existing_business?.obligations ?? []).length > 0
            || (props.formation.existing_business?.risks ?? []).length > 0
            || (props.formation.existing_business?.constraints ?? []).length > 0,
        gap: props.formation.existing_business?.gap_assessment !== null,
        conversion:
            props.formation.existing_business?.conversion_plan !== null,
        partner_setup: false,
    };
    const labels: Record<string, string> = {
        profile: c.value.profile,
        bmc: c.value.stepCurrentBmc,
        financial: c.value.stepFinancial,
        assets: c.value.stepAssets,
        valuation: c.value.stepValuation,
        owners: c.value.stepOwners,
        obligations_risks: c.value.stepObligationsRisks,
        gap: c.value.stepGap,
        conversion: c.value.stepConversion,
        partner_setup: c.value.stepPartnerSetup,
    };

    return Object.keys(labels).map((key) => {
        const isCurrent =
            key === 'bmc'
                ? active.value === 'bmc'
                : active.value === 'baseline'
                  && existingFocus.value === key;

        return {
            key,
            label: labels[key],
            helper: sources[key]
                ? c.value.recorded
                : c.value.continueSetup,
            state: isCurrent
                ? 'current' as const
                : sources[key]
                  ? 'recorded' as const
                  : key === 'partner_setup'
                    ? 'next' as const
                    : 'available' as const,
        };
    });
});

const selectFormationStep = (key: string) => {
    if (props.formation.journey === 'new') {
        const target = newStepTarget[key];

        if (target === 'partnership') {
            router.visit('/partnership');

            return;
        }

        if (target) {
            active.value = target;
        }

        return;
    }

    if (key === 'partner_setup') {
        router.visit('/partnership');

        return;
    }

    if (key === 'bmc') {
        active.value = 'bmc';

        return;
    }

    existingFocus.value = key;
    active.value = 'baseline';

    window.setTimeout(() => {
        document
            .getElementById('formation-existing-' + key)
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 0);
};
</script>

<template>
    <AuthenticatedLayout>
        <main class="min-h-screen bg-[radial-gradient(circle_at_92%_0%,rgb(210_167_67_/_9%),transparent_25rem),linear-gradient(180deg,#f7f9f6_0%,#f1f5f1_100%)] px-4 py-5 text-[var(--pbr-ink)] sm:px-6 sm:py-6 lg:px-7 lg:py-7">
            <section class="mx-auto max-w-[1500px]">
                <header class="relative overflow-hidden rounded-[26px] border border-[#cfe0d4] bg-[linear-gradient(145deg,#ffffff_0%,#f4f9f5_64%,#fbf7eb_100%)] p-5 shadow-[0_18px_46px_rgb(16_35_26_/_7%)] sm:p-7">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.2em] text-[var(--pbr-green)]">
                                {{ formation.journey === 'new' ? c.newJourney : c.existingJourney }}
                            </p>
                            <h1 class="mt-2 text-2xl font-black tracking-[-0.03em] sm:text-3xl">
                                {{ c.title }}
                            </h1>
                            <p class="mt-2 max-w-4xl text-sm leading-6 text-[var(--pbr-muted)]">
                                {{ c.description }}
                            </p>
                        </div>
                        <div class="text-right text-xs text-slate-500">
                            <p class="font-semibold text-slate-800">{{ formation.business.name }}</p>
                            <p>{{ formation.business.base_currency }} · {{ formation.business.business_stage }}</p>
                        </div>
                    </div>
                    <p class="mt-5 rounded-[16px] border border-[#d9e5dc] bg-white/80 px-4 py-3 text-sm leading-6 text-[var(--pbr-ink-soft)]">
                        {{ c.notice }}
                    </p>
                </header>

                <section class="mt-5">
                    <div class="mb-3">
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]">
                            {{ c.journeyTitle }}
                        </p>
                        <p class="mt-1 max-w-4xl text-sm leading-6 text-[var(--pbr-muted)]">
                            {{ c.journeyHelp }}
                        </p>
                    </div>
                    <GuidedJourneyStepper
                        :steps="formationSteps"
                        :label="c.journeyTitle"
                        @select="selectFormationStep"
                    />
                </section>

                <nav class="mt-5 flex gap-1 overflow-x-auto rounded-[16px] border border-[#d9e5dc] bg-white/80 p-1.5 shadow-[0_8px_24px_rgb(16_35_26_/_3%)]" aria-label="Formation sections">
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
                    <button v-if="formation.journey === 'new'" type="button" :class="sectionButton('direction')" @click="active = 'direction'">
                        {{ c.direction }}
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

                    </div>
                </section>

                <section v-show="active === 'bmc'" class="py-6">
                    <BusinessModelGuidedJourney
                        v-if="formation.business_model_foundation"
                        :key="formation.business.id"
                        :journey="formation.journey"
                        :currency="formation.business.base_currency"
                        :bmc="formation.bmc"
                        :foundation="formation.business_model_foundation"
                        :can-manage="formation.permissions.can_manage_bmc"
                        @open-demand="active = 'validation'"
                    />
                </section>

                <section
                    v-if="formation.journey === 'new'"
                    v-show="active === 'validation'"
                    class="py-6"
                >
                    <DemandEvidenceGuidedJourney
                        :key="formation.business.id"
                        :business-id="formation.business.id"
                        :assumptions="formation.new_business?.assumptions ?? []"
                        :validations="formation.new_business?.validations ?? []"
                        :can-manage="formation.permissions.can_manage_formation"
                    />
                </section>

                <section
                    v-if="formation.journey === 'new'"
                    v-show="active === 'feasibility'"
                    class="py-6"
                >
                    <DeepFeasibilityGuidedJourney
                        v-if="formation.new_business?.deep_feasibility_foundation"
                        :key="formation.business.id"
                        :foundation="formation.new_business.deep_feasibility_foundation"
                        :history="formation.new_business.deep_feasibility_history"
                        :can-manage="formation.permissions.can_manage_formation"
                        @open-business-model="active = 'bmc'"
                        @open-demand="active = 'validation'"
                    />
                    <div
                        v-else
                        class="rounded-[22px] border border-[#d9e1e4] bg-[#f7f8f9] p-5 text-sm leading-6 text-[var(--pbr-muted)]"
                        role="status"
                    >
                        {{ c.feasibilityUnavailable }}
                    </div>
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

                <section
                    v-if="formation.journey === 'new' && active === 'direction'"
                    class="py-6"
                >
                    <div class="rounded-[22px] border border-[#d8e4db] bg-white p-5 shadow-[0_12px_30px_rgb(16_35_26_/_5%)] sm:p-6">
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]">
                            6 · {{ c.direction }}
                        </p>
                        <h2 class="mt-1 text-xl font-black tracking-[-0.02em]">
                            {{ c.stepDirection }}
                        </h2>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-[var(--pbr-muted)]">
                            The OS supports evidence-based human judgment. It does not choose the direction for the owners.
                        </p>

                        <form class="mt-5 grid gap-3 lg:grid-cols-[15rem_1fr_auto]" @submit.prevent="post('/formation/new/direction', direction)">
                            <select v-model="direction.direction" class="pbr-input-control min-h-11 bg-white px-3">
                                <option value="go">Go</option>
                                <option value="revise">Revise</option>
                                <option value="hold">Hold</option>
                                <option value="no_go">No-Go</option>
                            </select>
                            <textarea v-model="direction.rationale" :placeholder="c.rationale" class="pbr-input-control min-h-24 p-3" required />
                            <button v-if="formation.permissions.can_manage_formation" class="min-h-11 rounded-xl bg-[var(--pbr-green-dark)] px-4 text-sm font-black text-white" type="submit">
                                {{ c.add }}
                            </button>
                        </form>

                        <div class="mt-5 space-y-2">
                            <article
                                v-for="row in formation.new_business?.directions ?? []"
                                :key="row.id"
                                class="rounded-[16px] border border-[#e0e8e2] bg-[#f9fbf9] p-4"
                            >
                                <p class="font-black">{{ String(row.direction).replace('_', '-') }}</p>
                                <p class="mt-1 text-sm leading-6 text-[var(--pbr-muted)]">{{ row.rationale }}</p>
                            </article>
                            <p v-if="(formation.new_business?.directions ?? []).length === 0" class="text-sm text-[var(--pbr-muted)]">
                                {{ c.noRows }}
                            </p>
                        </div>
                    </div>
                </section>

                <section v-if="formation.journey === 'existing' && active === 'baseline'" class="py-6">
                    <header>
                        <h2 class="text-lg font-semibold">{{ c.existingJourney }}</h2>
                        <p class="mt-1 text-sm text-slate-600">Valuation and existing-owner baseline are required before partner admission or dilution modeling later in the PBR journey.</p>
                    </header>

                    <div class="mt-6 grid gap-8 xl:grid-cols-2">
                        <form id="formation-existing-profile" class="scroll-mt-24 space-y-3 rounded-[18px] border border-[#dce6de] bg-white p-5 shadow-[0_8px_22px_rgb(16_35_26_/_3%)]" @submit.prevent="saveExistingProfile">
                            <h3 class="font-semibold">{{ c.profile }}</h3>
                            <OptionalTemporalInput v-model="existingProfile.operating_since" type="date" class="min-h-11 w-full border border-slate-300 px-3" />
                            <textarea v-model="existingProfile.summary" class="min-h-24 w-full border border-slate-300 p-3" :placeholder="c.summary" />
                            <textarea v-model="existingProfile.notes" class="min-h-20 w-full border border-slate-300 p-3" :placeholder="c.notes" />
                            <button v-if="formation.permissions.can_manage_formation" class="min-h-11 border border-slate-950 px-4 text-sm font-semibold">{{ c.save }}</button>
                        </form>

                        <form id="formation-existing-financial" class="scroll-mt-24 grid gap-3 rounded-[18px] border border-[#dce6de] bg-white p-5 shadow-[0_8px_22px_rgb(16_35_26_/_3%)]" @submit.prevent="post('/formation/existing/financial-snapshots', financial)">
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

                        <div id="formation-existing-assets" class="scroll-mt-24 space-y-4 rounded-[18px] border border-[#dce6de] bg-white p-5 shadow-[0_8px_22px_rgb(16_35_26_/_3%)]">
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

                        <div id="formation-existing-valuation" class="scroll-mt-24 xl:col-span-2">
                            <BusinessValuationGuidedJourney
                                :key="formation.existing_business?.business_valuation?.id ?? formation.business.id"
                                :business-id="formation.business.id"
                                :currency="formation.business.base_currency"
                                :financial-snapshots="formation.existing_business?.financial_snapshots ?? []"
                                :assets="formation.existing_business?.assets ?? []"
                                :liabilities="formation.existing_business?.liabilities ?? []"
                                :latest="formation.existing_business?.business_valuation ?? null"
                                :can-manage="formation.permissions.can_manage_formation"
                            />
                        </div>

                        <div id="formation-existing-owners" class="scroll-mt-24 space-y-4 rounded-[18px] border border-[#dce6de] bg-white p-5 shadow-[0_8px_22px_rgb(16_35_26_/_3%)]">
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

                        <section id="formation-existing-obligations_risks" class="scroll-mt-24 space-y-5 rounded-[18px] border border-[#dce6de] bg-white p-5 shadow-[0_8px_22px_rgb(16_35_26_/_3%)]">
                            <div class="space-y-4">
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
                        </section>

                        <form id="formation-existing-gap" class="scroll-mt-24 space-y-3 rounded-[18px] border border-[#dce6de] bg-white p-5 shadow-[0_8px_22px_rgb(16_35_26_/_3%)]" @submit.prevent="saveGap">
                            <h3 class="font-semibold">{{ c.gapAssessment }}</h3>
                            <textarea v-model="gapAssessment.gaps" class="min-h-24 w-full border border-slate-300 p-3" :placeholder="c.gapAssessment" />
                            <textarea v-model="gapAssessment.priorities" class="min-h-24 w-full border border-slate-300 p-3" :placeholder="c.priorities" />
                            <button class="min-h-10 border border-slate-950 px-3 text-xs font-semibold">{{ c.save }}</button>
                        </form>

                        <form id="formation-existing-conversion" class="scroll-mt-24 space-y-3 rounded-[18px] border border-[#dce6de] bg-white p-5 shadow-[0_8px_22px_rgb(16_35_26_/_3%)]" @submit.prevent="saveConversion">
                            <h3 class="font-semibold">{{ c.conversionPlan }}</h3>
                            <textarea v-model="conversion.plan" class="min-h-40 w-full border border-slate-300 p-3" :placeholder="c.plan" required />
                            <button class="min-h-10 border border-slate-950 px-3 text-xs font-semibold">{{ c.save }}</button>
                        </form>

                    </div>
                </section>

                <section v-if="active === 'capital'" class="py-6">
                    <CapitalGuidedJourney
                        :draft="formation.capital.planning_draft"
                        :calculation="formation.capital.planning_calculation"
                        :rule-draft="formation.capital.rule_draft"
                        :rule-read-model="formation.capital.rule_read_model"
                        :business-model-foundation="formation.business_model_foundation"
                        :currency="formation.business.base_currency"
                        :can-manage="formation.permissions.can_manage_capital"
                        @open-business-model="active = 'bmc'"
                    />
                </section>
            </section>
        </main>
    </AuthenticatedLayout>
</template>
