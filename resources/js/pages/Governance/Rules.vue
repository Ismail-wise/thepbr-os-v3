<script setup lang="ts">
import OptionalTemporalInput from '../../components/OptionalTemporalInput.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import { useI18n } from '../../i18n/useI18n';

type Membership = { id: string; email: string };
type RuleActor = {
    id: string;
    governance_charter_rule_id: string;
    membership_id: string;
    capacity: string;
    is_decision_owner: boolean;
    is_consulted: boolean;
    can_approve: boolean;
    can_vote: boolean;
    can_sign: boolean;
};
type Rule = {
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
    amount_min: string | null;
    amount_max: string | null;
};

type CharterHeader = {
    governance_owner_membership_id: string;
    voting_basis: string;
    default_approval_rule: string;
    meeting_frequency: string | null;
    default_quorum_count: number;
    minutes_owner_membership_id: string;
    conflict_of_interest_rule: string;
    deadlock_rule: string;
    remote_voting_allowed: boolean;
    written_resolution_allowed: boolean;
};
type VersionRow = {
    id: string;
    version_number: number;
    revision: number;
    frozen_at: string | null;
    effective_from: string | null;
};

type FormationAuthorityVersion = VersionRow & {
    content_hash: string;
    state: string | null;
};
type AuthorityChange = {
    id: string;
    subject_type: string;
    subject_id: string;
    action: string;
    proposal_version_id: string;
    authorizing_decision_id: string | null;
    authorized_at: string | null;
    decision_status: string | null;
    decision_outcome: string | null;
};

const props = defineProps<{
    governanceRules: {
        business: { id: string; name: string };
        permissions: {
            manage: boolean;
            bootstrap_formation_authority: boolean;
        };
        formation_authority: {
            established: boolean;
            versions: FormationAuthorityVersion[];
        };
        current_source: null | {
            kind: string;
            formal_record_version_id: string;
            version_number: number;
            initial_bootstrap: boolean;
        };
        current_charter: null | {
            formal_record_version_id: string;
            version_number: number;
            effective_from: string | null;
            header: CharterHeader;
            rules: Rule[];
            actors: RuleActor[];
        };
        versions: VersionRow[];
        authority_changes: AuthorityChange[];
        memberships: Membership[];
    };
}>();

const { t } = useI18n();
const firstMembership = props.governanceRules.memberships[0]?.id ?? '';

const actor = (owner = false) => ({
    membership_id: firstMembership,
    capacity: owner ? 'Decision Owner' : 'Approver',
    is_decision_owner: owner,
    is_consulted: false,
    can_approve: true,
    can_vote: false,
    can_sign: false,
});

const formationAuthorityActor = () => ({
    membership_id: firstMembership,
    capacity: 'Formation Decision Participant',
    can_approve: true,
    can_vote: false,
    can_sign: false,
});

const formationAuthorityRule = () => ({
    decision_type: 'general_management',
    decision_method: 'approval',
    required_approvals: 1,
    required_votes: 0,
    quorum_count: 1,
    signature_required: false,
    reserved_matter: false,
    amount_min: '',
    amount_max: '',
    actors: [formationAuthorityActor()],
});

const formationAuthority = useForm({
    effective_from: new Date().toISOString().slice(0, 10),
    rules: [formationAuthorityRule()],
});

const formationFreeze = useForm({
    expected_revision: 1,
});

const formationEstablish = useForm({});

const freezeFormationAuthority = (
    version: FormationAuthorityVersion,
) => {
    formationFreeze.expected_revision = version.revision;
    formationFreeze.post(
        `/governance/rules/formation-authority/${version.id}/freeze`,
        { preserveScroll: true },
    );
};

const establishFormationAuthority = (
    version: FormationAuthorityVersion,
) => {
    formationEstablish.post(
        `/governance/rules/formation-authority/${version.id}/establish`,
        { preserveScroll: true },
    );
};

const rule = () => ({
    decision_type: 'general_management',
    category: 'management',
    decision_method: 'approval',
    required_approvals: 1,
    required_votes: 0,
    quorum_count: 1,
    signature_required: false,
    reserved_matter: false,
    meeting_required: false,
    record_required: true,
    amount_min: '',
    amount_max: '',
    actors: [actor(true)],
});

const currentCharter = props.governanceRules.current_charter;
const currentHeader = currentCharter?.header ?? null;

const actorFromCurrentCharter = (row: RuleActor) => ({
    membership_id: row.membership_id,
    capacity: row.capacity,
    is_decision_owner: row.is_decision_owner,
    is_consulted: row.is_consulted,
    can_approve: row.can_approve,
    can_vote: row.can_vote,
    can_sign: row.can_sign,
});

const ruleFromCurrentCharter = (row: Rule) => ({
    decision_type: row.decision_type,
    category: row.category,
    decision_method: row.decision_method,
    required_approvals: row.required_approvals,
    required_votes: row.required_votes,
    quorum_count: row.quorum_count,
    signature_required: row.signature_required,
    reserved_matter: row.reserved_matter,
    meeting_required: row.meeting_required,
    record_required: row.record_required,
    amount_min: row.amount_min ?? '',
    amount_max: row.amount_max ?? '',
    actors:
        currentCharter?.actors
            .filter(
                (candidate) =>
                    candidate.governance_charter_rule_id === row.id,
            )
            .map(actorFromCurrentCharter) ?? [],
});

const currentCharterRules =
    currentCharter?.rules.map(ruleFromCurrentCharter) ?? [];

const localDateTimeInputValue = (date: Date): string => {
    const local = new Date(
        date.getTime() - date.getTimezoneOffset() * 60_000,
    );

    return local.toISOString().slice(0, 16);
};

const localDateTimeToUtcIso = (value: string): string => {
    const parsed = new Date(value);

    return Number.isNaN(parsed.getTime())
        ? value
        : parsed.toISOString();
};

const charter = useForm({
    effective_from: localDateTimeInputValue(new Date()),
    review_due_at: '',
    governance_owner_membership_id:
        currentHeader?.governance_owner_membership_id ?? firstMembership,
    voting_basis:
        currentHeader?.voting_basis ??
        'One eligible Governance participant, one vote',
    default_approval_rule:
        currentHeader?.default_approval_rule ??
        'Use the exact Decision/Authority Matrix rule',
    meeting_frequency:
        currentHeader === null
            ? 'Monthly'
            : (currentHeader.meeting_frequency ?? ''),
    default_quorum_count:
        currentHeader?.default_quorum_count ?? 1,
    minutes_owner_membership_id:
        currentHeader?.minutes_owner_membership_id ?? firstMembership,
    conflict_of_interest_rule:
        currentHeader?.conflict_of_interest_rule ??
        'Conflicts must be disclosed and the conflicted participant must recuse from the affected decision.',
    deadlock_rule:
        currentHeader?.deadlock_rule ??
        'Escalate unresolved deadlock under the approved deadlock process before structural remedies.',
    remote_voting_allowed:
        currentHeader?.remote_voting_allowed ?? true,
    written_resolution_allowed:
        currentHeader?.written_resolution_allowed ?? true,
    rules:
        currentCharter === null
            ? [rule()]
            : currentCharterRules,
});

const delegation = useForm({
    delegator_membership_id: firstMembership,
    delegate_membership_id: firstMembership,
    decision_type: '',
    scope: '',
    effective_from: '',
    expires_at: '',
});

const emergency = useForm({
    grantee_membership_id: firstMembership,
    decision_type: '',
    scope: '',
    capacity: 'Emergency Decision Participant',
    can_approve: true,
    can_vote: false,
    can_sign: false,
    reason: '',
    effective_from: '',
    expires_at: '',
});

type PostData = NonNullable<Parameters<typeof router.post>[1]>;

const post = (url: string, data: PostData = {}) =>
    router.post(url, data, { preserveScroll: true });

const formError = (errors: object, key: string) =>
    (errors as Record<string, string | undefined>)[key];

const firstFormError = (errors: object) =>
    Object.values(errors as Record<string, string | undefined>)
        .find((value) => value !== undefined);

const submitCharter = (): void => {
    charter
        .transform((data) => ({
            ...data,
            effective_from: data.effective_from
                ? localDateTimeToUtcIso(data.effective_from)
                : data.effective_from,
        }))
        .post('/governance/rules/charter', {
            preserveScroll: true,
        });
};

const actorsFor = (ruleId: string) =>
    props.governanceRules.current_charter?.actors.filter(
        (row) => row.governance_charter_rule_id === ruleId,
    ) ?? [];

const formatDate = (value: string | null | undefined) =>
    value ? new Date(value).toLocaleDateString() : '—';
</script>

<template>
    <Head :title="t('governance.rulesTitle')" />
    <AuthenticatedLayout>
        <main class="min-h-screen bg-[radial-gradient(circle_at_88%_0%,rgb(210_167_67_/_8%),transparent_26rem),linear-gradient(180deg,#f7f9f6_0%,#f1f5f1_100%)] px-4 py-5 text-[var(--pbr-ink)] sm:px-6 sm:py-6 lg:px-8 lg:py-7">
            <div class="mx-auto w-full max-w-[1500px]">
            <header class="rounded-[24px] border border-[#d8e4da] bg-white/90 p-5 shadow-[0_14px_34px_rgb(16_35_26_/_5%)] sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                            {{ governanceRules.business.name }}
                        </p>
                        <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-950">
                            {{ t('governance.rulesTitle') }}
                        </h1>
                        <p class="mt-2 max-w-4xl text-sm leading-6 text-slate-600">
                            {{ t('governance.rulesDescription') }}
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <Link href="/governance" class="inline-flex min-h-11 items-center rounded-xl border border-[#d8e4da] bg-white px-4 text-sm font-bold text-slate-800 hover:bg-[#f6f8f6]">
                            {{ t('governance.decisionCenterNav') }}
                        </Link>
                        <Link href="/governance/meetings" class="inline-flex min-h-11 items-center rounded-xl bg-[var(--pbr-green-dark)] px-4 text-sm font-bold text-white">
                            {{ t('governance.meetingsTitle') }}
                        </Link>
                    </div>
                </div>
            </header>

            <section class="mt-5 rounded-[20px] border border-[#cfe1d3] bg-[#f3f8f4] p-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]">
                            {{ t('governance.currentAuthority') }}
                        </p>
                        <p class="mt-2 text-lg font-black text-[var(--pbr-ink)]">
                            {{
                                governanceRules.current_source
                                    ? governanceRules.current_source.kind
                                    : 'No Current Authority'
                            }}
                        </p>
                        <p class="mt-2 max-w-4xl text-sm leading-6 text-[var(--pbr-muted)]">
                            {{ t('governance.authorityHelp') }}
                        </p>
                    </div>

                    <details
                        v-if="governanceRules.current_source"
                        class="rounded-xl border border-[#d7e4da] bg-white px-3 py-2 text-xs text-slate-600"
                    >
                        <summary class="cursor-pointer font-bold text-slate-700">
                            {{ t('governance.advancedDetails') }}
                        </summary>
                        <p class="mt-2">
                            Version {{ governanceRules.current_source.version_number }}
                        </p>
                    </details>
                </div>
            </section>

            <section
                v-if="governanceRules.permissions.bootstrap_formation_authority"
                class="mt-6 border border-slate-200 bg-white"
            >
                <div class="border-b border-slate-200 p-5">
                    <h2 class="text-lg font-bold text-slate-950">
                        Temporary Formation Authority
                    </h2>
                    <p class="mt-1 max-w-4xl text-sm leading-6 text-slate-600">
                        Use an explicit frozen Formation Authority Policy before an Effective Governance Charter exists. System access alone never grants approval, voting or signing authority.
                    </p>
                </div>

                <div
                    v-if="governanceRules.current_source?.kind === 'governance_charter'"
                    class="p-5 text-sm text-slate-700"
                >
                    Current Effective Governance is already in force. The temporary bootstrap path is retired.
                </div>

                <div
                    v-else-if="governanceRules.formation_authority.established"
                    class="p-5 text-sm text-slate-700"
                >
                    Temporary Formation Authority is established from its exact frozen policy version. Replace it only through the normal Effective Governance workflow.
                </div>

                <div v-else class="p-5">
                    <details
                        v-if="governanceRules.formation_authority.versions.length === 0"
                        class="border border-slate-200"
                    >
                        <summary class="cursor-pointer px-4 py-3 font-semibold">
                            Prepare Formation Authority Policy
                        </summary>

                        <form
                            class="space-y-5 border-t border-slate-200 p-4"
                            @submit.prevent="
                                formationAuthority.post(
                                    '/governance/rules/formation-authority',
                                    { preserveScroll: true },
                                )
                            "
                        >
                            <label class="block text-sm font-medium">
                                Effective from
                                <input
                                    v-model="formationAuthority.effective_from"
                                    type="date"
                                    required
                                    class="mt-1 min-h-11 w-full max-w-sm border border-slate-300 px-3"
                                />
                            </label>

                            <div class="space-y-4">
                                <div class="flex items-center justify-between gap-3">
                                    <h3 class="font-semibold">
                                        Temporary Decision / Authority Rules
                                    </h3>
                                    <button
                                        type="button"
                                        class="min-h-10 border border-slate-300 px-3 text-sm font-semibold"
                                        @click="
                                            formationAuthority.rules.push(
                                                formationAuthorityRule(),
                                            )
                                        "
                                    >
                                        Add rule
                                    </button>
                                </div>

                                <article
                                    v-for="(r, ri) in formationAuthority.rules"
                                    :key="ri"
                                    class="border border-slate-200 bg-slate-50 p-4"
                                >
                                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                        <label class="text-sm font-medium">
                                            Decision type
                                            <input
                                                v-model="r.decision_type"
                                                required
                                                class="mt-1 min-h-10 w-full border border-slate-300 px-2"
                                            />
                                        </label>

                                        <label class="text-sm font-medium">
                                            Decision method
                                            <select
                                                v-model="r.decision_method"
                                                class="mt-1 min-h-10 w-full border border-slate-300 px-2"
                                            >
                                                <option value="approval">Approval</option>
                                                <option value="vote">Vote</option>
                                                <option value="approval_and_vote">Approval + Vote</option>
                                            </select>
                                        </label>

                                        <label class="text-sm font-medium">
                                            Required approvals
                                            <input
                                                v-model.number="r.required_approvals"
                                                type="number"
                                                min="0"
                                                class="mt-1 min-h-10 w-full border border-slate-300 px-2"
                                            />
                                        </label>

                                        <label class="text-sm font-medium">
                                            Required votes
                                            <input
                                                v-model.number="r.required_votes"
                                                type="number"
                                                min="0"
                                                class="mt-1 min-h-10 w-full border border-slate-300 px-2"
                                            />
                                        </label>

                                        <label class="text-sm font-medium">
                                            Quorum
                                            <input
                                                v-model.number="r.quorum_count"
                                                type="number"
                                                min="1"
                                                class="mt-1 min-h-10 w-full border border-slate-300 px-2"
                                            />
                                        </label>

                                        <label class="text-sm font-medium">
                                            Amount minimum
                                            <input
                                                v-model="r.amount_min"
                                                inputmode="decimal"
                                                class="mt-1 min-h-10 w-full border border-slate-300 px-2"
                                            />
                                        </label>

                                        <label class="text-sm font-medium">
                                            Amount maximum
                                            <input
                                                v-model="r.amount_max"
                                                inputmode="decimal"
                                                class="mt-1 min-h-10 w-full border border-slate-300 px-2"
                                            />
                                        </label>
                                    </div>

                                    <div class="mt-3 flex flex-wrap gap-5 text-sm">
                                        <label>
                                            <input v-model="r.reserved_matter" type="checkbox" />
                                            Reserved matter
                                        </label>
                                        <label>
                                            <input v-model="r.signature_required" type="checkbox" />
                                            Signature required
                                        </label>
                                    </div>

                                    <div class="mt-4 space-y-3">
                                        <div class="flex items-center justify-between gap-3">
                                            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                                                Explicit eligible actors
                                            </p>
                                            <button
                                                type="button"
                                                class="text-xs font-semibold underline"
                                                @click="r.actors.push(formationAuthorityActor())"
                                            >
                                                Add actor
                                            </button>
                                        </div>

                                        <div
                                            v-for="(a, ai) in r.actors"
                                            :key="ai"
                                            class="grid gap-3 border-l-2 border-slate-300 pl-3 md:grid-cols-2 xl:grid-cols-4"
                                        >
                                            <label class="text-sm font-medium">
                                                Membership
                                                <select
                                                    v-model="a.membership_id"
                                                    class="mt-1 min-h-10 w-full border border-slate-300 px-2"
                                                >
                                                    <option
                                                        v-for="m in governanceRules.memberships"
                                                        :key="m.id"
                                                        :value="m.id"
                                                    >
                                                        {{ m.email }}
                                                    </option>
                                                </select>
                                            </label>

                                            <label class="text-sm font-medium">
                                                Capacity
                                                <input
                                                    v-model="a.capacity"
                                                    required
                                                    class="mt-1 min-h-10 w-full border border-slate-300 px-2"
                                                />
                                            </label>

                                            <div class="flex flex-wrap items-end gap-4 pb-2 text-sm">
                                                <label>
                                                    <input v-model="a.can_approve" type="checkbox" />
                                                    Approve
                                                </label>
                                                <label>
                                                    <input v-model="a.can_vote" type="checkbox" />
                                                    Vote
                                                </label>
                                                <label>
                                                    <input v-model="a.can_sign" type="checkbox" />
                                                    Sign
                                                </label>
                                            </div>

                                            <button
                                                type="button"
                                                class="self-end text-left text-xs font-semibold text-red-700"
                                                @click="
                                                    r.actors.length > 1
                                                    && r.actors.splice(ai, 1)
                                                "
                                            >
                                                Remove actor
                                            </button>
                                        </div>
                                    </div>

                                    <button
                                        type="button"
                                        class="mt-4 text-xs font-semibold text-red-700"
                                        @click="
                                            formationAuthority.rules.length > 1
                                            && formationAuthority.rules.splice(ri, 1)
                                        "
                                    >
                                        Remove rule
                                    </button>
                                </article>
                            </div>

                            <p
                                v-if="firstFormError(formationAuthority.errors)"
                                class="text-sm text-red-700"
                            >
                                {{ firstFormError(formationAuthority.errors) }}
                            </p>

                            <button
                                type="submit"
                                :disabled="formationAuthority.processing"
                                class="min-h-11 bg-slate-950 px-4 text-sm font-semibold text-white disabled:opacity-50"
                            >
                                Create Formation Authority Policy Draft
                            </button>
                        </form>
                    </details>

                    <div
                        v-if="governanceRules.formation_authority.versions.length > 0"
                        class="overflow-x-auto"
                    >
                        <table class="min-w-full border-collapse text-left text-sm">
                            <thead>
                                <tr class="border-b border-slate-300 text-slate-600">
                                    <th class="px-3 py-3">Version</th>
                                    <th class="px-3 py-3">Effective from</th>
                                    <th class="px-3 py-3">State</th>
                                    <th class="px-3 py-3">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="v in governanceRules.formation_authority.versions"
                                    :key="v.id"
                                    class="border-b border-slate-200"
                                >
                                    <td class="px-3 py-3 font-semibold">
                                        v{{ v.version_number }}
                                    </td>
                                    <td class="px-3 py-3">
                                        {{ formatDate(v.effective_from) }}
                                    </td>
                                    <td class="px-3 py-3">
                                        {{ v.state ?? '—' }}
                                    </td>
                                    <td class="px-3 py-3">
                                        <button
                                            v-if="v.state === 'draft'"
                                            type="button"
                                            :disabled="formationFreeze.processing"
                                            class="min-h-9 border border-slate-300 px-3 text-xs font-semibold disabled:opacity-50"
                                            @click="freezeFormationAuthority(v)"
                                        >
                                            Freeze for Formation Authority
                                        </button>
                                        <button
                                            v-else-if="v.state === 'ready_for_review'"
                                            type="button"
                                            :disabled="formationEstablish.processing"
                                            class="min-h-9 bg-slate-950 px-3 text-xs font-semibold text-white disabled:opacity-50"
                                            @click="establishFormationAuthority(v)"
                                        >
                                            Establish Temporary Formation Authority
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p
                        v-if="firstFormError(formationFreeze.errors)"
                        class="mt-3 text-sm text-red-700"
                    >
                        {{ firstFormError(formationFreeze.errors) }}
                    </p>
                    <p
                        v-if="firstFormError(formationEstablish.errors)"
                        class="mt-3 text-sm text-red-700"
                    >
                        {{ firstFormError(formationEstablish.errors) }}
                    </p>
                </div>
            </section>

            <section class="mt-6 rounded-[22px] border border-[#d8e4da] bg-white/90 p-5 shadow-[0_10px_28px_rgb(16_35_26_/_4%)] sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]">
                            {{ t('governance.currentAuthority') }}
                        </p>
                        <h2 class="mt-1 text-xl font-black tracking-[-0.02em] text-[var(--pbr-ink)]">Current Effective Governance Charter</h2>
                        <p class="mt-1 max-w-4xl text-sm leading-6 text-[var(--pbr-muted)]">
                            {{ t('governance.authorityMatrixHelp') }}
                        </p>
                    </div>
                    <details
                        v-if="governanceRules.current_charter"
                        class="rounded-xl border border-[#d8e4da] bg-[#f8faf8] px-3 py-2 text-xs text-slate-600"
                    >
                        <summary class="cursor-pointer font-bold text-slate-700">
                            {{ t('governance.advancedDetails') }}
                        </summary>
                        <p class="mt-2">
                            Version {{ governanceRules.current_charter.version_number }}
                        </p>
                    </details>
                </div>

                <div v-if="!governanceRules.current_charter" class="mt-4 border border-dashed border-slate-300 p-5 text-sm text-slate-600">
                    No Effective Governance Charter yet. Temporary Formation Authority remains the controlled fallback.
                </div>

                <div v-else class="mt-4 overflow-x-auto">
                    <table class="min-w-full border-collapse text-left text-sm">
                        <thead>
                            <tr class="border-b border-slate-300 text-slate-600">
                                <th class="px-3 py-3">Decision</th>
                                <th class="px-3 py-3">Category</th>
                                <th class="px-3 py-3">Threshold</th>
                                <th class="px-3 py-3">Controls</th>
                                <th class="px-3 py-3">Actors</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in governanceRules.current_charter.rules" :key="row.id" class="border-b border-slate-200 align-top">
                                <td class="px-3 py-4 font-semibold">{{ row.decision_type }}</td>
                                <td class="px-3 py-4">{{ row.category }}</td>
                                <td class="px-3 py-4">
                                    {{ row.decision_method }} · A{{ row.required_approvals }} / V{{ row.required_votes }} / Q{{ row.quorum_count }}
                                </td>
                                <td class="px-3 py-4 text-xs">
                                    <span v-if="row.reserved_matter">Reserved </span>
                                    <span v-if="row.signature_required">Signature </span>
                                    <span v-if="row.meeting_required">Meeting </span>
                                    <span v-if="row.record_required">Record</span>
                                </td>
                                <td class="px-3 py-4 text-xs">
                                    <div v-for="a in actorsFor(row.id)" :key="a.id" class="mb-2">
                                        <p class="font-semibold">{{ a.capacity }}</p>
                                        <p class="text-slate-600">
                                            {{ a.is_decision_owner ? 'Owner ' : '' }}
                                            {{ a.is_consulted ? 'Consulted ' : '' }}
                                            {{ a.can_approve ? 'Approve ' : '' }}
                                            {{ a.can_vote ? 'Vote ' : '' }}
                                            {{ a.can_sign ? 'Sign' : '' }}
                                        </p>
                                        <details class="mt-1 text-[11px] text-slate-500">
                                            <summary class="cursor-pointer font-semibold">
                                                {{ t('governance.advancedDetails') }}
                                            </summary>
                                            <p class="mt-1 break-all font-mono">{{ a.membership_id }}</p>
                                        </details>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <details v-if="governanceRules.permissions.manage" class="mt-8 border border-slate-200 bg-white">
                <summary class="cursor-pointer px-5 py-4 font-semibold text-slate-950">
                    Create Governance Charter Draft / Amendment
                </summary>
                <form class="space-y-6 border-t border-slate-200 p-5" @submit.prevent="submitCharter">
                    <p
                        v-if="currentCharter"
                        class="border border-slate-200 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-700"
                    >
                        This amendment starts from Current Effective Governance Charter.
                        Existing authority rules and actors are copied into this draft form
                        so only intended changes need to be made.
                    </p>

                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <label class="text-sm font-medium">Effective from
                            <OptionalTemporalInput
                                v-model="charter.effective_from"
                                type="datetime-local"
                                required
                                class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                            />
                        </label>
                        <label class="text-sm font-medium">Review due
                            <OptionalTemporalInput v-model="charter.review_due_at" type="date" class="mt-1 min-h-11 w-full border border-slate-300 px-3" />
                        </label>
                        <label class="text-sm font-medium">Governance owner
                            <select v-model="charter.governance_owner_membership_id" class="mt-1 min-h-11 w-full border border-slate-300 px-3">
                                <option v-for="m in governanceRules.memberships" :key="m.id" :value="m.id">{{ m.email }}</option>
                            </select>
                        </label>
                        <label class="text-sm font-medium">Minutes owner
                            <select v-model="charter.minutes_owner_membership_id" class="mt-1 min-h-11 w-full border border-slate-300 px-3">
                                <option v-for="m in governanceRules.memberships" :key="m.id" :value="m.id">{{ m.email }}</option>
                            </select>
                        </label>
                        <label class="text-sm font-medium">Voting basis
                            <input v-model="charter.voting_basis" class="mt-1 min-h-11 w-full border border-slate-300 px-3" />
                        </label>
                        <label class="text-sm font-medium">Default approval rule
                            <input v-model="charter.default_approval_rule" class="mt-1 min-h-11 w-full border border-slate-300 px-3" />
                        </label>
                        <label class="text-sm font-medium">Meeting frequency
                            <input v-model="charter.meeting_frequency" class="mt-1 min-h-11 w-full border border-slate-300 px-3" />
                        </label>
                        <label class="text-sm font-medium">Default quorum
                            <input v-model.number="charter.default_quorum_count" type="number" min="1" class="mt-1 min-h-11 w-full border border-slate-300 px-3" />
                        </label>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <label class="text-sm font-medium">Conflict-of-interest rule
                            <textarea v-model="charter.conflict_of_interest_rule" rows="3" class="mt-1 w-full border border-slate-300 p-3" />
                        </label>
                        <label class="text-sm font-medium">Deadlock rule
                            <textarea v-model="charter.deadlock_rule" rows="3" class="mt-1 w-full border border-slate-300 p-3" />
                        </label>
                    </div>

                    <div class="flex flex-wrap gap-5 text-sm">
                        <label><input v-model="charter.remote_voting_allowed" type="checkbox" /> Remote voting allowed</label>
                        <label><input v-model="charter.written_resolution_allowed" type="checkbox" /> Written resolution allowed</label>
                    </div>

                    <div class="space-y-4">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="font-semibold">Decision / Authority Matrix</h3>
                            <button type="button" class="min-h-10 border border-slate-300 px-3 text-sm font-semibold" @click="charter.rules.push(rule())">Add rule</button>
                        </div>

                        <article v-for="(r, ri) in charter.rules" :key="ri" class="border border-slate-200 bg-slate-50 p-4">
                            <div class="grid gap-3 md:grid-cols-3 xl:grid-cols-6">
                                <input v-model="r.decision_type" class="min-h-10 border border-slate-300 px-2 text-sm" placeholder="Decision type" />
                                <select v-model="r.category" class="min-h-10 border border-slate-300 px-2 text-sm">
                                    <option value="daily_operating">Daily Operating</option>
                                    <option value="management">Management</option>
                                    <option value="major_business">Major Business</option>
                                    <option value="ownership_structural">Ownership / Structural</option>
                                    <option value="custom">Custom</option>
                                </select>
                                <select v-model="r.decision_method" class="min-h-10 border border-slate-300 px-2 text-sm">
                                    <option value="approval">Approval</option>
                                    <option value="vote">Vote</option>
                                    <option value="approval_and_vote">Approval + Vote</option>
                                </select>
                                <input v-model.number="r.required_approvals" type="number" min="0" class="min-h-10 border border-slate-300 px-2" aria-label="Required approvals" />
                                <input v-model.number="r.required_votes" type="number" min="0" class="min-h-10 border border-slate-300 px-2" aria-label="Required votes" />
                                <input v-model.number="r.quorum_count" type="number" min="1" class="min-h-10 border border-slate-300 px-2" aria-label="Quorum" />
                                <input v-model="r.amount_min" class="min-h-10 border border-slate-300 px-2" placeholder="Amount min" />
                                <input v-model="r.amount_max" class="min-h-10 border border-slate-300 px-2" placeholder="Amount max" />
                            </div>

                            <div class="mt-3 flex flex-wrap gap-4 text-xs">
                                <label><input v-model="r.reserved_matter" type="checkbox" /> Reserved</label>
                                <label><input v-model="r.signature_required" type="checkbox" /> Signature</label>
                                <label><input v-model="r.meeting_required" type="checkbox" /> Meeting</label>
                                <label><input v-model="r.record_required" type="checkbox" /> Record</label>
                            </div>

                            <div class="mt-4 space-y-2">
                                <div class="flex justify-between">
                                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Authority actors</p>
                                    <button type="button" class="text-xs font-semibold underline" @click="r.actors.push(actor(false))">Add actor</button>
                                </div>
                                <div v-for="(a, ai) in r.actors" :key="ai" class="grid gap-2 border-l-2 border-slate-300 pl-3 md:grid-cols-3 xl:grid-cols-6">
                                    <select v-model="a.membership_id" class="min-h-10 border border-slate-300 px-2 text-xs">
                                        <option v-for="m in governanceRules.memberships" :key="m.id" :value="m.id">{{ m.email }}</option>
                                    </select>
                                    <input v-model="a.capacity" class="min-h-10 border border-slate-300 px-2 text-xs" placeholder="Capacity" />
                                    <label class="text-xs"><input v-model="a.is_decision_owner" type="checkbox" /> Owner</label>
                                    <label class="text-xs"><input v-model="a.is_consulted" type="checkbox" /> Consulted</label>
                                    <div class="flex gap-2 text-xs">
                                        <label><input v-model="a.can_approve" type="checkbox" /> A</label>
                                        <label><input v-model="a.can_vote" type="checkbox" /> V</label>
                                        <label><input v-model="a.can_sign" type="checkbox" /> S</label>
                                    </div>
                                    <button type="button" class="text-left text-xs font-semibold text-red-700" @click="r.actors.length > 1 && r.actors.splice(ai, 1)">Remove</button>
                                </div>
                            </div>

                            <button type="button" class="mt-4 text-xs font-semibold text-red-700" @click="charter.rules.length > 1 && charter.rules.splice(ri, 1)">
                                Remove rule
                            </button>
                        </article>
                    </div>

                    <p v-if="formError(charter.errors, 'charter')" class="text-sm text-red-700">{{ formError(charter.errors, 'charter') }}</p>
                    <button type="submit" :disabled="charter.processing" class="min-h-11 bg-slate-950 px-4 text-sm font-semibold text-white disabled:opacity-50">
                        Create versioned Charter draft
                    </button>
                </form>
            </details>

            <section class="mt-8 border-t border-slate-200 pt-6">
                <h2 class="text-lg font-bold">Charter Version Workflow</h2>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full border-collapse text-left text-sm">
                        <thead><tr class="border-b border-slate-300 text-slate-600"><th class="px-3 py-3">Version</th><th class="px-3 py-3">Effective</th><th class="px-3 py-3">State</th><th class="px-3 py-3">Controls</th></tr></thead>
                        <tbody>
                            <tr v-for="v in governanceRules.versions" :key="v.id" class="border-b border-slate-200">
                                <td class="px-3 py-3 font-semibold">v{{ v.version_number }}</td>
                                <td class="px-3 py-3">{{ formatDate(v.effective_from) }}</td>
                                <td class="px-3 py-3">{{ v.frozen_at ? 'Frozen' : 'Draft' }}</td>
                                <td class="px-3 py-3">
                                    <div v-if="governanceRules.permissions.manage" class="flex flex-wrap gap-2">
                                        <button v-if="!v.frozen_at" type="button" class="min-h-9 border border-slate-300 px-3 text-xs font-semibold" @click="post('/governance/rules/charter/' + v.id + '/submit', { expected_revision: v.revision })">Freeze + Proposal</button>
                                        <template v-else>
                                            <button type="button" class="min-h-9 border border-slate-300 px-3 text-xs font-semibold" @click="post('/governance/rules/charter/' + v.id + '/content-review', { target: 'under_review' })">Under Review</button>
                                            <button type="button" class="min-h-9 bg-slate-900 px-3 text-xs font-semibold text-white" @click="post('/governance/rules/charter/' + v.id + '/content-review', { target: 'approved' })">Content Approved</button>
                                        </template>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="governanceRules.versions.length === 0"><td colspan="4" class="px-3 py-5 text-slate-500">No Charter versions.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="governanceRules.permissions.manage" class="mt-8 grid gap-6 border-t border-slate-200 pt-6 xl:grid-cols-2">
                <form class="border border-slate-200 p-5" @submit.prevent="delegation.post('/governance/rules/delegations', { preserveScroll: true })">
                    <h2 class="font-bold">Propose Delegation</h2>
                    <p class="mt-1 text-xs leading-5 text-slate-600">Delegation stays inert until its exact Frozen Proposal Version receives an approved Governance Decision.</p>
                    <div class="mt-4 grid gap-3">
                        <select v-model="delegation.delegator_membership_id" class="min-h-11 border border-slate-300 px-3"><option v-for="m in governanceRules.memberships" :key="m.id" :value="m.id">Delegator · {{ m.email }}</option></select>
                        <select v-model="delegation.delegate_membership_id" class="min-h-11 border border-slate-300 px-3"><option v-for="m in governanceRules.memberships" :key="m.id" :value="m.id">Delegate · {{ m.email }}</option></select>
                        <input v-model="delegation.decision_type" required class="min-h-11 border border-slate-300 px-3" placeholder="Exact decision type" />
                        <textarea v-model="delegation.scope" required class="border border-slate-300 p-3" placeholder="Scope" />
                        <div class="grid gap-3 sm:grid-cols-2"><OptionalTemporalInput v-model="delegation.effective_from" type="datetime-local" class="min-h-11 border border-slate-300 px-3" /><OptionalTemporalInput v-model="delegation.expires_at" type="datetime-local" class="min-h-11 border border-slate-300 px-3" /></div>
                    </div>
                    <p v-if="formError(delegation.errors, 'delegation')" class="mt-2 text-sm text-red-700">{{ formError(delegation.errors, 'delegation') }}</p>
                    <button type="submit" class="mt-4 min-h-11 bg-slate-950 px-4 text-sm font-semibold text-white">Freeze Delegation Proposal</button>
                </form>

                <form class="border border-slate-200 p-5" @submit.prevent="emergency.post('/governance/rules/emergency-authority', { preserveScroll: true })">
                    <h2 class="font-bold">Propose Emergency Authority</h2>
                    <p class="mt-1 text-xs leading-5 text-slate-600">Temporary, exact-scope and governance-authorized. It never grants System Permission.</p>
                    <div class="mt-4 grid gap-3">
                        <select v-model="emergency.grantee_membership_id" class="min-h-11 border border-slate-300 px-3"><option v-for="m in governanceRules.memberships" :key="m.id" :value="m.id">{{ m.email }}</option></select>
                        <input v-model="emergency.decision_type" required class="min-h-11 border border-slate-300 px-3" placeholder="Exact decision type" />
                        <input v-model="emergency.capacity" required class="min-h-11 border border-slate-300 px-3" placeholder="Capacity" />
                        <textarea v-model="emergency.scope" required class="border border-slate-300 p-3" placeholder="Scope" />
                        <textarea v-model="emergency.reason" required class="border border-slate-300 p-3" placeholder="Reason" />
                        <div class="flex gap-4 text-sm"><label><input v-model="emergency.can_approve" type="checkbox" /> Approve</label><label><input v-model="emergency.can_vote" type="checkbox" /> Vote</label><label><input v-model="emergency.can_sign" type="checkbox" /> Sign</label></div>
                        <div class="grid gap-3 sm:grid-cols-2"><OptionalTemporalInput v-model="emergency.effective_from" type="datetime-local" class="min-h-11 border border-slate-300 px-3" /><input v-model="emergency.expires_at" type="datetime-local" required class="min-h-11 border border-slate-300 px-3" /></div>
                    </div>
                    <p v-if="formError(emergency.errors, 'emergency_authority')" class="mt-2 text-sm text-red-700">{{ formError(emergency.errors, 'emergency_authority') }}</p>
                    <button type="submit" class="mt-4 min-h-11 bg-slate-950 px-4 text-sm font-semibold text-white">Freeze Emergency Proposal</button>
                </form>
            </section>

            <section class="mt-8 border-t border-slate-200 pt-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-lg font-bold">Authority Change Register</h2>
                    <Link href="/governance" class="text-sm font-semibold underline">Review / decide Frozen Proposals</Link>
                </div>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full border-collapse text-left text-sm">
                        <thead><tr class="border-b border-slate-300 text-slate-600"><th class="px-3 py-3">Type</th><th class="px-3 py-3">Action</th><th class="px-3 py-3">Proposal</th><th class="px-3 py-3">Authorization</th><th class="px-3 py-3">Control</th></tr></thead>
                        <tbody>
                            <tr v-for="row in governanceRules.authority_changes" :key="row.id" class="border-b border-slate-200">
                                <td class="px-3 py-3">{{ row.subject_type }}</td>
                                <td class="px-3 py-3">{{ row.action }}</td>
                                <td class="px-3 py-3 text-xs">
                                    <span class="font-semibold text-slate-700">Frozen proposal</span>
                                    <details class="mt-1 text-slate-500">
                                        <summary class="cursor-pointer font-semibold">
                                            {{ t('governance.advancedDetails') }}
                                        </summary>
                                        <p class="mt-1 break-all font-mono">{{ row.proposal_version_id }}</p>
                                    </details>
                                </td>
                                <td class="px-3 py-3">{{ row.authorized_at ? 'Authorized' : (row.decision_status ?? 'Pending') + ' ' + (row.decision_outcome ?? '') }}</td>
                                <td class="px-3 py-3">
                                    <button v-if="governanceRules.permissions.manage && !row.authorized_at" type="button" class="min-h-9 border border-slate-900 px-3 text-xs font-semibold" @click="post('/governance/rules/authority-changes/' + row.id + '/authorize')">Sync approved Decision</button>
                                    <button v-if="governanceRules.permissions.manage && row.authorized_at && row.action === 'grant'" type="button" class="ml-2 min-h-9 border border-slate-300 px-3 text-xs font-semibold" @click="post('/governance/rules/authority-revocations', { subject_type: row.subject_type, subject_id: row.subject_id })">Propose revocation</button>
                                </td>
                            </tr>
                            <tr v-if="governanceRules.authority_changes.length === 0"><td colspan="5" class="px-3 py-5 text-slate-500">No governed authority changes.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
            </div>
        </main>
    </AuthenticatedLayout>
</template>
