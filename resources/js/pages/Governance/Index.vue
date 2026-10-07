<script setup lang="ts">
import OptionalTemporalInput from '../../components/OptionalTemporalInput.vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import Grade6MvpGuide from '../../components/journey/Grade6MvpGuide.vue';

type LanguageMode = 'en' | 'my' | 'mixed';

type RecordVersionRow = {
    id: string;
    versionNumber: number;
    state: string | null;
};

type ProposalVersionRow = {
    id: string;
    proposalId: string;
    versionNumber: number;
    proposalRevision: number;
    proposalContentHash: string;
    snapshotHash: string;
    frozenAt: string | null;
    recordVersions: RecordVersionRow[];
    review: null | {
        id: string;
        reviewerMembershipId: string;
        status: string;
        outcome: string | null;
        notes: string | null;
        dueAt: string | null;
        resolvedAt: string | null;
    };
    decisionIds: string[];
    canCreateReview: boolean;
    canCompleteReview: boolean;
    canOpenDecision: boolean;
};

type DecisionRow = {
    id: string;
    proposalVersionId: string;
    recordVersionIds: string[];
    recordVersions: RecordVersionRow[];
    type: string;
    amount: string | null;
    meetingId: string | null;
    sourceKind: string | null;
    meetingRequired: boolean;
    recordRequired: boolean;
    status: string;
    outcome: string | null;
    openedAt: string | null;
    resolvedAt: string | null;
    method: string | null;
    requiredApprovals: number;
    requiredVotes: number;
    quorumCount: number;
    signatureRequired: boolean;
    reservedMatter: boolean;
    progress: {
        approvals: number;
        supportingVotes: number;
        votesCast: number;
    };
    myParticipant: null | {
        id: string;
        capacity: string;
        status: string;
        canApprove: boolean;
        canVote: boolean;
        canSign: boolean;
    };
    actions: {
        canApprove: boolean;
        canVote: boolean;
        canRecuse: boolean;
        canResolve: boolean;
        canAdminister: boolean;
    };
};

type SignatureRow = {
    id: string;
    decisionId: string;
    documentVersionId: string;
    documentHash: string;
    status: string;
    requestedAt: string | null;
    sentAt: string | null;
    completedAt: string | null;
    signedCount: number;
    signerCount: number;
    canSend: boolean;
    canComplete: boolean;
    canSign: boolean;
    canDecline: boolean;
};

type ActionRow = {
    id: string;
    decisionId: string | null;
    formalRecordVersionId: string | null;
    assignedMembershipId: string;
    title: string;
    description: string | null;
    status: string;
    blockedReason: string | null;
    dueAt: string | null;
    completedAt: string | null;
    canManage: boolean;
};

type ReviewRow = {
    id: string;
    formalRecordVersionId: string;
    reviewerMembershipId: string;
    status: string;
    outcome: string | null;
    notes: string | null;
    dueAt: string | null;
    resolvedAt: string | null;
    canComplete: boolean;
};

type AmendmentRow = {
    id: string;
    formalRecordVersionId: string;
    reviewId: string | null;
    reason: string;
    status: string;
    resolvedAt: string | null;
    canResolve: boolean;
};

type NotificationRow = {
    id: string;
    kind: string;
    subjectType: string;
    subjectId: string;
    status: string;
    createdAt: string | null;
};

type GovernanceWorkspace = {
    business: { id: string; name: string };
    membership: { id: string };
    authority: null | {
        authority_mode: 'none' | 'bootstrap' | 'effective' | 'charter';
        source_version_id: string | null;
        source_content_hash: string | null;
        source_state: string | null;
        rules: Array<{
            id: string;
            sequence: number;
            decision_type: string;
            category: string;
            decision_method: string;
            required_approvals: number;
            required_votes: number;
            quorum_count: number;
            signature_required: boolean;
            reserved_matter: boolean;
            meeting_required: boolean;
            record_required: boolean;
            actors: Array<{
                membership_id: string;
                capacity: string;
                can_approve: boolean;
                can_vote: boolean;
                can_sign: boolean;
            }>;
        }>;
    };
    meetings: Array<{
        id: string;
        title: string;
        heldAt: string | null;
        quorumRequired: number;
        quorumPresent: number;
        authoritySourceVersionId: string;
    }>;
    summary: {
        needsAttention: number;
        openDecisions: number;
        pendingSignatures: number;
        openActions: number;
    };
    proposalVersions: ProposalVersionRow[];
    decisions: DecisionRow[];
    signatureRequests: SignatureRow[];
    actions: ActionRow[];
    reviews: ReviewRow[];
    amendments: AmendmentRow[];
    notifications: NotificationRow[];
    activeMemberships: Array<{ id: string; label: string }>;
    permissions: {
        canManageGovernance: boolean;
        canManageActions: boolean;
    };
};

const props = defineProps<{
    governance: GovernanceWorkspace;
}>();

const page = usePage();

const governanceActionError = computed(() => {
    const errors = page.props.errors as
        | Record<string, string>
        | undefined;

    return errors?.formal_record_version_id ?? '';
});

const mode = computed(
    () =>
        ((page.props.uiLanguageMode as LanguageMode | undefined) ??
            'en') as LanguageMode,
);

const copy = {
    en: {
        title: 'Governance Command Center',
        description:
            'Review authority, decisions, approvals, votes, signatures, reviews, actions and effectivity from one controlled workspace.',
        rights:
            'System access does not create governance authority. Approval, voting and signing remain bound to the captured authority and exact participant.',
        attention: 'Needs Your Attention',
        authority: 'Authority',
        rulesAuthority: 'Rules & Authority',
        decisions: 'Decision Register',
        signatures: 'Signature Requests',
        actions: 'Actions',
        reviews: 'Reviews & Amendments',
        notifications: 'Notifications',
        openDecisions: 'Open decisions',
        pendingSignatures: 'Pending signatures',
        openActions: 'Open actions',
        none: 'None',
        type: 'Type',
        state: 'State',
        progress: 'Progress',
        myCapacity: 'My capacity',
        controls: 'Actions',
        approve: 'Approve',
        reject: 'Reject',
        voteFor: 'Vote For',
        voteAgainst: 'Vote Against',
        abstain: 'Abstain',
        recuse: 'Recuse',
        resolve: 'Resolve decision',
        signatureRequired: 'Signature required',
        documentVersion: 'Document Version ID',
        document: 'Document',
        createSignature: 'Create signature request',
        send: 'Send',
        sign: 'Sign exact version',
        decline: 'Decline',
        complete: 'Complete',
        prepare: 'Ready for Effect',
        effective: 'Make Effective',
        assignedTo: 'Assigned membership',
        actionTitle: 'Action title',
        dueDate: 'Due date',
        createAction: 'Create action',
        inProgress: 'In Progress',
        blocked: 'Blocked',
        blockedReason: 'Blocked reason',
        completed: 'Completed',
        remainsValid: 'Still valid',
        amendmentRequired: 'Amendment required',
        noLongerApplicable: 'Retire / no longer applicable',
        accept: 'Accept',
        read: 'Mark read',
        bootstrap: 'Temporary Formation Authority',
        effectiveAuthority: 'Current Effective Authority',
        noAuthority: 'No authority source',
        proposalFlow: 'Frozen Proposal Version History',
        proposalVersion: 'Proposal Version',
        proposalReview: 'Proposal Review',
        startReview: 'Start my review',
        approveReview: 'Approve review',
        requestChanges: 'Request changes',
        rejectReview: 'Reject review',
        openDecision: 'Open decision',
        decisionType: 'Decision type',
        decisionAmount: 'Decision amount (optional)',
        createRecordReview: 'Create post-effect review',
        noRows: 'No authorized records are visible.',
        meetings: 'Meetings',
        decisionCenter: 'Decision Center',
        decisionCenterHelp: 'See what needs a decision, why you are involved and what action is available now. Technical identifiers stay under Advanced Details.',
        whyAsked: 'Why am I being asked?',
        whyApprove: 'You are an eligible approver captured for this decision.',
        whyVote: 'You are an eligible voter captured for this decision.',
        whySign: 'You are an eligible signer captured for this decision.',
        whyRecuse: 'You may disclose a conflict and recuse from this decision.',
        whyAdmin: 'You can administer this decision workflow.',
        whyObserver: 'You can view this decision, but no participant action is currently assigned to you.',
        advancedDetails: 'Advanced Details',
        technicalTrail: 'Technical / audit trail',
        secondaryWork: 'Follow-up work',
        authorityMatrix: 'Authority Matrix',
    },
    my: {
        title: 'အုပ်ချုပ်ဆုံးဖြတ်မှု Command Center',
        description:
            'ဆုံးဖြတ်ပိုင်ခွင့်၊ ဆုံးဖြတ်ချက်၊ အတည်ပြုမှု၊ မဲပေးမှု၊ လက်မှတ်၊ ပြန်လည်သုံးသပ်မှု၊ လုပ်ဆောင်ချက်နှင့် အာဏာသက်ရောက်မှုကို တစ်နေရာတည်းမှ စီမံကြည့်ရှုနိုင်သည်။',
        rights:
            'System အသုံးပြုခွင့်ရှိတာနဲ့ အုပ်ချုပ်ဆုံးဖြတ်ပိုင်ခွင့် မရပါ။ Approve, Vote, Sign လုပ်ခွင့်တွေက သိမ်းဆည်းထားတဲ့ Authority Snapshot နဲ့ သက်ဆိုင်ရာ participant ကိုပဲ အခြေခံပါတယ်။',
        attention: 'သင့်အာရုံစိုက်ရန်လိုသည်',
        authority: 'ဆုံးဖြတ်ပိုင်ခွင့်',
        rulesAuthority: 'စည်းမျဉ်းနှင့် ဆုံးဖြတ်ပိုင်ခွင့်',
        decisions: 'ဆုံးဖြတ်ချက် မှတ်တမ်း',
        signatures: 'လက်မှတ်တောင်းခံမှုများ',
        actions: 'လုပ်ဆောင်ရန်များ',
        reviews: 'ပြန်လည်သုံးသပ်မှုနှင့် ပြင်ဆင်ချက်များ',
        notifications: 'အသိပေးချက်များ',
        openDecisions: 'ဖွင့်ထားသော ဆုံးဖြတ်ချက်',
        pendingSignatures: 'စောင့်ဆိုင်းနေသော လက်မှတ်',
        openActions: 'မပြီးသေးသော လုပ်ဆောင်ချက်',
        none: 'မရှိ',
        type: 'အမျိုးအစား',
        state: 'အခြေအနေ',
        progress: 'တိုးတက်မှု',
        myCapacity: 'ကျွန်ုပ်၏ အခန်းကဏ္ဍ',
        controls: 'လုပ်ဆောင်ချက်',
        approve: 'အတည်ပြုမည်',
        reject: 'ပယ်ချမည်',
        voteFor: 'ထောက်ခံမဲ',
        voteAgainst: 'ကန့်ကွက်မဲ',
        abstain: 'မဲမပေးဘဲနေမည်',
        recuse: 'ပါဝင်မှုမှ ရှောင်မည်',
        resolve: 'ဆုံးဖြတ်ချက် ပိတ်မည်',
        signatureRequired: 'လက်မှတ်လိုအပ်သည်',
        documentVersion: 'Document Version ID',
        document: 'Document',
        createSignature: 'လက်မှတ်တောင်းခံမှု ဖန်တီးမည်',
        send: 'ပို့မည်',
        sign: 'ဤ Version ကို လက်မှတ်ထိုးမည်',
        decline: 'ငြင်းမည်',
        complete: 'ပြီးဆုံးမည်',
        prepare: 'သက်ရောက်ရန် အသင့်',
        effective: 'Effective ပြုလုပ်မည်',
        assignedTo: 'တာဝန်ပေးမည့် Membership',
        actionTitle: 'လုပ်ဆောင်ချက်ခေါင်းစဉ်',
        dueDate: 'နောက်ဆုံးရက်',
        createAction: 'လုပ်ဆောင်ချက် ဖန်တီးမည်',
        inProgress: 'လုပ်ဆောင်နေသည်',
        blocked: 'ပိတ်ဆို့နေသည်',
        blockedReason: 'ပိတ်ဆို့ရသည့်အကြောင်း',
        completed: 'ပြီးဆုံးပြီ',
        remainsValid: 'ဆက်လက်မှန်ကန်သည်',
        amendmentRequired: 'ပြင်ဆင်ချက်လိုအပ်သည်',
        noLongerApplicable: 'မသက်ဆိုင်တော့ပါ',
        accept: 'လက်ခံမည်',
        read: 'ဖတ်ပြီးအဖြစ်ထားမည်',
        bootstrap: 'ယာယီ Formation Authority',
        effectiveAuthority: 'လက်ရှိ Effective Authority',
        noAuthority: 'ဆုံးဖြတ်ပိုင်ခွင့် Source မရှိသေးပါ',
        proposalFlow: 'Frozen Proposal Version မှတ်တမ်း',
        proposalVersion: 'Proposal Version',
        proposalReview: 'Proposal ပြန်လည်သုံးသပ်မှု',
        startReview: 'ကျွန်ုပ် ပြန်လည်သုံးသပ်မည်',
        approveReview: 'Review အတည်ပြုမည်',
        requestChanges: 'ပြင်ဆင်ရန် ပြန်ပို့မည်',
        rejectReview: 'Review ပယ်ချမည်',
        openDecision: 'ဆုံးဖြတ်ချက် စတင်မည်',
        decisionType: 'ဆုံးဖြတ်ချက် အမျိုးအစား',
        decisionAmount: 'ဆုံးဖြတ်မည့်ပမာဏ (ရှိလျှင်)',
        createRecordReview: 'Effective record ကို ပြန်လည်သုံးသပ်မည်',
        noRows: 'သင်ကြည့်ရှုခွင့်ရှိသော မှတ်တမ်း မရှိသေးပါ။',
        meetings: 'အစည်းအဝေးများ',
        decisionCenter: 'ဆုံးဖြတ်ချက် Center',
        decisionCenterHelp: 'ဘာကို ဆုံးဖြတ်ရမလဲ၊ ဘာကြောင့် သင်ပါဝင်နေရတာလဲ၊ အခု ဘာလုပ်နိုင်လဲကို ကြည့်ပါ။ Technical identifier များကို Advanced Details ထဲမှာပဲ ထားပါသည်။',
        whyAsked: 'ဘာကြောင့် ကျွန်ုပ်ကို လုပ်ဆောင်ခိုင်းထားတာလဲ?',
        whyApprove: 'ဤဆုံးဖြတ်ချက်အတွက် သင်သည် captured eligible approver ဖြစ်သည်။',
        whyVote: 'ဤဆုံးဖြတ်ချက်အတွက် သင်သည် captured eligible voter ဖြစ်သည်။',
        whySign: 'ဤဆုံးဖြတ်ချက်အတွက် သင်သည် captured eligible signer ဖြစ်သည်။',
        whyRecuse: 'Conflict ရှိပါက ဖော်ပြပြီး ဤဆုံးဖြတ်ချက်မှ recuse လုပ်နိုင်သည်။',
        whyAdmin: 'ဤဆုံးဖြတ်ချက် workflow ကို စီမံခန့်ခွဲနိုင်သည်။',
        whyObserver: 'ဤဆုံးဖြတ်ချက်ကို ကြည့်နိုင်သော်လည်း လက်ရှိ participant action တာဝန်မရှိပါ။',
        advancedDetails: 'Advanced Details',
        technicalTrail: 'Technical / audit trail',
        secondaryWork: 'နောက်ဆက်တွဲ လုပ်ငန်းများ',
        authorityMatrix: 'Authority Matrix',
    },
    mixed: {
        title: 'Governance Command Center · အုပ်ချုပ်ဆုံးဖြတ်မှု',
        description:
            'Authority, Decisions, Approvals, Votes, Signatures, Reviews, Actions နဲ့ Effectivity ကို controlled workspace တစ်ခုထဲမှာ စီမံပါ။',
        rights:
            'System access ≠ Governance authority. Approve, Vote, Sign လုပ်ခွင့်က captured Authority Snapshot နဲ့ exact participant ကိုပဲ အခြေခံပါတယ်။',
        attention: 'Needs Your Attention · သင့်အာရုံစိုက်ရန်',
        authority: 'Authority · ဆုံးဖြတ်ပိုင်ခွင့်',
        rulesAuthority: 'Rules & Authority · စည်းမျဉ်း/ဆုံးဖြတ်ပိုင်ခွင့်',
        decisions: 'Decision Register · ဆုံးဖြတ်ချက်မှတ်တမ်း',
        signatures: 'Signature Requests · လက်မှတ်တောင်းခံမှု',
        actions: 'Actions · လုပ်ဆောင်ရန်',
        reviews: 'Reviews & Amendments · ပြန်လည်သုံးသပ်/ပြင်ဆင်',
        notifications: 'Notifications · အသိပေးချက်',
        openDecisions: 'Open decisions',
        pendingSignatures: 'Pending signatures',
        openActions: 'Open actions',
        none: 'None · မရှိ',
        type: 'Type · အမျိုးအစား',
        state: 'State · အခြေအနေ',
        progress: 'Progress · တိုးတက်မှု',
        myCapacity: 'My capacity · ကျွန်ုပ်၏အခန်းကဏ္ဍ',
        controls: 'Actions · လုပ်ဆောင်ချက်',
        approve: 'Approve · အတည်ပြု',
        reject: 'Reject · ပယ်ချ',
        voteFor: 'Vote For · ထောက်ခံ',
        voteAgainst: 'Vote Against · ကန့်ကွက်',
        abstain: 'Abstain · မဲမပေး',
        recuse: 'Recuse · ပါဝင်မှုမှရှောင်',
        resolve: 'Resolve decision',
        signatureRequired: 'Signature required · လက်မှတ်လိုအပ်',
        documentVersion: 'Document Version ID',
        document: 'Document',
        createSignature: 'Create signature request',
        send: 'Send · ပို့',
        sign: 'Sign exact version · လက်မှတ်ထိုး',
        decline: 'Decline · ငြင်း',
        complete: 'Complete · ပြီးဆုံး',
        prepare: 'Ready for Effect',
        effective: 'Make Effective',
        assignedTo: 'Assigned Membership',
        actionTitle: 'Action title',
        dueDate: 'Due date',
        createAction: 'Create action',
        inProgress: 'In Progress',
        blocked: 'Blocked',
        blockedReason: 'Blocked reason',
        completed: 'Completed',
        remainsValid: 'Still valid',
        amendmentRequired: 'Amendment required',
        noLongerApplicable: 'Retire / no longer applicable',
        accept: 'Accept',
        read: 'Mark read',
        bootstrap: 'Temporary Formation Authority',
        effectiveAuthority: 'Current Effective Authority',
        noAuthority: 'No authority source',
        proposalFlow: 'Frozen Proposal Version History · Version မှတ်တမ်း',
        proposalVersion: 'Proposal Version',
        proposalReview: 'Proposal Review · ပြန်လည်သုံးသပ်မှု',
        startReview: 'Start my review · Review စ',
        approveReview: 'Approve review · အတည်ပြု',
        requestChanges: 'Request changes · ပြင်ဆင်ရန်ပြန်ပို့',
        rejectReview: 'Reject review · ပယ်ချ',
        openDecision: 'Open decision · ဆုံးဖြတ်ချက်စ',
        decisionType: 'Decision type',
        decisionAmount: 'Decision amount · ပမာဏ',
        createRecordReview: 'Create post-effect review',
        noRows: 'No authorized records are visible.',
        meetings: 'Meetings · အစည်းအဝေးများ',
        decisionCenter: 'Decision Center · ဆုံးဖြတ်ချက်',
        decisionCenterHelp: 'ဘာကို decide လုပ်ရမလဲ၊ why you are involved နဲ့ available action ကို အရင်ကြည့်ပါ။ Technical identifiers ကို Advanced Details ထဲမှာပဲထားပါတယ်။',
        whyAsked: 'Why am I being asked? · ဘာကြောင့်လဲ?',
        whyApprove: 'You are a captured eligible approver for this decision.',
        whyVote: 'You are a captured eligible voter for this decision.',
        whySign: 'You are a captured eligible signer for this decision.',
        whyRecuse: 'Conflict ရှိရင် disclose လုပ်ပြီး recuse လုပ်နိုင်တယ်။',
        whyAdmin: 'You can administer this decision workflow.',
        whyObserver: 'View access ရှိပေမယ့် current participant action မရှိပါ။',
        advancedDetails: 'Advanced Details',
        technicalTrail: 'Technical / audit trail',
        secondaryWork: 'Follow-up work · နောက်ဆက်တွဲ',
        authorityMatrix: 'Authority Matrix',
    },
} as const;

const c = computed(() => copy[mode.value]);

type PostData = NonNullable<Parameters<typeof router.post>[1]>;

const post = (url: string, data: PostData = {}) => {
    router.post(url, data, {
        preserveScroll: true,
    });
};

const signatureDocumentVersions = reactive<Record<string, string>>({});
const actionTitles = reactive<Record<string, string>>({});
const actionAssignees = reactive<Record<string, string>>({});
const actionDueDates = reactive<Record<string, string>>({});
const recusalReasons = reactive<Record<string, string>>({});
const blockedReasons = reactive<Record<string, string>>({});
const proposalDecisionTypes = reactive<Record<string, string>>({});
const proposalDecisionAmounts = reactive<Record<string, string>>({});
const proposalDecisionMeetingIds = reactive<Record<string, string>>({});

const authorityLabel = computed(() => {
    const authorityMode = props.governance.authority?.authority_mode ?? 'none';

    if (authorityMode === 'bootstrap') return c.value.bootstrap;
    if (authorityMode === 'effective' || authorityMode === 'charter') {
        return c.value.effectiveAuthority;
    }

    return c.value.noAuthority;
});

const decisionReasons = (decision: DecisionRow): string[] => {
    const reasons: string[] = [];

    if (decision.myParticipant?.capacity) {
        reasons.push(decision.myParticipant.capacity);
    }
    if (decision.actions.canApprove) reasons.push(c.value.whyApprove);
    if (decision.actions.canVote) reasons.push(c.value.whyVote);
    if (decision.myParticipant?.canSign) reasons.push(c.value.whySign);
    if (decision.actions.canRecuse) reasons.push(c.value.whyRecuse);
    if (decision.actions.canAdminister) reasons.push(c.value.whyAdmin);

    return reasons.length > 0 ? reasons : [c.value.whyObserver];
};

const signatureReason = (row: SignatureRow): string => {
    if (row.canSign) return c.value.whySign;
    if (row.canSend || row.canComplete) return c.value.whyAdmin;

    return c.value.whyObserver;
};

const selectedRule = (proposalId: string) => {
    const type =
        proposalDecisionTypes[proposalId] ||
        props.governance.authority?.rules?.[0]?.decision_type ||
        '';

    return props.governance.authority?.rules.find(
        (row) => row.decision_type === type,
    );
};

const eligibleMeetings = (proposalId: string) => {
    const sourceId = props.governance.authority?.source_version_id;
    const rule = selectedRule(proposalId);

    if (!rule?.meeting_required || !sourceId) return [];

    return props.governance.meetings.filter(
        (meeting) =>
            meeting.authoritySourceVersionId === sourceId &&
            meeting.quorumPresent >= meeting.quorumRequired,
    );
};

const formatDate = (value: string | null): string => {
    if (value === null) return '—';

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? value
        : new Intl.DateTimeFormat(undefined, {
              year: 'numeric',
              month: 'short',
              day: 'numeric',
          }).format(date);
};

const signExactVersion = (row: SignatureRow) => {
    if (
        !window.confirm(
            `${c.value.sign}\n\n${row.documentVersionId}\nSHA-256 ${row.documentHash}`,
        )
    ) {
        return;
    }

    post(`/governance/signature-requests/${row.id}/sign`, {
        consent: true,
    });
};

const makeEffective = (decision: DecisionRow, versionId: string) => {
    if (!window.confirm(`${c.value.effective}\n\n${versionId}`)) return;

    post(`/governance/decisions/${decision.id}/make-effective`, {
        formal_record_version_id: versionId,
    });
};
</script>

<template>
    <AuthenticatedLayout>
        <Grade6MvpGuide step="governance" />
        <main class="min-h-screen bg-[radial-gradient(circle_at_88%_0%,rgb(210_167_67_/_8%),transparent_26rem),linear-gradient(180deg,#f7f9f6_0%,#f1f5f1_100%)] px-4 py-5 text-[var(--pbr-ink)] sm:px-6 sm:py-6 lg:px-8 lg:py-7">
            <div class="mx-auto w-full max-w-[1500px]">
            <header class="rounded-[24px] border border-[#d8e4da] bg-white/90 p-5 shadow-[0_14px_34px_rgb(16_35_26_/_5%)] sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                            {{ governance.business.name }}
                        </p>
                        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                            {{ c.title }}
                        </h1>
                        <p class="mt-2 max-w-4xl text-sm leading-6 text-slate-600">
                            {{ c.description }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <Link
                            href="/governance/rules"
                            class="inline-flex min-h-10 items-center rounded-xl border border-[#d8e4da] bg-white px-3 text-xs font-bold text-slate-800 hover:bg-[#f6f8f6]"
                        >
                            {{ c.rulesAuthority }}
                        </Link>
                        <Link
                            href="/governance/meetings"
                            class="inline-flex min-h-10 items-center rounded-xl border border-[#d8e4da] bg-white px-3 text-xs font-bold text-slate-800 hover:bg-[#f6f8f6]"
                        >
                            {{ c.meetings }}
                        </Link>

                        <span
                            class="inline-flex min-h-8 items-center border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-800"
                        >
                            {{ authorityLabel }}
                        </span>
                    </div>
                </div>

                <p
                    class="mt-5 border-l-4 border-slate-800 bg-slate-100 px-4 py-3 text-sm font-medium leading-6 text-slate-800"
                    role="note"
                >
                    {{ c.rights }}
                </p>

                <p
                    v-if="governanceActionError"
                    class="mt-3 border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800"
                    role="alert"
                >
                    {{ governanceActionError }}
                </p>
            </header>

            <section class="grid gap-px border-b border-slate-200 bg-slate-200 py-6 sm:grid-cols-2 xl:grid-cols-4">
                <div class="bg-white p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        {{ c.attention }}
                    </p>
                    <p class="mt-2 text-3xl font-semibold text-slate-950">
                        {{ governance.summary.needsAttention }}
                    </p>
                </div>
                <div class="bg-white p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        {{ c.openDecisions }}
                    </p>
                    <p class="mt-2 text-3xl font-semibold text-slate-950">
                        {{ governance.summary.openDecisions }}
                    </p>
                </div>
                <div class="bg-white p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        {{ c.pendingSignatures }}
                    </p>
                    <p class="mt-2 text-3xl font-semibold text-slate-950">
                        {{ governance.summary.pendingSignatures }}
                    </p>
                </div>
                <div class="bg-white p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        {{ c.openActions }}
                    </p>
                    <p class="mt-2 text-3xl font-semibold text-slate-950">
                        {{ governance.summary.openActions }}
                    </p>
                </div>
            </section>

            <section class="border-b border-slate-200 py-6">
                <h2 class="text-lg font-semibold text-slate-950">
                    {{ c.authority }}
                </h2>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full border-collapse text-left text-sm">
                        <thead>
                            <tr class="border-b border-slate-300 text-slate-600">
                                <th class="px-3 py-3 font-semibold">{{ c.type }}</th>
                                <th class="px-3 py-3 font-semibold">{{ c.state }}</th>
                                <th class="px-3 py-3 font-semibold">{{ c.progress }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="rule in governance.authority?.rules ?? []"
                                :key="rule.id"
                                class="border-b border-slate-200 align-top"
                            >
                                <td class="px-3 py-3 font-medium text-slate-950">
                                    {{ rule.decision_type }}
                                </td>
                                <td class="px-3 py-3 text-slate-700">
                                    {{ rule.decision_method }}
                                </td>
                                <td class="px-3 py-3 text-slate-700">
                                    A {{ rule.required_approvals }} · V
                                    {{ rule.required_votes }} · Q
                                    {{ rule.quorum_count }}
                                    <span v-if="rule.signature_required">
                                        · {{ c.signatureRequired }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="(governance.authority?.rules.length ?? 0) === 0">
                                <td colspan="3" class="px-3 py-5 text-slate-600">
                                    {{ c.noRows }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="border-b border-slate-200 py-6">
                <div class="flex items-center justify-between gap-4">
                    <h2 class="text-lg font-semibold text-slate-950">
                        {{ c.proposalFlow }}
                    </h2>
                    <span class="text-xs font-semibold text-slate-500">
                        {{ governance.proposalVersions.length }}
                    </span>
                </div>

                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full border-collapse text-left text-sm">
                        <thead>
                            <tr class="border-b border-slate-300 text-slate-600">
                                <th class="px-3 py-3 font-semibold">
                                    {{ c.proposalVersion }}
                                </th>
                                <th class="px-3 py-3 font-semibold">
                                    {{ c.proposalReview }}
                                </th>
                                <th class="px-3 py-3 font-semibold">
                                    {{ c.state }}
                                </th>
                                <th class="px-3 py-3 font-semibold">
                                    {{ c.controls }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in governance.proposalVersions"
                                :key="row.id"
                                class="border-b border-slate-200 align-top"
                            >
                                <td class="min-w-72 px-3 py-4">
                                    <p class="font-semibold text-slate-950">
                                        Frozen proposal
                                    </p>
                                    <p class="mt-1 text-xs text-slate-500">
                                        {{ formatDate(row.frozenAt) }}
                                    </p>
                                    <details class="mt-3 text-xs text-slate-600">
                                        <summary class="cursor-pointer font-semibold text-slate-700">
                                            {{ c.advancedDetails }}
                                        </summary>
                                        <div class="mt-2 space-y-1 border-l-2 border-slate-200 pl-3">
                                            <p>Proposal version {{ row.versionNumber }} · revision {{ row.proposalRevision }}</p>
                                            <code class="block break-all text-[10px]">{{ row.id }}</code>
                                            <p class="break-all font-mono text-[10px]">
                                                SHA-256 {{ row.proposalContentHash }}
                                            </p>
                                        </div>
                                    </details>
                                </td>

                                <td class="min-w-48 px-3 py-4">
                                    <template v-if="row.review">
                                        <p class="font-semibold text-slate-800">
                                            {{ row.review.status }}
                                        </p>
                                        <p
                                            v-if="row.review.outcome"
                                            class="mt-1 text-xs text-slate-600"
                                        >
                                            {{ row.review.outcome }}
                                        </p>
                                    </template>
                                    <span v-else>—</span>
                                </td>

                                <td class="min-w-56 px-3 py-4">
                                    <div
                                        v-for="recordVersion in row.recordVersions"
                                        :key="recordVersion.id"
                                        class="mb-2"
                                    >
                                        <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                            {{ recordVersion.state ?? '—' }}
                                        </span>
                                    </div>

                                    <p
                                        v-if="row.decisionIds.length > 0"
                                        class="mt-2 text-xs text-slate-600"
                                    >
                                        Decision {{ row.decisionIds.length }}
                                    </p>
                                </td>

                                <td class="min-w-96 px-3 py-4">
                                    <button
                                        v-if="row.canCreateReview"
                                        type="button"
                                        class="min-h-10 border border-slate-300 bg-white px-3 text-xs font-semibold"
                                        @click="
                                            post(
                                                `/governance/proposal-versions/${row.id}/reviews`,
                                                {
                                                    reviewer_membership_id:
                                                        governance.membership.id,
                                                },
                                            )
                                        "
                                    >
                                        {{ c.startReview }}
                                    </button>

                                    <div
                                        v-if="
                                            row.review &&
                                            row.canCompleteReview
                                        "
                                        class="flex flex-wrap gap-2"
                                    >
                                        <button
                                            type="button"
                                            class="min-h-10 border border-slate-900 bg-slate-900 px-3 text-xs font-semibold text-white"
                                            @click="
                                                post(
                                                    `/governance/proposal-reviews/${row.review.id}/complete`,
                                                    { outcome: 'approved' },
                                                )
                                            "
                                        >
                                            {{ c.approveReview }}
                                        </button>

                                        <button
                                            type="button"
                                            class="min-h-10 border border-slate-300 bg-white px-3 text-xs font-semibold"
                                            @click="
                                                post(
                                                    `/governance/proposal-reviews/${row.review.id}/complete`,
                                                    {
                                                        outcome:
                                                            'changes_requested',
                                                    },
                                                )
                                            "
                                        >
                                            {{ c.requestChanges }}
                                        </button>

                                        <button
                                            type="button"
                                            class="min-h-10 border border-slate-300 bg-white px-3 text-xs font-semibold"
                                            @click="
                                                post(
                                                    `/governance/proposal-reviews/${row.review.id}/complete`,
                                                    { outcome: 'rejected' },
                                                )
                                            "
                                        >
                                            {{ c.rejectReview }}
                                        </button>
                                    </div>

                                    <div
                                        v-if="row.canOpenDecision"
                                        class="mt-3 grid max-w-xl gap-2 sm:grid-cols-2"
                                    >
                                        <select
                                            v-model="proposalDecisionTypes[row.id]"
                                            class="min-h-10 border border-slate-300 bg-white px-2 text-xs"
                                        >
                                            <option value="">
                                                {{ c.decisionType }}
                                            </option>
                                            <option
                                                v-for="rule in governance.authority?.rules ?? []"
                                                :key="rule.id"
                                                :value="rule.decision_type"
                                            >
                                                {{ rule.decision_type }}
                                            </option>
                                        </select>

                                        <input
                                            v-model="proposalDecisionAmounts[row.id]"
                                            class="min-h-10 border border-slate-300 px-3 text-xs"
                                            :placeholder="c.decisionAmount"
                                        />

                                        <select
                                            v-if="selectedRule(row.id)?.meeting_required"
                                            v-model="proposalDecisionMeetingIds[row.id]"
                                            class="min-h-10 border border-slate-300 bg-white px-2 text-xs sm:col-span-2"
                                        >
                                            <option value="">Select qualifying held meeting</option>
                                            <option
                                                v-for="meeting in eligibleMeetings(row.id)"
                                                :key="meeting.id"
                                                :value="meeting.id"
                                            >
                                                {{ meeting.title }}
                                            </option>
                                        </select>

                                        <button
                                            type="button"
                                            class="min-h-10 border border-slate-900 bg-slate-900 px-3 text-xs font-semibold text-white sm:col-span-2"
                                            :disabled="
                                                !(
                                                    proposalDecisionTypes[row.id] ||
                                                    governance.authority?.rules?.[0]
                                                        ?.decision_type
                                                ) ||
                                                (selectedRule(row.id)?.meeting_required &&
                                                    !proposalDecisionMeetingIds[row.id])
                                            "
                                            @click="
                                                post(
                                                    `/governance/proposal-versions/${row.id}/decisions`,
                                                    {
                                                        decision_type:
                                                            proposalDecisionTypes[
                                                                row.id
                                                            ] ||
                                                            governance.authority
                                                                ?.rules?.[0]
                                                                ?.decision_type ||
                                                            '',
                                                        decision_amount:
                                                            proposalDecisionAmounts[
                                                                row.id
                                                            ] || null,
                                                        meeting_id:
                                                            proposalDecisionMeetingIds[
                                                                row.id
                                                            ] || null,
                                                    },
                                                )
                                            "
                                        >
                                            {{ c.openDecision }}
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="governance.proposalVersions.length === 0">
                                <td colspan="4" class="px-3 py-5 text-slate-600">
                                    {{ c.noRows }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="border-b border-slate-200 py-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]">
                            {{ c.decisions }}
                        </p>
                        <h2 class="mt-1 text-xl font-black tracking-[-0.02em] text-[var(--pbr-ink)]">
                            {{ c.decisionCenter }}
                        </h2>
                        <p class="mt-1 max-w-4xl text-sm leading-6 text-[var(--pbr-muted)]">
                            {{ c.decisionCenterHelp }}
                        </p>
                    </div>
                    <span class="rounded-full bg-[#eef4ef] px-3 py-1 text-xs font-black text-[#476052]">
                        {{ governance.decisions.length }}
                    </span>
                </div>

                <div class="mt-4 overflow-x-auto rounded-[18px] border border-[#d8e4da] bg-white">
                    <table class="min-w-full border-collapse text-left text-sm">
                        <thead>
                            <tr class="border-b border-slate-300 text-slate-600">
                                <th class="px-3 py-3 font-semibold">{{ c.type }}</th>
                                <th class="px-3 py-3 font-semibold">{{ c.state }}</th>
                                <th class="px-3 py-3 font-semibold">{{ c.progress }}</th>
                                <th class="px-3 py-3 font-semibold">{{ c.myCapacity }}</th>
                                <th class="px-3 py-3 font-semibold">{{ c.controls }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="decision in governance.decisions"
                                :key="decision.id"
                                class="border-b border-slate-200 align-top"
                            >
                                <td class="min-w-48 px-3 py-4">
                                    <p class="font-semibold text-slate-950">
                                        {{ decision.type }}
                                    </p>
                                    <details class="mt-2 text-xs text-slate-600">
                                        <summary class="cursor-pointer font-semibold text-slate-700">
                                            {{ c.advancedDetails }}
                                        </summary>
                                        <code class="mt-2 block break-all font-mono text-[10px] text-slate-500">
                                            {{ decision.id }}
                                        </code>
                                    </details>
                                    <p
                                        v-if="decision.reservedMatter"
                                        class="mt-2 text-xs font-semibold text-slate-700"
                                    >
                                        Reserved matter
                                    </p>
                                </td>
                                <td class="px-3 py-4">
                                    <span class="font-semibold text-slate-800">
                                        {{ decision.status }}
                                    </span>
                                    <p v-if="decision.outcome" class="mt-1 text-xs text-slate-600">
                                        {{ decision.outcome }}
                                    </p>
                                </td>
                                <td class="min-w-44 px-3 py-4 text-slate-700">
                                    <p>
                                        A {{ decision.progress.approvals }}/{{
                                            decision.requiredApprovals
                                        }}
                                    </p>
                                    <p>
                                        V {{ decision.progress.supportingVotes }}/{{
                                            decision.requiredVotes
                                        }}
                                    </p>
                                    <p>
                                        Q {{ decision.progress.votesCast }}/{{
                                            decision.quorumCount
                                        }}
                                    </p>
                                </td>
                                <td class="min-w-56 px-3 py-4 text-slate-700">
                                    <template v-if="decision.myParticipant">
                                        <p class="font-medium">
                                            {{ decision.myParticipant.capacity }}
                                        </p>
                                        <p class="mt-1 text-xs">
                                            {{ decision.myParticipant.status }}
                                        </p>
                                    </template>
                                    <span v-else>—</span>

                                    <details class="mt-3 rounded-lg bg-[#f5f8f5] px-3 py-2 text-xs">
                                        <summary class="cursor-pointer font-black text-[var(--pbr-green-dark)]">
                                            {{ c.whyAsked }}
                                        </summary>
                                        <ul class="mt-2 space-y-1.5 leading-5 text-slate-600">
                                            <li
                                                v-for="reason in decisionReasons(decision)"
                                                :key="reason"
                                            >
                                                {{ reason }}
                                            </li>
                                        </ul>
                                    </details>
                                </td>
                                <td class="min-w-80 px-3 py-4">
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            v-if="decision.actions.canApprove"
                                            type="button"
                                            class="min-h-10 border border-slate-900 bg-slate-900 px-3 text-xs font-semibold text-white"
                                            @click="
                                                post(
                                                    `/governance/decisions/${decision.id}/approvals`,
                                                    { outcome: 'approved' },
                                                )
                                            "
                                        >
                                            {{ c.approve }}
                                        </button>
                                        <button
                                            v-if="decision.actions.canApprove"
                                            type="button"
                                            class="min-h-10 border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-800"
                                            @click="
                                                post(
                                                    `/governance/decisions/${decision.id}/approvals`,
                                                    { outcome: 'rejected' },
                                                )
                                            "
                                        >
                                            {{ c.reject }}
                                        </button>
                                        <button
                                            v-if="decision.actions.canVote"
                                            type="button"
                                            class="min-h-10 border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-800"
                                            @click="
                                                post(
                                                    `/governance/decisions/${decision.id}/votes`,
                                                    { choice: 'for' },
                                                )
                                            "
                                        >
                                            {{ c.voteFor }}
                                        </button>
                                        <button
                                            v-if="decision.actions.canVote"
                                            type="button"
                                            class="min-h-10 border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-800"
                                            @click="
                                                post(
                                                    `/governance/decisions/${decision.id}/votes`,
                                                    { choice: 'against' },
                                                )
                                            "
                                        >
                                            {{ c.voteAgainst }}
                                        </button>
                                        <button
                                            v-if="decision.actions.canVote"
                                            type="button"
                                            class="min-h-10 border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-800"
                                            @click="
                                                post(
                                                    `/governance/decisions/${decision.id}/votes`,
                                                    { choice: 'abstain' },
                                                )
                                            "
                                        >
                                            {{ c.abstain }}
                                        </button>
                                        <button
                                            v-if="decision.actions.canResolve"
                                            type="button"
                                            class="min-h-10 border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-800"
                                            @click="
                                                post(
                                                    `/governance/decisions/${decision.id}/resolve`,
                                                )
                                            "
                                        >
                                            {{ c.resolve }}
                                        </button>
                                    </div>

                                    <div
                                        v-if="decision.actions.canRecuse"
                                        class="mt-3 flex max-w-xl gap-2"
                                    >
                                        <input
                                            v-model="recusalReasons[decision.id]"
                                            type="text"
                                            maxlength="1000"
                                            class="min-h-10 min-w-0 flex-1 border border-slate-300 bg-white px-3 text-xs"
                                            placeholder="Conflict / recusal reason"
                                        />
                                        <button
                                            type="button"
                                            class="min-h-10 border border-slate-300 bg-white px-3 text-xs font-semibold"
                                            :disabled="!recusalReasons[decision.id]"
                                            @click="
                                                post(
                                                    `/governance/decisions/${decision.id}/recusal`,
                                                    {
                                                        reason:
                                                            recusalReasons[
                                                                decision.id
                                                            ],
                                                    },
                                                )
                                            "
                                        >
                                            {{ c.recuse }}
                                        </button>
                                    </div>

                                    <details
                                        v-if="
                                            (
                                                decision.signatureRequired
                                                && decision.status === 'decided'
                                                && decision.outcome === 'approved'
                                                && decision.actions.canAdminister
                                            )
                                            || (
                                                decision.actions.canAdminister
                                                && decision.status === 'decided'
                                                && decision.outcome === 'approved'
                                            )
                                        "
                                        class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-3"
                                    >
                                        <summary class="cursor-pointer text-xs font-black text-slate-700">
                                            {{ c.advancedDetails }}
                                        </summary>

                                    <div
                                        v-if="
                                            decision.signatureRequired &&
                                            decision.status === 'decided' &&
                                            decision.outcome === 'approved' &&
                                            decision.actions.canAdminister
                                        "
                                        class="mt-3 flex max-w-xl gap-2"
                                    >
                                        <input
                                            v-model="
                                                signatureDocumentVersions[
                                                    decision.id
                                                ]
                                            "
                                            type="text"
                                            class="min-h-10 min-w-0 flex-1 border border-slate-300 px-3 font-mono text-xs"
                                            :placeholder="c.documentVersion"
                                        />
                                        <button
                                            type="button"
                                            class="min-h-10 border border-slate-900 bg-slate-900 px-3 text-xs font-semibold text-white"
                                            :disabled="
                                                !signatureDocumentVersions[
                                                    decision.id
                                                ]
                                            "
                                            @click="
                                                post(
                                                    `/governance/decisions/${decision.id}/signature-requests`,
                                                    {
                                                        document_version_id:
                                                            signatureDocumentVersions[
                                                                decision.id
                                                            ],
                                                    },
                                                )
                                            "
                                        >
                                            {{ c.createSignature }}
                                        </button>
                                    </div>

                                    <div
                                        v-if="
                                            decision.actions.canAdminister &&
                                            decision.status === 'decided' &&
                                            decision.outcome === 'approved' &&
                                            governance.activeMemberships.length > 0
                                        "
                                        class="mt-3 grid max-w-xl gap-2 sm:grid-cols-2"
                                    >
                                        <select
                                            v-model="actionAssignees[decision.id]"
                                            class="min-h-10 border border-slate-300 bg-white px-2 text-xs"
                                        >
                                            <option value="">{{ c.assignedTo }}</option>
                                            <option
                                                v-for="member in governance.activeMemberships"
                                                :key="member.id"
                                                :value="member.id"
                                            >
                                                {{ member.label }}
                                            </option>
                                        </select>
                                        <input
                                            v-model="actionTitles[decision.id]"
                                            class="min-h-10 border border-slate-300 px-3 text-xs"
                                            :placeholder="c.actionTitle"
                                        />
                                        <OptionalTemporalInput
                                            v-model="actionDueDates[decision.id]"
                                            type="date"
                                            class="min-h-10 border border-slate-300 px-3 text-xs"
                                        />
                                        <button
                                            type="button"
                                            class="min-h-10 border border-slate-300 bg-white px-3 text-xs font-semibold"
                                            :disabled="
                                                !actionAssignees[decision.id] ||
                                                !actionTitles[decision.id]
                                            "
                                            @click="
                                                post(
                                                    `/governance/decisions/${decision.id}/actions`,
                                                    {
                                                        assigned_membership_id:
                                                            actionAssignees[
                                                                decision.id
                                                            ],
                                                        title:
                                                            actionTitles[
                                                                decision.id
                                                            ],
                                                        due_at:
                                                            actionDueDates[
                                                                decision.id
                                                            ] || null,
                                                    },
                                                )
                                            "
                                        >
                                            {{ c.createAction }}
                                        </button>
                                    </div>

                                    <div
                                        v-if="
                                            decision.actions.canAdminister &&
                                            decision.status === 'decided' &&
                                            decision.outcome === 'approved' &&
                                            decision.recordVersions.length > 0
                                        "
                                        class="mt-3 space-y-2"
                                    >
                                        <div
                                            v-for="recordVersion in decision.recordVersions"
                                            :key="recordVersion.id"
                                            class="flex flex-wrap items-center gap-2"
                                        >
                                            <span class="text-xs font-semibold text-slate-600">
                                                {{ recordVersion.state ?? '—' }}
                                            </span>

                                            <details class="text-xs text-slate-500">
                                                <summary class="cursor-pointer font-semibold">
                                                    {{ c.advancedDetails }}
                                                </summary>
                                                <code class="mt-1 block break-all text-[10px]">
                                                    {{ recordVersion.id }}
                                                </code>
                                            </details>

                                            <button
                                                v-if="recordVersion.state === 'approved'"
                                                type="button"
                                                class="min-h-9 border border-slate-300 bg-white px-2 text-xs font-semibold"
                                                @click="
                                                    post(
                                                        `/governance/decisions/${decision.id}/prepare-effect`,
                                                        {
                                                            formal_record_version_id:
                                                                recordVersion.id,
                                                        },
                                                    )
                                                "
                                            >
                                                {{ c.prepare }}
                                            </button>

                                            <button
                                                v-if="
                                                    recordVersion.state ===
                                                    'ready_for_effect'
                                                "
                                                type="button"
                                                class="min-h-9 border border-slate-900 bg-slate-900 px-2 text-xs font-semibold text-white"
                                                @click="
                                                    makeEffective(
                                                        decision,
                                                        recordVersion.id,
                                                    )
                                                "
                                            >
                                                {{ c.effective }}
                                            </button>

                                            <button
                                                v-if="recordVersion.state === 'effective'"
                                                type="button"
                                                class="min-h-9 border border-slate-300 bg-white px-2 text-xs font-semibold"
                                                @click="
                                                    post(
                                                        `/governance/record-versions/${recordVersion.id}/reviews`,
                                                        {
                                                            reviewer_membership_id:
                                                                governance.membership
                                                                    .id,
                                                        },
                                                    )
                                                "
                                            >
                                                {{ c.createRecordReview }}
                                            </button>
                                        </div>
                                    </div>
                                    </details>
                                </td>
                            </tr>
                            <tr v-if="governance.decisions.length === 0">
                                <td colspan="5" class="px-3 py-5 text-slate-600">
                                    {{ c.noRows }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="border-b border-slate-200 py-6">
                <h2 class="text-lg font-semibold text-slate-950">
                    {{ c.signatures }}
                </h2>

                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full border-collapse text-left text-sm">
                        <thead>
                            <tr class="border-b border-slate-300 text-slate-600">
                                <th class="px-3 py-3 font-semibold">{{ c.state }}</th>
                                <th class="px-3 py-3 font-semibold">{{ c.document }}</th>
                                <th class="px-3 py-3 font-semibold">{{ c.progress }}</th>
                                <th class="px-3 py-3 font-semibold">{{ c.controls }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in governance.signatureRequests"
                                :key="row.id"
                                class="border-b border-slate-200 align-top"
                            >
                                <td class="px-3 py-4 font-semibold text-slate-800">
                                    {{ row.status }}
                                </td>
                                <td class="max-w-md px-3 py-4">
                                    <p class="font-medium text-slate-800">
                                        Exact document locked for signature
                                    </p>
                                    <p class="mt-1 text-xs text-slate-500">
                                        {{ formatDate(row.requestedAt) }}
                                    </p>
                                    <details class="mt-2 text-xs text-slate-600">
                                        <summary class="cursor-pointer font-semibold text-slate-700">
                                            {{ c.advancedDetails }}
                                        </summary>
                                        <div class="mt-2 space-y-1 border-l-2 border-slate-200 pl-3">
                                            <code class="block break-all text-[10px]">{{ row.documentVersionId }}</code>
                                            <p class="break-all font-mono text-[10px]">{{ row.documentHash }}</p>
                                        </div>
                                    </details>
                                    <p
                                        v-if="row.canSign || row.canSend || row.canComplete"
                                        class="mt-3 rounded-lg bg-[#f5f8f5] px-3 py-2 text-xs leading-5 text-slate-600"
                                    >
                                        <strong class="text-[var(--pbr-green-dark)]">{{ c.whyAsked }}</strong>
                                        {{ signatureReason(row) }}
                                    </p>
                                </td>
                                <td class="px-3 py-4 text-slate-700">
                                    {{ row.signedCount }}/{{ row.signerCount }}
                                </td>
                                <td class="px-3 py-4">
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            v-if="row.canSend"
                                            type="button"
                                            class="min-h-10 border border-slate-300 bg-white px-3 text-xs font-semibold"
                                            @click="
                                                post(
                                                    `/governance/signature-requests/${row.id}/send`,
                                                )
                                            "
                                        >
                                            {{ c.send }}
                                        </button>
                                        <button
                                            v-if="row.canSign"
                                            type="button"
                                            class="min-h-10 border border-slate-900 bg-slate-900 px-3 text-xs font-semibold text-white"
                                            @click="signExactVersion(row)"
                                        >
                                            {{ c.sign }}
                                        </button>
                                        <button
                                            v-if="row.canDecline"
                                            type="button"
                                            class="min-h-10 border border-slate-300 bg-white px-3 text-xs font-semibold"
                                            @click="
                                                post(
                                                    `/governance/signature-requests/${row.id}/decline`,
                                                )
                                            "
                                        >
                                            {{ c.decline }}
                                        </button>
                                        <button
                                            v-if="row.canComplete"
                                            type="button"
                                            class="min-h-10 border border-slate-300 bg-white px-3 text-xs font-semibold"
                                            @click="
                                                post(
                                                    `/governance/signature-requests/${row.id}/complete`,
                                                )
                                            "
                                        >
                                            {{ c.complete }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="governance.signatureRequests.length === 0">
                                <td colspan="4" class="px-3 py-5 text-slate-600">
                                    {{ c.noRows }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="border-b border-slate-200 py-6">
                <h2 class="text-lg font-semibold text-slate-950">
                    {{ c.actions }}
                </h2>

                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full border-collapse text-left text-sm">
                        <thead>
                            <tr class="border-b border-slate-300 text-slate-600">
                                <th class="px-3 py-3 font-semibold">{{ c.type }}</th>
                                <th class="px-3 py-3 font-semibold">{{ c.state }}</th>
                                <th class="px-3 py-3 font-semibold">{{ c.dueDate }}</th>
                                <th class="px-3 py-3 font-semibold">{{ c.controls }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in governance.actions"
                                :key="row.id"
                                class="border-b border-slate-200 align-top"
                            >
                                <td class="px-3 py-4">
                                    <p class="font-semibold text-slate-950">{{ row.title }}</p>
                                    <p v-if="row.description" class="mt-1 text-xs text-slate-600">
                                        {{ row.description }}
                                    </p>
                                </td>
                                <td class="px-3 py-4">
                                    <p class="font-semibold text-slate-800">{{ row.status }}</p>
                                    <p v-if="row.blockedReason" class="mt-1 text-xs text-slate-600">
                                        {{ row.blockedReason }}
                                    </p>
                                </td>
                                <td class="px-3 py-4 text-slate-700">
                                    {{ formatDate(row.dueAt) }}
                                </td>
                                <td class="min-w-72 px-3 py-4">
                                    <div v-if="row.canManage" class="flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            class="min-h-9 border border-slate-300 bg-white px-2 text-xs font-semibold"
                                            @click="
                                                post(
                                                    `/governance/actions/${row.id}/status`,
                                                    { status: 'in_progress' },
                                                )
                                            "
                                        >
                                            {{ c.inProgress }}
                                        </button>
                                        <button
                                            type="button"
                                            class="min-h-9 border border-slate-300 bg-white px-2 text-xs font-semibold"
                                            @click="
                                                post(
                                                    `/governance/actions/${row.id}/status`,
                                                    { status: 'completed' },
                                                )
                                            "
                                        >
                                            {{ c.completed }}
                                        </button>
                                    </div>
                                    <div v-if="row.canManage" class="mt-2 flex gap-2">
                                        <input
                                            v-model="blockedReasons[row.id]"
                                            class="min-h-9 min-w-0 flex-1 border border-slate-300 px-2 text-xs"
                                            :placeholder="c.blockedReason"
                                        />
                                        <button
                                            type="button"
                                            class="min-h-9 border border-slate-300 bg-white px-2 text-xs font-semibold"
                                            :disabled="!blockedReasons[row.id]"
                                            @click="
                                                post(
                                                    `/governance/actions/${row.id}/status`,
                                                    {
                                                        status: 'blocked',
                                                        blocked_reason:
                                                            blockedReasons[
                                                                row.id
                                                            ],
                                                    },
                                                )
                                            "
                                        >
                                            {{ c.blocked }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="governance.actions.length === 0">
                                <td colspan="4" class="px-3 py-5 text-slate-600">
                                    {{ c.noRows }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="border-b border-slate-200 py-6">
                <h2 class="text-lg font-semibold text-slate-950">
                    {{ c.reviews }}
                </h2>

                <div class="mt-4 grid gap-6 lg:grid-cols-2">
                    <div class="overflow-x-auto">
                        <table class="min-w-full border-collapse text-left text-sm">
                            <thead>
                                <tr class="border-b border-slate-300 text-slate-600">
                                    <th class="px-3 py-3 font-semibold">Review</th>
                                    <th class="px-3 py-3 font-semibold">{{ c.controls }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in governance.reviews"
                                    :key="row.id"
                                    class="border-b border-slate-200 align-top"
                                >
                                    <td class="px-3 py-4">
                                        <p class="font-semibold">{{ row.status }}</p>
                                        <details class="mt-2 text-xs text-slate-600">
                                            <summary class="cursor-pointer font-semibold text-slate-700">
                                                {{ c.advancedDetails }}
                                            </summary>
                                            <code class="mt-2 block break-all text-[10px] text-slate-500">
                                                {{ row.formalRecordVersionId }}
                                            </code>
                                        </details>
                                    </td>
                                    <td class="px-3 py-4">
                                        <div v-if="row.canComplete" class="flex flex-wrap gap-2">
                                            <button
                                                type="button"
                                                class="min-h-9 border border-slate-300 bg-white px-2 text-xs font-semibold"
                                                @click="
                                                    post(
                                                        `/governance/reviews/${row.id}/complete`,
                                                        { outcome: 'remains_valid' },
                                                    )
                                                "
                                            >
                                                {{ c.remainsValid }}
                                            </button>
                                            <button
                                                type="button"
                                                class="min-h-9 border border-slate-300 bg-white px-2 text-xs font-semibold"
                                                @click="
                                                    post(
                                                        `/governance/reviews/${row.id}/complete`,
                                                        {
                                                            outcome:
                                                                'amendment_required',
                                                        },
                                                    )
                                                "
                                            >
                                                {{ c.amendmentRequired }}
                                            </button>
                                            <button
                                                type="button"
                                                class="min-h-9 border border-slate-300 bg-white px-2 text-xs font-semibold"
                                                @click="
                                                    post(
                                                        `/governance/reviews/${row.id}/complete`,
                                                        {
                                                            outcome:
                                                                'no_longer_applicable',
                                                        },
                                                    )
                                                "
                                            >
                                                {{ c.noLongerApplicable }}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="governance.reviews.length === 0">
                                    <td colspan="2" class="px-3 py-5 text-slate-600">
                                        {{ c.noRows }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full border-collapse text-left text-sm">
                            <thead>
                                <tr class="border-b border-slate-300 text-slate-600">
                                    <th class="px-3 py-3 font-semibold">Amendment</th>
                                    <th class="px-3 py-3 font-semibold">{{ c.controls }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in governance.amendments"
                                    :key="row.id"
                                    class="border-b border-slate-200 align-top"
                                >
                                    <td class="px-3 py-4">
                                        <p class="font-semibold">{{ row.status }}</p>
                                        <p class="mt-1 text-xs text-slate-600">
                                            {{ row.reason }}
                                        </p>
                                    </td>
                                    <td class="px-3 py-4">
                                        <div v-if="row.canResolve" class="flex flex-wrap gap-2">
                                            <button
                                                type="button"
                                                class="min-h-9 border border-slate-900 bg-slate-900 px-2 text-xs font-semibold text-white"
                                                @click="
                                                    post(
                                                        `/governance/amendments/${row.id}/resolve`,
                                                        { outcome: 'accepted' },
                                                    )
                                                "
                                            >
                                                {{ c.accept }}
                                            </button>
                                            <button
                                                type="button"
                                                class="min-h-9 border border-slate-300 bg-white px-2 text-xs font-semibold"
                                                @click="
                                                    post(
                                                        `/governance/amendments/${row.id}/resolve`,
                                                        { outcome: 'rejected' },
                                                    )
                                                "
                                            >
                                                {{ c.reject }}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="governance.amendments.length === 0">
                                    <td colspan="2" class="px-3 py-5 text-slate-600">
                                        {{ c.noRows }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section class="py-6">
                <h2 class="text-lg font-semibold text-slate-950">
                    {{ c.notifications }}
                </h2>

                <div class="mt-4 divide-y divide-slate-200 border-y border-slate-200">
                    <div
                        v-for="row in governance.notifications"
                        :key="row.id"
                        class="flex flex-wrap items-center justify-between gap-3 py-3"
                    >
                        <div>
                            <p class="text-sm font-semibold text-slate-900">
                                {{ row.kind }}
                            </p>
                            <p class="mt-1 text-xs text-slate-500">
                                {{ row.subjectType }} · {{ formatDate(row.createdAt) }}
                            </p>
                        </div>

                        <button
                            v-if="row.status === 'unread'"
                            type="button"
                            class="min-h-9 border border-slate-300 bg-white px-3 text-xs font-semibold"
                            @click="
                                post(
                                    `/governance/notifications/${row.id}/read`,
                                )
                            "
                        >
                            {{ c.read }}
                        </button>
                    </div>

                    <p
                        v-if="governance.notifications.length === 0"
                        class="py-5 text-sm text-slate-600"
                    >
                        {{ c.noRows }}
                    </p>
                </div>
            </section>
            </div>
        </main>
    </AuthenticatedLayout>
</template>
