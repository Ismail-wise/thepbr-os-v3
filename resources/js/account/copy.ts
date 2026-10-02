import { computed } from 'vue';
import { useI18n } from '../i18n/useI18n';
import type { UiLanguageMode } from '../i18n/catalog';

const en = {
    accountEyebrow: 'Your PBR workspace',
    accountTitle: 'Account Home',
    accountSubtitle:
        'Start with the work that needs you, then move into the right Business.',
    welcome: 'Welcome back',
    signedInAs: 'Signed in as',
    needsYou: 'Needs you now',
    needsYouHelp:
        'Only authorized work from Businesses you can currently access appears here.',
    nothingWaiting: 'Nothing needs your attention right now',
    nothingWaitingHelp:
        'You can open a Business to review its current position or continue setup.',
    yourBusinesses: 'Your Businesses',
    businessesHelp:
        'Only Businesses with an active Membership are shown.',
    viewAllBusinesses: 'View all Businesses',
    recentNotifications: 'Recent notifications',
    notificationsHelp:
        'Governance notifications are filtered again against current record visibility.',
    viewAllNotifications: 'View all notifications',
    accountTools: 'Account tools',
    profileSettings: 'Profile & Settings',
    createBusiness: 'Create Business',
    signOut: 'Sign out',
    navHome: 'Account Home',
    navBusinesses: 'My Businesses',
    navWork: 'My Work',
    navNotifications: 'Notifications',
    navApprovals: 'My Approvals',
    navSignatures: 'My Signatures',
    businessesEyebrow: 'Business access',
    businessesTitle: 'My Businesses',
    businessesSubtitle:
        'Open any Business you currently belong to. Hidden or revoked Businesses are never counted here.',
    noBusinesses: 'No active Businesses yet',
    noBusinessesHelp:
        'Create a Business to start a new PBR workspace.',
    workEyebrow: 'Assigned work',
    workTitle: 'My Work',
    workSubtitle:
        'Actions and reviews assigned to you across Businesses you can currently access.',
    noWork: 'No work is currently assigned to you',
    noWorkHelp:
        'When an authorized Business assigns you an Action or Review, it will appear here.',
    notificationsEyebrow: 'Governance updates',
    notificationsTitle: 'Notifications',
    notificationsSubtitle:
        'A read-only account view of notifications you are currently allowed to see.',
    noNotifications: 'No notifications are available right now',
    noNotificationsHelp:
        'New authorized Governance updates will appear here.',
    approvalsEyebrow: 'Decisions waiting on you',
    approvalsTitle: 'My Approvals',
    approvalsSubtitle:
        'Only open Decisions where you are an eligible approver are shown.',
    noApprovals: 'No approvals are waiting for you',
    noApprovalsHelp:
        'If a governed Decision requires your approval, it will appear here.',
    signaturesEyebrow: 'Documents waiting on you',
    signaturesTitle: 'My Signatures',
    signaturesSubtitle:
        'Only active Signature Requests where you are an exact signer are shown.',
    noSignatures: 'No signatures are waiting for you',
    noSignaturesHelp:
        'If a governed document requires your signature, it will appear here.',
    openBusiness: 'Open Business',
    reviewApproval: 'Review approval',
    reviewSignature: 'Review signature',
    reviewWork: 'Open work',
    reviewNotification: 'Open update',
    stage: 'Stage',
    setup: 'PBR Setup',
    status: 'Workspace',
    currency: 'Base currency',
    due: 'Due',
    requested: 'Requested',
    opened: 'Opened',
    created: 'Created',
    unread: 'Unread',
    read: 'Read',
    current: 'Current',
    notStarted: 'Not started',
    notAvailable: 'Not available',
    approval: 'Approval',
    signature: 'Signature',
    proposalReview: 'Proposal review',
    recordReview: 'Business record review',
    operationsAction: 'Operations action',
    governanceAction: 'Governance action',
    actionAssigned: 'New work assigned',
    proposalReviewAssigned: 'Proposal review assigned',
    reviewAssigned: 'Review assigned',
    signatureRequested: 'Signature requested',
    governanceUpdate: 'Governance update',
    privacyNote:
        'Account pages never grant access or authority. Opening an item takes you into the existing Business workflow.',
};

type AccountCopy = Record<keyof typeof en, string>;

const my = {
    accountEyebrow: 'သင့် PBR အလုပ်ခွင်',
    accountTitle: 'အကောင့် ပင်မစာမျက်နှာ',
    accountSubtitle:
        'သင့်ကို လုပ်ဆောင်ဖို့လိုတဲ့ အလုပ်တွေကို အရင်ကြည့်ပြီး သက်ဆိုင်ရာ လုပ်ငန်းထဲကို ဝင်နိုင်ပါတယ်။',
    welcome: 'ပြန်လည်ကြိုဆိုပါတယ်',
    signedInAs: 'ဝင်ထားသောအကောင့်',
    needsYou: 'သင့်လုပ်ဆောင်ချက်လိုနေသောအရာများ',
    needsYouHelp:
        'သင်လက်ရှိ ဝင်ရောက်ခွင့်ရှိသော လုပ်ငန်းများမှ ခွင့်ပြုထားသည့် အလုပ်များကိုသာ ပြထားပါတယ်။',
    nothingWaiting: 'ယခုအချိန်မှာ သင့်လုပ်ဆောင်ချက်လိုတာ မရှိပါ',
    nothingWaitingHelp:
        'လုပ်ငန်းတစ်ခုကို ဖွင့်ပြီး လက်ရှိအခြေအနေကို ပြန်ကြည့်နိုင်သလို setup ကိုလည်း ဆက်လုပ်နိုင်ပါတယ်။',
    yourBusinesses: 'သင့်လုပ်ငန်းများ',
    businessesHelp:
        'Active Membership ရှိနေသော လုပ်ငန်းများကိုသာ ပြထားပါတယ်။',
    viewAllBusinesses: 'လုပ်ငန်းအားလုံးကြည့်ရန်',
    recentNotifications: 'လတ်တလော အသိပေးချက်များ',
    notificationsHelp:
        'Governance notification တိုင်းကို လက်ရှိ record access နဲ့ ထပ်မံစစ်ပြီးမှ ပြပါတယ်။',
    viewAllNotifications: 'အသိပေးချက်အားလုံးကြည့်ရန်',
    accountTools: 'အကောင့် အသုံးအဆောင်များ',
    profileSettings: 'ကိုယ်ရေးအချက်အလက်နှင့် ဆက်တင်များ',
    createBusiness: 'လုပ်ငန်းအသစ် ဖန်တီးရန်',
    signOut: 'ထွက်ရန်',
    navHome: 'အကောင့် ပင်မ',
    navBusinesses: 'ကျွန်ုပ်၏ လုပ်ငန်းများ',
    navWork: 'ကျွန်ုပ်၏ အလုပ်များ',
    navNotifications: 'အသိပေးချက်များ',
    navApprovals: 'ကျွန်ုပ်၏ အတည်ပြုချက်များ',
    navSignatures: 'ကျွန်ုပ်၏ လက်မှတ်များ',
    businessesEyebrow: 'လုပ်ငန်းဝင်ရောက်ခွင့်',
    businessesTitle: 'ကျွန်ုပ်၏ လုပ်ငန်းများ',
    businessesSubtitle:
        'သင်လက်ရှိ အဖွဲ့ဝင်ဖြစ်သော လုပ်ငန်းကို ဖွင့်နိုင်ပါတယ်။ ဝင်ရောက်ခွင့်မရှိတော့သော လုပ်ငန်းများကို မပြ၊ မတွက်ပါ။',
    noBusinesses: 'Active လုပ်ငန်း မရှိသေးပါ',
    noBusinessesHelp:
        'PBR workspace အသစ်စတင်ရန် လုပ်ငန်းတစ်ခု ဖန်တီးပါ။',
    workEyebrow: 'တာဝန်ပေးထားသော အလုပ်များ',
    workTitle: 'ကျွန်ုပ်၏ အလုပ်များ',
    workSubtitle:
        'သင်ဝင်ရောက်ခွင့်ရှိသော လုပ်ငန်းများအတွင်း သင့်ထံတာဝန်ပေးထားသည့် Actions နှင့် Reviews များ။',
    noWork: 'ယခု သင့်ထံတာဝန်ပေးထားသော အလုပ်မရှိပါ',
    noWorkHelp:
        'ခွင့်ပြုထားသော လုပ်ငန်းမှ Action သို့မဟုတ် Review တာဝန်ပေးလာပါက ဒီနေရာမှာ ပေါ်လာပါမယ်။',
    notificationsEyebrow: 'Governance အပ်ဒိတ်များ',
    notificationsTitle: 'အသိပေးချက်များ',
    notificationsSubtitle:
        'သင်လက်ရှိကြည့်ခွင့်ရှိသော notification များကိုသာ စုစည်းပြထားသော read-only view ဖြစ်ပါတယ်။',
    noNotifications: 'ယခုကြည့်နိုင်သော အသိပေးချက် မရှိပါ',
    noNotificationsHelp:
        'ခွင့်ပြုထားသော Governance အပ်ဒိတ်အသစ်များ ဒီနေရာမှာ ပေါ်လာပါမယ်။',
    approvalsEyebrow: 'သင့်အတည်ပြုချက်ကို စောင့်နေသော ဆုံးဖြတ်ချက်များ',
    approvalsTitle: 'ကျွန်ုပ်၏ အတည်ပြုချက်များ',
    approvalsSubtitle:
        'သင်အတည်ပြုခွင့်ရှိသော open Decision များကိုသာ ပြထားပါတယ်။',
    noApprovals: 'သင့်အတည်ပြုချက် စောင့်နေသောအရာ မရှိပါ',
    noApprovalsHelp:
        'Governance Decision တစ်ခုက သင့် approval လိုလာပါက ဒီနေရာမှာ ပေါ်လာပါမယ်။',
    signaturesEyebrow: 'သင့်လက်မှတ်ကို စောင့်နေသော စာရွက်စာတမ်းများ',
    signaturesTitle: 'ကျွန်ုပ်၏ လက်မှတ်များ',
    signaturesSubtitle:
        'သင်ကိုယ်တိုင် signer အဖြစ် သတ်မှတ်ထားသော active Signature Request များကိုသာ ပြထားပါတယ်။',
    noSignatures: 'သင့်လက်မှတ် စောင့်နေသောအရာ မရှိပါ',
    noSignaturesHelp:
        'Governance document တစ်ခုက သင့်လက်မှတ်လိုလာပါက ဒီနေရာမှာ ပေါ်လာပါမယ်။',
    openBusiness: 'လုပ်ငန်းဖွင့်ရန်',
    reviewApproval: 'Approval ကြည့်ရန်',
    reviewSignature: 'Signature ကြည့်ရန်',
    reviewWork: 'အလုပ်ဖွင့်ရန်',
    reviewNotification: 'အပ်ဒိတ်ဖွင့်ရန်',
    stage: 'အဆင့်',
    setup: 'PBR Setup',
    status: 'Workspace',
    currency: 'အခြေခံငွေကြေး',
    due: 'သတ်မှတ်ရက်',
    requested: 'တောင်းဆိုချိန်',
    opened: 'ဖွင့်ထားချိန်',
    created: 'ဖန်တီးချိန်',
    unread: 'မဖတ်ရသေး',
    read: 'ဖတ်ပြီး',
    current: 'လက်ရှိ',
    notStarted: 'မစရသေး',
    notAvailable: 'မရနိုင်ပါ',
    approval: 'Approval',
    signature: 'Signature',
    proposalReview: 'Proposal review',
    recordReview: 'Business record review',
    operationsAction: 'Operations action',
    governanceAction: 'Governance action',
    actionAssigned: 'အလုပ်အသစ် တာဝန်ပေးထားသည်',
    proposalReviewAssigned: 'Proposal review တာဝန်ပေးထားသည်',
    reviewAssigned: 'Review တာဝန်ပေးထားသည်',
    signatureRequested: 'လက်မှတ်ထိုးရန် တောင်းဆိုထားသည်',
    governanceUpdate: 'Governance အပ်ဒိတ်',
    privacyNote:
        'Account စာမျက်နှာများက access သို့မဟုတ် authority အသစ် မပေးပါ။ Item တစ်ခုဖွင့်လျှင် မူလ Business workflow ထဲသို့ ဝင်သွားပါမယ်။',
} satisfies AccountCopy;

const mixed = {
    ...en,
    accountSubtitle:
        'အရင်ဆုံး သင့် action လိုနေတဲ့ work ကိုကြည့်ပြီး သက်ဆိုင်ရာ Business ထဲကို ဝင်ပါ။',
    needsYouHelp:
        'လက်ရှိ access ရှိတဲ့ Businesses မှ authorized work တွေကိုပဲ ဒီနေရာမှာ စုစည်းပြထားပါတယ်။',
    businessesHelp:
        'Active Membership ရှိတဲ့ Businesses တွေကိုပဲ ပြထားပါတယ်။',
    notificationsHelp:
        'Governance notifications တွေကို current record visibility နဲ့ ထပ်စစ်ပြီးမှ ပြပါတယ်။',
    workSubtitle:
        'လက်ရှိ access ရှိတဲ့ Businesses အားလုံးက သင့်ထံ assign လုပ်ထားတဲ့ Actions နဲ့ Reviews တွေပါ။',
    notificationsSubtitle:
        'လက်ရှိ သင်မြင်ခွင့်ရှိတဲ့ notifications တွေကိုစုထားတဲ့ read-only account view ဖြစ်ပါတယ်။',
    approvalsSubtitle:
        'သင် eligible approver ဖြစ်တဲ့ open Decisions တွေကိုပဲ ပြထားပါတယ်။',
    signaturesSubtitle:
        'သင် exact signer ဖြစ်တဲ့ active Signature Requests တွေကိုပဲ ပြထားပါတယ်။',
    privacyNote:
        'Account pages က access သို့မဟုတ် authority အသစ်မပေးပါ။ Item ဖွင့်ရင် existing Business workflow ထဲကိုဝင်ပါမယ်။',
} satisfies AccountCopy;

const copy: Record<UiLanguageMode, AccountCopy> = {
    en,
    my,
    mixed,
};

export const useAccountCopy = () => {
    const { uiLanguageMode } = useI18n();

    return {
        c: computed(() => copy[uiLanguageMode.value]),
    };
};

export const humanizeAccountValue = (value: string | null): string => {
    if (!value) {
        return '';
    }

    return value
        .replace(/[_-]+/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
};

export const accountKindLabel = (
    kind: string,
    c: AccountCopy,
): string => {
    const labels: Record<string, string> = {
        approval: c.approval,
        signature: c.signature,
        proposal_review: c.proposalReview,
        record_review: c.recordReview,
        operations_action: c.operationsAction,
        governance_action: c.governanceAction,
        action_assigned: c.actionAssigned,
        proposal_review_assigned: c.proposalReviewAssigned,
        review_assigned: c.reviewAssigned,
        signature_requested: c.signatureRequested,
        governance_update: c.governanceUpdate,
    };

    return labels[kind] ?? c.governanceUpdate;
};
