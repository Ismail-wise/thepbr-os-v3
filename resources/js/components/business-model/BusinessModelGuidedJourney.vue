<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import GuidedJourneyStepper from '../hybrid/GuidedJourneyStepper.vue';
import PbrButton from '../ui/PbrButton.vue';
import PbrDraftStatus from '../ui/PbrDraftStatus.vue';
import PbrFormSection from '../ui/PbrFormSection.vue';
import PbrTextInput from '../ui/PbrTextInput.vue';
import GuidedSuggestionTextarea from './GuidedSuggestionTextarea.vue';
import {
    useAutosaveDraft,
    type DraftSaveState,
} from '../../composables/useAutosaveDraft';
import { useI18n } from '../../i18n/useI18n';

type Journey = 'new' | 'existing';
type PutData = NonNullable<Parameters<typeof router.put>[1]>;
type GenericRow = Record<string, unknown>;

type Economics = {
    status: string;
    contributionMarginPerUnit: string | null;
    grossMarginPercent: number | null;
    breakEvenUnits: number | null;
    breakEvenRevenue: string | null;
    expectedMonthlyRevenue: string | null;
    expectedMonthlyGrossProfit: string | null;
    expectedMonthlyOperatingProfit: string | null;
};

type DemandSummary = {
    status: string;
    assumptions: number;
    validated_assumptions: number;
    invalidated_assumptions: number;
    validation_activities: number;
    completed_validations: number;
    evidence_links: number;
};

type Foundation = {
    operating_profile: GenericRow | null;
    economics: Economics;
    demand: DemandSummary;
};

type BmcDraft = {
    customer_segments: string;
    value_propositions: string;
    channels: string;
    customer_relationships: string;
    revenue_streams: string;
    key_resources: string;
    key_activities: string;
    key_partnerships: string;
    cost_structure: string;
};

type ProfileDraft = {
    business_purpose: string;
    market: string;
    location: string;
    operating_model: string;
    excluded_activities: string;
    pricing_notes: string;
    unit_name: string;
    average_selling_price: string;
    variable_cost_per_unit: string;
    monthly_fixed_cost: string;
    expected_monthly_units: string;
    scalability_strategy: string;
    scalability_constraints: string;
    first_12_month_plan: string;
};

type StepKey =
    | 'purpose'
    | 'customer'
    | 'offer'
    | 'market'
    | 'revenue'
    | 'economics'
    | 'channels'
    | 'relationships'
    | 'operations'
    | 'delivery'
    | 'costs'
    | 'scalability'
    | 'boundaries'
    | 'first_year'
    | 'review';

const props = defineProps<{
    journey: Journey;
    currency: string;
    bmc: GenericRow | null;
    foundation: Foundation;
    canManage: boolean;
}>();

const emit = defineEmits<{
    openDemand: [];
}>();

const { uiLanguageMode } = useI18n();

const field = (row: GenericRow | null | undefined, key: string): string => {
    const value = row?.[key];

    return value === null || value === undefined ? '' : String(value);
};

const bmcDraft = reactive<BmcDraft>({
    customer_segments: field(props.bmc, 'customer_segments'),
    value_propositions: field(props.bmc, 'value_propositions'),
    channels: field(props.bmc, 'channels'),
    customer_relationships: field(props.bmc, 'customer_relationships'),
    revenue_streams: field(props.bmc, 'revenue_streams'),
    key_resources: field(props.bmc, 'key_resources'),
    key_activities: field(props.bmc, 'key_activities'),
    key_partnerships: field(props.bmc, 'key_partnerships'),
    cost_structure: field(props.bmc, 'cost_structure'),
});

const profileDraft = reactive<ProfileDraft>({
    business_purpose: field(
        props.foundation.operating_profile,
        'business_purpose',
    ),
    market: field(props.foundation.operating_profile, 'market'),
    location: field(props.foundation.operating_profile, 'location'),
    operating_model: field(
        props.foundation.operating_profile,
        'operating_model',
    ),
    excluded_activities: field(
        props.foundation.operating_profile,
        'excluded_activities',
    ),
    pricing_notes: field(
        props.foundation.operating_profile,
        'pricing_notes',
    ),
    unit_name: field(props.foundation.operating_profile, 'unit_name'),
    average_selling_price: field(
        props.foundation.operating_profile,
        'average_selling_price',
    ),
    variable_cost_per_unit: field(
        props.foundation.operating_profile,
        'variable_cost_per_unit',
    ),
    monthly_fixed_cost: field(
        props.foundation.operating_profile,
        'monthly_fixed_cost',
    ),
    expected_monthly_units: field(
        props.foundation.operating_profile,
        'expected_monthly_units',
    ),
    scalability_strategy: field(
        props.foundation.operating_profile,
        'scalability_strategy',
    ),
    scalability_constraints: field(
        props.foundation.operating_profile,
        'scalability_constraints',
    ),
    first_12_month_plan: field(
        props.foundation.operating_profile,
        'first_12_month_plan',
    ),
});

const bmcRevision = ref(Number(props.bmc?.revision ?? 0));
const profileRevision = ref(
    Number(props.foundation.operating_profile?.revision ?? 0),
);

watch(
    () => props.bmc?.revision,
    (value) => {
        const next = Number(value ?? 0);

        if (next > bmcRevision.value) {
            bmcRevision.value = next;
        }
    },
);

watch(
    () => props.foundation.operating_profile?.revision,
    (value) => {
        const next = Number(value ?? 0);

        if (next > profileRevision.value) {
            profileRevision.value = next;
        }
    },
);

const copy = {
    en: {
        eyebrow: 'Guided Business Model',
        title: 'Build how this Business will work',
        subtitleNew:
            'Answer one practical question at a time. Your answers are saved into the existing PBR Business Model and reused later.',
        subtitleExisting:
            'Capture how the Business works today. Existing-business history and valuation stay in their own later steps.',
        progressLabel: 'Business Model guided journey',
        draftIdle: 'Draft ready',
        draftDirty: 'Changes waiting to save',
        draftSaving: 'Saving draft…',
        draftSaved: 'Draft saved',
        draftError: 'Draft could not be saved',
        saveError:
            'We could not save this step yet. Keep your answers here and try again.',
        previous: 'Previous',
        next: 'Save & continue',
        review: 'Review what we have',
        demandCta: 'Continue to demand evidence',
        advisory:
            'This is editable planning data. It does not create ownership, approval, valuation or official governance truth.',
        stepPurpose: 'Purpose',
        stepCustomer: 'Customer',
        stepOffer: 'Product / service',
        stepMarket: 'Market & location',
        stepRevenue: 'Revenue model',
        stepEconomics: 'Pricing & break-even',
        stepChannels: 'Sales channels',
        stepRelationships: 'Customer relationship',
        stepOperations: 'Operating model',
        stepDelivery: 'Delivery engine',
        stepCosts: 'Cost structure',
        stepScalability: 'Scalability',
        stepBoundaries: 'Boundaries',
        stepFirstYear: 'First 12 months',
        stepReview: 'Review',
        purposeLabel: 'Why does this Business exist?',
        purposeInstruction:
            'Describe the practical purpose partners should stay aligned around.',
        purposeExample:
            'Example: Help small restaurants reduce order mistakes with a simple digital workflow.',
        purposeSuggestions: [
            'Solve a clear customer problem',
            'Make an existing process faster or cheaper',
            'Create a reliable recurring service',
        ],
        customerLabel: 'Who is the main customer?',
        customerInstruction:
            'Name the people or businesses most likely to buy first. Be specific rather than saying “everyone”.',
        customerExample:
            'Example: Myanmar-owned restaurants in Chiang Mai with 5–30 staff.',
        customerSuggestions: [
            'Small local businesses',
            'Growing SME teams',
            'Individual consumers',
            'Business-to-business buyers',
        ],
        offerLabel: 'What do you sell, and why would they choose it?',
        offerInstruction:
            'Describe the product or service together with the main value it gives the customer.',
        offerExample:
            'Example: Monthly inventory automation that reduces manual stock checking.',
        offerSuggestions: [
            'Done-for-you service',
            'Subscription / recurring service',
            'Physical product',
            'Digital product or software',
        ],
        marketLabel: 'Which market are you serving?',
        marketInstruction:
            'Describe the market, industry or customer environment you are entering.',
        marketExample:
            'Example: Independent food and retail SMEs serving Myanmar customers in northern Thailand.',
        locationLabel: 'Where will the Business operate?',
        locationInstruction:
            'Only add location detail if it matters to customers, delivery, regulation or costs.',
        locationExample:
            'Example: Chiang Mai first, then online delivery across Thailand.',
        revenueLabel: 'How will the Business earn money?',
        revenueInstruction:
            'Choose a simple revenue logic or write your own. You can combine more than one.',
        revenueExample:
            'Example: One-time setup fee + monthly support subscription.',
        revenueSuggestions: [
            'One-time sale',
            'Subscription / recurring fee',
            'Service fee',
            'Commission / transaction fee',
        ],
        economicsTitle: 'What does one sale need to earn?',
        economicsInstruction:
            'Use your best current estimate. These values stay editable and feed deterministic break-even calculations.',
        unitLabel: 'What is one sellable unit?',
        unitInstruction:
            'Use the unit customers actually pay for.',
        unitExample: 'Example: meal, project, monthly subscription, package',
        priceLabel: 'Average selling price per unit',
        priceInstruction:
            'Use the average amount collected from one unit before tax if possible.',
        priceExample: 'Example: 150.00',
        variableLabel: 'Direct / variable cost per unit',
        variableInstruction:
            'Include costs that rise when one more unit is sold: materials, delivery, direct labor or payment fees.',
        variableExample: 'Example: 65.00',
        fixedLabel: 'Monthly operating cost',
        fixedInstruction:
            'Include recurring costs that continue even if sales are slow: rent, salaries, software and admin.',
        fixedExample: 'Example: 120000.00',
        unitsLabel: 'Expected units sold per month',
        unitsInstruction:
            'Use a realistic base estimate, not the best possible month.',
        unitsExample: 'Example: 800',
        pricingNotesLabel: 'Pricing notes',
        pricingNotesInstruction:
            'Record important assumptions such as different packages, discounts or seasonal pricing.',
        pricingNotesExample:
            'Example: Base package 150; corporate orders use negotiated pricing.',
        economicsWaiting:
            'Add price, direct cost and monthly operating cost to calculate break-even.',
        economicsInvalid:
            'Selling price must be greater than direct cost before break-even can be calculated.',
        contributionMargin: 'Contribution per unit',
        grossMargin: 'Contribution margin',
        breakEvenUnits: 'Break-even units',
        breakEvenRevenue: 'Break-even revenue',
        expectedRevenue: 'Expected monthly revenue',
        expectedProfit: 'Expected monthly operating result',
        channelsLabel: 'How will customers find and buy from you?',
        channelsInstruction:
            'List the main sales or delivery channels you actually plan to use.',
        channelsExample:
            'Example: Facebook inquiry → consultation call → direct invoice.',
        channelSuggestions: [
            'Direct sales',
            'Social media',
            'Website / online store',
            'Referral partners',
        ],
        relationshipsLabel: 'How will you win and keep customers?',
        relationshipsInstruction:
            'Describe the relationship customers should experience before and after buying.',
        relationshipsExample:
            'Example: Guided onboarding, monthly check-in and fast support.',
        relationshipSuggestions: [
            'Personal service',
            'Self-service',
            'Account management',
            'Community / membership',
        ],
        operationsLabel: 'How will the Business operate day to day?',
        operationsInstruction:
            'Describe the delivery model in plain language: people-led, store-based, online, project-based, automated, or a mix.',
        operationsExample:
            'Example: Small internal team handles sales; standardized delivery uses automation and weekly review.',
        operationsSuggestions: [
            'People-led service',
            'Store / location based',
            'Online / remote delivery',
            'Hybrid people + automation',
        ],
        resourcesLabel: 'What must you have to deliver?',
        resourcesInstruction:
            'List the most important people, assets, systems, licences, data or inventory.',
        resourcesExample:
            'Example: 2 advisors, CRM, automation server and standard templates.',
        activitiesLabel: 'What work must happen repeatedly?',
        activitiesInstruction:
            'List the critical activities that create and deliver customer value.',
        activitiesExample:
            'Example: Lead qualification, setup, quality check, support and monthly review.',
        partnersLabel: 'Who or what outside the Business do you rely on?',
        partnersInstruction:
            'Include suppliers, platforms, specialists or strategic partners only if they matter to delivery.',
        partnersExample:
            'Example: Cloud provider, accountant, payment provider and referral partners.',
        costsLabel: 'What are the main cost areas?',
        costsInstruction:
            'Summarize the cost structure. Detailed numeric assumptions are already captured in the break-even step.',
        costsExample:
            'Example: Staff, cloud/software, office, marketing and customer support.',
        scaleLabel: 'How could the Business handle more demand?',
        scaleInstruction:
            'Describe what can be standardized, automated, delegated, duplicated or expanded without quality collapsing.',
        scaleExample:
            'Example: Standardize onboarding, automate reporting and certify delivery partners.',
        scaleSuggestions: [
            'Standardize the process',
            'Automate repeatable work',
            'Train additional delivery staff',
            'Expand through partners / branches',
        ],
        constraintsLabel: 'What could stop the Business from scaling?',
        constraintsInstruction:
            'Name the real bottlenecks: founder time, skilled staff, supply, location, capital, regulation or technology.',
        constraintsExample:
            'Example: Senior specialist capacity and customer onboarding time.',
        boundariesLabel: 'What will this Business deliberately NOT do?',
        boundariesInstruction:
            'Clear boundaries reduce partner disagreement and uncontrolled expansion.',
        boundariesExample:
            'Example: No legal advice, no consumer lending and no custom projects outside the core service.',
        firstYearLabel: 'What should the first 12 months look like?',
        firstYearInstruction:
            'Write the practical sequence from validation to stable operation and controlled growth.',
        firstYearExample:
            'Example: Q1 validate and pilot; Q2 launch; Q3 standardize; Q4 expand channels.',
        reviewTitle: 'Business Model summary',
        reviewHelp:
            'Everything here remains editable. Deep Feasibility will later reuse this Business Model, demand evidence and break-even data instead of asking again.',
        demandTitle: 'Demand evidence',
        demandNotStarted: 'Not started',
        demandTesting: 'Testing',
        demandValidated: 'Validated',
        assumptions: 'assumptions',
        validations: 'completed validations',
        evidence: 'evidence links',
        existingDemandNote:
            'For an Existing Business, current financial and operating evidence can also support later Deep Feasibility. You are not forced through a startup validation checklist.',
    },
    my: {
        eyebrow: 'Guided Business Model',
        title: 'ဒီ Business ဘယ်လိုလည်ပတ်မလဲ သတ်မှတ်ပါ',
        subtitleNew:
            'မေးခွန်းတစ်ခုချင်း ဖြေသွားပါ။ အဖြေတွေကို ရှိပြီးသား PBR Business Model ထဲမှာ သိမ်းပြီး နောက်အဆင့်တွေမှာ ပြန်သုံးပါမယ်။',
        subtitleExisting:
            'လက်ရှိ Business တကယ်ဘယ်လိုလည်ပတ်နေတယ်ဆိုတာ မှတ်တမ်းတင်ပါ။ အရင်သမိုင်းနဲ့ Business Valuation ကို နောက်သီးသန့်အဆင့်မှာ ဆက်လုပ်ပါမယ်။',
        progressLabel: 'Business Model guided journey',
        draftIdle: 'Draft အဆင်သင့်',
        draftDirty: 'မသိမ်းရသေးသော ပြောင်းလဲမှုရှိသည်',
        draftSaving: 'Draft သိမ်းနေသည်…',
        draftSaved: 'Draft သိမ်းပြီး',
        draftError: 'Draft မသိမ်းနိုင်သေးပါ',
        saveError:
            'ဒီအဆင့်ကို မသိမ်းနိုင်သေးပါ။ အဖြေတွေကို မဖျက်ဘဲ ထားပြီး ပြန်စမ်းပါ။',
        previous: 'နောက်ပြန်',
        next: 'သိမ်းပြီး ဆက်မည်',
        review: 'အကျဉ်းချုပ်ကြည့်မည်',
        demandCta: 'Demand Evidence ဆက်လုပ်မည်',
        advisory:
            'ဒီအပိုင်းက ပြင်လို့ရတဲ့ Planning Data ဖြစ်ပါတယ်။ Ownership, Approval, Valuation ဒါမှမဟုတ် Governance အတည်ပြုချက် မဖြစ်သေးပါ။',
        stepPurpose: 'ရည်ရွယ်ချက်',
        stepCustomer: 'Customer',
        stepOffer: 'Product / Service',
        stepMarket: 'Market & Location',
        stepRevenue: 'ဝင်ငွေပုံစံ',
        stepEconomics: 'စျေးနှုန်း & Break-even',
        stepChannels: 'အရောင်းလမ်းကြောင်း',
        stepRelationships: 'Customer Relationship',
        stepOperations: 'Operating Model',
        stepDelivery: 'Delivery Engine',
        stepCosts: 'Cost Structure',
        stepScalability: 'Scalability',
        stepBoundaries: 'ကန့်သတ်ချက်',
        stepFirstYear: 'ပထမ ၁၂ လ',
        stepReview: 'အကျဉ်းချုပ်',
        purposeLabel: 'ဒီ Business ကို ဘာကြောင့် လုပ်တာလဲ?',
        purposeInstruction:
            'Partner အားလုံး တစ်လမ်းတည်းသွားနိုင်ဖို့ အဓိကရည်ရွယ်ချက်ကို ရိုးရိုးရှင်းရှင်းရေးပါ။',
        purposeExample:
            'ဥပမာ - စားသောက်ဆိုင်ငယ်တွေမှာ Order မှားတာ လျော့အောင် ရိုးရှင်းတဲ့ Digital Workflow ပေးမယ်။',
        purposeSuggestions: [
            'Customer ပြဿနာတစ်ခုကို ဖြေရှင်းမယ်',
            'ရှိပြီးသားလုပ်ငန်းစဉ်ကို မြန်အောင် / စရိတ်သက်သာအောင်လုပ်မယ်',
            'ယုံကြည်ရတဲ့ Recurring Service တစ်ခုတည်ဆောက်မယ်',
        ],
        customerLabel: 'အဓိက Customer က ဘယ်သူလဲ?',
        customerInstruction:
            'အစမှာ တကယ်ဝယ်နိုင်ဆုံး လူ/လုပ်ငန်းအုပ်စုကို တိတိကျကျရေးပါ။ “လူတိုင်း” လို့ မရေးပါနဲ့။',
        customerExample:
            'ဥပမာ - Chiang Mai မှာ Staff 5–30 ယောက်ရှိတဲ့ မြန်မာပိုင် Restaurant တွေ။',
        customerSuggestions: [
            'Local Business အသေးစားများ',
            'တိုးတက်နေသော SME Team များ',
            'တစ်ဦးချင်း Customer များ',
            'Business-to-Business Customer များ',
        ],
        offerLabel: 'ဘာကိုရောင်းမလဲ၊ Customer က ဘာကြောင့်ရွေးမလဲ?',
        offerInstruction:
            'Product / Service နဲ့ Customer ရမယ့် အဓိက Value ကို တစ်ခါတည်း ရေးပါ။',
        offerExample:
            'ဥပမာ - Manual Stock Check လျော့စေတဲ့ Monthly Inventory Automation Service။',
        offerSuggestions: [
            'Done-for-you Service',
            'Subscription / Recurring Service',
            'Physical Product',
            'Digital Product / Software',
        ],
        marketLabel: 'ဘယ် Market ကို ဝင်မလဲ?',
        marketInstruction:
            'ဝင်မယ့် Market, Industry ဒါမှမဟုတ် Customer Environment ကို ရေးပါ။',
        marketExample:
            'ဥပမာ - Thailand မြောက်ပိုင်းရှိ Myanmar Customer ကိုဝန်ဆောင်မှုပေးတဲ့ Food/Retail SME တွေ။',
        locationLabel: 'Business ကို ဘယ်နေရာမှာ လည်ပတ်မလဲ?',
        locationInstruction:
            'Customer, Delivery, Regulation ဒါမှမဟုတ် Cost ကို သက်ရောက်မှ Location ကို ထည့်ပါ။',
        locationExample:
            'ဥပမာ - Chiang Mai မှာစပြီး Thailand တစ်နိုင်ငံလုံး Online Delivery။',
        revenueLabel: 'Business က ဘယ်လိုဝင်ငွေရမလဲ?',
        revenueInstruction:
            'အဓိက Revenue Logic ကိုရွေးပါ ဒါမှမဟုတ် ကိုယ့်ပုံစံကို ရေးပါ။ တစ်မျိုးထက်ပိုပေါင်းလို့ရပါတယ်။',
        revenueExample:
            'ဥပမာ - One-time Setup Fee + Monthly Support Subscription။',
        revenueSuggestions: [
            'One-time Sale',
            'Subscription / Recurring Fee',
            'Service Fee',
            'Commission / Transaction Fee',
        ],
        economicsTitle: 'Sale တစ်ခုက ဘယ်လောက်အကျိုးအမြတ်ပေးရမလဲ?',
        economicsInstruction:
            'လက်ရှိအကောင်းဆုံး Estimate ကို သုံးပါ။ နောက်ပိုင်းပြင်လို့ရပြီး Break-even ကို စနစ်က တိတိကျကျတွက်ပေးပါမယ်။',
        unitLabel: 'ရောင်းတဲ့ Unit တစ်ခုက ဘာလဲ?',
        unitInstruction:
            'Customer တကယ်ပေးချေတဲ့ Unit ကို သုံးပါ။',
        unitExample: 'ဥပမာ - အစားအစာတစ်ပွဲ၊ Project တစ်ခု၊ Monthly Subscription၊ Package',
        priceLabel: 'Unit တစ်ခု၏ ပျမ်းမျှရောင်းစျေး',
        priceInstruction:
            'ဖြစ်နိုင်ရင် Tax မပါခင် Unit တစ်ခုက ရမယ့် ပျမ်းမျှငွေကို ထည့်ပါ။',
        priceExample: 'ဥပမာ - 150.00',
        variableLabel: 'Unit တစ်ခု၏ Direct / Variable Cost',
        variableInstruction:
            'Sale တစ်ခုတိုးတိုင်း တိုးလာမယ့် Material, Delivery, Direct Labor, Payment Fee စရိတ်တွေ ထည့်ပါ။',
        variableExample: 'ဥပမာ - 65.00',
        fixedLabel: 'တစ်လ Operating Cost',
        fixedInstruction:
            'Sale နည်းနေလည်း ဆက်ပေးရမယ့် Rent, Salary, Software, Admin စရိတ်တွေ ထည့်ပါ။',
        fixedExample: 'ဥပမာ - 120000.00',
        unitsLabel: 'တစ်လ ခန့်မှန်းရောင်းနိုင်မယ့် Unit',
        unitsInstruction:
            'အကောင်းဆုံးလကို မယူဘဲ လက်တွေ့ကျတဲ့ Base Estimate သုံးပါ။',
        unitsExample: 'ဥပမာ - 800',
        pricingNotesLabel: 'Pricing မှတ်ချက်',
        pricingNotesInstruction:
            'Package မတူတာ၊ Discount, Season Pricing စတဲ့ အရေးကြီး Assumption တွေ ရေးပါ။',
        pricingNotesExample:
            'ဥပမာ - Base Package 150; Corporate Order တွေကို Negotiated Pricing သုံးမယ်။',
        economicsWaiting:
            'Break-even တွက်ဖို့ Selling Price, Direct Cost နဲ့ Monthly Operating Cost ကို ထည့်ပါ။',
        economicsInvalid:
            'Break-even တွက်နိုင်ဖို့ Selling Price က Direct Cost ထက် မြင့်ရပါမယ်။',
        contributionMargin: 'Unit တစ်ခု Contribution',
        grossMargin: 'Contribution Margin',
        breakEvenUnits: 'Break-even Units',
        breakEvenRevenue: 'Break-even Revenue',
        expectedRevenue: 'တစ်လ Expected Revenue',
        expectedProfit: 'တစ်လ Expected Operating Result',
        channelsLabel: 'Customer က ဘယ်လိုသိပြီး ဘယ်လိုဝယ်မလဲ?',
        channelsInstruction:
            'တကယ်သုံးမယ့် Sales / Delivery Channel အဓိကတွေကို ရေးပါ။',
        channelsExample:
            'ဥပမာ - Facebook Inquiry → Consultation Call → Direct Invoice။',
        channelSuggestions: [
            'Direct Sales',
            'Social Media',
            'Website / Online Store',
            'Referral Partner',
        ],
        relationshipsLabel: 'Customer ကို ဘယ်လိုရပြီး ဘယ်လိုထိန်းမလဲ?',
        relationshipsInstruction:
            'မဝယ်ခင်နဲ့ ဝယ်ပြီးနောက် Customer Experience ကို ရေးပါ။',
        relationshipsExample:
            'ဥပမာ - Guided Onboarding, Monthly Check-in နဲ့ Fast Support။',
        relationshipSuggestions: [
            'Personal Service',
            'Self-service',
            'Account Management',
            'Community / Membership',
        ],
        operationsLabel: 'နေ့စဉ် Business ကို ဘယ်လိုလည်ပတ်မလဲ?',
        operationsInstruction:
            'People-led, Store-based, Online, Project-based, Automated ဒါမှမဟုတ် ပေါင်းစပ်ပုံကို ရိုးရိုးရှင်းရှင်းရေးပါ။',
        operationsExample:
            'ဥပမာ - Internal Team က Sales ကိုကိုင်၊ Standardized Delivery ကို Automation နဲ့ Weekly Review သုံးမယ်။',
        operationsSuggestions: [
            'People-led Service',
            'Store / Location Based',
            'Online / Remote Delivery',
            'People + Automation Hybrid',
        ],
        resourcesLabel: 'Delivery လုပ်ဖို့ ဘာတွေ မဖြစ်မနေလိုလဲ?',
        resourcesInstruction:
            'အရေးကြီးတဲ့ လူ၊ Asset, System, Licence, Data ဒါမှမဟုတ် Inventory ကို ရေးပါ။',
        resourcesExample:
            'ဥပမာ - Advisor 2 ယောက်၊ CRM၊ Automation Server နဲ့ Standard Template။',
        activitiesLabel: 'ဘယ်အလုပ်တွေ ပုံမှန်ထပ်လုပ်ရမလဲ?',
        activitiesInstruction:
            'Customer Value ဖန်တီးပြီး ပို့ဆောင်ဖို့ မဖြစ်မနေလိုတဲ့ အလုပ်တွေကို ရေးပါ။',
        activitiesExample:
            'ဥပမာ - Lead Qualification, Setup, Quality Check, Support နဲ့ Monthly Review။',
        partnersLabel: 'Business အပြင်ဘက်က ဘယ်သူတွေကို အားကိုးရမလဲ?',
        partnersInstruction:
            'Delivery ကို တကယ်သက်ရောက်တဲ့ Supplier, Platform, Specialist, Strategic Partner တွေကိုပဲ ထည့်ပါ။',
        partnersExample:
            'ဥပမာ - Cloud Provider, Accountant, Payment Provider နဲ့ Referral Partner။',
        costsLabel: 'အဓိက Cost Area တွေက ဘာတွေလဲ?',
        costsInstruction:
            'Cost Structure ကို အကျဉ်းချုပ်ရေးပါ။ Numeric Assumption တွေကို Break-even Step မှာ ထည့်ပြီးသားဖြစ်ပါတယ်။',
        costsExample:
            'ဥပမာ - Staff, Cloud/Software, Office, Marketing နဲ့ Customer Support။',
        scaleLabel: 'Demand ပိုများလာရင် ဘယ်လိုတိုးချဲ့မလဲ?',
        scaleInstruction:
            'Quality မကျဘဲ Standardize, Automate, Delegate, Duplicate, Expand လုပ်နိုင်တာတွေ ရေးပါ။',
        scaleExample:
            'ဥပမာ - Onboarding ကို Standardize, Reporting ကို Automate, Delivery Partner တွေကို Certify လုပ်မယ်။',
        scaleSuggestions: [
            'Process ကို Standardize လုပ်မယ်',
            'ထပ်ခါထပ်ခါအလုပ်ကို Automate လုပ်မယ်',
            'Delivery Staff ထပ်လေ့ကျင့်မယ်',
            'Partner / Branch ကနေ Expand လုပ်မယ်',
        ],
        constraintsLabel: 'Scale လုပ်တာကို ဘာကတားနိုင်လဲ?',
        constraintsInstruction:
            'Founder Time, Skilled Staff, Supply, Location, Capital, Regulation, Technology စတဲ့ Bottleneck အစစ်ကို ရေးပါ။',
        constraintsExample:
            'ဥပမာ - Senior Specialist Capacity နဲ့ Customer Onboarding Time။',
        boundariesLabel: 'ဒီ Business က ဘာတွေကို ရည်ရွယ်ချက်ရှိရှိ မလုပ်ဘူးလဲ?',
        boundariesInstruction:
            'Boundary ရှင်းရင် Partner အငြင်းပွားမှုနဲ့ မထိန်းနိုင်တဲ့ Expansion လျော့ပါတယ်။',
        boundariesExample:
            'ဥပမာ - Legal Advice မပေး၊ Consumer Lending မလုပ်၊ Core Service အပြင် Custom Project မယူ။',
        firstYearLabel: 'ပထမ ၁၂ လကို ဘယ်လိုသွားမလဲ?',
        firstYearInstruction:
            'Validation ကနေ Stable Operation နဲ့ Controlled Growth အထိ လက်တွေ့ Sequence ကို ရေးပါ။',
        firstYearExample:
            'ဥပမာ - Q1 Validate/Pilot; Q2 Launch; Q3 Standardize; Q4 Channel Expand။',
        reviewTitle: 'Business Model အကျဉ်းချုပ်',
        reviewHelp:
            'ဒီ Data အားလုံး နောက်ပိုင်းပြင်လို့ရပါတယ်။ Deep Feasibility က Business Model, Demand Evidence နဲ့ Break-even ကို ပြန်မမေးဘဲ တိုက်ရိုက်အသုံးပြုပါမယ်။',
        demandTitle: 'Demand Evidence',
        demandNotStarted: 'မစရသေး',
        demandTesting: 'စမ်းသပ်နေသည်',
        demandValidated: 'Validated',
        assumptions: 'Assumptions',
        validations: 'ပြီးဆုံး Validation',
        evidence: 'Evidence Links',
        existingDemandNote:
            'Existing Business အတွက် လက်ရှိ Financial/Operating Evidence ကို Deep Feasibility မှာ သုံးနိုင်ပါတယ်။ Startup Validation Checklist ကို မဖြစ်မနေ ပြန်လုပ်ခိုင်းမှာ မဟုတ်ပါ။',
    },
    mixed: {
        eyebrow: 'Guided Business Model',
        title: 'ဒီ Business ဘယ်လို work လုပ်မလဲ သတ်မှတ်ပါ',
        subtitleNew:
            'One practical question at a time ဖြေပါ။ Answers တွေကို existing PBR Business Model ထဲမှာ save လုပ်ပြီး later steps မှာ reuse လုပ်ပါမယ်။',
        subtitleExisting:
            'Business အခုတကယ်ဘယ်လို work လုပ်နေတယ်ဆိုတာ capture လုပ်ပါ။ History / Valuation က later dedicated steps မှာပဲ ဆက်သွားပါမယ်။',
        progressLabel: 'Business Model guided journey',
        draftIdle: 'Draft ready',
        draftDirty: 'Changes waiting to save',
        draftSaving: 'Saving draft…',
        draftSaved: 'Draft saved',
        draftError: 'Draft could not be saved',
        saveError:
            'ဒီ step ကို မသိမ်းနိုင်သေးပါ။ Answers မဖျက်ဘဲထားပြီး ပြန်စမ်းပါ။',
        previous: 'Previous',
        next: 'Save & continue',
        review: 'Review what we have',
        demandCta: 'Continue to demand evidence',
        advisory:
            'ဒီအပိုင်းက editable planning data ဖြစ်ပါတယ်။ Ownership, Approval, Valuation or Governance truth မဟုတ်သေးပါ။',
        stepPurpose: 'Purpose',
        stepCustomer: 'Customer',
        stepOffer: 'Product / Service',
        stepMarket: 'Market & Location',
        stepRevenue: 'Revenue model',
        stepEconomics: 'Pricing & break-even',
        stepChannels: 'Sales channels',
        stepRelationships: 'Customer relationship',
        stepOperations: 'Operating model',
        stepDelivery: 'Delivery engine',
        stepCosts: 'Cost structure',
        stepScalability: 'Scalability',
        stepBoundaries: 'Boundaries',
        stepFirstYear: 'First 12 months',
        stepReview: 'Review',
        purposeLabel: 'Why does this Business exist?',
        purposeInstruction:
            'Partners အားလုံး align ဖြစ်ဖို့ practical purpose ကို plain language နဲ့ ရေးပါ။',
        purposeExample:
            'Example: Small restaurants တွေ order mistakes လျော့ဖို့ simple digital workflow ပေးမယ်။',
        purposeSuggestions: [
            'Solve a clear customer problem',
            'Make a process faster or cheaper',
            'Create a reliable recurring service',
        ],
        customerLabel: 'Main customer က ဘယ်သူလဲ?',
        customerInstruction:
            'First buyers ဖြစ်နိုင်ဆုံး people/business group ကို specific ရေးပါ။ “Everyone” မသုံးပါနဲ့။',
        customerExample:
            'Example: Chiang Mai မှာ staff 5–30 ရှိတဲ့ Myanmar-owned restaurants.',
        customerSuggestions: [
            'Small local businesses',
            'Growing SME teams',
            'Individual consumers',
            'B2B buyers',
        ],
        offerLabel: 'ဘာကို sell မလဲ၊ ဘာကြောင့် customer က choose မလဲ?',
        offerInstruction:
            'Product / Service နဲ့ main customer value ကို တစ်ခါတည်း ရေးပါ။',
        offerExample:
            'Example: Manual stock checking လျော့စေတဲ့ monthly inventory automation.',
        offerSuggestions: [
            'Done-for-you service',
            'Subscription / recurring service',
            'Physical product',
            'Digital product / software',
        ],
        marketLabel: 'ဘယ် Market ကို serve လုပ်မလဲ?',
        marketInstruction:
            'Market, industry or customer environment ကို capture လုပ်ပါ။',
        marketExample:
            'Example: Northern Thailand မှာ Myanmar customers ကို serve လုပ်တဲ့ Food/Retail SMEs.',
        locationLabel: 'Business က ဘယ်နေရာမှာ operate လုပ်မလဲ?',
        locationInstruction:
            'Customer, delivery, regulation or cost ကို affect လုပ်ရင် location detail ထည့်ပါ။',
        locationExample:
            'Example: Chiang Mai first, then online delivery across Thailand.',
        revenueLabel: 'Business က ဘယ်လို money earn လုပ်မလဲ?',
        revenueInstruction:
            'Simple revenue logic ကိုရွေး သို့မဟုတ် own model ရေးပါ။ Multiple models ပေါင်းလို့ရပါတယ်။',
        revenueExample:
            'Example: One-time setup fee + monthly support subscription.',
        revenueSuggestions: [
            'One-time sale',
            'Subscription / recurring fee',
            'Service fee',
            'Commission / transaction fee',
        ],
        economicsTitle: 'One sale က ဘယ်လောက် earn လုပ်ပေးရမလဲ?',
        economicsInstruction:
            'Best current estimate ကိုသုံးပါ။ Editable ဖြစ်ပြီး deterministic break-even calculation ကို feed လုပ်ပါမယ်။',
        unitLabel: 'One sellable unit က ဘာလဲ?',
        unitInstruction:
            'Customer တကယ် pay လုပ်တဲ့ unit ကို သုံးပါ။',
        unitExample: 'Example: meal, project, monthly subscription, package',
        priceLabel: 'Average selling price per unit',
        priceInstruction:
            'Possible ဖြစ်ရင် tax မပါခင် average collected amount ကို ထည့်ပါ။',
        priceExample: 'Example: 150.00',
        variableLabel: 'Direct / variable cost per unit',
        variableInstruction:
            'One more sale ဖြစ်တိုင်း တိုးလာတဲ့ material, delivery, direct labor, payment fee ကို ထည့်ပါ။',
        variableExample: 'Example: 65.00',
        fixedLabel: 'Monthly operating cost',
        fixedInstruction:
            'Sales slow ဖြစ်လည်း ဆက်ရှိနေတဲ့ rent, salary, software, admin cost ကို ထည့်ပါ။',
        fixedExample: 'Example: 120000.00',
        unitsLabel: 'Expected units sold per month',
        unitsInstruction:
            'Best month မဟုတ်ဘဲ realistic base estimate ကို သုံးပါ။',
        unitsExample: 'Example: 800',
        pricingNotesLabel: 'Pricing notes',
        pricingNotesInstruction:
            'Packages, discounts, seasonal pricing စတဲ့ important assumptions ရေးပါ။',
        pricingNotesExample:
            'Example: Base package 150; corporate orders use negotiated pricing.',
        economicsWaiting:
            'Break-even တွက်ဖို့ price, direct cost နဲ့ monthly operating cost ထည့်ပါ။',
        economicsInvalid:
            'Break-even တွက်ဖို့ selling price က direct cost ထက် greater ဖြစ်ရပါမယ်။',
        contributionMargin: 'Contribution per unit',
        grossMargin: 'Contribution margin',
        breakEvenUnits: 'Break-even units',
        breakEvenRevenue: 'Break-even revenue',
        expectedRevenue: 'Expected monthly revenue',
        expectedProfit: 'Expected monthly operating result',
        channelsLabel: 'Customers က ဘယ်လို find/buy လုပ်မလဲ?',
        channelsInstruction:
            'Main sales or delivery channels ကို ရေးပါ။',
        channelsExample:
            'Example: Facebook inquiry → consultation call → direct invoice.',
        channelSuggestions: [
            'Direct sales',
            'Social media',
            'Website / online store',
            'Referral partners',
        ],
        relationshipsLabel: 'Customers ကို ဘယ်လို win and keep လုပ်မလဲ?',
        relationshipsInstruction:
            'Before/after purchase customer relationship ကို ရေးပါ။',
        relationshipsExample:
            'Example: Guided onboarding, monthly check-in and fast support.',
        relationshipSuggestions: [
            'Personal service',
            'Self-service',
            'Account management',
            'Community / membership',
        ],
        operationsLabel: 'Day-to-day Business ကို ဘယ်လို operate လုပ်မလဲ?',
        operationsInstruction:
            'People-led, store-based, online, project-based, automated or hybrid ပုံစံကို ရေးပါ။',
        operationsExample:
            'Example: Internal team handles sales; standardized delivery uses automation and weekly review.',
        operationsSuggestions: [
            'People-led service',
            'Store / location based',
            'Online / remote delivery',
            'Hybrid people + automation',
        ],
        resourcesLabel: 'Delivery လုပ်ဖို့ ဘာ resources လိုလဲ?',
        resourcesInstruction:
            'Critical people, assets, systems, licences, data or inventory ကို ရေးပါ။',
        resourcesExample:
            'Example: 2 advisors, CRM, automation server and standard templates.',
        activitiesLabel: 'ဘယ် activities တွေ repeat ဖြစ်ရမလဲ?',
        activitiesInstruction:
            'Customer value create/deliver လုပ်ဖို့ critical work ကို ရေးပါ။',
        activitiesExample:
            'Example: Lead qualification, setup, quality check, support and monthly review.',
        partnersLabel: 'Outside Business က ဘယ် partners/suppliers ကို rely လုပ်ရမလဲ?',
        partnersInstruction:
            'Delivery ကို materially affect လုပ်တဲ့ external dependencies ပဲ ထည့်ပါ။',
        partnersExample:
            'Example: Cloud provider, accountant, payment provider and referral partners.',
        costsLabel: 'Main cost areas က ဘာတွေလဲ?',
        costsInstruction:
            'Cost structure summary ရေးပါ။ Numeric assumptions က break-even step မှာ already captured ဖြစ်ပါတယ်။',
        costsExample:
            'Example: Staff, cloud/software, office, marketing and customer support.',
        scaleLabel: 'Demand ပိုများလာရင် ဘယ်လို scale လုပ်မလဲ?',
        scaleInstruction:
            'Quality မကျဘဲ standardize, automate, delegate, duplicate or expand လုပ်နိုင်တာကို ရေးပါ။',
        scaleExample:
            'Example: Standardize onboarding, automate reporting and certify delivery partners.',
        scaleSuggestions: [
            'Standardize the process',
            'Automate repeatable work',
            'Train more delivery staff',
            'Expand through partners / branches',
        ],
        constraintsLabel: 'Scale ကို ဘာက limit လုပ်နိုင်လဲ?',
        constraintsInstruction:
            'Founder time, skilled staff, supply, location, capital, regulation or technology bottleneck ကို ရေးပါ။',
        constraintsExample:
            'Example: Senior specialist capacity and customer onboarding time.',
        boundariesLabel: 'Business က deliberately ဘာမလုပ်ဘူးလဲ?',
        boundariesInstruction:
            'Clear boundaries က partner disagreement နဲ့ uncontrolled expansion ကို လျော့စေပါတယ်။',
        boundariesExample:
            'Example: No legal advice, no consumer lending, no custom projects outside core service.',
        firstYearLabel: 'First 12 months ကို ဘယ်လိုသွားမလဲ?',
        firstYearInstruction:
            'Validation → stable operation → controlled growth sequence ကို ရေးပါ။',
        firstYearExample:
            'Example: Q1 validate/pilot; Q2 launch; Q3 standardize; Q4 expand channels.',
        reviewTitle: 'Business Model summary',
        reviewHelp:
            'Everything editable ဖြစ်ပါတယ်။ Deep Feasibility က Business Model, demand evidence, break-even data ကို reuse လုပ်ပြီး ပြန်မမေးပါ။',
        demandTitle: 'Demand evidence',
        demandNotStarted: 'Not started',
        demandTesting: 'Testing',
        demandValidated: 'Validated',
        assumptions: 'assumptions',
        validations: 'completed validations',
        evidence: 'evidence links',
        existingDemandNote:
            'Existing Business အတွက် current financial/operating evidence ကို later Deep Feasibility မှာ reuse လုပ်နိုင်ပြီး startup validation checklist ကို force မလုပ်ပါ။',
    },
} as const;

const c = computed(() => copy[uiLanguageMode.value]);

const stepKeys: StepKey[] = [
    'purpose',
    'customer',
    'offer',
    'market',
    'revenue',
    'economics',
    'channels',
    'relationships',
    'operations',
    'delivery',
    'costs',
    'scalability',
    'boundaries',
    'first_year',
    'review',
];

const stepLabels = computed<Record<StepKey, string>>(() => ({
    purpose: c.value.stepPurpose,
    customer: c.value.stepCustomer,
    offer: c.value.stepOffer,
    market: c.value.stepMarket,
    revenue: c.value.stepRevenue,
    economics: c.value.stepEconomics,
    channels: c.value.stepChannels,
    relationships: c.value.stepRelationships,
    operations: c.value.stepOperations,
    delivery: c.value.stepDelivery,
    costs: c.value.stepCosts,
    scalability: c.value.stepScalability,
    boundaries: c.value.stepBoundaries,
    first_year: c.value.stepFirstYear,
    review: c.value.stepReview,
}));

const recorded = (key: StepKey): boolean => {
    const nonempty = (...values: string[]): boolean =>
        values.every((value) => value.trim() !== '');

    switch (key) {
        case 'purpose':
            return nonempty(profileDraft.business_purpose);
        case 'customer':
            return nonempty(bmcDraft.customer_segments);
        case 'offer':
            return nonempty(bmcDraft.value_propositions);
        case 'market':
            return nonempty(profileDraft.market);
        case 'revenue':
            return nonempty(bmcDraft.revenue_streams);
        case 'economics':
            return nonempty(
                profileDraft.unit_name,
                profileDraft.average_selling_price,
                profileDraft.variable_cost_per_unit,
                profileDraft.monthly_fixed_cost,
            );
        case 'channels':
            return nonempty(bmcDraft.channels);
        case 'relationships':
            return nonempty(bmcDraft.customer_relationships);
        case 'operations':
            return nonempty(profileDraft.operating_model);
        case 'delivery':
            return nonempty(
                bmcDraft.key_resources,
                bmcDraft.key_activities,
                bmcDraft.key_partnerships,
            );
        case 'costs':
            return nonempty(bmcDraft.cost_structure);
        case 'scalability':
            return nonempty(profileDraft.scalability_strategy);
        case 'boundaries':
            return nonempty(profileDraft.excluded_activities);
        case 'first_year':
            return nonempty(profileDraft.first_12_month_plan);
        case 'review':
            return stepKeys
                .filter((step) => step !== 'review')
                .some((step) => recorded(step));
    }
};

const currentStep = ref<StepKey>(
    stepKeys.find((key) => key !== 'review' && !recorded(key))
        ?? 'review',
);

const currentIndex = computed(() =>
    Math.max(0, stepKeys.indexOf(currentStep.value)),
);

const journeySteps = computed(() =>
    stepKeys.map((key, index) => ({
        key,
        label: stepLabels.value[key],
        state:
            key === currentStep.value
                ? ('current' as const)
                : recorded(key)
                  ? ('recorded' as const)
                  : index === currentIndex.value + 1
                    ? ('next' as const)
                    : ('available' as const),
    })),
);

const inertiaPut = (
    url: string,
    data: PutData,
): Promise<void> =>
    new Promise((resolve, reject) => {
        router.put(url, data, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
            onSuccess: () => resolve(),
            onError: (errors) => {
                const first = Object.values(errors)[0];

                reject(
                    new Error(
                        typeof first === 'string'
                            ? first
                            : c.value.saveError,
                    ),
                );
            },
            onCancel: () => reject(new Error(c.value.saveError)),
        });
    });

let bmcQueue: Promise<void> = Promise.resolve();
let profileQueue: Promise<void> = Promise.resolve();

const queueBmcSave = (snapshot: BmcDraft): Promise<void> => {
    bmcQueue = bmcQueue
        .catch(() => undefined)
        .then(async () => {
            const expectedRevision = bmcRevision.value;

            await inertiaPut('/formation/bmc', {
                expected_revision: expectedRevision,
                ...snapshot,
            });

            bmcRevision.value = expectedRevision + 1;
        });

    return bmcQueue;
};

const queueProfileSave = (snapshot: ProfileDraft): Promise<void> => {
    profileQueue = profileQueue
        .catch(() => undefined)
        .then(async () => {
            const expectedRevision = profileRevision.value;

            await inertiaPut('/formation/business-model/foundation', {
                expected_revision: expectedRevision,
                ...snapshot,
            });

            profileRevision.value = expectedRevision + 1;
        });

    return profileQueue;
};

const bmcAutosave = useAutosaveDraft<BmcDraft>({
    source: () => ({ ...bmcDraft }),
    save: queueBmcSave,
    enabled: () => props.canManage,
    delay: 1000,
});

const profileAutosave = useAutosaveDraft<ProfileDraft>({
    source: () => ({ ...profileDraft }),
    save: queueProfileSave,
    enabled: () => props.canManage,
    delay: 1000,
});

const draftState = computed<DraftSaveState>(() => {
    const states = [
        bmcAutosave.state.value,
        profileAutosave.state.value,
    ];

    if (states.includes('error')) {
        return 'error';
    }

    if (states.includes('saving')) {
        return 'saving';
    }

    if (states.includes('dirty')) {
        return 'dirty';
    }

    if (states.includes('saved')) {
        return 'saved';
    }

    return 'idle';
});

const lastSavedAt = computed<Date | null>(() => {
    const times = [
        bmcAutosave.lastSavedAt.value,
        profileAutosave.lastSavedAt.value,
    ].filter((value): value is Date => value instanceof Date);

    if (times.length === 0) {
        return null;
    }

    return times.sort(
        (left, right) => right.getTime() - left.getTime(),
    )[0];
});

const navigationError = ref('');

const flushDirty = async (): Promise<void> => {
    const tasks: Promise<void>[] = [];

    if (
        bmcAutosave.state.value === 'dirty'
        || bmcAutosave.state.value === 'error'
    ) {
        tasks.push(bmcAutosave.flush());
    }

    if (
        profileAutosave.state.value === 'dirty'
        || profileAutosave.state.value === 'error'
    ) {
        tasks.push(profileAutosave.flush());
    }

    await Promise.all(tasks);
};

const selectStep = async (key: string): Promise<void> => {
    if (!stepKeys.includes(key as StepKey)) {
        return;
    }

    navigationError.value = '';

    try {
        await flushDirty();
        currentStep.value = key as StepKey;
    } catch {
        navigationError.value = c.value.saveError;
    }
};

const move = async (direction: -1 | 1): Promise<void> => {
    navigationError.value = '';

    try {
        await flushDirty();
    } catch {
        navigationError.value = c.value.saveError;

        return;
    }

    const next = currentIndex.value + direction;

    if (next < 0 || next >= stepKeys.length) {
        return;
    }

    currentStep.value = stepKeys[next];
};

const demandStatus = computed(() => {
    if (props.foundation.demand.status === 'validated') {
        return c.value.demandValidated;
    }

    if (props.foundation.demand.status === 'testing') {
        return c.value.demandTesting;
    }

    return c.value.demandNotStarted;
});

const money = (value: string | null): string =>
    value === null ? '—' : value + ' ' + props.currency;
</script>

<template>
    <div class="space-y-5">
        <header
            class="overflow-hidden rounded-[26px] border border-[#d4e2d7] bg-[radial-gradient(circle_at_90%_0%,rgb(210_167_67_/_14%),transparent_18rem),linear-gradient(145deg,#ffffff,#f4faf6)] p-5 shadow-[0_16px_38px_rgb(16_35_26_/_6%)] sm:p-7"
        >
            <div
                class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"
            >
                <div class="min-w-0">
                    <p
                        class="text-xs font-black uppercase tracking-[0.17em] text-[var(--pbr-green)]"
                    >
                        {{ c.eyebrow }}
                    </p>
                    <h2
                        class="pbr-safe-copy mt-2 text-2xl font-black tracking-[-0.035em] sm:text-3xl"
                    >
                        {{ c.title }}
                    </h2>
                    <p
                        class="pbr-safe-copy mt-2 max-w-3xl text-sm leading-7 text-[var(--pbr-muted)]"
                    >
                        {{
                            journey === 'new'
                                ? c.subtitleNew
                                : c.subtitleExisting
                        }}
                    </p>
                </div>

                <PbrDraftStatus
                    :state="draftState"
                    :last-saved-at="lastSavedAt"
                    :idle-label="c.draftIdle"
                    :dirty-label="c.draftDirty"
                    :saving-label="c.draftSaving"
                    :saved-label="c.draftSaved"
                    :error-label="c.draftError"
                />
            </div>

            <p
                role="note"
                class="mt-4 rounded-2xl border border-[#cfe0d4] bg-white/75 px-4 py-3 text-xs leading-5 text-[var(--pbr-green-dark)]"
            >
                {{ c.advisory }}
            </p>
        </header>

        <GuidedJourneyStepper
            :steps="journeySteps"
            :label="c.progressLabel"
            compact
            @select="selectStep"
        />

        <p
            v-if="navigationError"
            role="alert"
            class="rounded-xl border border-[#efc9c2] bg-[#fff5f3] px-4 py-3 text-sm font-bold text-[var(--pbr-red)]"
        >
            {{ navigationError }}
        </p>

        <PbrFormSection
            v-if="currentStep === 'purpose'"
            numbered="1"
            :title="c.purposeLabel"
            :instruction="c.purposeInstruction"
        >
            <GuidedSuggestionTextarea
                v-model="profileDraft.business_purpose"
                :label="c.purposeLabel"
                :instruction="c.purposeInstruction"
                :example="c.purposeExample"
                :suggestions="[...c.purposeSuggestions]"
                :disabled="!canManage"
            />
        </PbrFormSection>

        <PbrFormSection
            v-else-if="currentStep === 'customer'"
            numbered="2"
            :title="c.customerLabel"
            :instruction="c.customerInstruction"
        >
            <GuidedSuggestionTextarea
                v-model="bmcDraft.customer_segments"
                :label="c.customerLabel"
                :instruction="c.customerInstruction"
                :example="c.customerExample"
                :suggestions="[...c.customerSuggestions]"
                :disabled="!canManage"
            />
        </PbrFormSection>

        <PbrFormSection
            v-else-if="currentStep === 'offer'"
            numbered="3"
            :title="c.offerLabel"
            :instruction="c.offerInstruction"
        >
            <GuidedSuggestionTextarea
                v-model="bmcDraft.value_propositions"
                :label="c.offerLabel"
                :instruction="c.offerInstruction"
                :example="c.offerExample"
                :suggestions="[...c.offerSuggestions]"
                :disabled="!canManage"
            />
        </PbrFormSection>

        <PbrFormSection
            v-else-if="currentStep === 'market'"
            numbered="4"
            :title="c.stepMarket"
            :instruction="c.marketInstruction"
        >
            <GuidedSuggestionTextarea
                v-model="profileDraft.market"
                :label="c.marketLabel"
                :instruction="c.marketInstruction"
                :example="c.marketExample"
                :disabled="!canManage"
            />

            <PbrTextInput
                v-if="profileDraft.market.trim() !== ''"
                v-model="profileDraft.location"
                :label="c.locationLabel"
                :instruction="c.locationInstruction"
                :example="c.locationExample"
                :disabled="!canManage"
            />
        </PbrFormSection>

        <PbrFormSection
            v-else-if="currentStep === 'revenue'"
            numbered="5"
            :title="c.revenueLabel"
            :instruction="c.revenueInstruction"
        >
            <GuidedSuggestionTextarea
                v-model="bmcDraft.revenue_streams"
                :label="c.revenueLabel"
                :instruction="c.revenueInstruction"
                :example="c.revenueExample"
                :suggestions="[...c.revenueSuggestions]"
                :disabled="!canManage"
            />
        </PbrFormSection>

        <PbrFormSection
            v-else-if="currentStep === 'economics'"
            numbered="6"
            :title="c.economicsTitle"
            :instruction="c.economicsInstruction"
        >
            <PbrTextInput
                v-model="profileDraft.unit_name"
                :label="c.unitLabel"
                :instruction="c.unitInstruction"
                :example="c.unitExample"
                :disabled="!canManage"
            />

            <PbrTextInput
                v-if="profileDraft.unit_name.trim() !== ''"
                v-model="profileDraft.average_selling_price"
                type="text"
                inputmode="decimal"
                :label="c.priceLabel"
                :instruction="c.priceInstruction"
                :example="c.priceExample"
                :disabled="!canManage"
            />

            <PbrTextInput
                v-if="profileDraft.average_selling_price.trim() !== ''"
                v-model="profileDraft.variable_cost_per_unit"
                type="text"
                inputmode="decimal"
                :label="c.variableLabel"
                :instruction="c.variableInstruction"
                :example="c.variableExample"
                :disabled="!canManage"
            />

            <PbrTextInput
                v-if="profileDraft.variable_cost_per_unit.trim() !== ''"
                v-model="profileDraft.monthly_fixed_cost"
                type="text"
                inputmode="decimal"
                :label="c.fixedLabel"
                :instruction="c.fixedInstruction"
                :example="c.fixedExample"
                :disabled="!canManage"
            />

            <PbrTextInput
                v-if="profileDraft.monthly_fixed_cost.trim() !== ''"
                v-model="profileDraft.expected_monthly_units"
                type="text"
                inputmode="decimal"
                :label="c.unitsLabel"
                :instruction="c.unitsInstruction"
                :example="c.unitsExample"
                :disabled="!canManage"
            />

            <GuidedSuggestionTextarea
                v-if="profileDraft.average_selling_price.trim() !== ''"
                v-model="profileDraft.pricing_notes"
                :label="c.pricingNotesLabel"
                :instruction="c.pricingNotesInstruction"
                :example="c.pricingNotesExample"
                :disabled="!canManage"
                :rows="3"
            />

            <div
                class="rounded-2xl border border-[#d5e4d9] bg-[linear-gradient(145deg,#f4faf6,#fffaf0)] p-4 sm:p-5"
                aria-live="polite"
            >
                <p
                    v-if="foundation.economics.status === 'incomplete'"
                    class="text-sm leading-6 text-[var(--pbr-muted)]"
                >
                    {{ c.economicsWaiting }}
                </p>
                <p
                    v-else-if="
                        foundation.economics.status !== 'ready'
                    "
                    class="text-sm font-bold leading-6 text-[#8a5a2f]"
                >
                    {{ c.economicsInvalid }}
                </p>

                <dl
                    v-else
                    class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <div class="rounded-xl bg-white/80 p-3">
                        <dt class="text-xs font-bold text-[var(--pbr-muted)]">
                            {{ c.contributionMargin }}
                        </dt>
                        <dd class="mt-1 text-lg font-black text-[var(--pbr-green-dark)]">
                            {{
                                money(
                                    foundation.economics
                                        .contributionMarginPerUnit,
                                )
                            }}
                        </dd>
                    </div>
                    <div class="rounded-xl bg-white/80 p-3">
                        <dt class="text-xs font-bold text-[var(--pbr-muted)]">
                            {{ c.grossMargin }}
                        </dt>
                        <dd class="mt-1 text-lg font-black text-[var(--pbr-green-dark)]">
                            {{
                                foundation.economics.grossMarginPercent
                            }}%
                        </dd>
                    </div>
                    <div class="rounded-xl bg-white/80 p-3">
                        <dt class="text-xs font-bold text-[var(--pbr-muted)]">
                            {{ c.breakEvenUnits }}
                        </dt>
                        <dd class="mt-1 text-lg font-black text-[var(--pbr-green-dark)]">
                            {{ foundation.economics.breakEvenUnits }}
                        </dd>
                    </div>
                    <div class="rounded-xl bg-white/80 p-3">
                        <dt class="text-xs font-bold text-[var(--pbr-muted)]">
                            {{ c.breakEvenRevenue }}
                        </dt>
                        <dd class="mt-1 text-lg font-black text-[var(--pbr-green-dark)]">
                            {{
                                money(
                                    foundation.economics.breakEvenRevenue,
                                )
                            }}
                        </dd>
                    </div>
                    <div class="rounded-xl bg-white/80 p-3">
                        <dt class="text-xs font-bold text-[var(--pbr-muted)]">
                            {{ c.expectedRevenue }}
                        </dt>
                        <dd class="mt-1 text-lg font-black text-[var(--pbr-green-dark)]">
                            {{
                                money(
                                    foundation.economics
                                        .expectedMonthlyRevenue,
                                )
                            }}
                        </dd>
                    </div>
                    <div class="rounded-xl bg-white/80 p-3">
                        <dt class="text-xs font-bold text-[var(--pbr-muted)]">
                            {{ c.expectedProfit }}
                        </dt>
                        <dd class="mt-1 text-lg font-black text-[var(--pbr-green-dark)]">
                            {{
                                money(
                                    foundation.economics
                                        .expectedMonthlyOperatingProfit,
                                )
                            }}
                        </dd>
                    </div>
                </dl>
            </div>
        </PbrFormSection>

        <PbrFormSection
            v-else-if="currentStep === 'channels'"
            numbered="7"
            :title="c.channelsLabel"
            :instruction="c.channelsInstruction"
        >
            <GuidedSuggestionTextarea
                v-model="bmcDraft.channels"
                :label="c.channelsLabel"
                :instruction="c.channelsInstruction"
                :example="c.channelsExample"
                :suggestions="[...c.channelSuggestions]"
                :disabled="!canManage"
            />
        </PbrFormSection>

        <PbrFormSection
            v-else-if="currentStep === 'relationships'"
            numbered="8"
            :title="c.relationshipsLabel"
            :instruction="c.relationshipsInstruction"
        >
            <GuidedSuggestionTextarea
                v-model="bmcDraft.customer_relationships"
                :label="c.relationshipsLabel"
                :instruction="c.relationshipsInstruction"
                :example="c.relationshipsExample"
                :suggestions="[...c.relationshipSuggestions]"
                :disabled="!canManage"
            />
        </PbrFormSection>

        <PbrFormSection
            v-else-if="currentStep === 'operations'"
            numbered="9"
            :title="c.operationsLabel"
            :instruction="c.operationsInstruction"
        >
            <GuidedSuggestionTextarea
                v-model="profileDraft.operating_model"
                :label="c.operationsLabel"
                :instruction="c.operationsInstruction"
                :example="c.operationsExample"
                :suggestions="[...c.operationsSuggestions]"
                :disabled="!canManage"
            />
        </PbrFormSection>

        <PbrFormSection
            v-else-if="currentStep === 'delivery'"
            numbered="10"
            :title="c.stepDelivery"
            :instruction="c.resourcesInstruction"
        >
            <GuidedSuggestionTextarea
                v-model="bmcDraft.key_resources"
                :label="c.resourcesLabel"
                :instruction="c.resourcesInstruction"
                :example="c.resourcesExample"
                :disabled="!canManage"
            />

            <GuidedSuggestionTextarea
                v-if="bmcDraft.key_resources.trim() !== ''"
                v-model="bmcDraft.key_activities"
                :label="c.activitiesLabel"
                :instruction="c.activitiesInstruction"
                :example="c.activitiesExample"
                :disabled="!canManage"
            />

            <GuidedSuggestionTextarea
                v-if="bmcDraft.key_activities.trim() !== ''"
                v-model="bmcDraft.key_partnerships"
                :label="c.partnersLabel"
                :instruction="c.partnersInstruction"
                :example="c.partnersExample"
                :disabled="!canManage"
            />
        </PbrFormSection>

        <PbrFormSection
            v-else-if="currentStep === 'costs'"
            numbered="11"
            :title="c.costsLabel"
            :instruction="c.costsInstruction"
        >
            <GuidedSuggestionTextarea
                v-model="bmcDraft.cost_structure"
                :label="c.costsLabel"
                :instruction="c.costsInstruction"
                :example="c.costsExample"
                :disabled="!canManage"
            />
        </PbrFormSection>

        <PbrFormSection
            v-else-if="currentStep === 'scalability'"
            numbered="12"
            :title="c.scaleLabel"
            :instruction="c.scaleInstruction"
        >
            <GuidedSuggestionTextarea
                v-model="profileDraft.scalability_strategy"
                :label="c.scaleLabel"
                :instruction="c.scaleInstruction"
                :example="c.scaleExample"
                :suggestions="[...c.scaleSuggestions]"
                :disabled="!canManage"
            />

            <GuidedSuggestionTextarea
                v-if="profileDraft.scalability_strategy.trim() !== ''"
                v-model="profileDraft.scalability_constraints"
                :label="c.constraintsLabel"
                :instruction="c.constraintsInstruction"
                :example="c.constraintsExample"
                :disabled="!canManage"
            />
        </PbrFormSection>

        <PbrFormSection
            v-else-if="currentStep === 'boundaries'"
            numbered="13"
            :title="c.boundariesLabel"
            :instruction="c.boundariesInstruction"
        >
            <GuidedSuggestionTextarea
                v-model="profileDraft.excluded_activities"
                :label="c.boundariesLabel"
                :instruction="c.boundariesInstruction"
                :example="c.boundariesExample"
                :disabled="!canManage"
            />
        </PbrFormSection>

        <PbrFormSection
            v-else-if="currentStep === 'first_year'"
            numbered="14"
            :title="c.firstYearLabel"
            :instruction="c.firstYearInstruction"
        >
            <GuidedSuggestionTextarea
                v-model="profileDraft.first_12_month_plan"
                :label="c.firstYearLabel"
                :instruction="c.firstYearInstruction"
                :example="c.firstYearExample"
                :disabled="!canManage"
            />
        </PbrFormSection>

        <PbrFormSection
            v-else
            numbered="15"
            :title="c.reviewTitle"
            :instruction="c.reviewHelp"
        >
            <div class="grid gap-3 md:grid-cols-2">
                <article
                    v-for="item in [
                        [c.stepPurpose, profileDraft.business_purpose],
                        [c.stepCustomer, bmcDraft.customer_segments],
                        [c.stepOffer, bmcDraft.value_propositions],
                        [c.stepMarket, profileDraft.market],
                        [c.stepRevenue, bmcDraft.revenue_streams],
                        [c.stepOperations, profileDraft.operating_model],
                        [c.stepScalability, profileDraft.scalability_strategy],
                        [c.stepFirstYear, profileDraft.first_12_month_plan],
                    ]"
                    :key="String(item[0])"
                    class="rounded-2xl border border-[#dce6de] bg-[#fafcfa] p-4"
                >
                    <p class="text-xs font-black uppercase tracking-[0.1em] text-[var(--pbr-green)]">
                        {{ item[0] }}
                    </p>
                    <p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-[var(--pbr-ink-soft)]">
                        {{ item[1] || '—' }}
                    </p>
                </article>
            </div>

            <section
                class="rounded-2xl border border-[#d5e4d9] bg-[var(--pbr-green-soft)] p-4"
            >
                <p class="text-xs font-black uppercase tracking-[0.1em] text-[var(--pbr-green)]">
                    {{ c.demandTitle }}
                </p>
                <p class="mt-1 text-lg font-black text-[var(--pbr-green-dark)]">
                    {{ demandStatus }}
                </p>
                <p class="mt-2 text-xs leading-5 text-[var(--pbr-muted)]">
                    {{ foundation.demand.assumptions }}
                    {{ c.assumptions }} ·
                    {{ foundation.demand.completed_validations }}
                    {{ c.validations }} ·
                    {{ foundation.demand.evidence_links }}
                    {{ c.evidence }}
                </p>

                <p
                    v-if="journey === 'existing'"
                    class="mt-3 text-xs leading-5 text-[var(--pbr-muted)]"
                >
                    {{ c.existingDemandNote }}
                </p>

                <PbrButton
                    v-else
                    class="mt-4"
                    type="button"
                    variant="secondary"
                    @click="emit('openDemand')"
                >
                    {{ c.demandCta }}
                </PbrButton>
            </section>
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
                :busy="draftState === 'saving'"
                :busy-label="c.draftSaving"
                @click="move(1)"
            >
                {{
                    currentIndex === stepKeys.length - 2
                        ? c.review
                        : c.next
                }}
            </PbrButton>
        </div>
    </div>
</template>
