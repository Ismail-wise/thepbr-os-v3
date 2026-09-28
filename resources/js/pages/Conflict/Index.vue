<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import { useI18n } from '../../i18n/useI18n';

type Policy = {
    formal_record_version_id: string;
    version_number: number;
    effective_from: string | null;
    review_due_at: string | null;
    conflict_owner_membership_id: string;
    formal_decision_type: string;
    deadlock_decision_type: string;
    misconduct_decision_type: string;
    urgent_risk_decision_type: string;
    settlement_decision_type: string;
    review_frequency: string;
};

type CaseRow = {
    id: string;
    case_number: string;
    conflict_type: string;
    urgency: string;
    stage: string;
    status: string;
    conflict_owner_membership_id: string;
    raised_at: string | null;
    review_due_at: string | null;
    revision: number;
};

type Participant = {
    id: string;
    membership_id: string | null;
    external_reference: string | null;
    participant_role: string;
    status: string;
};

type UpdateRow = {
    id: string;
    sequence: number;
    from_stage: string;
    to_stage: string;
    from_status: string;
    to_status: string;
    note_code: string | null;
    occurred_at: string;
};

type GenericRow = Record<string, string | number | boolean | null>;

type CaseDetail = CaseRow & {
    description: string;
    business_impact: string;
    related_rule_reference: string | null;
    resolution_source_type: string | null;
    resolution_source_id: string | null;
    participants: Participant[];
    updates: UpdateRow[];
    direct_discussions: GenericRow[];
    mediations: GenericRow[];
    decision_submissions: GenericRow[];
    escalations: GenericRow[];
    deadlock: GenericRow | null;
    investigations: GenericRow[];
    urgent_risks: GenericRow[];
    settlements: GenericRow[];
    referrals: GenericRow[];
    action_links: GenericRow[];
    reviews: GenericRow[];
};

const props = defineProps<{
    conflict: {
        current_membership_id: string;
        capabilities: { can_view: boolean; can_manage: boolean };
        policy: Policy | null;
        counts: {
            visible_cases: number;
            needs_attention: number;
            open: number;
        };
        cases: CaseRow[];
        selected_case: CaseDetail | null;
    };
}>();

const { t } = useI18n();
const selected = computed(() => props.conflict.selected_case);
const today = new Date().toISOString().slice(0, 10);
type PostData = NonNullable<Parameters<typeof router.post>[1]>;
const post = (url: string, data: PostData = {}) =>
    router.post(url, data, { preserveScroll: true });

const caseForm = useForm({
    conflict_type: 'ordinary_disagreement',
    description: '',
    business_impact: '',
    urgency: 'normal',
    related_rule_reference: '',
    review_due_at: '',
    participants: [
        {
            membership_id: props.conflict.current_membership_id,
            external_reference: null as string | null,
            participant_role: 'party',
        },
    ],
    view_membership_ids: [] as string[],
    manage_membership_ids: [] as string[],
});

const directForm = useForm({
    expected_case_revision: selected.value?.revision ?? 1,
    issues_discussed: '',
    party_position_summary: '',
    proposed_solutions: '',
    outcome: 'continue_mediation',
    participant_membership_ids:
        selected.value?.participants
            .filter((row) => row.membership_id && row.status === 'active')
            .map((row) => row.membership_id as string) ?? [],
    meeting_at: '',
    follow_up_at: '',
});

const mediationForm = useForm({
    expected_case_revision: selected.value?.revision ?? 1,
    mediator_type: 'external',
    neutrality_check: '',
    mediation_at: '',
    mediator_membership_id: null as string | null,
    external_mediator_reference: '',
    response_deadline: '',
});

const decisionForm = useForm({
    expected_case_revision: selected.value?.revision ?? 1,
    reviewer_membership_id: props.conflict.current_membership_id,
    proposed_decision_summary: '',
    conditions: '',
    appeal_reference: '',
    review_due_at: '',
});

const actionForm = useForm({
    operations_role_id: '',
    assigned_membership_id: props.conflict.current_membership_id,
    title: '',
    source_type: 'conflict_case',
    source_id: selected.value?.id ?? '',
    description: '',
    due_at: '',
});

watch(
    () => ({
        id: selected.value?.id ?? '',
        revision: selected.value?.revision ?? 1,
    }),
    ({ id, revision }) => {
        directForm.expected_case_revision = revision;
        mediationForm.expected_case_revision = revision;
        decisionForm.expected_case_revision = revision;
        actionForm.source_id = id;
    },
);

const stageLabel = (value: string) => value.replaceAll('_', ' ');
const shortDate = (value: string | null) =>
    value ? new Date(value).toLocaleString() : '—';

const canEnterDirectDiscussion = computed(
    () => selected.value?.stage === 'intake',
);
</script>

<template>
    <Head :title="t('conflict.title')" />

    <AuthenticatedLayout>
        <main class="mx-auto w-full max-w-[1500px] px-4 py-6 sm:px-6 lg:px-8">
            <header class="border-b border-slate-200 pb-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                            {{ t('conflict.confidential') }}
                        </p>
                        <h1 class="mt-2 text-2xl font-bold text-slate-950">
                            {{ t('conflict.title') }}
                        </h1>
                        <p class="mt-2 max-w-4xl text-sm leading-6 text-slate-600">
                            {{ t('conflict.description') }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Link
                            href="/governance"
                            class="inline-flex min-h-11 items-center border border-slate-300 px-4 text-sm font-semibold"
                        >
                            Governance
                        </Link>
                        <Link
                            href="/operations"
                            class="inline-flex min-h-11 items-center border border-slate-300 px-4 text-sm font-semibold"
                        >
                            Operations
                        </Link>
                        <Link
                            href="/records/documents"
                            class="inline-flex min-h-11 items-center border border-slate-300 px-4 text-sm font-semibold"
                        >
                            Evidence / Vault
                        </Link>
                    </div>
                </div>
                <p class="mt-5 border-l-4 border-slate-800 bg-slate-100 px-4 py-3 text-sm font-semibold text-slate-800">
                    {{ t('conflict.boundary') }}
                </p>
            </header>

            <section class="mt-6 grid gap-4 md:grid-cols-3">
                <div class="border border-slate-200 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        {{ t('conflict.needsAttention') }}
                    </p>
                    <p class="mt-2 text-2xl font-bold" data-testid="conflict-attention-count">
                        {{ conflict.counts.needs_attention }}
                    </p>
                    <p class="mt-1 text-xs text-slate-500">Authorized cases only</p>
                </div>
                <div class="border border-slate-200 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        {{ t('conflict.visibleCases') }}
                    </p>
                    <p class="mt-2 text-2xl font-bold" data-testid="conflict-visible-count">
                        {{ conflict.counts.visible_cases }}
                    </p>
                </div>
                <div class="border border-slate-200 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        {{ t('conflict.openCases') }}
                    </p>
                    <p class="mt-2 text-2xl font-bold">
                        {{ conflict.counts.open }}
                    </p>
                </div>
            </section>

            <section class="mt-8">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-bold">{{ t('conflict.currentPolicy') }}</h2>
                        <p class="mt-1 text-sm text-slate-600">
                            Policy captures exact Governance and Operations versions. Amendments create a new version.
                        </p>
                    </div>
                    <span
                        v-if="conflict.policy"
                        class="border border-slate-300 px-2 py-1 text-xs font-semibold"
                    >
                        v{{ conflict.policy.version_number }} · Effective
                    </span>
                </div>
                <div
                    v-if="!conflict.policy"
                    class="mt-4 border border-dashed border-slate-300 p-5 text-sm text-slate-500"
                >
                    {{ t('conflict.noPolicy') }}
                </div>
                <div v-else class="mt-4 overflow-x-auto border border-slate-200">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b bg-slate-50">
                                <th class="px-3 py-3">Decision type</th>
                                <th class="px-3 py-3">Deadlock</th>
                                <th class="px-3 py-3">Misconduct</th>
                                <th class="px-3 py-3">Urgent risk</th>
                                <th class="px-3 py-3">Settlement</th>
                                <th class="px-3 py-3">Review</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="border-b border-slate-100">
                                <td class="px-3 py-3">{{ conflict.policy.formal_decision_type }}</td>
                                <td class="px-3 py-3">{{ conflict.policy.deadlock_decision_type }}</td>
                                <td class="px-3 py-3">{{ conflict.policy.misconduct_decision_type }}</td>
                                <td class="px-3 py-3">{{ conflict.policy.urgent_risk_decision_type }}</td>
                                <td class="px-3 py-3">{{ conflict.policy.settlement_decision_type }}</td>
                                <td class="px-3 py-3">{{ conflict.policy.review_frequency }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="mt-8">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-lg font-bold">{{ t('conflict.register') }}</h2>
                    <span class="text-xs font-semibold text-slate-500">
                        {{ t('conflict.confidential') }}
                    </span>
                </div>

                <div class="mt-3 overflow-x-auto border border-slate-200">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b bg-slate-50">
                                <th class="px-3 py-3">{{ t('conflict.caseNumber') }}</th>
                                <th class="px-3 py-3">{{ t('conflict.type') }}</th>
                                <th class="px-3 py-3">{{ t('conflict.urgency') }}</th>
                                <th class="px-3 py-3">{{ t('conflict.stage') }}</th>
                                <th class="px-3 py-3">{{ t('conflict.status') }}</th>
                                <th class="px-3 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in conflict.cases"
                                :key="row.id"
                                class="border-b border-slate-100"
                                data-testid="conflict-case-row"
                            >
                                <td class="px-3 py-3 font-semibold">{{ row.case_number }}</td>
                                <td class="px-3 py-3">{{ stageLabel(row.conflict_type) }}</td>
                                <td class="px-3 py-3">{{ row.urgency }}</td>
                                <td class="px-3 py-3">{{ stageLabel(row.stage) }}</td>
                                <td class="px-3 py-3">{{ row.status }}</td>
                                <td class="px-3 py-3 text-right">
                                    <Link
                                        :href="'/conflict?case=' + row.id"
                                        class="text-xs font-semibold underline"
                                    >
                                        {{ t('conflict.selectCase') }}
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="conflict.cases.length === 0">
                                <td colspan="6" class="px-3 py-6 text-slate-500">
                                    {{ t('conflict.noCases') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <details
                v-if="conflict.capabilities.can_manage && conflict.policy"
                class="mt-8 border border-slate-200"
            >
                <summary class="cursor-pointer px-5 py-4 font-semibold">
                    {{ t('conflict.createCase') }}
                </summary>
                <form
                    class="grid gap-4 border-t border-slate-200 p-5 md:grid-cols-2"
                    @submit.prevent="caseForm.post('/conflict/cases', { preserveScroll: true })"
                >
                    <label class="text-sm font-medium">
                        {{ t('conflict.type') }}
                        <select v-model="caseForm.conflict_type" class="mt-1 min-h-11 w-full border border-slate-300 px-3">
                            <option value="ordinary_disagreement">Ordinary disagreement</option>
                            <option value="governance_dispute">Governance dispute</option>
                            <option value="financial_dispute">Financial dispute</option>
                            <option value="role_performance">Role / performance</option>
                            <option value="conflict_of_interest">Conflict of interest</option>
                            <option value="misconduct">Misconduct</option>
                            <option value="agreement_breach">Agreement breach</option>
                            <option value="urgent_risk">Urgent risk</option>
                            <option value="deadlock_50_50">50/50 deadlock</option>
                            <option value="relationship_breakdown">Relationship breakdown</option>
                            <option value="other">Other</option>
                        </select>
                    </label>
                    <label class="text-sm font-medium">
                        {{ t('conflict.urgency') }}
                        <select v-model="caseForm.urgency" class="mt-1 min-h-11 w-full border border-slate-300 px-3">
                            <option value="low">Low</option>
                            <option value="normal">Normal</option>
                            <option value="high">High</option>
                            <option value="critical">Critical</option>
                        </select>
                    </label>
                    <label class="text-sm font-medium md:col-span-2">
                        Description
                        <textarea v-model="caseForm.description" required class="mt-1 min-h-24 w-full border border-slate-300 p-3" />
                    </label>
                    <label class="text-sm font-medium md:col-span-2">
                        Business impact
                        <textarea v-model="caseForm.business_impact" required class="mt-1 min-h-24 w-full border border-slate-300 p-3" />
                    </label>
                    <label class="text-sm font-medium">
                        Related rule / agreement reference
                        <input v-model="caseForm.related_rule_reference" class="mt-1 min-h-11 w-full border border-slate-300 px-3" />
                    </label>
                    <label class="text-sm font-medium">
                        {{ t('conflict.reviewDue') }}
                        <input v-model="caseForm.review_due_at" type="date" class="mt-1 min-h-11 w-full border border-slate-300 px-3" />
                    </label>
                    <div class="md:col-span-2">
                        <p class="text-xs text-slate-600">
                            You are recorded as a case party by default. Case participation does not grant access to other parties.
                        </p>
                        <button
                            type="submit"
                            :disabled="caseForm.processing"
                            class="mt-3 min-h-11 bg-slate-950 px-5 text-sm font-semibold text-white disabled:opacity-50"
                            data-testid="conflict-open-case"
                        >
                            {{ t('conflict.createCase') }}
                        </button>
                    </div>
                </form>
            </details>

            <section v-if="selected" class="mt-10 border-t border-slate-300 pt-8" data-testid="conflict-case-workspace">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            {{ t('conflict.confidential') }} · {{ selected.case_number }}
                        </p>
                        <h2 class="mt-2 text-xl font-bold">
                            {{ stageLabel(selected.conflict_type) }}
                        </h2>
                        <p class="mt-2 max-w-4xl text-sm text-slate-700">
                            {{ selected.description }}
                        </p>
                        <p class="mt-2 max-w-4xl text-sm text-slate-500">
                            {{ selected.business_impact }}
                        </p>
                    </div>
                    <div class="flex gap-2 text-xs">
                        <span class="border border-slate-300 px-2 py-1">{{ stageLabel(selected.stage) }}</span>
                        <span class="border border-slate-300 px-2 py-1">{{ selected.status }}</span>
                        <span class="border border-slate-300 px-2 py-1">rev {{ selected.revision }}</span>
                    </div>
                </div>

                <div class="mt-6 grid gap-6 xl:grid-cols-2">
                    <div class="border border-slate-200">
                        <h3 class="border-b border-slate-200 px-4 py-3 font-semibold">{{ t('conflict.participants') }}</h3>
                        <div class="divide-y divide-slate-100">
                            <div v-for="row in selected.participants" :key="row.id" class="px-4 py-3 text-sm">
                                <span class="font-semibold">{{ row.participant_role }}</span>
                                <span class="ml-2 text-slate-500">{{ row.membership_id ?? row.external_reference }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="border border-slate-200">
                        <h3 class="border-b border-slate-200 px-4 py-3 font-semibold">{{ t('conflict.caseHistory') }}</h3>
                        <div class="max-h-72 divide-y divide-slate-100 overflow-y-auto">
                            <div v-for="row in selected.updates" :key="row.id" class="px-4 py-3 text-sm">
                                <p class="font-semibold">{{ stageLabel(row.from_stage) }} → {{ stageLabel(row.to_stage) }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ row.note_code ?? 'workflow transition' }} · {{ shortDate(row.occurred_at) }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="conflict.capabilities.can_manage" class="mt-6 grid gap-6 xl:grid-cols-2">
                    <form
                        v-if="canEnterDirectDiscussion"
                        class="border border-slate-200 p-4"
                        @submit.prevent="post('/conflict/cases/' + selected.id + '/transition', { expected_revision: selected.revision, target: 'direct_discussion', note_code: 'direct_discussion_started' })"
                    >
                        <h3 class="font-semibold">{{ t('conflict.directDiscussion') }}</h3>
                        <p class="mt-2 text-sm text-slate-600">Move the case into Direct Discussion before recording the discussion.</p>
                        <button type="submit" class="mt-3 min-h-10 border border-slate-300 px-4 text-sm font-semibold">Start Direct Discussion</button>
                    </form>

                    <form
                        v-if="selected.stage === 'direct_discussion'"
                        class="space-y-3 border border-slate-200 p-4"
                        @submit.prevent="directForm.post('/conflict/cases/' + selected.id + '/direct-discussions', { preserveScroll: true })"
                    >
                        <h3 class="font-semibold">{{ t('conflict.directDiscussion') }}</h3>
                        <textarea v-model="directForm.issues_discussed" required class="min-h-20 w-full border border-slate-300 p-2" placeholder="Issues discussed" />
                        <textarea v-model="directForm.party_position_summary" required class="min-h-20 w-full border border-slate-300 p-2" placeholder="Party positions" />
                        <textarea v-model="directForm.proposed_solutions" required class="min-h-20 w-full border border-slate-300 p-2" placeholder="Proposed solutions" />
                        <select v-model="directForm.outcome" class="min-h-10 w-full border border-slate-300 px-2">
                            <option value="resolved">Resolved</option>
                            <option value="continue_mediation">Continue to mediation</option>
                            <option value="continue_formal_decision">Continue to formal decision</option>
                        </select>
                        <button type="submit" class="min-h-10 bg-slate-950 px-4 text-sm font-semibold text-white">Record Discussion</button>
                    </form>

                    <form
                        v-if="selected.stage === 'mediation'"
                        class="space-y-3 border border-slate-200 p-4"
                        @submit.prevent="mediationForm.post('/conflict/cases/' + selected.id + '/mediations', { preserveScroll: true })"
                    >
                        <h3 class="font-semibold">{{ t('conflict.mediation') }}</h3>
                        <input v-model="mediationForm.external_mediator_reference" required class="min-h-10 w-full border border-slate-300 px-2" placeholder="External neutral mediator reference" />
                        <textarea v-model="mediationForm.neutrality_check" required class="min-h-20 w-full border border-slate-300 p-2" placeholder="Neutrality / conflict check" />
                        <input v-model="mediationForm.mediation_at" type="datetime-local" required class="min-h-10 w-full border border-slate-300 px-2" />
                        <button type="submit" class="min-h-10 bg-slate-950 px-4 text-sm font-semibold text-white">Schedule Mediation</button>
                    </form>

                    <form
                        v-if="['formal_decision','deadlock','misconduct_investigation','urgent_risk'].includes(selected.stage)"
                        class="space-y-3 border border-slate-200 p-4"
                        @submit.prevent="decisionForm.post('/conflict/cases/' + selected.id + '/decisions', { preserveScroll: true })"
                    >
                        <h3 class="font-semibold">{{ t('conflict.decisions') }}</h3>
                        <textarea v-model="decisionForm.proposed_decision_summary" required class="min-h-24 w-full border border-slate-300 p-2" placeholder="Frozen decision package summary" />
                        <textarea v-model="decisionForm.conditions" class="min-h-16 w-full border border-slate-300 p-2" placeholder="Conditions" />
                        <button type="submit" class="min-h-10 bg-slate-950 px-4 text-sm font-semibold text-white">Create Frozen Decision Package</button>
                        <p class="text-xs text-slate-500">Review, Approval/Vote and Signature remain in Governance.</p>
                    </form>

                    <form
                        class="space-y-3 border border-slate-200 p-4"
                        @submit.prevent="actionForm.post('/conflict/cases/' + selected.id + '/actions', { preserveScroll: true })"
                    >
                        <h3 class="font-semibold">{{ t('conflict.followUp') }}</h3>
                        <input v-model="actionForm.operations_role_id" required class="min-h-10 w-full border border-slate-300 px-2" placeholder="Current Operations Role ID" />
                        <input v-model="actionForm.assigned_membership_id" required class="min-h-10 w-full border border-slate-300 px-2" placeholder="Assigned Membership ID" />
                        <input v-model="actionForm.title" required class="min-h-10 w-full border border-slate-300 px-2" placeholder="Action title" />
                        <textarea v-model="actionForm.description" class="min-h-16 w-full border border-slate-300 p-2" placeholder="Description" />
                        <button type="submit" class="min-h-10 border border-slate-300 px-4 text-sm font-semibold">Create Operations Action</button>
                    </form>
                </div>

                <section class="mt-8 grid gap-6 lg:grid-cols-4">
                    <div class="border border-slate-200 p-4">
                        <h3 class="font-semibold">{{ t('conflict.mediation') }}</h3>
                        <p class="mt-2 text-2xl font-bold" data-testid="conflict-mediation-count">{{ selected.mediations.length }}</p>
                    </div>
                    <div class="border border-slate-200 p-4">
                        <h3 class="font-semibold">{{ t('conflict.decisions') }}</h3>
                        <p class="mt-2 text-2xl font-bold" data-testid="conflict-decision-count">{{ selected.decision_submissions.length }}</p>
                    </div>
                    <div class="border border-slate-200 p-4">
                        <h3 class="font-semibold">{{ t('conflict.settlements') }}</h3>
                        <p class="mt-2 text-2xl font-bold" data-testid="conflict-settlement-count">{{ selected.settlements.length }}</p>
                    </div>
                    <div class="border border-slate-200 p-4">
                        <h3 class="font-semibold">{{ t('conflict.followUp') }}</h3>
                        <p class="mt-2 text-2xl font-bold" data-testid="conflict-action-count">{{ selected.action_links.length }}</p>
                    </div>
                </section>
            </section>
        </main>
    </AuthenticatedLayout>
</template>
