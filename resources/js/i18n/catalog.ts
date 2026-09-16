export const uiLanguageModes = ['en', 'my', 'mixed'] as const;

export type UiLanguageMode = (typeof uiLanguageModes)[number];

export const isUiLanguageMode = (value: unknown): value is UiLanguageMode =>
    typeof value === 'string' &&
    (uiLanguageModes as readonly string[]).includes(value);

export const normalizeUiLanguageMode = (value: unknown): UiLanguageMode =>
    isUiLanguageMode(value) ? value : 'en';

const englishCatalog = {
    'common.brand': 'thePBR OS',
    'common.email': 'Email',
    'common.backToAccount': 'Back to account',

    'nav.workspaceNavigation': 'Workspace navigation',
    'nav.home': 'Home',
    'nav.createBusiness': 'Create Business',
    'nav.profileSettings': 'Profile & Settings',

    'shell.currentBusiness': 'Current Business',
    'shell.noBusinessSelected': 'No Business selected',
    'shell.openNavigation': 'Open workspace navigation',
    'shell.closeNavigation': 'Close workspace navigation',

    'businessSwitcher.label': 'Business',
    'businessSwitcher.ariaLabel': 'Select current Business',
    'businessSwitcher.noneAccessible': 'No accessible Businesses',
    'businessSwitcher.select': 'Select a Business',
    'businessSwitcher.error': 'Business could not be selected.',

    'account.title': 'Account',
    'account.signOut': 'Sign out',
    'account.signingOut': 'Signing out…',
    'account.signedInIdentity': 'Signed-in identity',

    'settings.title': 'Profile & Settings',
    'settings.description':
        'Manage your account profile and personal preferences.',
    'settings.updated': 'Account settings updated.',
    'settings.identity': 'Identity',
    'settings.emailChangeUnavailable':
        'Email changes are not available from this settings page.',
    'settings.displayName': 'Display name',
    'settings.preferences': 'Preferences',
    'settings.language': 'Language',
    'settings.languagePreferenceHelp':
        'This preference changes OS interface copy only. It does not translate or mutate stored Business data.',
    'settings.timezone': 'Timezone',
    'settings.save': 'Save settings',
    'settings.saving': 'Saving…',

    'language.en': 'English',
    'language.my': 'မြန်မာ',
    'language.mixed': 'မြန်မာ + EN',

    'login.title': 'Sign in',
    'login.description':
        'Access your private partnership business workspace.',
    'login.password': 'Password',
    'login.signIn': 'Sign in',
    'login.signingIn': 'Signing in…',

    'businessCreate.title': 'Create Business',
    'businessCreate.description':
        'Create the Business workspace and establish your access to it. Ownership and governance are handled separately.',
    'businessCreate.name': 'Business name',
    'businessCreate.originLegend': 'How is this Business entering PBR?',
    'businessCreate.originHelp':
        'Choose the Business origin explicitly. This does not determine its current stage, ownership, or governance.',
    'businessCreate.stage': 'Current Business stage',
    'businessCreate.stageHelp':
        'Select the current stage independently from the Business origin.',
    'businessCreate.selectStage': 'Select a stage',
    'businessCreate.baseCurrency': 'Base currency',
    'businessCreate.baseCurrencyHelp':
        'Enter a three-letter uppercase currency code, for example USD, MMK, or THB.',
    'businessCreate.cancel': 'Cancel',
    'businessCreate.create': 'Create Business',
    'businessCreate.creating': 'Creating…',

    'businessOrigin.started_through_pbr': 'Started through PBR',
    'businessOrigin.existing_business_imported_into_pbr':
        'Existing Business imported into PBR',

    'businessStage.idea': 'Idea',
    'businessStage.validation': 'Validation',
    'businessStage.planning': 'Planning',
    'businessStage.pre_launch': 'Pre-launch',
    'businessStage.operating': 'Operating',
    'businessStage.growth': 'Growth',
    'businessStage.restructuring': 'Restructuring',
    'businessStage.exit': 'Exit',
} as const;

export type TranslationKey = keyof typeof englishCatalog;
export type TranslationCatalog = Record<TranslationKey, string>;

const myanmarCatalog = {
    'common.brand': 'thePBR OS',
    'common.email': 'အီးမေးလ်',
    'common.backToAccount': 'အကောင့်သို့ ပြန်သွားမည်',

    'nav.workspaceNavigation': 'လုပ်ငန်းအလုပ်ခွင် လမ်းညွှန်',
    'nav.home': 'ပင်မ',
    'nav.createBusiness': 'လုပ်ငန်းဖန်တီးရန်',
    'nav.profileSettings': 'ကိုယ်ရေးအချက်အလက်နှင့် ဆက်တင်များ',

    'shell.currentBusiness': 'လက်ရှိလုပ်ငန်း',
    'shell.noBusinessSelected': 'လုပ်ငန်း မရွေးရသေးပါ',
    'shell.openNavigation': 'လုပ်ငန်းအလုပ်ခွင် လမ်းညွှန်ကို ဖွင့်ရန်',
    'shell.closeNavigation': 'လုပ်ငန်းအလုပ်ခွင် လမ်းညွှန်ကို ပိတ်ရန်',

    'businessSwitcher.label': 'လုပ်ငန်း',
    'businessSwitcher.ariaLabel': 'လက်ရှိလုပ်ငန်းကို ရွေးချယ်ရန်',
    'businessSwitcher.noneAccessible':
        'ဝင်ရောက်ခွင့်ရှိသည့် လုပ်ငန်းမရှိပါ',
    'businessSwitcher.select': 'လုပ်ငန်းတစ်ခု ရွေးပါ',
    'businessSwitcher.error': 'လုပ်ငန်းကို ရွေးချယ်၍ မရပါ။',

    'account.title': 'အကောင့်',
    'account.signOut': 'ထွက်မည်',
    'account.signingOut': 'ထွက်နေသည်…',
    'account.signedInIdentity': 'ဝင်ရောက်ထားသည့် အကောင့်အချက်အလက်',

    'settings.title': 'ကိုယ်ရေးအချက်အလက်နှင့် ဆက်တင်များ',
    'settings.description':
        'သင့်အကောင့်၏ ကိုယ်ရေးအချက်အလက်နှင့် ကိုယ်ပိုင်ရွေးချယ်မှုများကို စီမံပါ။',
    'settings.updated': 'အကောင့်ဆက်တင်များကို အပ်ဒိတ်လုပ်ပြီးပါပြီ။',
    'settings.identity': 'ကိုယ်ရေးအချက်အလက်',
    'settings.emailChangeUnavailable':
        'ဤဆက်တင်စာမျက်နှာမှ အီးမေးလ်ကို ပြောင်းလဲ၍ မရသေးပါ။',
    'settings.displayName': 'ပြသမည့်အမည်',
    'settings.preferences': 'ကိုယ်ပိုင်ရွေးချယ်မှုများ',
    'settings.language': 'ဘာသာစကား',
    'settings.languagePreferenceHelp':
        'ဤရွေးချယ်မှုသည် OS မျက်နှာပြင်စာသားများကိုသာ ပြောင်းလဲစေပါသည်။ သိမ်းထားသည့် လုပ်ငန်းအချက်အလက်များကို ဘာသာပြန်ခြင်း သို့မဟုတ် ပြောင်းလဲခြင်း မပြုပါ။',
    'settings.timezone': 'အချိန်ဇုန်',
    'settings.save': 'ဆက်တင်များ သိမ်းမည်',
    'settings.saving': 'သိမ်းနေသည်…',

    'language.en': 'English',
    'language.my': 'မြန်မာ',
    'language.mixed': 'မြန်မာ + EN',

    'login.title': 'ဝင်ရောက်မည်',
    'login.description':
        'သင့်ကိုယ်ပိုင် မိတ်ဖက်လုပ်ငန်း အလုပ်ခွင်သို့ ဝင်ရောက်ပါ။',
    'login.password': 'စကားဝှက်',
    'login.signIn': 'ဝင်ရောက်မည်',
    'login.signingIn': 'ဝင်ရောက်နေသည်…',

    'businessCreate.title': 'လုပ်ငန်းဖန်တီးရန်',
    'businessCreate.description':
        'လုပ်ငန်းအလုပ်ခွင်ကို ဖန်တီးပြီး သင့်ဝင်ရောက်ခွင့်ကို သတ်မှတ်ပါ။ ပိုင်ဆိုင်မှုနှင့် အုပ်ချုပ်ဆုံးဖြတ်မှုကို သီးခြားစီ စီမံပါသည်။',
    'businessCreate.name': 'လုပ်ငန်းအမည်',
    'businessCreate.originLegend':
        'ဤလုပ်ငန်းသည် PBR ထဲသို့ မည်သို့ စတင်ဝင်ရောက်လာသနည်း။',
    'businessCreate.originHelp':
        'လုပ်ငန်း၏ မူလအစကို တိတိကျကျ ရွေးပါ။ ဤရွေးချယ်မှုက လက်ရှိအဆင့်၊ ပိုင်ဆိုင်မှု သို့မဟုတ် အုပ်ချုပ်ဆုံးဖြတ်မှုကို မသတ်မှတ်ပါ။',
    'businessCreate.stage': 'လက်ရှိလုပ်ငန်းအဆင့်',
    'businessCreate.stageHelp':
        'လက်ရှိအဆင့်ကို လုပ်ငန်း၏ မူလအစနှင့် သီးခြားစီ ရွေးချယ်ပါ။',
    'businessCreate.selectStage': 'အဆင့်တစ်ခု ရွေးပါ',
    'businessCreate.baseCurrency': 'အခြေခံငွေကြေး',
    'businessCreate.baseCurrencyHelp':
        'ဥပမာ USD, MMK သို့မဟုတ် THB ကဲ့သို့ အင်္ဂလိပ်စာလုံးအကြီး ၃ လုံးပါ ငွေကြေးကုဒ်ကို ထည့်ပါ။',
    'businessCreate.cancel': 'မလုပ်တော့ပါ',
    'businessCreate.create': 'လုပ်ငန်းဖန်တီးမည်',
    'businessCreate.creating': 'ဖန်တီးနေသည်…',

    'businessOrigin.started_through_pbr':
        'PBR မှတစ်ဆင့် စတင်ခဲ့သော လုပ်ငန်း',
    'businessOrigin.existing_business_imported_into_pbr':
        'PBR ထဲသို့ ထည့်သွင်းထားသော လက်ရှိလုပ်ငန်း',

    'businessStage.idea': 'စိတ်ကူးအဆင့်',
    'businessStage.validation': 'အတည်ပြုစမ်းသပ်အဆင့်',
    'businessStage.planning': 'အစီအစဉ်ရေးဆွဲအဆင့်',
    'businessStage.pre_launch': 'စတင်မဖွင့်မီအဆင့်',
    'businessStage.operating': 'လည်ပတ်နေသည့်အဆင့်',
    'businessStage.growth': 'တိုးတက်ကြီးထွားအဆင့်',
    'businessStage.restructuring': 'ဖွဲ့စည်းပုံပြန်လည်ပြင်ဆင်အဆင့်',
    'businessStage.exit': 'ထွက်ခွာ/လွှဲပြောင်းအဆင့်',
} satisfies TranslationCatalog;

const mixedCatalog = {
    'common.brand': 'thePBR OS',
    'common.email': 'Email',
    'common.backToAccount': 'Account သို့ ပြန်သွားမည်',

    'nav.workspaceNavigation': 'Business Workspace လမ်းညွှန်',
    'nav.home': 'Home',
    'nav.createBusiness': 'Create Business',
    'nav.profileSettings': 'Profile & Settings',

    'shell.currentBusiness': 'Current Business',
    'shell.noBusinessSelected': 'Business မရွေးရသေးပါ',
    'shell.openNavigation': 'Business Workspace လမ်းညွှန်ကို ဖွင့်ရန်',
    'shell.closeNavigation': 'Business Workspace လမ်းညွှန်ကို ပိတ်ရန်',

    'businessSwitcher.label': 'Business',
    'businessSwitcher.ariaLabel': 'Current Business ကို ရွေးချယ်ရန်',
    'businessSwitcher.noneAccessible':
        'ဝင်ရောက်ခွင့်ရှိသည့် Business မရှိပါ',
    'businessSwitcher.select': 'Business တစ်ခု ရွေးပါ',
    'businessSwitcher.error': 'Business ကို ရွေးချယ်၍ မရပါ။',

    'account.title': 'Account',
    'account.signOut': 'Sign out',
    'account.signingOut': 'Sign out လုပ်နေသည်…',
    'account.signedInIdentity': 'ဝင်ရောက်ထားသည့် Account အချက်အလက်',

    'settings.title': 'Profile & Settings',
    'settings.description':
        'သင့် Account Profile နှင့် ကိုယ်ပိုင် preferences များကို စီမံပါ။',
    'settings.updated': 'Account settings ကို update လုပ်ပြီးပါပြီ။',
    'settings.identity': 'Identity',
    'settings.emailChangeUnavailable':
        'ဤ Settings စာမျက်နှာမှ Email ကို ပြောင်းလဲ၍ မရသေးပါ။',
    'settings.displayName': 'Display name',
    'settings.preferences': 'Preferences',
    'settings.language': 'Language',
    'settings.languagePreferenceHelp':
        'ဤ preference သည် OS interface copy ကိုသာ ပြောင်းလဲစေပါသည်။ သိမ်းထားသည့် Business data ကို ဘာသာပြန်ခြင်း သို့မဟုတ် ပြောင်းလဲခြင်း မပြုပါ။',
    'settings.timezone': 'Timezone',
    'settings.save': 'Save settings',
    'settings.saving': 'Saving…',

    'language.en': 'English',
    'language.my': 'မြန်မာ',
    'language.mixed': 'မြန်မာ + EN',

    'login.title': 'Sign in',
    'login.description':
        'သင့် private Partnership Business Workspace သို့ ဝင်ရောက်ပါ။',
    'login.password': 'Password',
    'login.signIn': 'Sign in',
    'login.signingIn': 'Signing in…',

    'businessCreate.title': 'Create Business',
    'businessCreate.description':
        'Business Workspace ကို ဖန်တီးပြီး သင့် access ကို သတ်မှတ်ပါ။ Ownership နှင့် Governance ကို သီးခြားစီ စီမံပါသည်။',
    'businessCreate.name': 'Business name',
    'businessCreate.originLegend':
        'ဤ Business သည် PBR ထဲသို့ မည်သို့ စတင်ဝင်ရောက်လာသနည်း။',
    'businessCreate.originHelp':
        'Business origin ကို တိတိကျကျ ရွေးပါ။ ဤရွေးချယ်မှုက current stage, Ownership သို့မဟုတ် Governance ကို မသတ်မှတ်ပါ။',
    'businessCreate.stage': 'Current Business stage',
    'businessCreate.stageHelp':
        'Current stage ကို Business origin နှင့် သီးခြားစီ ရွေးချယ်ပါ။',
    'businessCreate.selectStage': 'Stage တစ်ခု ရွေးပါ',
    'businessCreate.baseCurrency': 'Base currency',
    'businessCreate.baseCurrencyHelp':
        'ဥပမာ USD, MMK သို့မဟုတ် THB ကဲ့သို့ အင်္ဂလိပ်စာလုံးအကြီး ၃ လုံးပါ currency code ကို ထည့်ပါ။',
    'businessCreate.cancel': 'Cancel',
    'businessCreate.create': 'Create Business',
    'businessCreate.creating': 'Creating…',

    'businessOrigin.started_through_pbr':
        'PBR မှတစ်ဆင့် စတင်ခဲ့သော Business',
    'businessOrigin.existing_business_imported_into_pbr':
        'PBR ထဲသို့ ထည့်သွင်းထားသော Existing Business',

    'businessStage.idea': 'Idea stage',
    'businessStage.validation': 'Validation stage',
    'businessStage.planning': 'Planning stage',
    'businessStage.pre_launch': 'Pre-launch stage',
    'businessStage.operating': 'Operating stage',
    'businessStage.growth': 'Growth stage',
    'businessStage.restructuring': 'Restructuring stage',
    'businessStage.exit': 'Exit stage',
} satisfies TranslationCatalog;

export const catalog = {
    en: englishCatalog,
    my: myanmarCatalog,
    mixed: mixedCatalog,
} satisfies Record<UiLanguageMode, TranslationCatalog>;

const englishTerminology = {
    pbr: 'PBR',
    business: 'Business',
    businessWorkspace: 'Business Workspace',
    currentBusiness: 'Current Business',
    membership: 'Membership',
    ownership: 'Ownership',
    governance: 'Governance',
    profileSettings: 'Profile & Settings',
} as const;

export type TerminologyKey = keyof typeof englishTerminology;
export type TerminologyCatalog = Record<TerminologyKey, string>;

const myanmarTerminology = {
    pbr: 'PBR',
    business: 'လုပ်ငန်း',
    businessWorkspace: 'လုပ်ငန်းအလုပ်ခွင်',
    currentBusiness: 'လက်ရှိလုပ်ငန်း',
    membership: 'အဖွဲ့ဝင်ဖြစ်မှု',
    ownership: 'ပိုင်ဆိုင်မှု',
    governance: 'အုပ်ချုပ်ဆုံးဖြတ်မှု',
    profileSettings: 'ကိုယ်ရေးအချက်အလက်နှင့် ဆက်တင်များ',
} satisfies TerminologyCatalog;

const mixedTerminology = {
    pbr: 'PBR',
    business: 'Business',
    businessWorkspace: 'Business Workspace',
    currentBusiness: 'Current Business',
    membership: 'Membership',
    ownership: 'Ownership',
    governance: 'Governance',
    profileSettings: 'Profile & Settings',
} satisfies TerminologyCatalog;

export const terminology = {
    en: englishTerminology,
    my: myanmarTerminology,
    mixed: mixedTerminology,
} satisfies Record<UiLanguageMode, TerminologyCatalog>;
