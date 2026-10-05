<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import GuidedJourneyStepper from '../hybrid/GuidedJourneyStepper.vue';
import ProgressiveReveal from '../hybrid/ProgressiveReveal.vue';
import PbrActionBar from '../ui/PbrActionBar.vue';
import PbrButton from '../ui/PbrButton.vue';
import PbrErrorSummary from '../ui/PbrErrorSummary.vue';
import PbrField from '../ui/PbrField.vue';
import PbrFormSection from '../ui/PbrFormSection.vue';
import PbrTextInput from '../ui/PbrTextInput.vue';
import { useI18n } from '../../i18n/useI18n';
import { humanErrorMessages } from '../../support/humanErrors';
import type { BusinessValuationReadModel } from '../../types/businessValuation';

type GenericRow = Record<string, unknown>;
type StepKey = 'sources' | 'facts' | 'assumptions' | 'review' | 'result';

const props = defineProps<{
    businessId: string;
    currency: string;
    financialSnapshots: GenericRow[];
    assets: GenericRow[];
    liabilities: GenericRow[];
    latest: BusinessValuationReadModel | null;
    canManage: boolean;
}>();

const { uiLanguageMode } = useI18n();

const copy = {
    en: {
        eyebrow: 'Guided Business Valuation',
        title: 'Estimate a practical value range for the existing Business',
        subtitle: 'Reuse recorded business facts first, add only missing historical facts and assumptions, then let PBR run only supported methods with enough data.',
        progress: 'Business Valuation guided journey',
        sourceStep: 'Business facts', factsStep: 'Historical facts', assumptionsStep: 'Assumptions', reviewStep: 'Review', resultStep: 'Result',
        sourcesTitle: 'Start with what PBR already knows',
        sourcesHelp: 'These are existing Business records. You do not need to type the same cash, asset or liability records again.',
        snapshot: 'Financial snapshot', noSnapshot: 'No financial snapshot is recorded yet.', assets: 'Business assets', liabilities: 'Business liabilities', recorded: 'recorded',
        next: 'Continue', previous: 'Back',
        factsTitle: 'Which historical earnings facts do you have?',
        factsHelp: 'Only enable facts you can support. Hidden inputs are not submitted and switching a method off does not erase your draft.',
        haveEbitda: 'I have a usable EBITDA figure', ebitdaHelp: 'Operating earnings before interest, tax, depreciation and amortization.',
        ebitdaLabel: 'Historical EBITDA', ebitdaInstruction: 'Use a normalized historical amount for the valuation period.', ebitdaExample: 'Example: 1,200,000',
        haveSde: 'I have owner earnings / SDE', sdeHelp: 'Useful for owner-operated businesses and based on normalized owner earnings.',
        sdeLabel: 'Owner earnings / SDE', sdeInstruction: 'Use a normalized historical owner-earnings amount.', sdeExample: 'Example: 1,450,000',
        haveDcf: 'I want to use a cash-flow based estimate', dcfHelp: 'DCF estimates value from future cash flow and needs a reliable Free Cash Flow starting point.',
        fcfLabel: 'Historical Free Cash Flow', fcfInstruction: 'Use the best supportable annual Free Cash Flow starting point.', fcfExample: 'Example: 900,000',
        debtLabel: 'Interest-bearing debt', debtInstruction: 'Enter debt used by earnings/DCF methods. This stays separate from the full liabilities list.', debtExample: 'Example: 250,000 or 0',
        debtReason: 'An earnings or cash-flow method is selected, so debt is needed for the cash/debt adjustment.',
        assumptionsTitle: 'Add assumptions only for the methods you want to run',
        assumptionsHelp: 'Asset-Based uses recorded assets and liabilities. Advanced assumptions stay hidden unless a selected method needs them.',
        ebitdaMultipleLabel: 'EBITDA multiple', sdeMultipleLabel: 'SDE multiple',
        multipleInstruction: 'Use a supportable market-style multiple. PBR does not invent one automatically.', multipleExample: 'Example: 4.0',
        dcfAssumptions: 'DCF assumptions',
        growthLabel: 'Annual growth assumption (%)', growthInstruction: 'Expected annual Free Cash Flow growth for the 5-year projection.', growthExample: 'Example: 8',
        discountLabel: 'Discount rate (%)', discountInstruction: 'Required return used to discount future cash flow back to today.', discountExample: 'Example: 15',
        terminalLabel: 'Terminal growth (%)', terminalInstruction: 'Long-run growth assumption after the explicit 5-year forecast.', terminalExample: 'Example: 3',
        methodReadiness: 'Method readiness', ready: 'Ready', needsData: 'Needs data', used: 'Used in this result', notUsed: 'Not used',
        ebitdaMethod: 'EBITDA Multiple', ebitdaMethodHelp: 'Operating earnings × a market-style multiple, adjusted for recorded cash and entered debt.',
        sdeMethod: 'Owner Earnings / SDE', sdeMethodHelp: 'Normalized owner earnings × a multiple, adjusted for cash and debt.',
        assetMethod: 'Asset-Based', assetMethodHelp: 'Recorded Business Assets minus recorded Business Liabilities.',
        dcfMethod: 'Discounted Cash Flow', dcfMethodHelp: 'Projects Free Cash Flow for five years and discounts future value back to today.',
        reviewTitle: 'Review before calculating', reviewHelp: 'Historical facts and assumptions stay visibly separate. PBR runs only methods with all required inputs.',
        asOfLabel: 'Valuation date', asOfInstruction: 'Choose the date this estimate should represent.',
        reviewStateLabel: 'Review status', draft: 'Draft', reviewed: 'Reviewed',
        reviewMeaning: 'Reviewed means you reviewed this planning estimate. It is not Governance approval, a signature or official ownership truth.',
        calculate: 'Calculate valuation range', calculating: 'Calculating…',
        localDraft: 'Unsaved guided answers are preserved in this browser session.',
        errorTitle: 'The valuation could not be calculated yet', errorHelp: 'Check the visible fields or add enough source data for at least one supported method.',
        resultTitle: 'Indicative Business Valuation', resultHelp: 'A planning estimate from available evidence and assumptions, not a guaranteed sale price.',
        low: 'Low', base: 'Central estimate', high: 'High', confidence: 'Confidence', evidenceQuality: 'Evidence quality', methodsUsed: 'Methods used',
        warnings: 'Important warnings', assumptionsUsed: 'Major assumptions',
        sourceReview: 'Where did the numbers come from?', sourceReviewHelp: 'Recorded sources and assumptions stay separate so you can see what is fact and what is estimated.',
        linkedEvidence: 'Linked evidence', verifiedEvidence: 'Verified evidence', openVault: 'Open Document Vault',
        vaultHelp: 'Add or verify documents in the Vault, then link authorized Evidence to this Business Valuation from the document screen.',
        advanced: 'Advanced calculation details', formula: 'Calculation basis',
        meaningTitle: 'What this result means',
        meaning: 'It is an indicative estimated range for planning. It is not a guaranteed market value, an independent/certified valuation, a transaction price, Ownership truth or Contribution Valuation.',
        noResult: 'No guided valuation has been calculated yet.', newEstimate: 'Prepare another estimate', currencyNote: 'All amounts use the Business base currency',
    },
    my: {
        eyebrow: 'Guided Business Valuation',
        title: 'ရှိပြီးသား Business ရဲ့ လက်တွေ့ကျတဲ့ တန်ဖိုး Range ကို ခန့်မှန်းပါ',
        subtitle: 'System ထဲမှာရှိပြီးသား Business Facts ကို အရင်ပြန်သုံးပြီး လိုအပ်တဲ့ Historical Facts နဲ့ Assumptions ကိုပဲ ဖြည့်ပါ။ Data လုံလောက်တဲ့ Method တွေကိုပဲ PBR ကတွက်ပေးပါမယ်။',
        progress: 'Business Valuation guided journey',
        sourceStep: 'Business Facts', factsStep: 'Historical Facts', assumptionsStep: 'Assumptions', reviewStep: 'Review', resultStep: 'Result',
        sourcesTitle: 'PBR မှာရှိပြီးသား အချက်အလက်ကနေ စပါ',
        sourcesHelp: 'ဒီအချက်တွေက ရှိပြီးသား Business Records ဖြစ်ပါတယ်။ Cash, Asset, Liability တူညီတဲ့ data ကို ပြန်ရိုက်စရာမလိုပါ။',
        snapshot: 'Financial Snapshot', noSnapshot: 'Financial Snapshot မရှိသေးပါ။', assets: 'Business Assets', liabilities: 'Business Liabilities', recorded: 'ခု မှတ်တမ်းရှိ',
        next: 'ဆက်သွားမည်', previous: 'နောက်ပြန်',
        factsTitle: 'ဘယ် Historical Earnings Facts တွေရှိသလဲ?',
        factsHelp: 'အထောက်အထားရှိတဲ့ Fact ကိုပဲ ဖွင့်ပါ။ Hidden Input တွေကို Submit မလုပ်ဘဲ Method ကိုပိတ်လိုက်လည်း Draft Data မဖျက်ပါ။',
        haveEbitda: 'အသုံးပြုလို့ရတဲ့ EBITDA ရှိတယ်', ebitdaHelp: 'Interest, Tax, Depreciation, Amortization မတိုင်ခင် Operating Earnings ကိုကြည့်တဲ့နည်းပါ။',
        ebitdaLabel: 'Historical EBITDA', ebitdaInstruction: 'Valuation period အတွက် normalized historical amount ကိုထည့်ပါ။', ebitdaExample: 'ဥပမာ - 1,200,000',
        haveSde: 'Owner Earnings / SDE ရှိတယ်', sdeHelp: 'Owner ကိုယ်တိုင်အဓိကလုပ်ကိုင်တဲ့ Business တွေအတွက် normalized owner earnings ကိုကြည့်တာပါ။',
        sdeLabel: 'Owner Earnings / SDE', sdeInstruction: 'Normalized historical owner-earnings amount ကိုထည့်ပါ။', sdeExample: 'ဥပမာ - 1,450,000',
        haveDcf: 'Cash-flow အခြေခံ Estimate ကိုသုံးချင်တယ်', dcfHelp: 'DCF က နောင်ရမယ့် Cash Flow ကိုအခြေခံပြီးတန်ဖိုးခန့်မှန်းတာပါ။',
        fcfLabel: 'Historical Free Cash Flow', fcfInstruction: 'အထောက်အထားပြနိုင်တဲ့ Annual Free Cash Flow Starting Point ကိုထည့်ပါ။', fcfExample: 'ဥပမာ - 900,000',
        debtLabel: 'Interest-bearing Debt', debtInstruction: 'Earnings / DCF methods အတွက် Debt ကိုထည့်ပါ။ Full Liabilities list နဲ့ သီးခြားထားပါတယ်။', debtExample: 'ဥပမာ - 250,000 သို့မဟုတ် 0',
        debtReason: 'Earnings သို့မဟုတ် Cash-flow Method ရွေးထားလို့ Cash/Debt adjustment အတွက် ဒီ Field လိုပါတယ်။',
        assumptionsTitle: 'သုံးမယ့် Method အတွက်လိုတဲ့ Assumption ကိုပဲ ထည့်ပါ',
        assumptionsHelp: 'Asset-Based Method က Recorded Assets နဲ့ Liabilities ကိုပဲသုံးပါတယ်။ Advanced Assumptions တွေကို လိုမှပဲပြပါတယ်။',
        ebitdaMultipleLabel: 'EBITDA Multiple', sdeMultipleLabel: 'SDE Multiple',
        multipleInstruction: 'အထောက်အထားပေးနိုင်တဲ့ Market-style Multiple ကိုသုံးပါ။ PBR က အလိုအလျောက်တီထွင်မပေးပါ။', multipleExample: 'ဥပမာ - 4.0',
        dcfAssumptions: 'DCF Assumptions',
        growthLabel: 'Annual Growth Assumption (%)', growthInstruction: '၅ နှစ် Projection အတွက် Free Cash Flow နှစ်စဉ်တိုးနှုန်း။', growthExample: 'ဥပမာ - 8',
        discountLabel: 'Discount Rate (%)', discountInstruction: 'နောင် Cash Flow ကို ဒီနေ့တန်ဖိုးပြန်တွက်ဖို့ သုံးတဲ့ Required Return။', discountExample: 'ဥပမာ - 15',
        terminalLabel: 'Terminal Growth (%)', terminalInstruction: 'ပထမ ၅ နှစ်ပြီးနောက် Long-run Growth Assumption။', terminalExample: 'ဥပမာ - 3',
        methodReadiness: 'Method အဆင်သင့်ဖြစ်မှု', ready: 'အဆင်သင့်', needsData: 'Data လိုသေး', used: 'ဒီ Result မှာသုံးထားသည်', notUsed: 'မသုံးထားပါ',
        ebitdaMethod: 'EBITDA Multiple', ebitdaMethodHelp: 'Operating Earnings ကို Multiple နဲ့တွက်ပြီး Recorded Cash နဲ့ Entered Debt ကိုညှိပါတယ်။',
        sdeMethod: 'Owner Earnings / SDE', sdeMethodHelp: 'Normalized Owner Earnings ကို Multiple နဲ့တွက်ပြီး Cash/Debt ကိုညှိပါတယ်။',
        assetMethod: 'Asset-Based', assetMethodHelp: 'Recorded Business Assets ထဲက Recorded Business Liabilities ကိုနုတ်ပြီးတွက်ပါတယ်။',
        dcfMethod: 'Discounted Cash Flow', dcfMethodHelp: 'Free Cash Flow ကို ၅ နှစ်ခန့်မှန်းပြီး နောင်တန်ဖိုးကို ဒီနေ့တန်ဖိုးအဖြစ်ပြန်တွက်ပါတယ်။',
        reviewTitle: 'မတွက်ခင် နောက်ဆုံးစစ်ပါ', reviewHelp: 'Historical Facts နဲ့ Assumptions ကို ခွဲပြထားပါတယ်။ Required Inputs ပြည့်တဲ့ Method ကိုပဲ PBR က run ပါမယ်။',
        asOfLabel: 'Valuation Date', asOfInstruction: 'ဒီ Estimate ကို ဘယ်နေ့အခြေအနေအဖြစ် သတ်မှတ်မလဲရွေးပါ။',
        reviewStateLabel: 'Review Status', draft: 'Draft', reviewed: 'Reviewed',
        reviewMeaning: 'Reviewed ဆိုတာ ဒီ Planning Estimate ကို သင်ပြန်စစ်ပြီးပြီဆိုတာသာဖြစ်ပါတယ်။ Governance Approval, Signature သို့မဟုတ် Ownership Truth မဟုတ်ပါ။',
        calculate: 'Valuation Range တွက်မည်', calculating: 'တွက်နေသည်…',
        localDraft: 'မသိမ်းရသေးတဲ့ Guided Answers တွေကို ဒီ Browser Session ထဲမှာ ယာယီထိန်းထားပါတယ်။',
        errorTitle: 'Valuation ကို မတွက်နိုင်သေးပါ', errorHelp: 'မြင်နေရတဲ့ Fields ကိုစစ်ပါ သို့မဟုတ် Supported Method တစ်ခုအတွက် Source Data လုံလောက်အောင်ထည့်ပါ။',
        resultTitle: 'Indicative Business Valuation', resultHelp: 'ဒီ Result က လက်ရှိ Evidence နဲ့ Assumptions အပေါ်အခြေခံတဲ့ Planning Estimate ဖြစ်ပြီး အရောင်းဈေး အာမခံချက်မဟုတ်ပါ။',
        low: 'အနိမ့်', base: 'အလယ်ခန့်မှန်းတန်ဖိုး', high: 'အမြင့်', confidence: 'Confidence', evidenceQuality: 'Evidence Quality', methodsUsed: 'အသုံးပြုထားတဲ့ Methods',
        warnings: 'သတိပြုရန်', assumptionsUsed: 'အဓိက Assumptions',
        sourceReview: 'ဒီ Numbers တွေ ဘယ်ကလာသလဲ?', sourceReviewHelp: 'Recorded Source နဲ့ Assumption ကို PBR က သီးခြားထားလို့ Fact ဘယ်ဟာ၊ Estimate ဘယ်ဟာဆိုတာ ပြန်စစ်နိုင်ပါတယ်။',
        linkedEvidence: 'Linked Evidence', verifiedEvidence: 'Verified Evidence', openVault: 'Document Vault ဖွင့်မည်',
        vaultHelp: 'Vault မှာ Documents ထည့်/Verify လုပ်ပြီး Document Screen ကနေ Authorized Evidence ကို ဒီ Business Valuation နဲ့ link လုပ်နိုင်ပါတယ်။',
        advanced: 'Advanced Calculation Details', formula: 'Calculation Basis',
        meaningTitle: 'ဒီ Result ရဲ့ အဓိပ္ပာယ်',
        meaning: 'Planning အတွက် Indicative Estimated Range သာဖြစ်ပါတယ်။ Guaranteed Market Value, Independent/Certified Valuation, Transaction Price, Ownership Truth သို့မဟုတ် Contribution Valuation မဟုတ်ပါ။',
        noResult: 'Guided Valuation မတွက်ရသေးပါ။', newEstimate: 'Estimate အသစ်ပြင်မည်', currencyNote: 'ငွေပမာဏအားလုံး Business Base Currency ကိုသုံးပါတယ်',
    },
    mixed: {
        eyebrow: 'Guided Business Valuation', title: 'Existing Business ရဲ့ practical value range ကို estimate လုပ်ပါ',
        subtitle: 'Existing PBR source data ကို reuse လုပ်ပြီး missing historical facts နဲ့ assumptions ကိုပဲ ဖြည့်ပါ။ Data လုံလောက်တဲ့ supported methods ကိုပဲ system က run ပါမယ်။',
        progress: 'Business Valuation guided journey',
        sourceStep: 'Business facts', factsStep: 'Historical facts', assumptionsStep: 'Assumptions', reviewStep: 'Review', resultStep: 'Result',
        sourcesTitle: 'PBR မှာရှိပြီးသား facts ကနေစပါ', sourcesHelp: 'Recorded cash, assets, liabilities ကို ပြန်မရိုက်ဘဲ canonical sources ကို reuse လုပ်ပါတယ်။',
        snapshot: 'Financial snapshot', noSnapshot: 'Financial snapshot မရှိသေးပါ။', assets: 'Business assets', liabilities: 'Business liabilities', recorded: 'recorded',
        next: 'Continue', previous: 'Back',
        factsTitle: 'Which historical earnings facts do you have?', factsHelp: 'Support လုပ်နိုင်တဲ့ facts ကိုပဲ enable လုပ်ပါ။ Hidden inputs မ submit ပါ။',
        haveEbitda: 'I have a usable EBITDA figure', ebitdaHelp: 'Operating earnings ကို multiple နဲ့ estimate လုပ်ဖို့သုံးပါတယ်။',
        ebitdaLabel: 'Historical EBITDA', ebitdaInstruction: 'Normalized historical amount ကိုထည့်ပါ။', ebitdaExample: 'Example: 1,200,000',
        haveSde: 'I have owner earnings / SDE', sdeHelp: 'Owner-operated business အတွက် normalized owner earnings ကိုသုံးပါတယ်။',
        sdeLabel: 'Owner earnings / SDE', sdeInstruction: 'Normalized historical owner earnings ကိုထည့်ပါ။', sdeExample: 'Example: 1,450,000',
        haveDcf: 'I want a cash-flow based estimate', dcfHelp: 'DCF က future Free Cash Flow ကို today value ပြန်တွက်ပါတယ်။',
        fcfLabel: 'Historical Free Cash Flow', fcfInstruction: 'Supportable annual FCF starting point ကိုထည့်ပါ။', fcfExample: 'Example: 900,000',
        debtLabel: 'Interest-bearing debt', debtInstruction: 'Earnings/DCF methods အတွက် debt ကိုထည့်ပါ။', debtExample: 'Example: 250,000 or 0', debtReason: 'Selected method က cash/debt adjustment လိုလို့ ဒီ field ပေါ်လာပါတယ်။',
        assumptionsTitle: 'Add only the assumptions your methods need', assumptionsHelp: 'Advanced assumptions ကို relevant method အတွက်ပဲ reveal လုပ်ပါတယ်။',
        ebitdaMultipleLabel: 'EBITDA multiple', sdeMultipleLabel: 'SDE multiple', multipleInstruction: 'Supportable market-style multiple ကိုသုံးပါ။', multipleExample: 'Example: 4.0',
        dcfAssumptions: 'DCF assumptions', growthLabel: 'Annual growth assumption (%)', growthInstruction: '5-year FCF growth assumption.', growthExample: 'Example: 8',
        discountLabel: 'Discount rate (%)', discountInstruction: 'Future cash flow ကို today value ပြန်တွက်တဲ့ required return.', discountExample: 'Example: 15',
        terminalLabel: 'Terminal growth (%)', terminalInstruction: 'Year 5 နောက်ပိုင်း long-run growth.', terminalExample: 'Example: 3',
        methodReadiness: 'Method readiness', ready: 'Ready', needsData: 'Needs data', used: 'Used in this result', notUsed: 'Not used',
        ebitdaMethod: 'EBITDA Multiple', ebitdaMethodHelp: 'Operating earnings × multiple, adjusted for cash and debt.',
        sdeMethod: 'Owner Earnings / SDE', sdeMethodHelp: 'Owner earnings × multiple, adjusted for cash and debt.',
        assetMethod: 'Asset-Based', assetMethodHelp: 'Recorded assets minus recorded liabilities.',
        dcfMethod: 'Discounted Cash Flow', dcfMethodHelp: '5-year Free Cash Flow projection discounted back to today.',
        reviewTitle: 'Review before calculating', reviewHelp: 'Historical facts နဲ့ assumptions ကို clearly separate လုပ်ထားပါတယ်။',
        asOfLabel: 'Valuation date', asOfInstruction: 'Estimate ကို represent လုပ်မယ့် date.', reviewStateLabel: 'Review status', draft: 'Draft', reviewed: 'Reviewed',
        reviewMeaning: 'Reviewed is planning review only; not approval, signature or ownership truth.',
        calculate: 'Calculate valuation range', calculating: 'Calculating…', localDraft: 'Unsaved guided answers stay in this browser session.',
        errorTitle: 'Valuation could not be calculated yet', errorHelp: 'Visible fields နဲ့ source readiness ကိုစစ်ပါ။',
        resultTitle: 'Indicative Business Valuation', resultHelp: 'Evidence + assumptions based planning estimate; not a guaranteed sale price.',
        low: 'Low', base: 'Central estimate', high: 'High', confidence: 'Confidence', evidenceQuality: 'Evidence quality', methodsUsed: 'Methods used',
        warnings: 'Warnings', assumptionsUsed: 'Major assumptions', sourceReview: 'Where did the numbers come from?', sourceReviewHelp: 'Recorded sources နဲ့ assumptions ကိုသီးခြား trace လုပ်နိုင်ပါတယ်။',
        linkedEvidence: 'Linked evidence', verifiedEvidence: 'Verified evidence', openVault: 'Open Document Vault', vaultHelp: 'Vault က authorized Evidence ကို Business Valuation နဲ့ link လုပ်နိုင်ပါတယ်။',
        advanced: 'Advanced calculation details', formula: 'Calculation basis', meaningTitle: 'What this result means',
        meaning: 'Indicative estimated range only; not guaranteed market value, certified valuation, transaction price, Ownership truth or Contribution Valuation.',
        noResult: 'No guided valuation yet.', newEstimate: 'Prepare another estimate', currencyNote: 'All amounts use the Business base currency',
    },
} as const;

const c = computed(() => copy[uiLanguageMode.value]);
const latestSnapshot = computed(() => props.financialSnapshots[0] ?? null);
const hasCashSource = computed(() => latestSnapshot.value?.cash != null);
const assetReady = computed(() => props.assets.length > 0);

const draft = reactive({
    as_of_date: new Date().toISOString().slice(0, 10),
    use_ebitda: false, ebitda: '',
    use_sde: false, owner_earnings: '',
    use_dcf: false, free_cash_flow: '',
    debt: '',
    ebitda_multiple: '', sde_multiple: '',
    growth_rate_percent: '', discount_rate_percent: '', terminal_growth_rate_percent: '',
    review_state: 'draft',
});

const storageKey = computed(() => 'pbr-business-valuation-draft:' + props.businessId);

onMounted(() => {
    if (typeof window === 'undefined') return;
    const stored = window.sessionStorage.getItem(storageKey.value);
    if (!stored) return;
    try {
        Object.assign(draft, JSON.parse(stored) as Partial<typeof draft>);
    } catch {
        window.sessionStorage.removeItem(storageKey.value);
    }
});

watch(draft, (value) => {
    if (typeof window === 'undefined') return;
    window.sessionStorage.setItem(storageKey.value, JSON.stringify(value));
}, { deep: true });

const needsDebt = computed(() => draft.use_ebitda || draft.use_sde || draft.use_dcf);
const ebitdaReady = computed(() => draft.use_ebitda && draft.ebitda.trim() !== '' && draft.ebitda_multiple.trim() !== '' && draft.debt.trim() !== '' && hasCashSource.value);
const sdeReady = computed(() => draft.use_sde && draft.owner_earnings.trim() !== '' && draft.sde_multiple.trim() !== '' && draft.debt.trim() !== '' && hasCashSource.value);
const dcfReady = computed(() => draft.use_dcf && draft.free_cash_flow.trim() !== '' && draft.growth_rate_percent.trim() !== '' && draft.discount_rate_percent.trim() !== '' && draft.terminal_growth_rate_percent.trim() !== '' && draft.debt.trim() !== '' && hasCashSource.value);
const anyMethodReady = computed(() => assetReady.value || ebitdaReady.value || sdeReady.value || dcfReady.value);

const focus = ref<StepKey>(props.latest === null ? 'sources' : 'result');
const stepKeys: StepKey[] = ['sources', 'facts', 'assumptions', 'review', 'result'];
const stepLabels = computed<Record<StepKey, string>>(() => ({
    sources: c.value.sourceStep, facts: c.value.factsStep, assumptions: c.value.assumptionsStep, review: c.value.reviewStep, result: c.value.resultStep,
}));
const steps = computed(() => stepKeys.map((key) => ({
    key,
    label: stepLabels.value[key],
    state: key === focus.value ? ('current' as const) : key === 'result' && props.latest !== null ? ('recorded' as const) : key === 'sources' ? ('recorded' as const) : ('available' as const),
    disabled: key === 'result' && props.latest === null,
})));

const busy = ref(false);
const errors = ref<string[]>([]);

const money = (value: unknown): string => {
    if (value === null || value === undefined || value === '') return '—';
    const number = Number(value);
    if (!Number.isFinite(number)) return String(value);
    return new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(number);
};
const sourceValue = (key: string): string => latestSnapshot.value?.[key] == null ? '—' : money(latestSnapshot.value[key]);
const assetTotal = computed(() => props.assets.reduce((total, row) => total + Number(row.estimated_value ?? 0), 0));
const liabilityTotal = computed(() => props.liabilities.reduce((total, row) => total + Number(row.outstanding_amount ?? 0), 0));

const methodCards = computed(() => [
    { key: 'ebitda_multiple', title: c.value.ebitdaMethod, help: c.value.ebitdaMethodHelp, ready: ebitdaReady.value },
    { key: 'owner_earnings_sde', title: c.value.sdeMethod, help: c.value.sdeMethodHelp, ready: sdeReady.value },
    { key: 'asset_based', title: c.value.assetMethod, help: c.value.assetMethodHelp, ready: assetReady.value },
    { key: 'discounted_cash_flow', title: c.value.dcfMethod, help: c.value.dcfMethodHelp, ready: dcfReady.value },
]);

const compact = <T extends Record<string, string>>(values: T): Partial<T> =>
    Object.fromEntries(Object.entries(values).filter(([, value]) => value.trim() !== '')) as Partial<T>;

const calculate = (): void => {
    if (busy.value || !props.canManage || !anyMethodReady.value) return;

    const historical = compact({
        ebitda: draft.use_ebitda ? draft.ebitda : '',
        owner_earnings: draft.use_sde ? draft.owner_earnings : '',
        free_cash_flow: draft.use_dcf ? draft.free_cash_flow : '',
        debt: needsDebt.value ? draft.debt : '',
    });
    const assumptions = compact({
        ebitda_multiple: draft.use_ebitda ? draft.ebitda_multiple : '',
        sde_multiple: draft.use_sde ? draft.sde_multiple : '',
        growth_rate_percent: draft.use_dcf ? draft.growth_rate_percent : '',
        discount_rate_percent: draft.use_dcf ? draft.discount_rate_percent : '',
        terminal_growth_rate_percent: draft.use_dcf ? draft.terminal_growth_rate_percent : '',
    });

    errors.value = [];
    busy.value = true;
    router.post('/formation/existing/business-valuation', {
        as_of_date: draft.as_of_date,
        historical,
        assumptions,
        review_state: draft.review_state,
    }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { focus.value = 'result'; },
        onError: (serverErrors) => { errors.value = humanErrorMessages(serverErrors); },
        onFinish: () => { busy.value = false; },
    });
};

const resultMethods = computed(() => Object.values(props.latest?.methods ?? {}));
const resultMethodCards = computed(() =>
    methodCards.value.map((method) => ({
        ...method,
        used: Object.hasOwn(props.latest?.methods ?? {}, method.key),
    })),
);
const assumptionsUsed = computed(() => Object.entries(props.latest?.assumptions ?? {}).filter(([, value]) => value !== null));
const selectStep = (key: string): void => {
    if (key === 'result' && props.latest === null) return;
    focus.value = key as StepKey;
};
</script>

<template>
    <section class="rounded-[24px] border border-[#cfe0d4] bg-[linear-gradient(145deg,#ffffff_0%,#f6faf7_68%,#fbf7eb_100%)] p-4 shadow-[0_14px_34px_rgb(16_35_26_/_6%)] sm:p-6">
        <header class="max-w-4xl">
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]">{{ c.eyebrow }}</p>
            <h3 class="mt-2 text-xl font-black tracking-[-0.025em] sm:text-2xl">{{ c.title }}</h3>
            <p class="mt-2 text-sm leading-6 text-[var(--pbr-muted)]">{{ c.subtitle }}</p>
        </header>

        <div class="mt-5">
            <GuidedJourneyStepper :steps="steps" :label="c.progress" @select="selectStep" />
        </div>
        <p class="mt-3 text-xs leading-5 text-[var(--pbr-muted)]">{{ c.localDraft }} · {{ c.currencyNote }}: {{ currency }}</p>
        <PbrErrorSummary class="mt-5" :title="c.errorTitle" :help="c.errorHelp" :errors="errors" />

        <div v-if="focus === 'sources'" class="mt-6">
            <PbrFormSection :title="c.sourcesTitle" :instruction="c.sourcesHelp" numbered="1">
                <div class="grid gap-3 lg:grid-cols-3">
                    <article class="rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                        <p class="text-xs font-black uppercase tracking-[0.12em] text-[var(--pbr-muted)]">{{ c.snapshot }}</p>
                        <template v-if="latestSnapshot">
                            <p class="mt-2 text-sm font-bold">{{ String(latestSnapshot.as_of_date ?? '—') }}</p>
                            <dl class="mt-3 grid grid-cols-2 gap-2 text-xs">
                                <div><dt class="text-[var(--pbr-muted)]">Revenue</dt><dd class="font-bold">{{ sourceValue('revenue') }}</dd></div>
                                <div><dt class="text-[var(--pbr-muted)]">Expenses</dt><dd class="font-bold">{{ sourceValue('expenses') }}</dd></div>
                                <div><dt class="text-[var(--pbr-muted)]">Cash</dt><dd class="font-bold">{{ sourceValue('cash') }}</dd></div>
                                <div><dt class="text-[var(--pbr-muted)]">Receivables</dt><dd class="font-bold">{{ sourceValue('receivables') }}</dd></div>
                                <div><dt class="text-[var(--pbr-muted)]">Payables</dt><dd class="font-bold">{{ sourceValue('payables') }}</dd></div>
                            </dl>
                        </template>
                        <p v-else class="mt-2 text-sm leading-6 text-[var(--pbr-muted)]">{{ c.noSnapshot }}</p>
                    </article>

                    <article class="rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                        <p class="text-xs font-black uppercase tracking-[0.12em] text-[var(--pbr-muted)]">{{ c.assets }}</p>
                        <p class="mt-2 text-2xl font-black">{{ money(assetTotal) }}</p>
                        <p class="text-xs text-[var(--pbr-muted)]">{{ assets.length }} {{ c.recorded }}</p>
                        <ul class="mt-3 space-y-1 text-sm">
                            <li v-for="row in assets.slice(0, 3)" :key="String(row.id)" class="flex justify-between gap-3">
                                <span class="min-w-0 break-words">{{ row.name }}</span><strong>{{ money(row.estimated_value) }}</strong>
                            </li>
                        </ul>
                    </article>

                    <article class="rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                        <p class="text-xs font-black uppercase tracking-[0.12em] text-[var(--pbr-muted)]">{{ c.liabilities }}</p>
                        <p class="mt-2 text-2xl font-black">{{ money(liabilityTotal) }}</p>
                        <p class="text-xs text-[var(--pbr-muted)]">{{ liabilities.length }} {{ c.recorded }}</p>
                        <ul class="mt-3 space-y-1 text-sm">
                            <li v-for="row in liabilities.slice(0, 3)" :key="String(row.id)" class="flex justify-between gap-3">
                                <span class="min-w-0 break-words">{{ row.name }}</span><strong>{{ money(row.outstanding_amount) }}</strong>
                            </li>
                        </ul>
                    </article>
                </div>
                <template #actions><PbrActionBar :bordered="false"><PbrButton variant="primary" @click="focus = 'facts'">{{ c.next }}</PbrButton></PbrActionBar></template>
            </PbrFormSection>
        </div>

        <div v-else-if="focus === 'facts'" class="mt-6">
            <PbrFormSection :title="c.factsTitle" :instruction="c.factsHelp" numbered="2">
                <div class="grid gap-3 lg:grid-cols-3">
                    <label v-for="item in [
                        { key: 'ebitda', model: 'use_ebitda', title: c.haveEbitda, help: c.ebitdaHelp },
                        { key: 'sde', model: 'use_sde', title: c.haveSde, help: c.sdeHelp },
                        { key: 'dcf', model: 'use_dcf', title: c.haveDcf, help: c.dcfHelp },
                    ]" :key="item.key" class="rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                        <span class="flex gap-3">
                            <input v-model="draft[item.model as 'use_ebitda' | 'use_sde' | 'use_dcf']" type="checkbox" class="mt-1 h-5 w-5 shrink-0 accent-[var(--pbr-green)]">
                            <span><strong class="block">{{ item.title }}</strong><span class="mt-1 block text-sm leading-6 text-[var(--pbr-muted)]">{{ item.help }}</span></span>
                        </span>
                    </label>
                </div>
                <ProgressiveReveal :visible="draft.use_ebitda"><PbrTextInput v-model="draft.ebitda" :label="c.ebitdaLabel" :instruction="c.ebitdaInstruction" :example="c.ebitdaExample" inputmode="decimal" /></ProgressiveReveal>
                <ProgressiveReveal :visible="draft.use_sde"><PbrTextInput v-model="draft.owner_earnings" :label="c.sdeLabel" :instruction="c.sdeInstruction" :example="c.sdeExample" inputmode="decimal" /></ProgressiveReveal>
                <ProgressiveReveal :visible="draft.use_dcf"><PbrTextInput v-model="draft.free_cash_flow" :label="c.fcfLabel" :instruction="c.fcfInstruction" :example="c.fcfExample" inputmode="decimal" /></ProgressiveReveal>
                <ProgressiveReveal :visible="needsDebt" :reason="c.debtReason"><PbrTextInput v-model="draft.debt" :label="c.debtLabel" :instruction="c.debtInstruction" :example="c.debtExample" inputmode="decimal" /></ProgressiveReveal>
                <template #actions>
                    <PbrActionBar :bordered="false"><template #secondary><PbrButton variant="ghost" @click="focus = 'sources'">{{ c.previous }}</PbrButton></template><PbrButton variant="primary" @click="focus = 'assumptions'">{{ c.next }}</PbrButton></PbrActionBar>
                </template>
            </PbrFormSection>
        </div>

        <div v-else-if="focus === 'assumptions'" class="mt-6">
            <PbrFormSection :title="c.assumptionsTitle" :instruction="c.assumptionsHelp" numbered="3">
                <ProgressiveReveal :visible="draft.use_ebitda"><PbrTextInput v-model="draft.ebitda_multiple" :label="c.ebitdaMultipleLabel" :instruction="c.multipleInstruction" :example="c.multipleExample" inputmode="decimal" /></ProgressiveReveal>
                <ProgressiveReveal :visible="draft.use_sde"><PbrTextInput v-model="draft.sde_multiple" :label="c.sdeMultipleLabel" :instruction="c.multipleInstruction" :example="c.multipleExample" inputmode="decimal" /></ProgressiveReveal>
                <ProgressiveReveal :visible="draft.use_dcf" :reason="c.dcfAssumptions">
                    <div class="pbr-form-grid">
                        <PbrTextInput v-model="draft.growth_rate_percent" :label="c.growthLabel" :instruction="c.growthInstruction" :example="c.growthExample" inputmode="decimal" />
                        <PbrTextInput v-model="draft.discount_rate_percent" :label="c.discountLabel" :instruction="c.discountInstruction" :example="c.discountExample" inputmode="decimal" />
                        <PbrTextInput v-model="draft.terminal_growth_rate_percent" :label="c.terminalLabel" :instruction="c.terminalInstruction" :example="c.terminalExample" inputmode="decimal" />
                    </div>
                </ProgressiveReveal>
                <div>
                    <h4 class="text-sm font-black">{{ c.methodReadiness }}</h4>
                    <div class="mt-3 grid gap-3 md:grid-cols-2">
                        <article v-for="method in methodCards" :key="method.key" class="rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                            <div class="flex items-start justify-between gap-3"><div><p class="font-black">{{ method.title }}</p><p class="mt-1 text-sm leading-6 text-[var(--pbr-muted)]">{{ method.help }}</p></div><span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-black" :class="method.ready ? 'bg-[var(--pbr-green-soft)] text-[var(--pbr-green-dark)]' : 'bg-slate-100 text-slate-500'">{{ method.ready ? c.ready : c.needsData }}</span></div>
                        </article>
                    </div>
                </div>
                <template #actions>
                    <PbrActionBar :bordered="false"><template #secondary><PbrButton variant="ghost" @click="focus = 'facts'">{{ c.previous }}</PbrButton></template><PbrButton variant="primary" @click="focus = 'review'">{{ c.next }}</PbrButton></PbrActionBar>
                </template>
            </PbrFormSection>
        </div>

        <div v-else-if="focus === 'review'" class="mt-6">
            <PbrFormSection :title="c.reviewTitle" :instruction="c.reviewHelp" numbered="4">
                <PbrField :label="c.asOfLabel" for-id="business-valuation-as-of" :instruction="c.asOfInstruction" required>
                    <template #default="{ descriptionId }"><input id="business-valuation-as-of" v-model="draft.as_of_date" type="date" :aria-describedby="descriptionId" class="pbr-input-control px-3.5 py-3"></template>
                </PbrField>
                <PbrField :label="c.reviewStateLabel" for-id="business-valuation-review-state" :instruction="c.reviewMeaning">
                    <template #default="{ descriptionId }"><select id="business-valuation-review-state" v-model="draft.review_state" :aria-describedby="descriptionId" class="pbr-input-control px-3.5 py-3"><option value="draft">{{ c.draft }}</option><option value="reviewed">{{ c.reviewed }}</option></select></template>
                </PbrField>
                <div class="grid gap-3 md:grid-cols-2">
                    <article v-for="method in methodCards" :key="method.key" class="rounded-2xl border border-[var(--pbr-line)] bg-white p-4"><p class="font-black">{{ method.title }}</p><p class="mt-1 text-sm text-[var(--pbr-muted)]">{{ method.ready ? c.ready : c.needsData }}</p></article>
                </div>
                <div class="rounded-2xl border border-[#e5d7ad] bg-[#fffaf0] p-4"><p class="font-black text-[#6d5720]">{{ c.meaningTitle }}</p><p class="mt-1 text-sm leading-6 text-[#735d28]">{{ c.meaning }}</p></div>
                <template #actions>
                    <PbrActionBar :bordered="false"><template #secondary><PbrButton variant="ghost" @click="focus = 'assumptions'">{{ c.previous }}</PbrButton></template><PbrButton v-if="canManage" variant="primary" :disabled="!anyMethodReady" :busy="busy" :busy-label="c.calculating" @click="calculate">{{ c.calculate }}</PbrButton></PbrActionBar>
                </template>
            </PbrFormSection>
        </div>

        <div v-else class="mt-6">
            <PbrFormSection :title="c.resultTitle" :instruction="c.resultHelp" numbered="5">
                <template v-if="latest">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <article class="rounded-2xl border border-[var(--pbr-line)] bg-white p-4"><p class="text-xs font-black uppercase tracking-[0.12em] text-[var(--pbr-muted)]">{{ c.low }}</p><p class="mt-2 text-xl font-black">{{ money(latest.range.low) }} {{ currency }}</p></article>
                        <article class="rounded-2xl border border-[var(--pbr-green)] bg-[var(--pbr-green-soft)] p-4"><p class="text-xs font-black uppercase tracking-[0.12em] text-[var(--pbr-green-dark)]">{{ c.base }}</p><p class="mt-2 text-2xl font-black text-[var(--pbr-green-dark)]">{{ money(latest.range.base) }} {{ currency }}</p></article>
                        <article class="rounded-2xl border border-[var(--pbr-line)] bg-white p-4"><p class="text-xs font-black uppercase tracking-[0.12em] text-[var(--pbr-muted)]">{{ c.high }}</p><p class="mt-2 text-xl font-black">{{ money(latest.range.high) }} {{ currency }}</p></article>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <article class="rounded-2xl border border-[var(--pbr-line)] bg-white p-4"><p class="text-xs text-[var(--pbr-muted)]">{{ c.confidence }}</p><p class="mt-1 text-lg font-black capitalize">{{ latest.confidence.level }}</p></article>
                        <article class="rounded-2xl border border-[var(--pbr-line)] bg-white p-4"><p class="text-xs text-[var(--pbr-muted)]">{{ c.evidenceQuality }}</p><p class="mt-1 text-lg font-black capitalize">{{ latest.evidenceQuality.level }}</p></article>
                        <article class="rounded-2xl border border-[var(--pbr-line)] bg-white p-4"><p class="text-xs text-[var(--pbr-muted)]">{{ c.methodsUsed }}</p><p class="mt-1 text-lg font-black">{{ latest.confidence.usableMethodCount }}</p></article>
                    </div>
                    <div>
                        <h4 class="text-sm font-black">{{ c.methodReadiness }}</h4>
                        <div class="mt-3 grid gap-3 md:grid-cols-2">
                            <article v-for="method in resultMethodCards" :key="method.key" class="rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="font-black">{{ method.title }}</p>
                                        <p class="mt-1 text-sm leading-6 text-[var(--pbr-muted)]">{{ method.help }}</p>
                                    </div>
                                    <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-black" :class="method.used ? 'bg-[var(--pbr-green-soft)] text-[var(--pbr-green-dark)]' : 'bg-slate-100 text-slate-500'">
                                        {{ method.used ? c.used : c.notUsed }}
                                    </span>
                                </div>
                            </article>
                        </div>
                    </div>
                    <div><h4 class="text-sm font-black">{{ c.methodsUsed }}</h4><div class="mt-3 grid gap-3 md:grid-cols-2">
                        <article v-for="method in resultMethods" :key="method.label" class="rounded-2xl border border-[var(--pbr-line)] bg-white p-4"><p class="font-black">{{ method.label }}</p><p class="mt-2 text-xl font-black">{{ money(method.value) }} {{ currency }}</p><details class="mt-3 text-sm text-[var(--pbr-muted)]"><summary class="cursor-pointer font-bold">{{ c.advanced }}</summary><p class="mt-2">{{ c.formula }}: {{ method.formula }}</p></details></article>
                    </div></div>
                    <section v-if="latest.warnings.length > 0" class="rounded-2xl border border-[#eadbb2] bg-[#fffaf0] p-4"><h4 class="font-black text-[#6d5720]">{{ c.warnings }}</h4><ul class="mt-2 space-y-1.5 pl-5 text-sm leading-6 text-[#735d28]"><li v-for="warning in latest.warnings" :key="warning" class="list-disc">{{ warning }}</li></ul></section>
                    <section class="rounded-2xl border border-[var(--pbr-line)] bg-white p-4"><h4 class="font-black">{{ c.assumptionsUsed }}</h4><dl v-if="assumptionsUsed.length > 0" class="mt-3 grid gap-2 sm:grid-cols-2"><div v-for="[key, value] in assumptionsUsed" :key="key" class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-[var(--pbr-muted)]">{{ key.replaceAll('_', ' ') }}</dt><dd class="mt-1 font-bold">{{ value }}</dd></div></dl><p v-else class="mt-2 text-sm text-[var(--pbr-muted)]">—</p></section>
                    <section class="rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                        <h4 class="font-black">{{ c.sourceReview }}</h4><p class="mt-1 text-sm leading-6 text-[var(--pbr-muted)]">{{ c.sourceReviewHelp }}</p>
                        <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <div class="rounded-xl bg-slate-50 p-3"><p class="text-xs text-[var(--pbr-muted)]">{{ c.snapshot }}</p><p class="mt-1 font-bold">{{ latest.provenance.financialSnapshot?.asOfDate ?? '—' }}</p></div>
                            <div class="rounded-xl bg-slate-50 p-3"><p class="text-xs text-[var(--pbr-muted)]">{{ c.assets }}</p><p class="mt-1 font-bold">{{ latest.provenance.assets?.length ?? 0 }} {{ c.recorded }}</p></div>
                            <div class="rounded-xl bg-slate-50 p-3"><p class="text-xs text-[var(--pbr-muted)]">{{ c.linkedEvidence }}</p><p class="mt-1 font-bold">{{ latest.evidenceQuality.linkedEvidenceCount }}</p></div>
                            <div class="rounded-xl bg-slate-50 p-3"><p class="text-xs text-[var(--pbr-muted)]">{{ c.verifiedEvidence }}</p><p class="mt-1 font-bold">{{ latest.evidenceQuality.verifiedEvidenceCount }}</p></div>
                        </div>
                        <div class="mt-4 flex flex-wrap items-center gap-3"><PbrButton href="/records/documents" variant="secondary">{{ c.openVault }}</PbrButton><p class="max-w-2xl text-xs leading-5 text-[var(--pbr-muted)]">{{ c.vaultHelp }}</p></div>
                    </section>
                    <section class="rounded-2xl border border-[#e5d7ad] bg-[#fffaf0] p-4"><h4 class="font-black text-[#6d5720]">{{ c.meaningTitle }}</h4><p class="mt-1 text-sm leading-6 text-[#735d28]">{{ c.meaning }}</p></section>
                </template>
                <p v-else class="text-sm text-[var(--pbr-muted)]">{{ c.noResult }}</p>
                <template #actions><PbrActionBar :bordered="false"><PbrButton variant="secondary" @click="focus = 'sources'">{{ c.newEstimate }}</PbrButton></PbrActionBar></template>
            </PbrFormSection>
        </div>
    </section>
</template>
