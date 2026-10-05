<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import GuidedJourneyStepper from '../hybrid/GuidedJourneyStepper.vue';
import ProgressiveReveal from '../hybrid/ProgressiveReveal.vue';
import PbrButton from '../ui/PbrButton.vue';
import PbrFormSection from '../ui/PbrFormSection.vue';
import { useI18n } from '../../i18n/useI18n';

type GenericRow = Record<string, any>;

type HistorySummary = {
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

type Focus =
    | 'overview'
    | 'evidence'
    | 'dimensions'
    | 'actions'
    | 'readiness'
    | 'history';

const props = defineProps<{
    foundation: GenericRow;
    history: HistorySummary[];
    canManage: boolean;
}>();

const emit = defineEmits<{
    openBusinessModel: [];
    openDemand: [];
}>();

const { uiLanguageMode } = useI18n();

const en = {
    eyebrow: 'Guided Deep Feasibility',
    title: 'Understand how ready this Business is to start',
    subtitle:
        'PBR reuses what you already recorded. It separates real strengths and blockers from evidence that is simply not available yet.',
    progress: 'Deep Feasibility guided journey',
    overview: 'Overview',
    evidence: 'What PBR knows',
    dimensions: 'Feasibility areas',
    actions: 'What to work on',
    readiness: 'Before GO can be evaluated',
    history: 'Assessment history',
    currentState: 'Current assessment',
    currentStateHelp:
        'This is decision support from current PBR evidence. It is not approval, ownership truth, valuation truth or a guarantee of success.',
    readinessLabel: 'Assessment readiness',
    confidence: 'Confidence',
    evidenceQuality: 'Evidence quality',
    recommendation: 'Final recommendation',
    recommendationPending: 'Not available yet',
    recommendationPendingHelp:
        'PBR can already assess several areas, but required evidence or downstream readiness areas are still missing. No GO / HOLD / NO-GO is being invented.',
    knownTitle: 'What PBR already knows',
    knownHelp:
        'These signals come from the existing Business Model, customer validation and economics records. You do not need to enter them again here.',
    businessModel: 'Business Model',
    demand: 'Demand / Customer Validation',
    economics: 'Unit Economics / Break-even',
    competition: 'Competition / Alternatives',
    scalability: 'Scalability',
    known: 'Available',
    needsEvidence: 'Needs more evidence',
    reviewBusinessModel: 'Review Business Model',
    completeValidation: 'Complete Customer Validation',
    dimensionsTitle: 'Feasibility dimensions',
    dimensionsHelp:
        'Each area shows what can be assessed now. Missing evidence and future dependencies are not treated as bad scores.',
    assessed: 'Assessed',
    insufficient: 'More evidence needed',
    unavailable: 'Available later',
    blocker: 'Blocker',
    strengthsTitle: 'Strengths',
    strengthsHelp:
        'Only positive findings supported by the deterministic assessment appear here.',
    noStrengths: 'No confirmed strengths are available from the current evidence yet.',
    concernsTitle: 'Risks, blockers and readiness gaps',
    concernsHelp:
        'Objective blockers are separated from risks, missing evidence and future dependencies.',
    risks: 'Risks',
    blockers: 'Blockers',
    evidenceGaps: 'Evidence gaps',
    dependencyGaps: 'Future dependencies',
    noRisks: 'No deterministic risk is currently identified.',
    noBlockers: 'No objective blocker is currently identified.',
    noEvidenceGaps: 'No core evidence gap is currently identified.',
    noDependencyGaps: 'No future dependency gap is currently identified.',
    actionsTitle: 'Required actions',
    actionsHelp:
        'Every action is linked to a specific feasibility area. PBR sends you back to the authoritative source instead of duplicating forms here.',
    noActions: 'No required action is currently available.',
    later: 'This becomes available later in the PBR journey.',
    goTitle: 'What must change before GO can be evaluated?',
    goHelp:
        'These are known blocker-clearance conditions, missing evidence or future dependencies. Completing them does not guarantee business success or a GO result.',
    noGoRequirements:
        'No additional requirement is currently listed, but final recommendation thresholds are not calibrated in this stage.',
    historyTitle: 'Immutable assessment history',
    historyHelp:
        'Each snapshot records what the system assessed at that moment. Historical assessments are read-only and are never rewritten when current business data changes.',
    noHistory: 'No assessment snapshot has been recorded yet.',
    assessedAreas: 'Assessed areas',
    gaps: 'Evidence gaps',
    futureAreas: 'Future areas',
    blockerCount: 'Blockers',
    riskCount: 'Risks',
    strengthCount: 'Strengths',
    actionCount: 'Actions',
    historyReadOnly: 'Read-only historical snapshot',
    viewSummary: 'Assessment summary',
    recordAssessment: 'Record current assessment',
    recording: 'Recording assessment…',
    recordHelp:
        'This creates a new immutable assessment snapshot. It does not approve, sign or make anything Effective.',
    recordError:
        'The assessment snapshot could not be recorded. Your current business data has not been changed.',
    statusNotStarted: 'Evidence not started',
    statusBuilding: 'Evidence building',
    statusCoreReady: 'Core evidence ready',
    confidenceLow: 'Low',
    confidenceMedium: 'Medium',
    confidenceHigh: 'High',
    qualityLimited: 'Limited',
    qualityTraceable: 'Traceable',
    qualityDocumented: 'Documented',
    dimensionDemand: 'Market / Demand',
    dimensionEconomics: 'Unit Economics / Financial Viability',
    dimensionModel: 'Business Model / Competition',
    dimensionScalability: 'Scalability',
    dimensionCapital: 'Capital / Funding Readiness',
    dimensionPartner: 'Partner Alignment',
    dimensionOperations: 'Operations Readiness',
    dimensionLegalRisk: 'Legal / Risk Readiness',
    dimensionSales: 'Sales Readiness',
    positiveDemand:
        'Customer validation supports the current demand assumption.',
    positiveProfit:
        'Current unit economics project a positive monthly operating result.',
    positiveMargin:
        'Contribution margin is positive and break-even can be calculated.',
    positiveModel:
        'Core Business Model and competition evidence are documented.',
    positiveScale:
        'Scalability strategy and known constraints are documented.',
    riskLoss:
        'Current economics project an operating loss at the recorded assumptions.',
    riskBreakEven:
        'Current economics are around operating break-even with little margin for error.',
    blockerMargin:
        'Direct cost is not below selling price, so each additional unit does not create positive contribution.',
    actionMargin:
        'Change price and/or direct variable cost until contribution per unit is greater than zero.',
    actionLoss:
        'Review price, sales volume, direct cost and fixed cost until the projected monthly operating result is at least break-even.',
    actionPositiveProfit:
        'Improve the operating assumptions until the projected monthly operating result is positive.',
    actionValidateAssumption:
        'Validate at least one important demand assumption with real customer evidence.',
    actionCompleteValidation:
        'Complete at least one customer validation activity.',
    actionEconomics:
        'Complete valid selling price, variable cost and fixed-cost inputs.',
    actionModel:
        'Complete the missing core Business Model evidence.',
    actionScale:
        'Document the scalability strategy and the main constraints.',
    dependencyCapital:
        'Complete the future Capital / Funding Readiness step before final feasibility can use capital evidence.',
    dependencyPartner:
        'Partner Alignment will become available when the protected Partner Dynamics dependency is integrated.',
    dependencyOperations:
        'Operations Readiness will become available after the relevant operating truth is completed.',
    dependencyLegalRisk:
        'Legal / Risk Readiness will become available after the relevant downstream risk and legal truth is completed.',
    dependencySales:
        'Sales Readiness will become available when the relevant sales-channel truth is available.',
    evidenceGeneric:
        'Additional evidence is required before this area can be assessed.',
    dependencyGeneric:
        'This area depends on a later PBR module and is not being scored yet.',
    assessedGeneric:
        'This area has enough current evidence for a deterministic assessment.',
    blockerGeneric:
        'The current canonical evidence supports an objective blocker in this area.',
};

const my = {
    ...en,
    eyebrow: 'Deep Feasibility လမ်းညွှန်',
    title: 'ဒီလုပ်ငန်းကို စဖို့ အခုဘယ်လောက်အဆင်သင့်ဖြစ်နေပြီလဲ',
    subtitle:
        'PBR က အရင်ဖြည့်ထားတဲ့ data ကိုပဲ ပြန်သုံးပြီး တကယ်ကောင်းနေတဲ့အချက်၊ တကယ်ပြင်ရမယ့်အချက်နဲ့ မရှိသေးတဲ့ evidence ကို သီးခြားပြပါတယ်။',
    progress: 'Deep Feasibility လမ်းညွှန်အဆင့်များ',
    overview: 'အနှစ်ချုပ်',
    evidence: 'PBR သိထားတာ',
    dimensions: 'စစ်ဆေးထားတဲ့အပိုင်းများ',
    actions: 'နောက်ဘာလုပ်ရမလဲ',
    readiness: 'GO စစ်ဆေးမတိုင်မီ',
    history: 'Assessment မှတ်တမ်း',
    currentState: 'လက်ရှိ assessment',
    currentStateHelp:
        'ဒါက လက်ရှိ PBR evidence ပေါ်မူတည်တဲ့ decision support ပဲဖြစ်ပါတယ်။ Approval၊ ownership truth၊ valuation truth သို့မဟုတ် အောင်မြင်မယ်ဆိုတဲ့ အာမခံချက် မဟုတ်ပါ။',
    readinessLabel: 'Assessment အဆင်သင့်အခြေအနေ',
    confidence: 'ယုံကြည်နိုင်မှု',
    evidenceQuality: 'Evidence အရည်အသွေး',
    recommendation: 'နောက်ဆုံး recommendation',
    recommendationPending: 'မထုတ်နိုင်သေးပါ',
    recommendationPendingHelp:
        'အချို့အပိုင်းတွေကို PBR က စစ်ဆေးနိုင်နေပြီဖြစ်ပေမယ့် လိုအပ်တဲ့ evidence နဲ့ နောက်ပိုင်း readiness အပိုင်းတွေ မပြည့်သေးပါ။ ဒါကြောင့် GO / HOLD / NO-GO ကို အတုထုတ်မထားပါ။',
    knownTitle: 'PBR က သိပြီးသားအချက်များ',
    knownHelp:
        'ဒီအချက်တွေက Business Model၊ Customer Validation နဲ့ Economics မှတ်တမ်းတွေကနေ ပြန်သုံးထားတာပါ။ ဒီနေရာမှာ ပြန်ဖြည့်စရာမလိုပါ။',
    known: 'ရှိပြီးသား',
    needsEvidence: 'Evidence ထပ်လိုသေး',
    reviewBusinessModel: 'Business Model ပြန်စစ်မယ်',
    completeValidation: 'Customer Validation ပြီးအောင်လုပ်မယ်',
    dimensionsTitle: 'Feasibility စစ်ဆေးတဲ့အပိုင်းများ',
    dimensionsHelp:
        'အခုစစ်ဆေးနိုင်တာနဲ့ မစစ်ဆေးနိုင်သေးတာကို ခွဲပြထားပါတယ်။ Evidence မလုံလောက်တာနဲ့ နောက်ပိုင်း dependency ကို အမှတ်နိမ့်သလို မတွက်ပါ။',
    assessed: 'စစ်ဆေးပြီး',
    insufficient: 'Evidence ထပ်လို',
    unavailable: 'နောက်ပိုင်းရမည်',
    blocker: 'တားဆီးချက်',
    strengthsTitle: 'ကောင်းနေတဲ့အချက်များ',
    strengthsHelp:
        'Deterministic assessment က အထောက်အထားရှိတယ်လို့ သတ်မှတ်ထားတဲ့ positive finding တွေပဲ ဒီမှာပြပါတယ်။',
    noStrengths: 'လက်ရှိ evidence နဲ့ အတည်ပြုထားတဲ့ strength မရှိသေးပါ။',
    concernsTitle: 'Risk၊ blocker နဲ့ readiness gaps',
    concernsHelp:
        'Objective blocker၊ risk၊ evidence gap နဲ့ future dependency ကို တစ်မျိုးစီခွဲပြထားပါတယ်။',
    noRisks: 'လက်ရှိ deterministic risk မတွေ့ရသေးပါ။',
    noBlockers: 'လက်ရှိ objective blocker မတွေ့ရသေးပါ။',
    noEvidenceGaps: 'လက်ရှိ core evidence gap မတွေ့ရသေးပါ။',
    noDependencyGaps: 'လက်ရှိ future dependency gap မရှိပါ။',
    actionsTitle: 'လုပ်ရမယ့်အချက်များ',
    actionsHelp:
        'Action တစ်ခုချင်းစီက feasibility area တစ်ခုနဲ့ ချိတ်ထားပါတယ်။ ဒီနေရာမှာ form ထပ်မလုပ်ဘဲ မူရင်း PBR source ကို ပြန်သွားစေပါတယ်။',
    noActions: 'လက်ရှိ required action မရှိသေးပါ။',
    later: 'ဒီအပိုင်းက PBR journey နောက်ပိုင်းမှာ ရလာပါမယ်။',
    goTitle: 'GO ကို စစ်ဆေးနိုင်ဖို့ ဘာတွေပြောင်းရမလဲ',
    goHelp:
        'ဒါတွေက blocker ဖြေရှင်းရန်၊ evidence ဖြည့်ရန် သို့မဟုတ် future dependency ရလာရန် လိုအပ်ချက်တွေပါ။ အကုန်ပြီးသွားလည်း လုပ်ငန်းအောင်မြင်မယ် သို့မဟုတ် GO သေချာမယ်လို့ မဆိုလိုပါ။',
    noGoRequirements:
        'ထပ်လိုတဲ့ requirement မပြထားပေမယ့် ဒီအဆင့်မှာ final recommendation thresholds ကို မသတ်မှတ်ရသေးပါ။',
    historyTitle: 'မပြောင်းနိုင်သော assessment history',
    historyHelp:
        'Snapshot တစ်ခုချင်းစီက အဲဒီအချိန် system သိထားတာနဲ့ assessment ရလဒ်ကို မှတ်တမ်းတင်ပါတယ်။ နောက်ပိုင်း data ပြောင်းလည်း အရင် snapshot ကို ပြန်မရေးပါ။',
    noHistory: 'Assessment snapshot မမှတ်တမ်းတင်ရသေးပါ။',
    assessedAreas: 'စစ်ဆေးပြီးအပိုင်း',
    gaps: 'Evidence gaps',
    futureAreas: 'နောက်ပိုင်းအပိုင်း',
    blockerCount: 'Blockers',
    historyReadOnly: 'ဖတ်ရန်သာ historical snapshot',
    viewSummary: 'အနှစ်ချုပ်ကြည့်မယ်',
    recordAssessment: 'လက်ရှိ assessment ကို မှတ်တမ်းတင်မယ်',
    recording: 'Assessment မှတ်တမ်းတင်နေသည်…',
    recordHelp:
        'ဒါက immutable assessment snapshot အသစ်တစ်ခု ဖန်တီးတာပဲဖြစ်ပြီး Approval၊ Signature သို့မဟုတ် Effective truth မဖြစ်စေပါ။',
    recordError:
        'Assessment snapshot ကို မမှတ်တမ်းတင်နိုင်သေးပါ။ လက်ရှိ business data ကိုတော့ မပြောင်းထားပါ။',
    statusNotStarted: 'Evidence မစရသေး',
    statusBuilding: 'Evidence တည်ဆောက်နေ',
    statusCoreReady: 'Core evidence အဆင်သင့်',
    confidenceLow: 'နည်း',
    confidenceMedium: 'အလယ်အလတ်',
    confidenceHigh: 'မြင့်',
    qualityLimited: 'အကန့်အသတ်ရှိ',
    qualityTraceable: 'ပြန်စစ်နိုင်',
    qualityDocumented: 'Documented',
    positiveDemand: 'Customer validation က လက်ရှိ demand assumption ကို ထောက်ပံ့နေပါတယ်။',
    positiveProfit: 'လက်ရှိ unit economics အရ monthly operating result အပေါင်းဖြစ်ပါတယ်။',
    positiveMargin: 'Contribution margin အပေါင်းဖြစ်ပြီး break-even ကိုတွက်နိုင်ပါတယ်။',
    positiveModel: 'Core Business Model နဲ့ competition evidence ကို မှတ်တမ်းတင်ထားပါတယ်။',
    positiveScale: 'Scalability strategy နဲ့ အဓိက constraints ကို မှတ်တမ်းတင်ထားပါတယ်။',
    riskLoss: 'လက်ရှိ assumptions အရ operating loss ဖြစ်နိုင်တယ်လို့ economics ကပြပါတယ်။',
    riskBreakEven: 'လက်ရှိ economics က operating break-even နားမှာရှိပြီး အမှားခံနိုင်စွမ်းနည်းပါတယ်။',
    blockerMargin: 'Direct cost က selling price ထက်မနိမ့်သေးလို့ unit တစ်ခုထပ်ရောင်းတိုင်း positive contribution မရသေးပါ။',
    actionMargin: 'Contribution per unit သုညထက်ကြီးလာအောင် price နဲ့/သို့မဟုတ် direct variable cost ကိုပြင်ပါ။',
    actionLoss: 'Projected monthly operating result က အနည်းဆုံး break-even ရောက်အောင် price၊ sales volume၊ direct cost နဲ့ fixed cost ကိုပြန်စစ်ပါ။',
    actionPositiveProfit: 'Projected monthly operating result အပေါင်းဖြစ်လာအောင် operating assumptions ကိုပြင်ပါ။',
    actionValidateAssumption: 'အရေးကြီးတဲ့ demand assumption အနည်းဆုံးတစ်ခုကို customer evidence နဲ့ validate လုပ်ပါ။',
    actionCompleteValidation: 'Customer validation activity အနည်းဆုံးတစ်ခုကို complete လုပ်ပါ။',
    actionEconomics: 'Selling price၊ variable cost နဲ့ fixed-cost inputs မှန်ကန်အောင် ဖြည့်ပါ။',
    actionModel: 'မပြည့်သေးတဲ့ core Business Model evidence ကို ဖြည့်ပါ။',
    actionScale: 'Scalability strategy နဲ့ အဓိက constraints ကို မှတ်တမ်းတင်ပါ။',
    dependencyCapital: 'နောက်ဆုံး feasibility မှာ capital evidence သုံးနိုင်ဖို့ Capital / Funding Readiness အပိုင်းကို နောက်ပိုင်းမှာ ပြီးအောင်လုပ်ရပါမယ်။',
    dependencyPartner: 'Protected Partner Dynamics dependency ကို integrate လုပ်တဲ့အခါ Partner Alignment ရလာပါမယ်။',
    dependencyOperations: 'သက်ဆိုင်ရာ operating truth ပြီးတဲ့အခါ Operations Readiness ရလာပါမယ်။',
    dependencyLegalRisk: 'နောက်ပိုင်း risk နဲ့ legal truth ပြီးတဲ့အခါ Legal / Risk Readiness ရလာပါမယ်။',
    dependencySales: 'သက်ဆိုင်ရာ sales-channel truth ရလာတဲ့အခါ Sales Readiness ရလာပါမယ်။',
    evidenceGeneric: 'ဒီအပိုင်းကို စစ်ဆေးနိုင်ဖို့ evidence ထပ်လိုသေးပါတယ်။',
    dependencyGeneric: 'ဒီအပိုင်းက PBR နောက်ပိုင်း module ကို မူတည်ပြီး အခု score မပေးသေးပါ။',
    assessedGeneric: 'လက်ရှိ evidence က deterministic assessment လုပ်နိုင်အောင် လုံလောက်ပါတယ်။',
    blockerGeneric: 'လက်ရှိ canonical evidence အရ ဒီအပိုင်းမှာ objective blocker ရှိပါတယ်။',
};

const mixed = {
    ...en,
    title: 'ဒီ Business ကို အခု start လုပ်ဖို့ ဘယ်လောက် ready ဖြစ်နေပြီလဲ',
    subtitle:
        'PBR က ရှိပြီးသား data ကို reuse လုပ်ပြီး strength, risk, blocker နဲ့ missing evidence ကို သီးခြားရှင်းပြပါတယ်။',
    currentStateHelp:
        'ဒါက current PBR evidence အပေါ် decision support ဖြစ်ပြီး Approval, Ownership Truth, Valuation Truth သို့မဟုတ် success guarantee မဟုတ်ပါ။',
    recommendationPendingHelp:
        'Core areas တချို့ကို assess လုပ်နိုင်ပေမယ့် evidence/dependency တချို့ မပြည့်သေးလို့ GO / HOLD / NO-GO ကို အတုမထုတ်ထားပါ။',
    knownHelp:
        'Business Model, Customer Validation နဲ့ Economics data ကို reuse လုပ်ထားတာဖြစ်ပြီး ဒီမှာ ပြန်ဖြည့်စရာမလိုပါ။',
    dimensionsHelp:
        'Missing evidence နဲ့ future dependency ကို bad score အဖြစ် မပြပါ။',
    actionsHelp:
        'Action တစ်ခုချင်းစီက source dimension နဲ့ traceable ဖြစ်ပြီး data-entry ကို authoritative PBR page မှာပဲလုပ်ပါတယ်။',
    goHelp:
        'ဒါတွေက blocker clear လုပ်ဖို့၊ evidence ဖြည့်ဖို့ သို့မဟုတ် future dependency ရဖို့လိုတာတွေပါ။ ပြီးတာနဲ့ success/GO ကို guarantee မလုပ်ပါ။',
    historyHelp:
        'Snapshot အဟောင်းကို data ပြောင်းတိုင်း rewrite မလုပ်ပါ။ Historical run က read-only ဖြစ်ပါတယ်။',
    recordHelp:
        'Immutable snapshot အသစ်ဖန်တီးတာပဲဖြစ်ပြီး Approval, Signature, Effective truth မဟုတ်ပါ။',
    blockerMargin: my.blockerMargin,
    actionMargin: my.actionMargin,
    actionLoss: my.actionLoss,
    actionPositiveProfit: my.actionPositiveProfit,
    actionValidateAssumption: my.actionValidateAssumption,
    actionCompleteValidation: my.actionCompleteValidation,
    actionEconomics: my.actionEconomics,
    actionModel: my.actionModel,
    actionScale: my.actionScale,
    dependencyCapital: my.dependencyCapital,
    dependencyPartner: my.dependencyPartner,
    dependencyOperations: my.dependencyOperations,
    dependencyLegalRisk: my.dependencyLegalRisk,
    dependencySales: my.dependencySales,
};

const c = computed(() =>
    uiLanguageMode.value === 'my'
        ? my
        : uiLanguageMode.value === 'mixed'
          ? mixed
          : en,
);

const focus = ref<Focus>('overview');
const recording = ref(false);
const recordError = ref(false);
const selectedHistory = ref(0);

const assessment = computed<GenericRow>(
    () => props.foundation.assessment ?? {},
);
const findings = computed<GenericRow>(
    () => props.foundation.findings ?? {},
);
const dimensions = computed<GenericRow[]>(
    () => assessment.value.dimensions ?? [],
);
const coverage = computed<GenericRow>(
    () => props.foundation.coverage ?? {},
);
const strengths = computed<GenericRow[]>(
    () => findings.value.strengths ?? [],
);
const risks = computed<GenericRow[]>(
    () => findings.value.risks ?? [],
);
const blockers = computed<GenericRow[]>(
    () => findings.value.blockers ?? [],
);
const readinessGaps = computed<GenericRow[]>(
    () => findings.value.readinessGaps ?? [],
);
const requiredActions = computed<GenericRow[]>(
    () => findings.value.requiredActions ?? [],
);
const goRequirements = computed<GenericRow[]>(
    () => findings.value.goReadiness?.requirements ?? [],
);

const evidenceGaps = computed(() =>
    readinessGaps.value.filter((gap) => gap.gapType === 'evidence'),
);
const dependencyGaps = computed(() =>
    readinessGaps.value.filter((gap) => gap.gapType === 'dependency'),
);

const steps = computed(() => {
    const recorded = {
        overview: true,
        evidence: Object.values(coverage.value).some(Boolean),
        dimensions: dimensions.value.length > 0,
        actions: requiredActions.value.length > 0,
        readiness: goRequirements.value.length > 0,
        history: props.history.length > 0,
    } satisfies Record<Focus, boolean>;

    const labels: Record<Focus, string> = {
        overview: c.value.overview,
        evidence: c.value.evidence,
        dimensions: c.value.dimensions,
        actions: c.value.actions,
        readiness: c.value.readiness,
        history: c.value.history,
    };

    return (Object.keys(labels) as Focus[]).map((key) => ({
        key,
        label: labels[key],
        state:
            focus.value === key
                ? ('current' as const)
                : recorded[key]
                  ? ('recorded' as const)
                  : ('available' as const),
    }));
});

const statusLabel = computed(() => {
    switch (props.foundation.status) {
        case 'core_evidence_ready':
            return c.value.statusCoreReady;
        case 'building':
            return c.value.statusBuilding;
        default:
            return c.value.statusNotStarted;
    }
});

const confidenceLabel = computed(() => {
    switch (props.foundation.confidence?.level) {
        case 'medium':
            return c.value.confidenceMedium;
        case 'high':
            return c.value.confidenceHigh;
        default:
            return c.value.confidenceLow;
    }
});

const evidenceQualityLabel = computed(() => {
    switch (props.foundation.evidenceQuality?.level) {
        case 'traceable':
            return c.value.qualityTraceable;
        case 'documented':
            return c.value.qualityDocumented;
        default:
            return c.value.qualityLimited;
    }
});

const dimensionLabel = (key: string): string => {
    const labels: Record<string, string> = {
        market_demand: c.value.dimensionDemand,
        unit_economics: c.value.dimensionEconomics,
        business_model_competition: c.value.dimensionModel,
        scalability: c.value.dimensionScalability,
        capital: c.value.dimensionCapital,
        partner_alignment: c.value.dimensionPartner,
        operations_readiness: c.value.dimensionOperations,
        legal_risk: c.value.dimensionLegalRisk,
        sales_readiness: c.value.dimensionSales,
    };

    return labels[key] ?? c.value.dimensions;
};

const stateLabel = (state: string): string => {
    switch (state) {
        case 'assessed':
            return c.value.assessed;
        case 'insufficient_evidence':
            return c.value.insufficient;
        case 'unavailable_dependency':
            return c.value.unavailable;
        case 'blocker':
            return c.value.blocker;
        default:
            return c.value.insufficient;
    }
};

const stateClasses = (state: string): string => {
    switch (state) {
        case 'assessed':
            return 'border-[#b9dcc7] bg-[#eef8f1] text-[#12663d]';
        case 'blocker':
            return 'border-[#e4b9b2] bg-[#fff1ef] text-[#9e3325]';
        case 'insufficient_evidence':
            return 'border-[#ead7a8] bg-[#fff9e9] text-[#7b6228]';
        default:
            return 'border-[#d9e1e4] bg-[#f5f7f8] text-[#637078]';
    }
};

function dependencyText(dependency: string): string {
    const values: Record<string, string> = {
        capital: c.value.dependencyCapital,
        partner_dynamics: c.value.dependencyPartner,
        operations: c.value.dependencyOperations,
        legal_risk: c.value.dependencyLegalRisk,
        sales_channels: c.value.dependencySales,
    };

    return values[dependency] ?? c.value.dependencyGeneric;
}

const dimensionMessage = (dimension: GenericRow): string => {
    switch (dimension.resultCode) {
        case 'validated_demand_supported':
            return c.value.positiveDemand;
        case 'projected_operating_profit':
            return c.value.positiveProfit;
        case 'positive_unit_margin_break_even_available':
            return c.value.positiveMargin;
        case 'projected_operating_loss':
            return c.value.riskLoss;
        case 'projected_operating_break_even':
            return c.value.riskBreakEven;
        case 'core_model_and_competition_documented':
            return c.value.positiveModel;
        case 'scalability_strategy_and_constraints_documented':
            return c.value.positiveScale;
        case 'non_positive_contribution_margin':
            return c.value.blockerMargin;
        case 'dependency_not_integrated':
            return dependencyText(String(dimension.dependency ?? ''));
        default:
            return dimension.state === 'assessed'
                ? c.value.assessedGeneric
                : dimension.state === 'blocker'
                  ? c.value.blockerGeneric
                  : dimension.state === 'unavailable_dependency'
                    ? c.value.dependencyGeneric
                    : c.value.evidenceGeneric;
    }
};

const strengthText = (code: string): string => {
    const values: Record<string, string> = {
        'strength.validated_demand_supported': c.value.positiveDemand,
        'strength.projected_operating_profit': c.value.positiveProfit,
        'strength.positive_unit_margin_break_even_available': c.value.positiveMargin,
        'strength.core_model_competition_documented': c.value.positiveModel,
        'strength.scalability_documented': c.value.positiveScale,
    };

    return values[code] ?? c.value.assessedGeneric;
};

const riskText = (code: string): string => {
    const values: Record<string, string> = {
        'risk.projected_operating_loss': c.value.riskLoss,
        'risk.projected_operating_break_even': c.value.riskBreakEven,
    };

    return values[code] ?? c.value.evidenceGeneric;
};

const blockerText = (code: string): string =>
    code === 'blocker.non_positive_contribution_margin'
        ? c.value.blockerMargin
        : c.value.blockerGeneric;

const actionText = (action: GenericRow): string => {
    const values: Record<string, string> = {
        'action.restore_positive_contribution_margin': c.value.actionMargin,
        'action.resolve_projected_operating_loss': c.value.actionLoss,
        'action.create_positive_operating_margin': c.value.actionPositiveProfit,
        'action.obtain_validated_demand_assumption': c.value.actionValidateAssumption,
        'action.complete_customer_validation': c.value.actionCompleteValidation,
        'action.complete_unit_economics_inputs': c.value.actionEconomics,
        'action.complete_business_model_evidence': c.value.actionModel,
        'action.complete_scalability_evidence': c.value.actionScale,
        'action.make_capital_readiness_available': c.value.dependencyCapital,
        'action.make_partner_alignment_available': c.value.dependencyPartner,
        'action.make_operations_readiness_available': c.value.dependencyOperations,
        'action.make_legal_risk_readiness_available': c.value.dependencyLegalRisk,
        'action.make_sales_readiness_available': c.value.dependencySales,
    };

    return (
        values[String(action.code)] ??
        (action.actionType === 'dependency'
            ? c.value.dependencyGeneric
            : c.value.evidenceGeneric)
    );
};

const actionTarget = (
    action: GenericRow,
): 'business-model' | 'demand' | null => {
    if (action.dimension === 'market_demand') {
        return 'demand';
    }

    if (
        action.dimension === 'unit_economics' ||
        action.dimension === 'business_model_competition' ||
        action.dimension === 'scalability'
    ) {
        return 'business-model';
    }

    return null;
};

const goRequirementText = (requirement: GenericRow): string =>
    actionText({
        code: String(requirement.code ?? '').replace(
            'go_requirement.',
            'action.',
        ),
        dimension: requirement.dimension,
        actionType: requirement.requirementType,
    });

const evidenceCards = computed(() => [
    {
        key: 'businessModel',
        label: c.value.businessModel,
        available: Boolean(coverage.value.businessModel),
        target: 'business-model' as const,
    },
    {
        key: 'demandValidated',
        label: c.value.demand,
        available: Boolean(coverage.value.demandValidated),
        target: 'demand' as const,
    },
    {
        key: 'unitEconomicsReady',
        label: c.value.economics,
        available: Boolean(coverage.value.unitEconomicsReady),
        target: 'business-model' as const,
    },
    {
        key: 'competitionAvailable',
        label: c.value.competition,
        available: Boolean(coverage.value.competitionAvailable),
        target: 'business-model' as const,
    },
    {
        key: 'scalabilityAvailable',
        label: c.value.scalability,
        available: Boolean(coverage.value.scalabilityAvailable),
        target: 'business-model' as const,
    },
]);

const openTarget = (target: 'business-model' | 'demand') => {
    if (target === 'demand') {
        emit('openDemand');
        return;
    }

    emit('openBusinessModel');
};

const recordAssessment = () => {
    if (!props.canManage || recording.value) {
        return;
    }

    recordError.value = false;

    router.post(
        '/formation/new/deep-feasibility/assessments',
        {},
        {
            preserveScroll: true,
            onStart: () => {
                recording.value = true;
            },
            onSuccess: () => {
                focus.value = 'history';
                selectedHistory.value = 0;
            },
            onError: () => {
                recordError.value = true;
            },
            onFinish: () => {
                recording.value = false;
            },
        },
    );
};

const formatTime = (value: string | null): string => {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return new Intl.DateTimeFormat(
        uiLanguageMode.value === 'my' ? 'my-MM' : 'en-GB',
        {
            dateStyle: 'medium',
            timeStyle: 'short',
        },
    ).format(date);
};
</script>

<template>
    <section
        class="space-y-5"
        data-testid="deep-feasibility-guided-journey"
    >
        <header
            class="rounded-[24px] border border-[#d4e2d7] bg-[linear-gradient(135deg,#f7fbf8,#fffdf7)] p-5 shadow-[0_16px_36px_rgb(16_35_26_/_6%)] sm:p-6"
        >
            <p
                class="pbr-safe-copy text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]"
            >
                {{ c.eyebrow }}
            </p>
            <h2
                class="pbr-safe-copy mt-2 max-w-4xl text-2xl font-black leading-tight tracking-[-0.025em] text-[var(--pbr-ink)]"
            >
                {{ c.title }}
            </h2>
            <p
                class="pbr-safe-copy mt-2 max-w-4xl text-sm leading-6 text-[var(--pbr-muted)]"
            >
                {{ c.subtitle }}
            </p>
        </header>

        <GuidedJourneyStepper
            :steps="steps"
            :label="c.progress"
            @select="focus = $event as Focus"
        />

        <ProgressiveReveal :visible="focus === 'overview'">
            <PbrFormSection
                :title="c.currentState"
                :instruction="c.currentStateHelp"
                numbered="1"
            >
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <article class="rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                        <p class="pbr-safe-copy text-xs font-bold text-[var(--pbr-muted)]">{{ c.readinessLabel }}</p>
                        <p class="pbr-safe-copy mt-2 text-base font-black text-[var(--pbr-ink)]">{{ statusLabel }}</p>
                    </article>
                    <article class="rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                        <p class="pbr-safe-copy text-xs font-bold text-[var(--pbr-muted)]">{{ c.confidence }}</p>
                        <p class="pbr-safe-copy mt-2 text-base font-black text-[var(--pbr-ink)]">{{ confidenceLabel }}</p>
                    </article>
                    <article class="rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                        <p class="pbr-safe-copy text-xs font-bold text-[var(--pbr-muted)]">{{ c.evidenceQuality }}</p>
                        <p class="pbr-safe-copy mt-2 text-base font-black text-[var(--pbr-ink)]">{{ evidenceQualityLabel }}</p>
                    </article>
                    <article class="rounded-2xl border border-[#e5d7b6] bg-[#fffaf0] p-4">
                        <p class="pbr-safe-copy text-xs font-bold text-[#826c38]">{{ c.recommendation }}</p>
                        <p class="pbr-safe-copy mt-2 text-base font-black text-[#66501f]">{{ c.recommendationPending }}</p>
                    </article>
                </div>
                <p class="pbr-safe-copy rounded-2xl border border-[#e5d7b6] bg-[#fffaf0] px-4 py-3 text-sm leading-6 text-[#705b2d]">
                    {{ c.recommendationPendingHelp }}
                </p>
            </PbrFormSection>
        </ProgressiveReveal>

        <ProgressiveReveal :visible="focus === 'evidence'">
            <PbrFormSection
                :title="c.knownTitle"
                :instruction="c.knownHelp"
                numbered="2"
            >
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <article
                        v-for="card in evidenceCards"
                        :key="card.key"
                        class="flex min-w-0 flex-col rounded-2xl border border-[var(--pbr-line)] bg-white p-4"
                    >
                        <div class="flex min-w-0 items-start justify-between gap-3">
                            <h3 class="pbr-safe-copy min-w-0 text-sm font-black text-[var(--pbr-ink)]">{{ card.label }}</h3>
                            <span
                                class="shrink-0 rounded-full border px-2.5 py-1 text-[11px] font-black"
                                :class="card.available
                                    ? 'border-[#b9dcc7] bg-[#eef8f1] text-[#12663d]'
                                    : 'border-[#ead7a8] bg-[#fff9e9] text-[#7b6228]'"
                            >
                                {{ card.available ? c.known : c.needsEvidence }}
                            </span>
                        </div>
                        <div class="mt-auto pt-4">
                            <PbrButton
                                v-if="!card.available"
                                variant="ghost"
                                @click="openTarget(card.target)"
                            >
                                {{ card.target === 'demand' ? c.completeValidation : c.reviewBusinessModel }}
                            </PbrButton>
                        </div>
                    </article>
                </div>
            </PbrFormSection>
        </ProgressiveReveal>

        <ProgressiveReveal :visible="focus === 'dimensions'">
            <div class="space-y-5">
                <PbrFormSection
                    :title="c.dimensionsTitle"
                    :instruction="c.dimensionsHelp"
                    numbered="3"
                >
                    <div class="grid gap-3 lg:grid-cols-2">
                        <article
                            v-for="dimension in dimensions"
                            :key="dimension.key"
                            class="min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4"
                        >
                            <div class="flex min-w-0 flex-wrap items-start justify-between gap-3">
                                <h3 class="pbr-safe-copy min-w-0 text-sm font-black leading-6 text-[var(--pbr-ink)]">
                                    {{ dimensionLabel(dimension.key) }}
                                </h3>
                                <span
                                    class="shrink-0 rounded-full border px-2.5 py-1 text-[11px] font-black"
                                    :class="stateClasses(dimension.state)"
                                >
                                    {{ stateLabel(dimension.state) }}
                                </span>
                            </div>
                            <p class="pbr-safe-copy mt-3 text-sm leading-6 text-[var(--pbr-muted)]">
                                {{ dimensionMessage(dimension) }}
                            </p>
                        </article>
                    </div>
                </PbrFormSection>

                <PbrFormSection
                    :title="c.strengthsTitle"
                    :instruction="c.strengthsHelp"
                >
                    <div v-if="strengths.length" class="grid gap-3 lg:grid-cols-2">
                        <article
                            v-for="strength in strengths"
                            :key="strength.code"
                            class="rounded-2xl border border-[#b9dcc7] bg-[#f1f9f3] p-4"
                        >
                            <p class="pbr-safe-copy text-sm font-bold leading-6 text-[#155f3c]">{{ strengthText(strength.code) }}</p>
                            <p class="pbr-safe-copy mt-2 text-xs font-semibold text-[#5b7465]">{{ dimensionLabel(strength.dimension) }}</p>
                        </article>
                    </div>
                    <p v-else class="pbr-safe-copy rounded-2xl border border-[var(--pbr-line)] bg-[#f7f9f7] p-4 text-sm leading-6 text-[var(--pbr-muted)]">
                        {{ c.noStrengths }}
                    </p>
                </PbrFormSection>

                <PbrFormSection
                    :title="c.concernsTitle"
                    :instruction="c.concernsHelp"
                >
                    <div class="grid gap-4 lg:grid-cols-2">
                        <section class="rounded-2xl border border-[#ead7a8] bg-[#fffaf0] p-4">
                            <h3 class="pbr-safe-copy text-sm font-black text-[#765e27]">{{ c.risks }}</h3>
                            <div v-if="risks.length" class="mt-3 space-y-3">
                                <p v-for="risk in risks" :key="risk.code" class="pbr-safe-copy text-sm leading-6 text-[#705b2d]">
                                    {{ riskText(risk.code) }}
                                </p>
                            </div>
                            <p v-else class="pbr-safe-copy mt-3 text-sm leading-6 text-[#81714d]">{{ c.noRisks }}</p>
                        </section>

                        <section class="rounded-2xl border border-[#e4b9b2] bg-[#fff2f0] p-4">
                            <h3 class="pbr-safe-copy text-sm font-black text-[#962f23]">{{ c.blockers }}</h3>
                            <div v-if="blockers.length" class="mt-3 space-y-3">
                                <p v-for="item in blockers" :key="item.code" class="pbr-safe-copy text-sm font-semibold leading-6 text-[#8d3328]">
                                    {{ blockerText(item.code) }}
                                </p>
                            </div>
                            <p v-else class="pbr-safe-copy mt-3 text-sm leading-6 text-[#8d6c67]">{{ c.noBlockers }}</p>
                        </section>

                        <section class="rounded-2xl border border-[#ead7a8] bg-white p-4">
                            <h3 class="pbr-safe-copy text-sm font-black text-[var(--pbr-ink)]">{{ c.evidenceGaps }}</h3>
                            <div v-if="evidenceGaps.length" class="mt-3 space-y-2">
                                <p
                                    v-for="gap in evidenceGaps"
                                    :key="gap.dimension + '-' + gap.resultCode"
                                    class="pbr-safe-copy text-sm leading-6 text-[var(--pbr-muted)]"
                                >
                                    {{ dimensionLabel(gap.dimension) }} — {{ c.evidenceGeneric }}
                                </p>
                            </div>
                            <p v-else class="pbr-safe-copy mt-3 text-sm leading-6 text-[var(--pbr-muted)]">{{ c.noEvidenceGaps }}</p>
                        </section>

                        <section class="rounded-2xl border border-[#d9e1e4] bg-[#f7f8f9] p-4">
                            <h3 class="pbr-safe-copy text-sm font-black text-[var(--pbr-ink)]">{{ c.dependencyGaps }}</h3>
                            <div v-if="dependencyGaps.length" class="mt-3 space-y-2">
                                <p
                                    v-for="gap in dependencyGaps"
                                    :key="gap.dimension + '-' + gap.dependency"
                                    class="pbr-safe-copy text-sm leading-6 text-[var(--pbr-muted)]"
                                >
                                    {{ dimensionLabel(gap.dimension) }} — {{ dependencyText(gap.dependency) }}
                                </p>
                            </div>
                            <p v-else class="pbr-safe-copy mt-3 text-sm leading-6 text-[var(--pbr-muted)]">{{ c.noDependencyGaps }}</p>
                        </section>
                    </div>
                </PbrFormSection>
            </div>
        </ProgressiveReveal>

        <ProgressiveReveal :visible="focus === 'actions'">
            <PbrFormSection
                :title="c.actionsTitle"
                :instruction="c.actionsHelp"
                numbered="4"
            >
                <div v-if="requiredActions.length" class="space-y-3">
                    <article
                        v-for="action in requiredActions"
                        :key="action.code + '-' + action.dimension"
                        class="flex min-w-0 flex-col gap-3 rounded-2xl border border-[var(--pbr-line)] bg-white p-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="min-w-0">
                            <p class="pbr-safe-copy text-sm font-bold leading-6 text-[var(--pbr-ink)]">{{ actionText(action) }}</p>
                            <p class="pbr-safe-copy mt-1 text-xs font-semibold text-[var(--pbr-muted)]">{{ dimensionLabel(action.dimension) }}</p>
                        </div>
                        <PbrButton
                            v-if="actionTarget(action)"
                            class="shrink-0"
                            variant="secondary"
                            @click="openTarget(actionTarget(action)!)"
                        >
                            {{ actionTarget(action) === 'demand' ? c.completeValidation : c.reviewBusinessModel }}
                        </PbrButton>
                        <span v-else class="pbr-safe-copy text-xs font-semibold leading-5 text-[var(--pbr-muted)]">
                            {{ c.later }}
                        </span>
                    </article>
                </div>
                <p v-else class="pbr-safe-copy rounded-2xl border border-[var(--pbr-line)] bg-[#f7f9f7] p-4 text-sm leading-6 text-[var(--pbr-muted)]">
                    {{ c.noActions }}
                </p>
            </PbrFormSection>
        </ProgressiveReveal>

        <ProgressiveReveal :visible="focus === 'readiness'">
            <PbrFormSection
                :title="c.goTitle"
                :instruction="c.goHelp"
                numbered="5"
            >
                <div v-if="goRequirements.length" class="space-y-3">
                    <article
                        v-for="requirement in goRequirements"
                        :key="requirement.code + '-' + requirement.dimension"
                        class="rounded-2xl border border-[#d9e1e4] bg-[#f8faf9] p-4"
                    >
                        <p class="pbr-safe-copy text-sm font-bold leading-6 text-[var(--pbr-ink)]">{{ goRequirementText(requirement) }}</p>
                        <p class="pbr-safe-copy mt-1 text-xs font-semibold text-[var(--pbr-muted)]">{{ dimensionLabel(requirement.dimension) }}</p>
                    </article>
                </div>
                <p v-else class="pbr-safe-copy rounded-2xl border border-[var(--pbr-line)] bg-[#f7f9f7] p-4 text-sm leading-6 text-[var(--pbr-muted)]">
                    {{ c.noGoRequirements }}
                </p>
                <p class="pbr-safe-copy rounded-2xl border border-[#e5d7b6] bg-[#fffaf0] px-4 py-3 text-sm font-semibold leading-6 text-[#705b2d]">
                    {{ c.goHelp }}
                </p>
            </PbrFormSection>
        </ProgressiveReveal>

        <ProgressiveReveal :visible="focus === 'history'">
            <PbrFormSection
                :title="c.historyTitle"
                :instruction="c.historyHelp"
                numbered="6"
            >
                <div v-if="history.length" class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
                    <div class="space-y-2">
                        <button
                            v-for="(run, index) in history"
                            :key="String(run.createdAt) + '-' + index"
                            type="button"
                            class="w-full min-w-0 rounded-2xl border p-4 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--pbr-green)]"
                            :class="selectedHistory === index
                                ? 'border-[#9fc7ad] bg-[#f0f8f2]'
                                : 'border-[var(--pbr-line)] bg-white hover:bg-[#f8faf8]'"
                            @click="selectedHistory = index"
                        >
                            <div class="flex min-w-0 flex-wrap items-center justify-between gap-2">
                                <span class="pbr-safe-copy text-sm font-black text-[var(--pbr-ink)]">{{ formatTime(run.createdAt) }}</span>
                                <span class="rounded-full border border-[#d9e1e4] bg-[#f7f8f9] px-2.5 py-1 text-[11px] font-black text-[#637078]">{{ c.historyReadOnly }}</span>
                            </div>
                            <p class="pbr-safe-copy mt-2 text-xs leading-5 text-[var(--pbr-muted)]">
                                {{ c.assessedAreas }}: {{ run.assessedDimensions }}
                                · {{ c.blockerCount }}: {{ run.blockers }}
                                · {{ c.gaps }}: {{ run.evidenceGaps }}
                            </p>
                        </button>
                    </div>

                    <article
                        v-if="history[selectedHistory]"
                        class="rounded-2xl border border-[var(--pbr-line)] bg-white p-5"
                    >
                        <p class="pbr-safe-copy text-xs font-black uppercase tracking-[0.12em] text-[var(--pbr-muted)]">{{ c.viewSummary }}</p>
                        <h3 class="pbr-safe-copy mt-2 text-lg font-black text-[var(--pbr-ink)]">{{ formatTime(history[selectedHistory].createdAt) }}</h3>
                        <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                            <div class="rounded-xl bg-[#f7f9f7] p-3">
                                <dt class="pbr-safe-copy text-xs text-[var(--pbr-muted)]">{{ c.assessedAreas }}</dt>
                                <dd class="mt-1 text-lg font-black">{{ history[selectedHistory].assessedDimensions }}</dd>
                            </div>
                            <div class="rounded-xl bg-[#fff9e9] p-3">
                                <dt class="pbr-safe-copy text-xs text-[#826c38]">{{ c.gaps }}</dt>
                                <dd class="mt-1 text-lg font-black text-[#6f5927]">{{ history[selectedHistory].evidenceGaps }}</dd>
                            </div>
                            <div class="rounded-xl bg-[#f5f7f8] p-3">
                                <dt class="pbr-safe-copy text-xs text-[var(--pbr-muted)]">{{ c.futureAreas }}</dt>
                                <dd class="mt-1 text-lg font-black">{{ history[selectedHistory].unavailableDependencies }}</dd>
                            </div>
                            <div class="rounded-xl bg-[#fff1ef] p-3">
                                <dt class="pbr-safe-copy text-xs text-[#8d6c67]">{{ c.blockerCount }}</dt>
                                <dd class="mt-1 text-lg font-black text-[#962f23]">{{ history[selectedHistory].blockers }}</dd>
                            </div>
                            <div class="rounded-xl bg-[#f1f9f3] p-3">
                                <dt class="pbr-safe-copy text-xs text-[#5b7465]">{{ c.strengthCount }}</dt>
                                <dd class="mt-1 text-lg font-black text-[#155f3c]">{{ history[selectedHistory].strengths }}</dd>
                            </div>
                            <div class="rounded-xl bg-[#fffaf0] p-3">
                                <dt class="pbr-safe-copy text-xs text-[#81714d]">{{ c.riskCount }}</dt>
                                <dd class="mt-1 text-lg font-black text-[#705b2d]">{{ history[selectedHistory].risks }}</dd>
                            </div>
                        </dl>
                    </article>
                </div>

                <p v-else class="pbr-safe-copy rounded-2xl border border-[var(--pbr-line)] bg-[#f7f9f7] p-4 text-sm leading-6 text-[var(--pbr-muted)]">
                    {{ c.noHistory }}
                </p>

                <div class="rounded-2xl border border-[#d4e2d7] bg-[#f5faf6] p-4">
                    <p class="pbr-safe-copy text-sm leading-6 text-[var(--pbr-green-dark)]">{{ c.recordHelp }}</p>
                    <p
                        v-if="recordError"
                        class="pbr-safe-copy mt-3 rounded-xl border border-[#e4b9b2] bg-[#fff1ef] px-3 py-2 text-sm leading-6 text-[#962f23]"
                        role="alert"
                    >
                        {{ c.recordError }}
                    </p>
                    <div class="mt-4">
                        <PbrButton
                            v-if="canManage"
                            variant="primary"
                            :busy="recording"
                            :busy-label="c.recording"
                            @click="recordAssessment"
                        >
                            {{ c.recordAssessment }}
                        </PbrButton>
                    </div>
                </div>
            </PbrFormSection>
        </ProgressiveReveal>
    </section>
</template>
