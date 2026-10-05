<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import GuidedJourneyStepper from '../hybrid/GuidedJourneyStepper.vue';
import ProgressiveReveal from '../hybrid/ProgressiveReveal.vue';
import PbrErrorSummary from '../ui/PbrErrorSummary.vue';
import PbrFormSection from '../ui/PbrFormSection.vue';
import { useI18n } from '../../i18n/useI18n';
import { humanErrorMessages } from '../../support/humanErrors';

type GenericRow = Record<string, any>;
type StepKey = 'startup' | 'assets' | 'working' | 'reserve' | 'funding';
type SectionMode = 'missing' | 'zero' | 'items';
type WorkingMethod =
    | 'missing'
    | 'canonical_operating_profile'
    | 'monthly_costs'
    | 'monthly_burn'
    | 'fixed_amount';
type ContingencyMethod = 'missing' | 'percentage' | 'fixed_amount';

type NumericInput = string | number;

type CapitalItem = {
    category: string;
    label: string;
    amount: NumericInput;
};

const props = defineProps<{
    draft: GenericRow | null;
    calculation: GenericRow | null;
    businessModelFoundation: GenericRow | null;
    currency: string;
    canManage: boolean;
}>();

const emit = defineEmits<{
    openBusinessModel: [];
}>();

const { uiLanguageMode } = useI18n();

const copy = {
    en: {
        eyebrow: 'Guided Capital Planning',
        title: 'Work out how much Capital this Business needs to start',
        subtitle:
            'Build the requirement step by step. PBR keeps one-time setup costs, operating cash needs, contingency and funding separate so the same cost is not counted twice.',
        progress: 'Capital guided calculate journey',
        planningBoundary:
            'This is Capital Planning. Saving a draft is not Approval, Signature, Partner Contribution, Equity, Shares, Ownership or an Effective record.',
        revision: 'Saved revision',
        notSaved: 'Not saved yet',
        startupStep: 'Startup Cost Plan',
        assetsStep: 'Initial Assets & Opening Inventory',
        workingStep: 'Working Capital Forecast',
        reserveStep: 'Contingency Reserve',
        fundingStep: 'Funding Position & Gap',
        startupTitle: 'What one-time costs are needed before opening?',
        startupHelp:
            'Record only costs needed to get the Business ready to open. You do not need to create every category.',
        assetsTitle: 'What assets or opening stock are needed?',
        assetsHelp:
            'Keep one-time equipment, furniture, technology and opening stock separate from recurring operating cash.',
        workingTitle: 'How much cash should be kept for early operations?',
        workingHelp:
            'Choose one method. PBR only saves inputs that belong to the selected method.',
        reserveTitle: 'How much contingency buffer should be kept?',
        reserveHelp:
            'This is a buffer for unexpected cost or delay. Percentage calculations use the current server-side Capital calculation base.',
        fundingTitle: 'How much confirmed funding is available now?',
        fundingHelp:
            'Confirmed Funding is a Capital planning input only. It is not Partner Contribution, Equity or Ownership.',
        openingDate: 'Planned opening date',
        openingDateHelp: 'Optional planning assumption. This is not an Effective Date.',
        sectionState: 'How should this section be recorded?',
        notEntered: 'Not entered yet',
        explicitZeroStartup: 'There are no Startup Costs / zero',
        explicitZeroAssets: 'There are no Initial Assets / Opening Inventory / zero',
        haveItems: 'I have items to enter',
        category: 'Category',
        description: 'Description',
        amount: 'Amount',
        addItem: 'Add item',
        remove: 'Remove',
        moveUp: 'Move up',
        moveDown: 'Move down',
        registrationLegal: 'Registration / Legal',
        deposit: 'Deposit',
        renovation: 'Renovation',
        launchMarketing: 'Launch Marketing',
        training: 'Training',
        equipment: 'Equipment',
        furniture: 'Furniture',
        technology: 'Technology',
        openingStock: 'Opening Stock',
        salary: 'Salary',
        rent: 'Rent',
        utilities: 'Utilities',
        software: 'Software',
        monthlyMarketing: 'Monthly Marketing',
        admin: 'Admin',
        other: 'Other',
        method: 'Working Capital method',
        methodMissing: 'Not selected yet',
        canonicalMethod: 'Use existing Business Model numbers',
        canonicalHelp:
            'PBR will reuse the current authorized Business Model economics instead of asking you to type the same assumptions again.',
        monthlyCostsMethod: 'Enter monthly operating costs',
        monthlyBurnMethod: 'Enter one monthly burn amount',
        fixedMethod: 'Enter one fixed Working Capital amount',
        months: 'Working Capital months',
        monthlyBurn: 'Monthly burn',
        fixedWorkingCapital: 'Fixed Working Capital amount',
        monthlyCostsState: 'Monthly cost list',
        zeroMonthlyCosts: 'Monthly operating costs are explicitly zero',
        canonicalAvailable: 'Current Business Model assumptions available',
        canonicalUnavailable:
            'Business Model economics are not available to this Capital view. PBR will not expose or guess those values.',
        canonicalMissing:
            'Business Model operating assumptions have not been completed yet.',
        sellingPrice: 'Average selling price',
        variableCost: 'Variable cost per unit',
        fixedCost: 'Monthly fixed cost',
        expectedUnits: 'Expected monthly units',
        resolvedMonthlyCost: 'Resolved monthly operating cost',
        updateBusinessModel: 'Review Business Model numbers',
        contingencyMethod: 'Contingency method',
        percentage: 'Percentage',
        fixedAmount: 'Fixed amount',
        contingencyPercentage: 'Contingency percentage',
        contingencyAmount: 'Contingency amount',
        currentBase: 'Current server calculation base',
        confirmedFunding: 'Confirmed Funding',
        confirmedFundingHelp:
            'Enter only funding that is genuinely confirmed for planning. Leave blank if it is not known yet.',
        livePosition: 'Current saved Capital position',
        livePositionHelp:
            'These values come from the server calculation contract. Unsaved edits do not create a second calculation truth.',
        preOpening: 'Pre-opening Cost',
        initialAssets: 'Initial Assets / Inventory',
        workingCapital: 'Working Capital',
        contingency: 'Contingency Reserve',
        totalRequired: 'Total Capital Required',
        funding: 'Confirmed Funding',
        gap: 'Funding Gap',
        surplus: 'Funding Surplus',
        fundedPercent: '% Funded',
        unavailable: 'Not available yet',
        explicitZero: 'Explicit zero',
        incompleteTitle: 'What still needs input?',
        startupIncomplete: 'Startup Cost Plan still has missing information.',
        assetsIncomplete: 'Initial Assets / Opening Inventory still has missing information.',
        workingIncomplete: 'Working Capital is missing or incomplete.',
        reserveIncomplete: 'Contingency Reserve is missing or incomplete.',
        fundingIncomplete: 'Confirmed Funding has not been entered yet.',
        saveFirst: 'Save the current draft to refresh the server-calculated Capital position.',
        saveDraft: 'Save Capital draft',
        saving: 'Saving…',
        saved: 'Capital draft saved. Server calculation refreshed.',
        readOnly:
            'You can review this Capital plan, but your access does not allow editing or saving it.',
        accessUnavailable:
            'Capital planning is not available for this account in the current Business.',
        previous: 'Back',
        next: 'Continue',
        errorTitle: 'The Capital draft could not be saved',
        errorHelp:
            'Review the visible fields. Missing information may stay blank, but entered amounts must be valid non-negative values.',
        staleError:
            'This Capital draft changed after you opened it. Reload the latest saved draft, review it, and then save again.',
        invalidDraftError:
            'Check the visible Capital fields. Entered amounts must be non-negative and every started cost item needs a description.',
        reloadLatest: 'Reload latest saved draft',
        latestLoaded: 'Latest saved Capital draft loaded.',
        itemNeeded:
            'Add at least one described item, or choose Not entered yet / explicit zero.',
        descriptionNeeded: 'Every entered cost item needs a description.',
        invalidMoney: 'Entered amounts must be non-negative with up to two decimal places.',
        invalidMonths: 'Working Capital months must be a whole number from 0 to 24.',
        invalidPercent: 'Contingency percentage must be from 0 to 100.',
        saveToResolve: 'Save this method to resolve the current Business Model numbers.',
        zeroMeaning:
            'Zero means you intentionally confirmed there is no amount. Blank means the information is not entered yet.',
    },
    my: {
        eyebrow: 'Guided Capital Planning',
        title: 'ဒီလုပ်ငန်းစဖို့ Capital ဘယ်လောက်လိုမလဲ အဆင့်လိုက်တွက်ပါ',
        subtitle:
            'စဖွင့်ခါစ ကုန်ကျစရိတ်၊ ပစ္စည်း/Stock၊ လည်ပတ်ငွေ၊ Contingency နဲ့ Funding ကို သီးခြားထားပြီး တစ်ခုတည်းကို နှစ်ခါမတွက်အောင် PBR က ကူညီပေးပါတယ်။',
        progress: 'Capital guided calculate journey',
        planningBoundary:
            'ဒီနေရာက Capital Planning သာဖြစ်ပါတယ်။ Draft သိမ်းတာက Approval, Signature, Partner Contribution, Equity, Shares, Ownership သို့မဟုတ် Effective Record မဟုတ်ပါ။',
        revision: 'သိမ်းထားသော Revision',
        notSaved: 'မသိမ်းရသေးပါ',
        startupStep: 'Startup Cost Plan',
        assetsStep: 'Initial Assets & Opening Inventory',
        workingStep: 'Working Capital Forecast',
        reserveStep: 'Contingency Reserve',
        fundingStep: 'Funding Position & Gap',
        startupTitle: 'မဖွင့်ခင် တစ်ကြိမ်တည်းကုန်ကျမယ့် ဘာတွေရှိသလဲ?',
        startupHelp:
            'Business စဖွင့်ဖို့ တကယ်လိုတဲ့ one-time costs ကိုပဲထည့်ပါ။ Category အားလုံး ဖြည့်ဖို့မလိုပါ။',
        assetsTitle: 'ဘယ် Assets နဲ့ Opening Stock တွေလိုမလဲ?',
        assetsHelp:
            'Equipment, Furniture, Technology နဲ့ Opening Stock ကို လစဉ်လည်ပတ်ငွေနဲ့ သီးခြားထားပါ။',
        workingTitle: 'အစပိုင်း လည်ပတ်ဖို့ ငွေဘယ်လောက်ထားမလဲ?',
        workingHelp:
            'နည်းတစ်မျိုးရွေးပါ။ ရွေးထားတဲ့နည်းနဲ့ဆိုင်တဲ့ Inputs တွေကိုပဲ PBR က official draft input အဖြစ်သိမ်းပါတယ်။',
        reserveTitle: 'မမျှော်လင့်ထားတဲ့ကုန်ကျစရိတ်အတွက် Buffer ဘယ်လောက်ထားမလဲ?',
        reserveHelp:
            'Unexpected cost / delay အတွက်ထားတဲ့ buffer ပါ။ Percentage ရွေးရင် လက်ရှိ server calculation base ကိုပဲသုံးပါတယ်။',
        fundingTitle: 'အခု တကယ် Confirm ဖြစ်ထားတဲ့ Funding ဘယ်လောက်ရှိသလဲ?',
        fundingHelp:
            'Confirmed Funding က Capital planning input သာဖြစ်ပြီး Partner Contribution, Equity သို့မဟုတ် Ownership မဟုတ်ပါ။',
        openingDate: 'ဖွင့်ရန် စီစဉ်ထားသည့်နေ့',
        openingDateHelp: 'Optional planning assumption သာဖြစ်ပြီး Effective Date မဟုတ်ပါ။',
        sectionState: 'ဒီ Section ကို ဘယ်လိုမှတ်မလဲ?',
        notEntered: 'မဖြည့်ရသေး',
        explicitZeroStartup: 'Startup Cost မရှိပါ / zero',
        explicitZeroAssets: 'Initial Assets / Opening Inventory မရှိပါ / zero',
        haveItems: 'ထည့်မယ့် Items ရှိတယ်',
        category: 'Category',
        description: 'အကြောင်းအရာ',
        amount: 'ငွေပမာဏ',
        addItem: 'Item ထည့်မည်',
        remove: 'ဖျက်မည်',
        moveUp: 'အပေါ်ရွှေ့',
        moveDown: 'အောက်ရွှေ့',
        registrationLegal: 'Registration / Legal',
        deposit: 'Deposit',
        renovation: 'Renovation',
        launchMarketing: 'Launch Marketing',
        training: 'Training',
        equipment: 'Equipment',
        furniture: 'Furniture',
        technology: 'Technology',
        openingStock: 'Opening Stock',
        salary: 'Salary',
        rent: 'Rent',
        utilities: 'Utilities',
        software: 'Software',
        monthlyMarketing: 'Monthly Marketing',
        admin: 'Admin',
        other: 'Other',
        method: 'Working Capital နည်းလမ်း',
        methodMissing: 'မရွေးရသေး',
        canonicalMethod: 'ရှိပြီးသား Business Model numbers ကိုသုံးမယ်',
        canonicalHelp:
            'PBR က လက်ရှိ authorized Business Model economics ကို ပြန်သုံးမယ်။ တူညီတဲ့ numbers ကို Capital မှာ ပြန်ရိုက်စရာမလိုပါ။',
        monthlyCostsMethod: 'လစဉ် operating costs ကို တစ်ခုချင်းထည့်မယ်',
        monthlyBurnMethod: 'Monthly burn တစ်ခုပဲထည့်မယ်',
        fixedMethod: 'Working Capital total တစ်ခုပဲထည့်မယ်',
        months: 'Working Capital အတွက် လအရေအတွက်',
        monthlyBurn: 'Monthly burn',
        fixedWorkingCapital: 'Fixed Working Capital amount',
        monthlyCostsState: 'Monthly cost list',
        zeroMonthlyCosts: 'Monthly operating costs ကို zero လို့အတည်ပြုထားတယ်',
        canonicalAvailable: 'လက်ရှိ Business Model assumptions ရှိပါတယ်',
        canonicalUnavailable:
            'ဒီ Capital view မှာ Business Model economics ကြည့်ခွင့်မရှိပါ။ PBR က အဲဒီ values တွေကို မပြဘဲ မခန့်မှန်းပါ။',
        canonicalMissing:
            'Business Model operating assumptions မပြည့်သေးပါ။',
        sellingPrice: 'Average selling price',
        variableCost: 'Variable cost per unit',
        fixedCost: 'Monthly fixed cost',
        expectedUnits: 'Expected monthly units',
        resolvedMonthlyCost: 'ပြန်သုံးထားသည့် monthly operating cost',
        updateBusinessModel: 'Business Model numbers ပြန်စစ်မည်',
        contingencyMethod: 'Contingency နည်းလမ်း',
        percentage: 'Percentage',
        fixedAmount: 'Fixed amount',
        contingencyPercentage: 'Contingency percentage',
        contingencyAmount: 'Contingency amount',
        currentBase: 'လက်ရှိ server calculation base',
        confirmedFunding: 'Confirmed Funding',
        confirmedFundingHelp:
            'တကယ် Confirm ဖြစ်ထားတဲ့ Funding ကိုပဲထည့်ပါ။ မသိသေးရင် blank ထားပါ။',
        livePosition: 'လက်ရှိ သိမ်းထားတဲ့ Capital Position',
        livePositionHelp:
            'ဒီ values တွေကို server calculation contract ကတွက်ထားတာပါ။ မသိမ်းရသေးတဲ့ edits တွေက calculation truth အသစ် မဖန်တီးပါ။',
        preOpening: 'Pre-opening Cost',
        initialAssets: 'Initial Assets / Inventory',
        workingCapital: 'Working Capital',
        contingency: 'Contingency Reserve',
        totalRequired: 'Total Capital Required',
        funding: 'Confirmed Funding',
        gap: 'Funding Gap',
        surplus: 'Funding Surplus',
        fundedPercent: '% Funded',
        unavailable: 'မတွက်နိုင်သေး',
        explicitZero: 'Zero လို့အတည်ပြုထားသည်',
        incompleteTitle: 'ဘာတွေလိုသေးလဲ?',
        startupIncomplete: 'Startup Cost Plan မှာ မပြည့်သေးတဲ့အချက်ရှိပါတယ်။',
        assetsIncomplete: 'Initial Assets / Opening Inventory မှာ မပြည့်သေးတဲ့အချက်ရှိပါတယ်။',
        workingIncomplete: 'Working Capital မပြည့်သေးပါ။',
        reserveIncomplete: 'Contingency Reserve မပြည့်သေးပါ။',
        fundingIncomplete: 'Confirmed Funding မဖြည့်ရသေးပါ။',
        saveFirst: 'Server-calculated Capital Position အသစ်ရဖို့ လက်ရှိ Draft ကိုသိမ်းပါ။',
        saveDraft: 'Capital Draft သိမ်းမည်',
        saving: 'သိမ်းနေသည်…',
        saved: 'Capital Draft သိမ်းပြီး Server Calculation ကို refresh လုပ်ပြီးပါပြီ။',
        readOnly:
            'ဒီ Capital Plan ကိုကြည့်နိုင်ပေမယ့် Edit/Save လုပ်ခွင့်မရှိပါ။',
        accessUnavailable:
            'ဒီ Business မှာ ဒီ Account အတွက် Capital Planning ကိုအသုံးပြုခွင့်မရှိပါ။',
        previous: 'နောက်ပြန်',
        next: 'ဆက်သွားမည်',
        errorTitle: 'Capital Draft ကို မသိမ်းနိုင်သေးပါ',
        errorHelp:
            'မြင်နေရတဲ့ Fields ကိုစစ်ပါ။ မသိသေးတဲ့အချက်ကို blank ထားလို့ရပေမယ့် ဖြည့်ထားတဲ့ amount က valid non-negative value ဖြစ်ရပါမယ်။',
        staleError:
            'ဒီ Capital Draft ကို သင်ဖွင့်ထားပြီးနောက် အခြား update တစ်ခုရှိသွားပါတယ်။ နောက်ဆုံးသိမ်းထားတဲ့ Draft ကိုပြန်ယူပြီး စစ်ဆေးပြီးမှ ပြန်သိမ်းပါ။',
        invalidDraftError:
            'မြင်နေရတဲ့ Capital Fields ကိုစစ်ပါ။ Amount က zero သို့မဟုတ် positive ဖြစ်ရပြီး စထားတဲ့ cost item တိုင်းမှာ description လိုပါတယ်။',
        reloadLatest: 'နောက်ဆုံးသိမ်းထားတဲ့ Draft ပြန်ယူမည်',
        latestLoaded: 'နောက်ဆုံးသိမ်းထားတဲ့ Capital Draft ကို ပြန်ယူပြီးပါပြီ။',
        itemNeeded:
            'Item တစ်ခုထည့်ပါ၊ မဟုတ်ရင် မဖြည့်ရသေး သို့မဟုတ် zero ကိုရွေးပါ။',
        descriptionNeeded: 'ထည့်ထားတဲ့ cost item တိုင်းမှာ description လိုပါတယ်။',
        invalidMoney: 'Amount က zero သို့မဟုတ် positive ဖြစ်ပြီး decimal ၂ လုံးအထိသာဖြစ်ရပါမယ်။',
        invalidMonths: 'Working Capital months က 0 မှ 24 အတွင်း whole number ဖြစ်ရပါမယ်။',
        invalidPercent: 'Contingency percentage က 0 မှ 100 အတွင်းဖြစ်ရပါမယ်။',
        saveToResolve: 'လက်ရှိ Business Model numbers ကို resolve လုပ်ဖို့ ဒီ method ကို Draft အဖြစ်သိမ်းပါ။',
        zeroMeaning:
            'Zero ဆိုတာ ပမာဏမရှိကြောင်း ကိုယ်တိုင်အတည်ပြုထားတာပါ။ Blank ဆိုတာ မဖြည့်ရသေးတာပါ။',
    },
    mixed: {
        eyebrow: 'Guided Capital Planning',
        title: 'ဒီ Business စဖို့ Capital ဘယ်လောက်လိုမလဲ guided steps နဲ့တွက်ပါ',
        subtitle:
            'One-time setup costs, Assets/Stock, operating cash, contingency နဲ့ funding ကို separate လုပ်ထားလို့ same cost ကို double-count မလုပ်ပါ။',
        progress: 'Capital guided calculate journey',
        planningBoundary:
            'This is Capital Planning only. Save Draft က Approval, Signature, Partner Contribution, Equity, Shares, Ownership or Effective truth မဟုတ်ပါ။',
        revision: 'Saved revision',
        notSaved: 'Not saved yet',
        startupStep: 'Startup Cost Plan',
        assetsStep: 'Initial Assets & Opening Inventory',
        workingStep: 'Working Capital Forecast',
        reserveStep: 'Contingency Reserve',
        fundingStep: 'Funding Position & Gap',
        startupTitle: 'Before opening, what one-time costs do you need?',
        startupHelp: 'Business ready ဖြစ်ဖို့လိုတဲ့ one-time costs ကိုပဲထည့်ပါ။ Category အားလုံး မလိုပါ။',
        assetsTitle: 'What Assets or Opening Stock do you need?',
        assetsHelp: 'One-time Assets/Stock ကို recurring Working Capital နဲ့ separate ထားပါ။',
        workingTitle: 'Early operations အတွက် cash ဘယ်လောက်ထားမလဲ?',
        workingHelp: 'Method တစ်ခုရွေးပါ။ Selected method နဲ့ဆိုင်တဲ့ inputs ကိုပဲ save လုပ်ပါတယ်။',
        reserveTitle: 'Unexpected cost / delay buffer ဘယ်လောက်ထားမလဲ?',
        reserveHelp: 'Percentage method က current server calculation base ကိုသုံးပါတယ်။',
        fundingTitle: 'How much Confirmed Funding is available now?',
        fundingHelp: 'Confirmed Funding is planning input only; not Contribution, Equity or Ownership.',
        openingDate: 'Planned opening date',
        openingDateHelp: 'Planning assumption only; not Effective Date.',
        sectionState: 'How should this section be recorded?',
        notEntered: 'Not entered yet',
        explicitZeroStartup: 'No Startup Costs / zero',
        explicitZeroAssets: 'No Initial Assets / Opening Inventory / zero',
        haveItems: 'I have items to enter',
        category: 'Category',
        description: 'Description',
        amount: 'Amount',
        addItem: 'Add item',
        remove: 'Remove',
        moveUp: 'Move up',
        moveDown: 'Move down',
        registrationLegal: 'Registration / Legal',
        deposit: 'Deposit',
        renovation: 'Renovation',
        launchMarketing: 'Launch Marketing',
        training: 'Training',
        equipment: 'Equipment',
        furniture: 'Furniture',
        technology: 'Technology',
        openingStock: 'Opening Stock',
        salary: 'Salary',
        rent: 'Rent',
        utilities: 'Utilities',
        software: 'Software',
        monthlyMarketing: 'Monthly Marketing',
        admin: 'Admin',
        other: 'Other',
        method: 'Working Capital method',
        methodMissing: 'Not selected yet',
        canonicalMethod: 'Use existing Business Model numbers',
        canonicalHelp: 'PBR က authorized Business Model economics ကို reuse လုပ်ပါတယ်။ Same numbers ကိုပြန်မရိုက်ရပါ။',
        monthlyCostsMethod: 'Enter monthly operating costs',
        monthlyBurnMethod: 'Enter one monthly burn amount',
        fixedMethod: 'Enter one fixed Working Capital amount',
        months: 'Working Capital months',
        monthlyBurn: 'Monthly burn',
        fixedWorkingCapital: 'Fixed Working Capital amount',
        monthlyCostsState: 'Monthly cost list',
        zeroMonthlyCosts: 'Monthly operating costs are explicitly zero',
        canonicalAvailable: 'Current Business Model assumptions available',
        canonicalUnavailable: 'Business Model economics access မရှိလို့ values ကို PBR မပြပါ၊ မခန့်မှန်းပါ။',
        canonicalMissing: 'Business Model operating assumptions are not complete yet.',
        sellingPrice: 'Average selling price',
        variableCost: 'Variable cost per unit',
        fixedCost: 'Monthly fixed cost',
        expectedUnits: 'Expected monthly units',
        resolvedMonthlyCost: 'Resolved monthly operating cost',
        updateBusinessModel: 'Review Business Model numbers',
        contingencyMethod: 'Contingency method',
        percentage: 'Percentage',
        fixedAmount: 'Fixed amount',
        contingencyPercentage: 'Contingency percentage',
        contingencyAmount: 'Contingency amount',
        currentBase: 'Current server calculation base',
        confirmedFunding: 'Confirmed Funding',
        confirmedFundingHelp: 'Confirmed amount ကိုပဲထည့်ပါ။ Unknown ဖြစ်ရင် blank ထားပါ။',
        livePosition: 'Current saved Capital position',
        livePositionHelp: 'Server calculation contract ကပေးတဲ့ values သာ authoritative calculation ဖြစ်ပါတယ်။',
        preOpening: 'Pre-opening Cost',
        initialAssets: 'Initial Assets / Inventory',
        workingCapital: 'Working Capital',
        contingency: 'Contingency Reserve',
        totalRequired: 'Total Capital Required',
        funding: 'Confirmed Funding',
        gap: 'Funding Gap',
        surplus: 'Funding Surplus',
        fundedPercent: '% Funded',
        unavailable: 'Not available yet',
        explicitZero: 'Explicit zero',
        incompleteTitle: 'What still needs input?',
        startupIncomplete: 'Startup Cost Plan needs more information.',
        assetsIncomplete: 'Initial Assets / Opening Inventory needs more information.',
        workingIncomplete: 'Working Capital is missing or incomplete.',
        reserveIncomplete: 'Contingency Reserve is missing or incomplete.',
        fundingIncomplete: 'Confirmed Funding has not been entered yet.',
        saveFirst: 'Save the draft to refresh server-calculated Capital position.',
        saveDraft: 'Save Capital draft',
        saving: 'Saving…',
        saved: 'Capital draft saved and server calculation refreshed.',
        readOnly: 'You can review this Capital plan but cannot edit or save it.',
        accessUnavailable: 'Capital planning is not available for this account.',
        previous: 'Back',
        next: 'Continue',
        errorTitle: 'Capital draft could not be saved',
        errorHelp: 'Visible fields ကိုစစ်ပါ။ Entered amounts must be valid non-negative values.',
        staleError: 'ဒီ Capital draft မှာ newer update ရှိသွားပါတယ်။ Latest saved draft ကို reload လုပ်ပြီး review လုပ်ပြီးမှ save ပြန်လုပ်ပါ။',
        invalidDraftError: 'Visible Capital fields ကိုစစ်ပါ။ Amounts must be non-negative and started cost items need descriptions.',
        reloadLatest: 'Reload latest saved draft',
        latestLoaded: 'Latest saved Capital draft loaded.',
        itemNeeded: 'Add an item, or choose Not entered yet / explicit zero.',
        descriptionNeeded: 'Every entered cost item needs a description.',
        invalidMoney: 'Amounts must be non-negative with up to two decimals.',
        invalidMonths: 'Working Capital months must be a whole number from 0 to 24.',
        invalidPercent: 'Contingency percentage must be from 0 to 100.',
        saveToResolve: 'Save this method to resolve current Business Model numbers.',
        zeroMeaning: 'Zero = intentionally no amount. Blank = not entered yet.',
    },
} as const;

const c = computed(() => copy[uiLanguageMode.value]);

const focus = ref<StepKey>('startup');
const expectedRevision = ref(0);
const openingDate = ref('');
const startupMode = ref<SectionMode>('missing');
const startupItems = ref<CapitalItem[]>([]);
const assetsMode = ref<SectionMode>('missing');
const assetItems = ref<CapitalItem[]>([]);
const workingMethod = ref<WorkingMethod>('missing');
const workingMonths = ref<NumericInput>('');
const monthlyBurn = ref<NumericInput>('');
const fixedWorkingCapital = ref<NumericInput>('');
const monthlyCostsMode = ref<SectionMode>('missing');
const monthlyCostItems = ref<CapitalItem[]>([]);
const contingencyMethod = ref<ContingencyMethod>('missing');
const contingencyPercentage = ref<NumericInput>('');
const contingencyAmount = ref<NumericInput>('');
const confirmedFunding = ref<NumericInput>('');
const busy = ref(false);
const errors = ref<string[]>([]);
const success = ref('');

const cloneItems = (value: unknown): CapitalItem[] => {
    if (!Array.isArray(value)) return [];

    return value.map((item) => ({
        category: String(item?.category ?? 'other'),
        label: String(item?.label ?? ''),
        amount:
            item?.amount === null || item?.amount === undefined
                ? ''
                : String(item.amount),
    }));
};

const sectionMode = (value: unknown): SectionMode => {
    if (value === null || value === undefined) return 'missing';
    if (Array.isArray(value) && value.length === 0) return 'zero';

    return 'items';
};

const hydrateDraft = (source: GenericRow | null): void => {
    const input = (source?.input ?? null) as GenericRow | null;

    expectedRevision.value = Number(source?.revision ?? 0);

    if (input === null) {
        openingDate.value = '';
        startupMode.value = 'missing';
        startupItems.value = [];
        assetsMode.value = 'missing';
        assetItems.value = [];
        workingMethod.value = 'missing';
        workingMonths.value = '';
        monthlyBurn.value = '';
        fixedWorkingCapital.value = '';
        monthlyCostsMode.value = 'missing';
        monthlyCostItems.value = [];
        contingencyMethod.value = 'missing';
        contingencyPercentage.value = '';
        contingencyAmount.value = '';
        confirmedFunding.value = '';

        return;
    }

    openingDate.value = String(input.openingDate ?? '');
    startupMode.value = sectionMode(input.preOpeningItems);
    startupItems.value = cloneItems(input.preOpeningItems);
    assetsMode.value = sectionMode(input.initialAssetsInventoryItems);
    assetItems.value = cloneItems(input.initialAssetsInventoryItems);

    const working = (input.workingCapital ?? null) as GenericRow | null;
    workingMethod.value = (working?.method ?? 'missing') as WorkingMethod;
    workingMonths.value =
        working?.months === null || working?.months === undefined
            ? ''
            : String(working.months);
    monthlyBurn.value = String(working?.monthlyBurn ?? '');
    fixedWorkingCapital.value = String(working?.amount ?? '');
    monthlyCostsMode.value = sectionMode(working?.items);
    monthlyCostItems.value = cloneItems(working?.items);

    const reserve = (input.contingency ?? null) as GenericRow | null;
    contingencyMethod.value = (reserve?.method ?? 'missing') as ContingencyMethod;
    contingencyPercentage.value = String(reserve?.percentage ?? '');
    contingencyAmount.value = String(reserve?.amount ?? '');
    confirmedFunding.value = String(input.confirmedFunding ?? '');
};

hydrateDraft(props.draft);

const preOpeningCategories = computed(() => [
    { value: 'registration_legal', label: c.value.registrationLegal },
    { value: 'deposit', label: c.value.deposit },
    { value: 'renovation', label: c.value.renovation },
    { value: 'launch_marketing', label: c.value.launchMarketing },
    { value: 'training', label: c.value.training },
    { value: 'other', label: c.value.other },
]);

const assetCategories = computed(() => [
    { value: 'equipment', label: c.value.equipment },
    { value: 'furniture', label: c.value.furniture },
    { value: 'technology', label: c.value.technology },
    { value: 'opening_stock', label: c.value.openingStock },
    { value: 'other', label: c.value.other },
]);

const monthlyCostCategories = computed(() => [
    { value: 'salary', label: c.value.salary },
    { value: 'rent', label: c.value.rent },
    { value: 'utilities', label: c.value.utilities },
    { value: 'software', label: c.value.software },
    { value: 'monthly_marketing', label: c.value.monthlyMarketing },
    { value: 'admin', label: c.value.admin },
    { value: 'other', label: c.value.other },
]);

const addItem = (
    items: CapitalItem[],
    category: string,
): void => {
    items.push({
        category,
        label: '',
        amount: '',
    });
};

const removeItem = (
    items: CapitalItem[],
    index: number,
): void => {
    items.splice(index, 1);
};

const moveItem = (
    items: CapitalItem[],
    index: number,
    direction: -1 | 1,
): void => {
    const target = index + direction;

    if (target < 0 || target >= items.length) return;

    const current = items[index];
    items[index] = items[target];
    items[target] = current;
};

const savedInput = computed(
    () => (props.draft?.input ?? null) as GenericRow | null,
);

const stepRecorded = (key: StepKey): boolean => {
    const input = savedInput.value;

    if (input === null) return false;

    if (key === 'startup') {
        return input.preOpeningItems !== null;
    }

    if (key === 'assets') {
        return input.initialAssetsInventoryItems !== null;
    }

    if (key === 'working') {
        return input.workingCapital !== null;
    }

    if (key === 'reserve') {
        return input.contingency !== null;
    }

    return input.confirmedFunding !== null;
};

const stepLabels = computed<Record<StepKey, string>>(() => ({
    startup: c.value.startupStep,
    assets: c.value.assetsStep,
    working: c.value.workingStep,
    reserve: c.value.reserveStep,
    funding: c.value.fundingStep,
}));

const steps = computed(() =>
    (['startup', 'assets', 'working', 'reserve', 'funding'] as StepKey[]).map(
        (key) => ({
            key,
            label: stepLabels.value[key],
            state:
                focus.value === key
                    ? ('current' as const)
                    : stepRecorded(key)
                      ? ('recorded' as const)
                      : ('available' as const),
        }),
    ),
);

const selectStep = (key: string): void => {
    focus.value = key as StepKey;
};

const previousStep = (): void => {
    const keys: StepKey[] = [
        'startup',
        'assets',
        'working',
        'reserve',
        'funding',
    ];
    const index = keys.indexOf(focus.value);

    if (index > 0) focus.value = keys[index - 1];
};

const nextStep = (): void => {
    const keys: StepKey[] = [
        'startup',
        'assets',
        'working',
        'reserve',
        'funding',
    ];
    const index = keys.indexOf(focus.value);

    if (index >= 0 && index < keys.length - 1) {
        focus.value = keys[index + 1];
    }
};

const inputText = (value: unknown): string => {
    if (value === null || value === undefined) {
        return '';
    }

    return String(value).trim();
};

const moneyOrNull = (value: unknown): string | null => {
    const normalized = inputText(value);

    return normalized === '' ? null : normalized;
};

const monthsOrNull = (): number | null => {
    const normalized = inputText(workingMonths.value);

    return normalized === '' ? null : Number(normalized);
};

const itemsPayload = (
    mode: SectionMode,
    items: CapitalItem[],
): GenericRow[] | null => {
    if (mode === 'missing') return null;
    if (mode === 'zero') return [];

    return items.map((item) => ({
        category: item.category,
        label: item.label.trim(),
        amount: moneyOrNull(item.amount),
    }));
};

const workingPayload = (): GenericRow | null => {
    if (workingMethod.value === 'missing') return null;

    if (workingMethod.value === 'canonical_operating_profile') {
        return {
            method: 'canonical_operating_profile',
            months: monthsOrNull(),
        };
    }

    if (workingMethod.value === 'monthly_costs') {
        return {
            method: 'monthly_costs',
            months: monthsOrNull(),
            items: itemsPayload(
                monthlyCostsMode.value,
                monthlyCostItems.value,
            ),
        };
    }

    if (workingMethod.value === 'monthly_burn') {
        return {
            method: 'monthly_burn',
            months: monthsOrNull(),
            monthlyBurn: moneyOrNull(monthlyBurn.value),
        };
    }

    return {
        method: 'fixed_amount',
        amount: moneyOrNull(fixedWorkingCapital.value),
    };
};

const contingencyPayload = (): GenericRow | null => {
    if (contingencyMethod.value === 'missing') return null;

    if (contingencyMethod.value === 'percentage') {
        return {
            method: 'percentage',
            percentage: moneyOrNull(contingencyPercentage.value),
        };
    }

    return {
        method: 'fixed_amount',
        amount: moneyOrNull(contingencyAmount.value),
    };
};

const buildInput = (): GenericRow => ({
    openingDate:
        inputText(openingDate.value) === ''
            ? null
            : inputText(openingDate.value),
    preOpeningItems: itemsPayload(
        startupMode.value,
        startupItems.value,
    ),
    initialAssetsInventoryItems: itemsPayload(
        assetsMode.value,
        assetItems.value,
    ),
    workingCapital: workingPayload(),
    contingency: contingencyPayload(),
    confirmedFunding: moneyOrNull(confirmedFunding.value),
});

const moneyPattern = /^\d{1,12}(?:\.\d{1,2})?$/;

const validateItems = (
    mode: SectionMode,
    items: CapitalItem[],
): string[] => {
    if (mode !== 'items') return [];

    if (items.length === 0) {
        return [c.value.itemNeeded];
    }

    const messages: string[] = [];

    if (items.some((item) => item.label.trim() === '')) {
        messages.push(c.value.descriptionNeeded);
    }

    if (
        items.some(
            (item) =>
                inputText(item.amount) !== ''
                && !moneyPattern.test(inputText(item.amount)),
        )
    ) {
        messages.push(c.value.invalidMoney);
    }

    return messages;
};

const validateVisibleInput = (): string[] => {
    const messages = [
        ...validateItems(startupMode.value, startupItems.value),
        ...validateItems(assetsMode.value, assetItems.value),
    ];

    if (workingMethod.value !== 'missing') {
        const months = inputText(workingMonths.value);

        if (
            workingMethod.value !== 'fixed_amount'
            && months !== ''
            && (!/^\d+$/.test(months)
                || Number(months) < 0
                || Number(months) > 24)
        ) {
            messages.push(c.value.invalidMonths);
        }

        if (
            workingMethod.value === 'monthly_burn'
            && inputText(monthlyBurn.value) !== ''
            && !moneyPattern.test(inputText(monthlyBurn.value))
        ) {
            messages.push(c.value.invalidMoney);
        }

        if (
            workingMethod.value === 'fixed_amount'
            && inputText(fixedWorkingCapital.value) !== ''
            && !moneyPattern.test(inputText(fixedWorkingCapital.value))
        ) {
            messages.push(c.value.invalidMoney);
        }

        if (workingMethod.value === 'monthly_costs') {
            messages.push(
                ...validateItems(
                    monthlyCostsMode.value,
                    monthlyCostItems.value,
                ),
            );
        }
    }

    if (
        contingencyMethod.value === 'percentage'
        && inputText(contingencyPercentage.value) !== ''
    ) {
        const value = Number(inputText(contingencyPercentage.value));

        if (
            !/^\d{1,3}(?:\.\d{1,2})?$/.test(
                inputText(contingencyPercentage.value),
            )
            || !Number.isFinite(value)
            || value < 0
            || value > 100
        ) {
            messages.push(c.value.invalidPercent);
        }
    }

    if (
        contingencyMethod.value === 'fixed_amount'
        && inputText(contingencyAmount.value) !== ''
        && !moneyPattern.test(inputText(contingencyAmount.value))
    ) {
        messages.push(c.value.invalidMoney);
    }

    if (
        inputText(confirmedFunding.value) !== ''
        && !moneyPattern.test(inputText(confirmedFunding.value))
    ) {
        messages.push(c.value.invalidMoney);
    }

    return [...new Set(messages)];
};

const formationDraftFromPage = (page: GenericRow): GenericRow | null => {
    const formation = page.props?.formation as GenericRow | undefined;

    return (formation?.capital?.planning_draft ?? null) as GenericRow | null;
};

const saveDraft = (): void => {
    if (!props.canManage || busy.value) return;

    errors.value = validateVisibleInput();
    success.value = '';

    if (errors.value.length > 0) return;

    busy.value = true;

    router.put(
        '/formation/capital/planning-draft',
        {
            expected_revision: expectedRevision.value,
            input: buildInput(),
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                const latest = formationDraftFromPage(page as GenericRow);
                hydrateDraft(latest);
                errors.value = [];
                success.value = c.value.saved;
            },
            onError: (serverErrors) => {
                const capitalError = serverErrors.capital_draft;

                if (typeof capitalError === 'string') {
                    errors.value = [
                        capitalError.includes('changed after you opened')
                            ? c.value.staleError
                            : c.value.invalidDraftError,
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
            const latest = formationDraftFromPage(page as GenericRow);
            hydrateDraft(latest);
            errors.value = [];
            success.value = c.value.latestLoaded;
        },
    });
};

const serverCalculation = computed(
    () => (props.calculation?.calculation ?? null) as GenericRow | null,
);

const canonicalProfile = computed(
    () =>
        (props.businessModelFoundation?.operating_profile ?? null) as
            | GenericRow
            | null,
);

const canonicalReuse = computed(
    () =>
        (serverCalculation.value?.canonicalReuse?.monthlyOperatingCost
            ?? null) as GenericRow | null,
);

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

const summaryCards = computed(() => {
    const result = serverCalculation.value;

    return [
        {
            key: 'pre',
            label: c.value.preOpening,
            value: result?.preOpening?.subtotal ?? null,
        },
        {
            key: 'assets',
            label: c.value.initialAssets,
            value: result?.initialAssetsInventory?.subtotal ?? null,
        },
        {
            key: 'working',
            label: c.value.workingCapital,
            value: result?.workingCapital?.amount ?? null,
        },
        {
            key: 'reserve',
            label: c.value.contingency,
            value: result?.contingency?.amount ?? null,
        },
        {
            key: 'total',
            label: c.value.totalRequired,
            value: result?.totalCapitalRequirement?.amount ?? null,
            emphasis: true,
        },
        {
            key: 'funding',
            label: c.value.funding,
            value: result?.fundingPosition?.confirmedFunding ?? null,
        },
        {
            key: 'gap',
            label: c.value.gap,
            value: result?.fundingPosition?.fundingGap ?? null,
            emphasis: true,
        },
    ];
});

const incompleteMessages = computed(() => {
    const result = serverCalculation.value;

    if (result === null) return [c.value.saveFirst];

    const messages: string[] = [];

    if (result.preOpening?.status !== 'calculable') {
        messages.push(c.value.startupIncomplete);
    }

    if (result.initialAssetsInventory?.status !== 'calculable') {
        messages.push(c.value.assetsIncomplete);
    }

    if (result.workingCapital?.status !== 'calculable') {
        messages.push(c.value.workingIncomplete);
    }

    if (result.contingency?.status !== 'calculable') {
        messages.push(c.value.reserveIncomplete);
    }

    if (
        result.totalCapitalRequirement?.status === 'calculable'
        && result.fundingPosition?.status !== 'calculable'
    ) {
        messages.push(c.value.fundingIncomplete);
    }

    return messages;
});

const fundedPercentage = computed(
    () => serverCalculation.value?.fundingPosition?.fundedPercentage ?? null,
);

const fundingSurplus = computed(
    () => serverCalculation.value?.fundingPosition?.fundingSurplus ?? null,
);

const contingencyBase = computed(
    () => serverCalculation.value?.contingency?.baseAmount ?? null,
);
</script>

<template>
    <section
        data-testid="capital-guided-journey"
        class="min-w-0 rounded-[24px] border border-[#cfe0d4] bg-[linear-gradient(145deg,#ffffff_0%,#f6faf7_68%,#fbf7eb_100%)] p-4 shadow-[0_14px_34px_rgb(16_35_26_/_6%)] sm:p-6"
    >
        <template v-if="draft">
            <header class="min-w-0 max-w-4xl">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]">
                    {{ c.eyebrow }}
                </p>
                <h2 class="mt-2 break-words text-xl font-black tracking-[-0.025em] sm:text-2xl">
                    {{ c.title }}
                </h2>
                <p class="pbr-safe-copy mt-2 break-words text-sm leading-6 text-[var(--pbr-muted)]">
                    {{ c.subtitle }}
                </p>
                <p class="pbr-safe-copy mt-3 rounded-2xl border border-[#e7d8aa] bg-[#fffaf0] px-4 py-3 text-xs leading-5 text-[#665527]">
                    {{ c.planningBoundary }}
                </p>
                <div class="mt-3 flex flex-wrap items-center gap-2 text-xs text-[var(--pbr-muted)]">
                    <span class="rounded-full border border-[var(--pbr-line)] bg-white px-3 py-1.5 font-bold">
                        {{ currency }}
                    </span>
                    <span class="rounded-full border border-[var(--pbr-line)] bg-white px-3 py-1.5 font-bold">
                        {{ c.revision }}:
                        {{ Number(draft.revision ?? 0) > 0 ? draft.revision : c.notSaved }}
                    </span>
                </div>
            </header>

            <div class="mt-5">
                <GuidedJourneyStepper
                    :steps="steps"
                    :label="c.progress"
                    @select="selectStep"
                />
            </div>

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

            <ProgressiveReveal :visible="focus === 'startup'">
                <PbrFormSection
                    :title="c.startupTitle"
                    :instruction="c.startupHelp"
                    numbered="1"
                >
                    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(14rem,0.38fr)]">
                        <fieldset class="min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                            <legend class="px-1 text-sm font-black">
                                {{ c.sectionState }}
                            </legend>
                            <div class="mt-2 grid gap-2">
                                <label class="flex min-w-0 items-start gap-3 rounded-xl border border-[var(--pbr-line)] p-3">
                                    <input v-model="startupMode" value="missing" type="radio" :disabled="!canManage" class="mt-1">
                                    <span class="min-w-0 break-words text-sm font-semibold">{{ c.notEntered }}</span>
                                </label>
                                <label class="flex min-w-0 items-start gap-3 rounded-xl border border-[var(--pbr-line)] p-3">
                                    <input v-model="startupMode" value="zero" type="radio" :disabled="!canManage" class="mt-1">
                                    <span class="min-w-0 break-words text-sm font-semibold">{{ c.explicitZeroStartup }}</span>
                                </label>
                                <label class="flex min-w-0 items-start gap-3 rounded-xl border border-[var(--pbr-line)] p-3">
                                    <input v-model="startupMode" value="items" type="radio" :disabled="!canManage" class="mt-1">
                                    <span class="min-w-0 break-words text-sm font-semibold">{{ c.haveItems }}</span>
                                </label>
                            </div>
                            <p class="pbr-safe-copy mt-3 text-xs leading-5 text-[var(--pbr-muted)]">
                                {{ c.zeroMeaning }}
                            </p>
                        </fieldset>

                        <label class="min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                            <span class="block text-sm font-black">{{ c.openingDate }}</span>
                            <span class="mt-1 block text-xs leading-5 text-[var(--pbr-muted)]">{{ c.openingDateHelp }}</span>
                            <input
                                v-model="openingDate"
                                type="date"
                                :disabled="!canManage"
                                class="mt-3 min-h-11 w-full min-w-0 rounded-xl border border-slate-300 bg-white px-3 text-sm disabled:bg-slate-50"
                            >
                        </label>
                    </div>

                    <div v-if="startupMode === 'items'" class="mt-5 space-y-3">
                        <article
                            v-for="(item, index) in startupItems"
                            :key="'startup-' + index"
                            class="min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4"
                        >
                            <div class="grid min-w-0 gap-3 md:grid-cols-[minmax(0,0.7fr)_minmax(0,1.4fr)_minmax(0,0.7fr)]">
                                <label class="min-w-0 text-sm font-bold">
                                    <span class="block">{{ c.category }}</span>
                                    <select
                                        v-model="item.category"
                                        :disabled="!canManage"
                                        class="mt-1 min-h-11 w-full min-w-0 rounded-xl border border-slate-300 bg-white px-3"
                                    >
                                        <option v-for="option in preOpeningCategories" :key="option.value" :value="option.value">
                                            {{ option.label }}
                                        </option>
                                    </select>
                                </label>
                                <label class="min-w-0 text-sm font-bold">
                                    <span class="block">{{ c.description }}</span>
                                    <input
                                        v-model="item.label"
                                        :disabled="!canManage"
                                        type="text"
                                        maxlength="160"
                                        class="mt-1 min-h-11 w-full min-w-0 rounded-xl border border-slate-300 bg-white px-3 disabled:bg-slate-50"
                                    >
                                </label>
                                <label class="min-w-0 text-sm font-bold">
                                    <span class="block">{{ c.amount }} ({{ currency }})</span>
                                    <input
                                        v-model="item.amount"
                                        :disabled="!canManage"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        inputmode="decimal"
                                        class="mt-1 min-h-11 w-full min-w-0 rounded-xl border border-slate-300 bg-white px-3 disabled:bg-slate-50"
                                    >
                                </label>
                            </div>
                            <div v-if="canManage" class="mt-3 flex flex-wrap gap-2">
                                <button type="button" class="min-h-9 rounded-lg border border-slate-300 px-3 text-xs font-bold" :disabled="index === 0" @click="moveItem(startupItems, index, -1)">
                                    {{ c.moveUp }}
                                </button>
                                <button type="button" class="min-h-9 rounded-lg border border-slate-300 px-3 text-xs font-bold" :disabled="index === startupItems.length - 1" @click="moveItem(startupItems, index, 1)">
                                    {{ c.moveDown }}
                                </button>
                                <button type="button" class="min-h-9 rounded-lg border border-[#e4c9c9] px-3 text-xs font-bold text-[#8d3434]" @click="removeItem(startupItems, index)">
                                    {{ c.remove }}
                                </button>
                            </div>
                        </article>

                        <button
                            v-if="canManage"
                            type="button"
                            class="min-h-10 rounded-xl border border-[var(--pbr-green)] bg-white px-4 text-sm font-black text-[var(--pbr-green-dark)]"
                            @click="addItem(startupItems, 'registration_legal')"
                        >
                            {{ c.addItem }}
                        </button>
                    </div>

                    <template #actions>
                        <div class="flex flex-wrap justify-end gap-2">
                            <button type="button" class="min-h-10 rounded-xl bg-[var(--pbr-green-dark)] px-4 text-sm font-black text-white" @click="nextStep">
                                {{ c.next }}
                            </button>
                        </div>
                    </template>
                </PbrFormSection>
            </ProgressiveReveal>

            <ProgressiveReveal :visible="focus === 'assets'">
                <PbrFormSection
                    :title="c.assetsTitle"
                    :instruction="c.assetsHelp"
                    numbered="2"
                >
                    <fieldset class="rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                        <legend class="px-1 text-sm font-black">{{ c.sectionState }}</legend>
                        <div class="mt-2 grid gap-2 md:grid-cols-3">
                            <label class="flex min-w-0 items-start gap-3 rounded-xl border border-[var(--pbr-line)] p-3">
                                <input v-model="assetsMode" value="missing" type="radio" :disabled="!canManage" class="mt-1">
                                <span class="min-w-0 break-words text-sm font-semibold">{{ c.notEntered }}</span>
                            </label>
                            <label class="flex min-w-0 items-start gap-3 rounded-xl border border-[var(--pbr-line)] p-3">
                                <input v-model="assetsMode" value="zero" type="radio" :disabled="!canManage" class="mt-1">
                                <span class="min-w-0 break-words text-sm font-semibold">{{ c.explicitZeroAssets }}</span>
                            </label>
                            <label class="flex min-w-0 items-start gap-3 rounded-xl border border-[var(--pbr-line)] p-3">
                                <input v-model="assetsMode" value="items" type="radio" :disabled="!canManage" class="mt-1">
                                <span class="min-w-0 break-words text-sm font-semibold">{{ c.haveItems }}</span>
                            </label>
                        </div>
                        <p class="pbr-safe-copy mt-3 text-xs leading-5 text-[var(--pbr-muted)]">{{ c.zeroMeaning }}</p>
                    </fieldset>

                    <div v-if="assetsMode === 'items'" class="mt-5 space-y-3">
                        <article
                            v-for="(item, index) in assetItems"
                            :key="'asset-' + index"
                            class="min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4"
                        >
                            <div class="grid min-w-0 gap-3 md:grid-cols-[minmax(0,0.7fr)_minmax(0,1.4fr)_minmax(0,0.7fr)]">
                                <label class="min-w-0 text-sm font-bold">
                                    <span class="block">{{ c.category }}</span>
                                    <select v-model="item.category" :disabled="!canManage" class="mt-1 min-h-11 w-full min-w-0 rounded-xl border border-slate-300 bg-white px-3">
                                        <option v-for="option in assetCategories" :key="option.value" :value="option.value">{{ option.label }}</option>
                                    </select>
                                </label>
                                <label class="min-w-0 text-sm font-bold">
                                    <span class="block">{{ c.description }}</span>
                                    <input v-model="item.label" :disabled="!canManage" type="text" maxlength="160" class="mt-1 min-h-11 w-full min-w-0 rounded-xl border border-slate-300 bg-white px-3 disabled:bg-slate-50">
                                </label>
                                <label class="min-w-0 text-sm font-bold">
                                    <span class="block">{{ c.amount }} ({{ currency }})</span>
                                    <input v-model="item.amount" :disabled="!canManage" type="number" min="0" step="0.01" inputmode="decimal" class="mt-1 min-h-11 w-full min-w-0 rounded-xl border border-slate-300 bg-white px-3 disabled:bg-slate-50">
                                </label>
                            </div>
                            <div v-if="canManage" class="mt-3 flex flex-wrap gap-2">
                                <button type="button" class="min-h-9 rounded-lg border border-slate-300 px-3 text-xs font-bold" :disabled="index === 0" @click="moveItem(assetItems, index, -1)">{{ c.moveUp }}</button>
                                <button type="button" class="min-h-9 rounded-lg border border-slate-300 px-3 text-xs font-bold" :disabled="index === assetItems.length - 1" @click="moveItem(assetItems, index, 1)">{{ c.moveDown }}</button>
                                <button type="button" class="min-h-9 rounded-lg border border-[#e4c9c9] px-3 text-xs font-bold text-[#8d3434]" @click="removeItem(assetItems, index)">{{ c.remove }}</button>
                            </div>
                        </article>

                        <button v-if="canManage" type="button" class="min-h-10 rounded-xl border border-[var(--pbr-green)] bg-white px-4 text-sm font-black text-[var(--pbr-green-dark)]" @click="addItem(assetItems, 'equipment')">
                            {{ c.addItem }}
                        </button>
                    </div>

                    <template #actions>
                        <div class="flex flex-wrap justify-between gap-2">
                            <button type="button" class="min-h-10 rounded-xl border border-slate-300 bg-white px-4 text-sm font-bold" @click="previousStep">{{ c.previous }}</button>
                            <button type="button" class="min-h-10 rounded-xl bg-[var(--pbr-green-dark)] px-4 text-sm font-black text-white" @click="nextStep">{{ c.next }}</button>
                        </div>
                    </template>
                </PbrFormSection>
            </ProgressiveReveal>

            <ProgressiveReveal :visible="focus === 'working'">
                <PbrFormSection
                    :title="c.workingTitle"
                    :instruction="c.workingHelp"
                    numbered="3"
                >
                    <label class="block max-w-xl text-sm font-black">
                        <span class="block">{{ c.method }}</span>
                        <select v-model="workingMethod" :disabled="!canManage" class="mt-2 min-h-11 w-full min-w-0 rounded-xl border border-slate-300 bg-white px-3">
                            <option value="missing">{{ c.methodMissing }}</option>
                            <option value="canonical_operating_profile">{{ c.canonicalMethod }}</option>
                            <option value="monthly_costs">{{ c.monthlyCostsMethod }}</option>
                            <option value="monthly_burn">{{ c.monthlyBurnMethod }}</option>
                            <option value="fixed_amount">{{ c.fixedMethod }}</option>
                        </select>
                    </label>

                    <ProgressiveReveal :visible="workingMethod === 'canonical_operating_profile'" :reason="c.canonicalHelp">
                        <div class="mt-4 min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                            <template v-if="businessModelFoundation !== null">
                                <p class="text-sm font-black">{{ canonicalProfile ? c.canonicalAvailable : c.canonicalMissing }}</p>
                                <dl v-if="canonicalProfile" class="mt-3 grid min-w-0 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                    <div class="min-w-0 rounded-xl bg-[#f7faf8] p-3">
                                        <dt class="break-words text-xs text-[var(--pbr-muted)]">{{ c.sellingPrice }}</dt>
                                        <dd class="mt-1 break-words text-sm font-black">{{ canonicalProfile.average_selling_price ?? '—' }}</dd>
                                    </div>
                                    <div class="min-w-0 rounded-xl bg-[#f7faf8] p-3">
                                        <dt class="break-words text-xs text-[var(--pbr-muted)]">{{ c.variableCost }}</dt>
                                        <dd class="mt-1 break-words text-sm font-black">{{ canonicalProfile.variable_cost_per_unit ?? '—' }}</dd>
                                    </div>
                                    <div class="min-w-0 rounded-xl bg-[#f7faf8] p-3">
                                        <dt class="break-words text-xs text-[var(--pbr-muted)]">{{ c.fixedCost }}</dt>
                                        <dd class="mt-1 break-words text-sm font-black">{{ canonicalProfile.monthly_fixed_cost ?? '—' }}</dd>
                                    </div>
                                    <div class="min-w-0 rounded-xl bg-[#f7faf8] p-3">
                                        <dt class="break-words text-xs text-[var(--pbr-muted)]">{{ c.expectedUnits }}</dt>
                                        <dd class="mt-1 break-words text-sm font-black">{{ canonicalProfile.expected_monthly_units ?? '—' }}</dd>
                                    </div>
                                </dl>

                                <p v-if="canonicalReuse?.status === 'reused'" class="mt-3 text-sm font-semibold text-[var(--pbr-green-dark)]">
                                    {{ c.resolvedMonthlyCost }}:
                                    {{ showMoney(canonicalReuse.value) }}
                                </p>
                                <p v-else class="mt-3 text-xs leading-5 text-[var(--pbr-muted)]">
                                    {{ c.saveToResolve }}
                                </p>

                                <button type="button" class="mt-3 min-h-10 rounded-xl border border-slate-300 bg-white px-4 text-sm font-bold" @click="emit('openBusinessModel')">
                                    {{ c.updateBusinessModel }}
                                </button>
                            </template>

                            <p v-else class="pbr-safe-copy text-sm leading-6 text-[var(--pbr-muted)]">
                                {{ c.canonicalUnavailable }}
                            </p>
                        </div>

                        <label class="mt-4 block max-w-md text-sm font-bold">
                            <span class="block">{{ c.months }}</span>
                            <input v-model="workingMonths" :disabled="!canManage" type="number" min="0" max="24" step="1" inputmode="numeric" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 disabled:bg-slate-50">
                        </label>
                    </ProgressiveReveal>

                    <ProgressiveReveal :visible="workingMethod === 'monthly_costs'">
                        <label class="mt-4 block max-w-md text-sm font-bold">
                            <span class="block">{{ c.months }}</span>
                            <input v-model="workingMonths" :disabled="!canManage" type="number" min="0" max="24" step="1" inputmode="numeric" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 disabled:bg-slate-50">
                        </label>

                        <fieldset class="mt-4 rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                            <legend class="px-1 text-sm font-black">{{ c.monthlyCostsState }}</legend>
                            <div class="mt-2 grid gap-2 md:grid-cols-3">
                                <label class="flex items-start gap-3 rounded-xl border border-[var(--pbr-line)] p-3">
                                    <input v-model="monthlyCostsMode" value="missing" type="radio" :disabled="!canManage" class="mt-1">
                                    <span class="text-sm font-semibold">{{ c.notEntered }}</span>
                                </label>
                                <label class="flex items-start gap-3 rounded-xl border border-[var(--pbr-line)] p-3">
                                    <input v-model="monthlyCostsMode" value="zero" type="radio" :disabled="!canManage" class="mt-1">
                                    <span class="text-sm font-semibold">{{ c.zeroMonthlyCosts }}</span>
                                </label>
                                <label class="flex items-start gap-3 rounded-xl border border-[var(--pbr-line)] p-3">
                                    <input v-model="monthlyCostsMode" value="items" type="radio" :disabled="!canManage" class="mt-1">
                                    <span class="text-sm font-semibold">{{ c.haveItems }}</span>
                                </label>
                            </div>
                        </fieldset>

                        <div v-if="monthlyCostsMode === 'items'" class="mt-4 space-y-3">
                            <article v-for="(item, index) in monthlyCostItems" :key="'monthly-' + index" class="rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                                <div class="grid min-w-0 gap-3 md:grid-cols-[minmax(0,0.7fr)_minmax(0,1.4fr)_minmax(0,0.7fr)]">
                                    <label class="min-w-0 text-sm font-bold">
                                        <span class="block">{{ c.category }}</span>
                                        <select v-model="item.category" :disabled="!canManage" class="mt-1 min-h-11 w-full min-w-0 rounded-xl border border-slate-300 bg-white px-3">
                                            <option v-for="option in monthlyCostCategories" :key="option.value" :value="option.value">{{ option.label }}</option>
                                        </select>
                                    </label>
                                    <label class="min-w-0 text-sm font-bold">
                                        <span class="block">{{ c.description }}</span>
                                        <input v-model="item.label" :disabled="!canManage" type="text" maxlength="160" class="mt-1 min-h-11 w-full min-w-0 rounded-xl border border-slate-300 bg-white px-3 disabled:bg-slate-50">
                                    </label>
                                    <label class="min-w-0 text-sm font-bold">
                                        <span class="block">{{ c.amount }} ({{ currency }})</span>
                                        <input v-model="item.amount" :disabled="!canManage" type="number" min="0" step="0.01" inputmode="decimal" class="mt-1 min-h-11 w-full min-w-0 rounded-xl border border-slate-300 bg-white px-3 disabled:bg-slate-50">
                                    </label>
                                </div>
                                <div v-if="canManage" class="mt-3 flex flex-wrap gap-2">
                                    <button type="button" class="min-h-9 rounded-lg border border-slate-300 px-3 text-xs font-bold" :disabled="index === 0" @click="moveItem(monthlyCostItems, index, -1)">{{ c.moveUp }}</button>
                                    <button type="button" class="min-h-9 rounded-lg border border-slate-300 px-3 text-xs font-bold" :disabled="index === monthlyCostItems.length - 1" @click="moveItem(monthlyCostItems, index, 1)">{{ c.moveDown }}</button>
                                    <button type="button" class="min-h-9 rounded-lg border border-[#e4c9c9] px-3 text-xs font-bold text-[#8d3434]" @click="removeItem(monthlyCostItems, index)">{{ c.remove }}</button>
                                </div>
                            </article>

                            <button v-if="canManage" type="button" class="min-h-10 rounded-xl border border-[var(--pbr-green)] bg-white px-4 text-sm font-black text-[var(--pbr-green-dark)]" @click="addItem(monthlyCostItems, 'salary')">
                                {{ c.addItem }}
                            </button>
                        </div>
                    </ProgressiveReveal>

                    <ProgressiveReveal :visible="workingMethod === 'monthly_burn'">
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <label class="min-w-0 text-sm font-bold">
                                <span class="block">{{ c.monthlyBurn }} ({{ currency }})</span>
                                <input v-model="monthlyBurn" :disabled="!canManage" type="number" min="0" step="0.01" inputmode="decimal" class="mt-1 min-h-11 w-full min-w-0 rounded-xl border border-slate-300 bg-white px-3 disabled:bg-slate-50">
                            </label>
                            <label class="min-w-0 text-sm font-bold">
                                <span class="block">{{ c.months }}</span>
                                <input v-model="workingMonths" :disabled="!canManage" type="number" min="0" max="24" step="1" inputmode="numeric" class="mt-1 min-h-11 w-full min-w-0 rounded-xl border border-slate-300 bg-white px-3 disabled:bg-slate-50">
                            </label>
                        </div>
                    </ProgressiveReveal>

                    <ProgressiveReveal :visible="workingMethod === 'fixed_amount'">
                        <label class="mt-4 block max-w-md text-sm font-bold">
                            <span class="block">{{ c.fixedWorkingCapital }} ({{ currency }})</span>
                            <input v-model="fixedWorkingCapital" :disabled="!canManage" type="number" min="0" step="0.01" inputmode="decimal" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 disabled:bg-slate-50">
                        </label>
                    </ProgressiveReveal>

                    <template #actions>
                        <div class="flex flex-wrap justify-between gap-2">
                            <button type="button" class="min-h-10 rounded-xl border border-slate-300 bg-white px-4 text-sm font-bold" @click="previousStep">{{ c.previous }}</button>
                            <button type="button" class="min-h-10 rounded-xl bg-[var(--pbr-green-dark)] px-4 text-sm font-black text-white" @click="nextStep">{{ c.next }}</button>
                        </div>
                    </template>
                </PbrFormSection>
            </ProgressiveReveal>

            <ProgressiveReveal :visible="focus === 'reserve'">
                <PbrFormSection
                    :title="c.reserveTitle"
                    :instruction="c.reserveHelp"
                    numbered="4"
                >
                    <label class="block max-w-xl text-sm font-black">
                        <span class="block">{{ c.contingencyMethod }}</span>
                        <select v-model="contingencyMethod" :disabled="!canManage" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3">
                            <option value="missing">{{ c.methodMissing }}</option>
                            <option value="percentage">{{ c.percentage }}</option>
                            <option value="fixed_amount">{{ c.fixedAmount }}</option>
                        </select>
                    </label>

                    <ProgressiveReveal :visible="contingencyMethod === 'percentage'">
                        <label class="mt-4 block max-w-md text-sm font-bold">
                            <span class="block">{{ c.contingencyPercentage }} (%)</span>
                            <input v-model="contingencyPercentage" :disabled="!canManage" type="number" min="0" max="100" step="0.01" inputmode="decimal" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 disabled:bg-slate-50">
                        </label>
                        <p class="mt-3 text-sm leading-6 text-[var(--pbr-muted)]">
                            {{ c.currentBase }}:
                            <strong>{{ showMoney(contingencyBase) }}</strong>
                        </p>
                    </ProgressiveReveal>

                    <ProgressiveReveal :visible="contingencyMethod === 'fixed_amount'">
                        <label class="mt-4 block max-w-md text-sm font-bold">
                            <span class="block">{{ c.contingencyAmount }} ({{ currency }})</span>
                            <input v-model="contingencyAmount" :disabled="!canManage" type="number" min="0" step="0.01" inputmode="decimal" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 disabled:bg-slate-50">
                        </label>
                    </ProgressiveReveal>

                    <template #actions>
                        <div class="flex flex-wrap justify-between gap-2">
                            <button type="button" class="min-h-10 rounded-xl border border-slate-300 bg-white px-4 text-sm font-bold" @click="previousStep">{{ c.previous }}</button>
                            <button type="button" class="min-h-10 rounded-xl bg-[var(--pbr-green-dark)] px-4 text-sm font-black text-white" @click="nextStep">{{ c.next }}</button>
                        </div>
                    </template>
                </PbrFormSection>
            </ProgressiveReveal>

            <ProgressiveReveal :visible="focus === 'funding'">
                <PbrFormSection
                    :title="c.fundingTitle"
                    :instruction="c.fundingHelp"
                    numbered="5"
                >
                    <label class="block max-w-md text-sm font-bold">
                        <span class="block">{{ c.confirmedFunding }} ({{ currency }})</span>
                        <span class="mt-1 block text-xs font-normal leading-5 text-[var(--pbr-muted)]">{{ c.confirmedFundingHelp }}</span>
                        <input v-model="confirmedFunding" :disabled="!canManage" type="number" min="0" step="0.01" inputmode="decimal" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 disabled:bg-slate-50">
                    </label>

                    <template #actions>
                        <div class="flex flex-wrap justify-start gap-2">
                            <button type="button" class="min-h-10 rounded-xl border border-slate-300 bg-white px-4 text-sm font-bold" @click="previousStep">{{ c.previous }}</button>
                        </div>
                    </template>
                </PbrFormSection>
            </ProgressiveReveal>

            <section class="mt-6 min-w-0 rounded-[22px] border border-[#d8e4da] bg-white/90 p-4 sm:p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="break-words text-base font-black text-[var(--pbr-ink)]">
                            {{ c.livePosition }}
                        </h3>
                        <p class="pbr-safe-copy mt-1 max-w-3xl break-words text-xs leading-5 text-[var(--pbr-muted)]">
                            {{ c.livePositionHelp }}
                        </p>
                    </div>
                    <span class="rounded-full border border-[var(--pbr-line)] bg-[#f8faf8] px-3 py-1.5 text-xs font-black">
                        {{ currency }}
                    </span>
                </div>

                <dl class="mt-4 grid min-w-0 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div
                        v-for="card in summaryCards"
                        :key="card.key"
                        class="min-w-0 rounded-2xl border p-4"
                        :class="card.emphasis ? 'border-[#c9ddcf] bg-[#eef7f0]' : 'border-[var(--pbr-line)] bg-white'"
                    >
                        <dt class="break-words text-xs font-semibold text-[var(--pbr-muted)]">
                            {{ card.label }}
                        </dt>
                        <dd class="mt-2 break-words text-lg font-black">
                            {{ showMoney(card.value) }}
                        </dd>
                    </div>

                    <div class="min-w-0 rounded-2xl border border-[var(--pbr-line)] bg-white p-4">
                        <dt class="break-words text-xs font-semibold text-[var(--pbr-muted)]">{{ c.fundedPercent }}</dt>
                        <dd class="mt-2 break-words text-lg font-black">{{ showPercent(fundedPercentage) }}</dd>
                    </div>

                    <div
                        v-if="fundingSurplus !== null && fundingSurplus !== '0.00'"
                        class="min-w-0 rounded-2xl border border-[#c9ddcf] bg-[#f3faf5] p-4"
                    >
                        <dt class="break-words text-xs font-semibold text-[var(--pbr-muted)]">{{ c.surplus }}</dt>
                        <dd class="mt-2 break-words text-lg font-black text-[var(--pbr-green-dark)]">{{ showMoney(fundingSurplus) }}</dd>
                    </div>
                </dl>

                <div v-if="incompleteMessages.length > 0" class="mt-4 rounded-2xl border border-[#eadcb1] bg-[#fffaf0] p-4">
                    <h4 class="text-sm font-black text-[#665527]">{{ c.incompleteTitle }}</h4>
                    <ul class="mt-2 space-y-1 text-sm leading-6 text-[#665527]">
                        <li v-for="message in incompleteMessages" :key="message">• {{ message }}</li>
                    </ul>
                </div>
            </section>

            <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-[var(--pbr-line)] pt-5">
                <button
                    v-if="errors.length > 0"
                    type="button"
                    class="min-h-10 rounded-xl border border-slate-300 bg-white px-4 text-sm font-bold"
                    @click="reloadLatest"
                >
                    {{ c.reloadLatest }}
                </button>
                <span v-else />

                <button
                    v-if="canManage"
                    type="button"
                    class="min-h-11 rounded-xl bg-[var(--pbr-green-dark)] px-5 text-sm font-black text-white disabled:cursor-wait disabled:opacity-60"
                    :disabled="busy"
                    @click="saveDraft"
                >
                    {{ busy ? c.saving : c.saveDraft }}
                </button>
            </div>
        </template>

        <div v-else class="rounded-2xl border border-[#eadcb1] bg-[#fffaf0] p-5">
            <p class="pbr-safe-copy text-sm font-semibold leading-6 text-[#665527]">
                {{ c.accessUnavailable }}
            </p>
        </div>
    </section>
</template>
