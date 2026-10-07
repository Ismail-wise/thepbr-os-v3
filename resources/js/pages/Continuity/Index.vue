<script setup lang="ts">
import OptionalTemporalInput from '../../components/OptionalTemporalInput.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import Grade6MvpGuide from '../../components/journey/Grade6MvpGuide.vue';
import { useI18n } from '../../i18n/useI18n';

type Membership = { id: string; email: string };
type Role = { id: string; role_key: string; name: string };
type VersionRow = { id: string; version_number: number; revision: number; frozen_at: string | null; effective_from: string | null; review_due_at: string | null; state: string | null };
type CriticalFunction = { id: string; operations_role_id: string; function_name: string; critical_process: string; maximum_downtime_minutes: number; primary_owner_membership_id: string; first_backup_membership_id: string; second_backup_membership_id: string | null; recovery_priority: number; minimum_resources: string; review_date: string | null; status: string };
type EmergencyAccess = { id: string; system_asset: string; primary_access_membership_id: string; backup_access_membership_id: string; access_level: string; emergency_access_procedure: string; secure_storage_reference: string | null; last_tested_date: string | null; review_date: string | null; removal_trigger: string; status: string };
type InterimPlan = { id: string; operations_role_id: string; interim_membership_id: string; trigger: string; governance_decision_type: string; spending_limit_minor_units: number | null; currency: string | null; decision_limit: string; maximum_interim_hours: number; reporting_requirement: string; status: string };
type Successor = { id: string; operations_role_id: string; current_owner_membership_id: string; candidate_membership_id: string; readiness_level: string; skills_gap: string | null; development_required: string | null; target_ready_date: string | null; status: string };
type CommunicationStep = { id: string; sequence: number; event_type: string; stakeholder: string; owner_membership_id: string; channel: string; timing: string; message_reference: string | null; governance_approval_may_be_required: boolean };
type RecoveryAction = { id: string; sequence: number; timeline_band: string; action: string; operations_role_id: string; required_resource: string | null };
type ContinuityTest = { id: string; scenario_name: string; scenario: string; result: string; owner_membership_id: string; next_test_date: string | null; completed_at: string | null };
type Activation = { id: string; emergency_access_record_id: string; activated_by_membership_id: string; required_decision_type: string | null; trigger: string; starts_at: string; expires_at: string; status: string; revision: number };
type CurrentPlan = { formal_record_version_id: string; version_number: number; effective_from: string | null; header: { operations_formal_record_version_id: string; continuity_owner_membership_id: string; governance_decision_type: string; review_frequency: string; test_frequency: string; notes: string | null }; critical_functions: CriticalFunction[]; emergency_access: EmergencyAccess[]; interim_authority_plans: InterimPlan[]; successors: Successor[]; communication_steps: CommunicationStep[]; recovery_actions: RecoveryAction[] };

const props = defineProps<{
    continuity: {
        business: { id: string; name: string };
        permissions: { manage: boolean };
        current: CurrentPlan | null;
        tests: ContinuityTest[];
        activations: Activation[];
        versions: VersionRow[];
        memberships: Membership[];
        operations_roles: Role[];
        prerequisites: {
            operations_register: {
                status: 'met' | 'missing' | 'unknown';
                can_open_operations: boolean;
            };
        };
        attention: { failed_tests: number; active_activations: number; requested_activations: number; continuity_gaps: number };
    };
}>();

const { t } = useI18n();
const today = new Date().toISOString().slice(0, 10);
const nowLocal = new Date().toISOString().slice(0, 16);
const firstMember = props.continuity.memberships[0]?.id ?? '';
const secondMember = props.continuity.memberships[1]?.id ?? '';
const firstRole = props.continuity.operations_roles[0]?.id ?? '';
const memberLabel = (id: string | null) => props.continuity.memberships.find((row) => row.id === id)?.email ?? id ?? '—';
const roleLabel = (id: string) => props.continuity.operations_roles.find((row) => row.id === id)?.name ?? id;
const accessLabel = (id: string) => props.continuity.current?.emergency_access.find((row) => row.id === id)?.system_asset ?? 'Restricted / unavailable';
const attentionTotal = computed(() => props.continuity.attention.failed_tests + props.continuity.attention.active_activations + props.continuity.attention.requested_activations + props.continuity.attention.continuity_gaps);
const humanize = (value: string | null): string => value ? value.replaceAll('_', ' ') : '—';
type PostData = NonNullable<Parameters<typeof router.post>[1]>;
const post = (url: string, data: PostData = {}) => router.post(url, data, { preserveScroll: true });

const governanceSync = useForm({});
const governanceSyncVersionId = ref('');
const syncGovernedDecision = (versionId: string): void => {
    governanceSyncVersionId.value = versionId;
    governanceSync.clearErrors();
    governanceSync.post(
        `/continuity/plan/${versionId}/sync-decision`,
        {
            preserveScroll: true,
            onSuccess: () => governanceSync.clearErrors(),
        },
    );
};

const plan = useForm({
    effective_from: today,
    review_due_at: '',
    continuity_owner_membership_id: firstMember,
    governance_decision_type: 'continuity_plan_approval',
    review_frequency: 'Quarterly',
    test_frequency: 'Quarterly',
    notes: '',
    critical_functions: [{
        operations_role_id: firstRole,
        function_name: '',
        critical_process: '',
        maximum_downtime_minutes: 240,
        primary_owner_membership_id: firstMember,
        first_backup_membership_id: secondMember,
        second_backup_membership_id: '',
        recovery_priority: 1,
        minimum_resources: '',
        review_date: '',
        status: 'ready',
    }],
    emergency_access: [] as Array<{ system_asset: string; primary_access_membership_id: string; backup_access_membership_id: string; access_level: string; emergency_access_procedure: string; secure_storage_reference: string; last_tested_date: string; review_date: string; removal_trigger: string; status: string }>,
    interim_authority_plans: [] as Array<{ operations_role_id: string; interim_membership_id: string; trigger: string; governance_decision_type: string; spending_limit_minor_units: number | null; currency: string; decision_limit: string; maximum_interim_hours: number; reporting_requirement: string; status: string }>,
    successors: [] as Array<{ operations_role_id: string; current_owner_membership_id: string; candidate_membership_id: string; readiness_level: string; skills_gap: string; development_required: string; target_ready_date: string; status: string }>,
    communication_steps: [] as Array<{ event_type: string; stakeholder: string; owner_membership_id: string; channel: string; timing: string; message_reference: string; governance_approval_may_be_required: boolean }>,
    recovery_actions: [] as Array<{ timeline_band: string; action: string; operations_role_id: string; required_resource: string }>,
});
const addCriticalFunction = () => plan.critical_functions.push({ operations_role_id: firstRole, function_name: '', critical_process: '', maximum_downtime_minutes: 240, primary_owner_membership_id: firstMember, first_backup_membership_id: secondMember, second_backup_membership_id: '', recovery_priority: 1, minimum_resources: '', review_date: '', status: 'ready' });
const addEmergencyAccess = () => plan.emergency_access.push({ system_asset: '', primary_access_membership_id: firstMember, backup_access_membership_id: secondMember, access_level: 'emergency operator', emergency_access_procedure: '', secure_storage_reference: '', last_tested_date: '', review_date: '', removal_trigger: 'End immediately when emergency condition ends.', status: 'active' });
const addInterimPlan = () => plan.interim_authority_plans.push({ operations_role_id: firstRole, interim_membership_id: secondMember, trigger: '', governance_decision_type: 'continuity_emergency_authority', spending_limit_minor_units: null, currency: 'USD', decision_limit: '', maximum_interim_hours: 24, reporting_requirement: '', status: 'active' });
const addSuccessor = () => plan.successors.push({ operations_role_id: firstRole, current_owner_membership_id: firstMember, candidate_membership_id: secondMember, readiness_level: 'developing', skills_gap: '', development_required: '', target_ready_date: '', status: 'candidate' });
const addCommunication = () => plan.communication_steps.push({ event_type: 'continuity_activation', stakeholder: '', owner_membership_id: firstMember, channel: 'phone', timing: 'Immediately', message_reference: '', governance_approval_may_be_required: false });
const addRecovery = () => plan.recovery_actions.push({ timeline_band: '0_24_hours', action: '', operations_role_id: firstRole, required_resource: '' });

const testForm = useForm({ scenario_name: '', scenario: '', owner_membership_id: firstMember, next_test_date: '' });
const activationForm = useForm({ emergency_access_record_id: props.continuity.current?.emergency_access[0]?.id ?? '', trigger: '', reason: '', starts_at: nowLocal, expires_at: '', required_decision_type: '', emergency_authority_grant_id: '' });
const actionForm = useForm({ source_type: 'continuity_critical_function', source_id: '', operations_role_id: firstRole, assigned_membership_id: firstMember, title: '', description: '', due_at: '' });
const actionSources = computed(() => {
    if (actionForm.source_type === 'continuity_test') {
        return props.continuity.tests.map((row) => ({
            id: row.id,
            label: row.scenario_name,
        }));
    }

    if (actionForm.source_type === 'emergency_access_activation') {
        return props.continuity.activations.map((row) => ({
            id: row.id,
            label: `${accessLabel(row.emergency_access_record_id)} · ${row.trigger}`,
        }));
    }

    return (props.continuity.current?.critical_functions ?? []).map((row) => ({
        id: row.id,
        label: row.function_name,
    }));
});
</script>

<template>
    <Head :title="t('continuity.title')" />
    <AuthenticatedLayout>
        <Grade6MvpGuide step="continuity" />
        <main class="min-h-screen px-4 py-5 text-[var(--pbr-ink)] sm:px-6 sm:py-6 lg:px-8 lg:py-7">
            <div class="mx-auto w-full max-w-[1500px]">
            <header class="pbr-surface p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ continuity.business.name }}</p>
                        <h1 class="mt-2 text-2xl font-bold text-slate-950">{{ t('continuity.title') }}</h1>
                        <p class="mt-2 max-w-4xl text-sm leading-6 text-slate-600">{{ t('continuity.description') }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Link href="/risk" class="inline-flex min-h-11 items-center border border-slate-300 px-4 text-sm font-semibold">Risk & Protection</Link>
                        <Link
                            v-if="continuity.prerequisites.operations_register.can_open_operations"
                            href="/operations"
                            class="inline-flex min-h-11 items-center border border-slate-300 px-4 text-sm font-semibold"
                        >
                            {{ t('continuity.openOperations') }}
                        </Link>
                        <Link href="/governance" class="inline-flex min-h-11 items-center border border-slate-300 px-4 text-sm font-semibold">Governance</Link>
                    </div>
                </div>
                <p class="mt-5 rounded-[16px] border border-[#cfe1d3] bg-[#f3f8f4] px-4 py-3 text-sm font-bold text-[var(--pbr-green-dark)]">{{ t('continuity.boundary') }}</p>
            </header>

            <section class="mt-6 grid gap-4 md:grid-cols-4">
                <div class="border border-slate-200 p-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Needs attention</p><p class="mt-2 text-2xl font-bold">{{ attentionTotal }}</p><p class="mt-1 text-xs text-slate-500">Authorized records only</p></div>
                <div class="border border-slate-200 p-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Continuity gaps</p><p class="mt-2 text-2xl font-bold">{{ continuity.attention.continuity_gaps }}</p></div>
                <div class="border border-slate-200 p-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Emergency access</p><p class="mt-2 text-2xl font-bold">{{ continuity.attention.active_activations + continuity.attention.requested_activations }}</p><p class="mt-1 text-xs text-slate-500">Active + requested</p></div>
                <div class="border border-slate-200 p-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Failed tests</p><p class="mt-2 text-2xl font-bold">{{ continuity.attention.failed_tests }}</p></div>
            </section>

            <section class="pbr-surface mt-6 p-5 sm:p-6">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div><h2 class="text-lg font-bold">Current Effective Continuity Plan</h2><p class="mt-1 text-sm text-slate-600">Exact Operations responsibility is preserved with this version. Later role changes do not rewrite history.</p></div>
                    <span v-if="continuity.current" class="border border-slate-300 px-2 py-1 text-xs font-semibold">v{{ continuity.current.version_number }} · Effective</span>
                </div>
                <div v-if="!continuity.current" class="mt-4 border border-dashed border-slate-300 p-5 text-sm text-slate-500">No Effective Continuity Plan yet.</div>
                <template v-else>
                    <div class="mt-4 overflow-x-auto border border-slate-200">
                        <div class="border-b border-slate-200 px-4 py-3 font-semibold">Critical Function & Backup Coverage</div>
                        <table class="min-w-full text-left text-sm">
                            <thead><tr class="border-b bg-slate-50"><th class="px-3 py-3">Function</th><th class="px-3 py-3">Primary</th><th class="px-3 py-3">Backup</th><th class="px-3 py-3">Maximum downtime</th><th class="px-3 py-3">Status</th></tr></thead>
                            <tbody><tr v-for="row in continuity.current.critical_functions" :key="row.id" class="border-b border-slate-100 align-top"><td class="px-3 py-3"><p class="font-semibold">{{ row.function_name }}</p><p class="mt-1 text-xs text-slate-500">{{ roleLabel(row.operations_role_id) }} · priority {{ row.recovery_priority }}</p><p class="mt-1 text-xs">{{ row.critical_process }}</p></td><td class="px-3 py-3 text-xs">{{ memberLabel(row.primary_owner_membership_id) }}</td><td class="px-3 py-3 text-xs"><p>{{ memberLabel(row.first_backup_membership_id) }}</p><p v-if="row.second_backup_membership_id">{{ memberLabel(row.second_backup_membership_id) }}</p></td><td class="px-3 py-3">{{ row.maximum_downtime_minutes }} min</td><td class="px-3 py-3 text-xs font-semibold">{{ humanize(row.status) }}</td></tr></tbody>
                        </table>
                    </div>

                    <div class="mt-5 grid gap-5 xl:grid-cols-2">
                        <div class="overflow-x-auto border border-slate-200">
                            <div class="border-b border-slate-200 px-4 py-3 font-semibold">Restricted Emergency Access Register</div>
                            <table class="min-w-full text-left text-sm"><thead><tr class="border-b bg-slate-50"><th class="px-3 py-3">System / asset</th><th class="px-3 py-3">People</th><th class="px-3 py-3">Control</th></tr></thead><tbody><tr v-for="row in continuity.current.emergency_access" :key="row.id" class="border-b border-slate-100"><td class="px-3 py-3"><p class="font-semibold">{{ row.system_asset }}</p><p class="text-xs text-slate-500">Restricted · {{ row.access_level }}</p></td><td class="px-3 py-3 text-xs">{{ memberLabel(row.primary_access_membership_id) }}<br />Backup: {{ memberLabel(row.backup_access_membership_id) }}</td><td class="px-3 py-3 text-xs"><p>{{ row.emergency_access_procedure }}</p><p class="mt-1 text-slate-500">Remove: {{ row.removal_trigger }}</p></td></tr><tr v-if="continuity.current.emergency_access.length === 0"><td colspan="3" class="px-3 py-5 text-slate-500">No authorized Emergency Access records.</td></tr></tbody></table>
                        </div>
                        <div class="overflow-x-auto border border-slate-200">
                            <div class="border-b border-slate-200 px-4 py-3 font-semibold">Interim Authority Plans</div>
                            <table class="min-w-full text-left text-sm"><thead><tr class="border-b bg-slate-50"><th class="px-3 py-3">Role / person</th><th class="px-3 py-3">Trigger</th><th class="px-3 py-3">Boundary</th></tr></thead><tbody><tr v-for="row in continuity.current.interim_authority_plans" :key="row.id" class="border-b border-slate-100"><td class="px-3 py-3"><p class="font-semibold">{{ roleLabel(row.operations_role_id) }}</p><p class="text-xs text-slate-500">{{ memberLabel(row.interim_membership_id) }}</p></td><td class="px-3 py-3 text-xs">{{ row.trigger }}</td><td class="px-3 py-3 text-xs"><p>{{ row.governance_decision_type }}</p><p>{{ row.decision_limit }} · max {{ row.maximum_interim_hours }}h</p><p class="mt-1 font-semibold text-amber-800">Plan only — grants no authority.</p></td></tr><tr v-if="continuity.current.interim_authority_plans.length === 0"><td colspan="3" class="px-3 py-5 text-slate-500">No interim authority plans.</td></tr></tbody></table>
                        </div>
                    </div>

                    <div class="mt-5 overflow-x-auto border border-slate-200">
                        <div class="border-b border-slate-200 px-4 py-3 font-semibold">Successor Readiness — separate from Backup</div>
                        <table class="min-w-full text-left text-sm"><thead><tr class="border-b bg-slate-50"><th class="px-3 py-3">Role</th><th class="px-3 py-3">Current owner</th><th class="px-3 py-3">Successor candidate</th><th class="px-3 py-3">Readiness</th></tr></thead><tbody><tr v-for="row in continuity.current.successors" :key="row.id" class="border-b border-slate-100"><td class="px-3 py-3 font-semibold">{{ roleLabel(row.operations_role_id) }}</td><td class="px-3 py-3 text-xs">{{ memberLabel(row.current_owner_membership_id) }}</td><td class="px-3 py-3 text-xs">{{ memberLabel(row.candidate_membership_id) }}</td><td class="px-3 py-3"><span class="border border-slate-300 px-2 py-1 text-xs">{{ humanize(row.readiness_level) }}</span><p class="mt-1 text-xs text-slate-500">{{ row.target_ready_date ?? 'No target date' }}</p></td></tr><tr v-if="continuity.current.successors.length === 0"><td colspan="4" class="px-3 py-5 text-slate-500">No Successor candidates.</td></tr></tbody></table>
                    </div>

                    <div class="mt-5 grid gap-5 xl:grid-cols-2">
                        <div class="border border-slate-200"><div class="border-b border-slate-200 px-4 py-3 font-semibold">Communication Plan</div><ol class="divide-y divide-slate-100"><li v-for="row in continuity.current.communication_steps" :key="row.id" class="p-4 text-sm"><p class="font-semibold">{{ row.sequence }}. {{ humanize(row.event_type) }} → {{ row.stakeholder }}</p><p class="mt-1 text-xs text-slate-500">{{ row.channel }} · {{ row.timing }} · owner {{ memberLabel(row.owner_membership_id) }}</p><p v-if="row.governance_approval_may_be_required" class="mt-1 text-xs font-semibold text-amber-800">Governance approval may be required; this marker is not Approval.</p></li><li v-if="continuity.current.communication_steps.length === 0" class="p-4 text-sm text-slate-500">No communication steps.</li></ol></div>
                        <div class="border border-slate-200"><div class="border-b border-slate-200 px-4 py-3 font-semibold">Recovery Actions</div><ol class="divide-y divide-slate-100"><li v-for="row in continuity.current.recovery_actions" :key="row.id" class="p-4 text-sm"><p class="font-semibold">{{ row.sequence }}. {{ row.action }}</p><p class="mt-1 text-xs text-slate-500">{{ humanize(row.timeline_band) }} · {{ roleLabel(row.operations_role_id) }}</p></li><li v-if="continuity.current.recovery_actions.length === 0" class="p-4 text-sm text-slate-500">No recovery actions.</li></ol></div>
                    </div>
                </template>
            </section>

            <section class="pbr-surface mt-6 p-5 sm:p-6">
                <h2 class="text-lg font-bold">Continuity Plan Version History</h2>
                <div class="mt-3 overflow-x-auto border border-slate-200"><table class="min-w-full text-left text-sm"><thead><tr class="border-b bg-slate-50"><th class="px-3 py-3">Version</th><th class="px-3 py-3">State</th><th class="px-3 py-3">Effective</th><th class="px-3 py-3">Context action</th></tr></thead><tbody><tr v-for="version in continuity.versions" :key="version.id" class="border-b border-slate-100"><td class="px-3 py-3 font-semibold">v{{ version.version_number }}</td><td class="px-3 py-3"><span class="border border-slate-300 px-2 py-1 text-xs">{{ humanize(version.state) }}</span></td><td class="px-3 py-3 text-xs">{{ version.effective_from ?? '—' }}</td><td class="px-3 py-3"><div v-if="continuity.permissions.manage" class="flex flex-wrap gap-2"><button v-if="version.state === 'draft'" class="text-xs font-semibold underline" @click="post('/continuity/plan/' + version.id + '/submit', { expected_revision: version.revision })">Submit</button><button v-if="version.state === 'ready_for_review'" class="text-xs font-semibold underline" @click="post('/continuity/plan/' + version.id + '/content-review', { target: 'under_review' })">Start review</button><button v-if="version.state === 'under_review'" class="text-xs font-semibold underline" @click="post('/continuity/plan/' + version.id + '/content-review', { target: 'approved' })">Approve content</button><button v-if="version.state === 'approved' || version.state === 'ready_for_effect'" :disabled="governanceSync.processing" class="text-xs font-semibold underline disabled:opacity-50" @click="syncGovernedDecision(version.id)">Sync governed Decision</button></div><p v-if="governanceSyncVersionId === version.id && Object.keys(governanceSync.errors).length" class="mt-2 text-xs text-red-700">{{ Object.values(governanceSync.errors)[0] }}</p></td></tr></tbody></table></div>
            </section>

            <section
                v-if="
                    continuity.permissions.manage
                    && continuity.prerequisites.operations_register.status !== 'met'
                "
                class="mt-8 border border-amber-300 bg-amber-50 p-5"
            >
                <p class="text-sm font-semibold text-amber-950">
                    {{
                        continuity.prerequisites.operations_register.status === 'missing'
                            ? t('continuity.prerequisiteMissing')
                            : t('continuity.prerequisiteUnknown')
                    }}
                </p>
                <Link
                    v-if="continuity.prerequisites.operations_register.can_open_operations"
                    href="/operations"
                    class="mt-3 inline-flex min-h-10 items-center border border-amber-400 bg-white px-3 text-sm font-semibold text-amber-950"
                >
                    {{ t('continuity.openOperations') }}
                </Link>
            </section>

            <details
                v-if="
                    continuity.permissions.manage
                    && continuity.prerequisites.operations_register.status === 'met'
                "
                class="pbr-surface mt-6"
            >
                <summary class="cursor-pointer px-5 py-4 font-semibold">Create Continuity Plan Draft / Amendment</summary>
                <form class="space-y-6 border-t border-slate-200 p-5" @submit.prevent="plan.post('/continuity/plan', { preserveScroll: true })">
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <label class="text-sm font-medium">Effective from<input v-model="plan.effective_from" type="date" required class="mt-1 min-h-11 w-full border border-slate-300 px-3" /></label>
                        <label class="text-sm font-medium">Continuity owner<select v-model="plan.continuity_owner_membership_id" required class="mt-1 min-h-11 w-full border border-slate-300 px-3"><option v-for="m in continuity.memberships" :key="m.id" :value="m.id">{{ m.email }}</option></select></label>
                        <label class="text-sm font-medium">Review frequency<input v-model="plan.review_frequency" required class="mt-1 min-h-11 w-full border border-slate-300 px-3" /></label>
                        <label class="text-sm font-medium">Test frequency<input v-model="plan.test_frequency" required class="mt-1 min-h-11 w-full border border-slate-300 px-3" /></label>
                    </div>
                    <label class="block text-sm font-medium">Governance Decision Type<input v-model="plan.governance_decision_type" required class="mt-1 min-h-11 w-full border border-slate-300 px-3" /><span class="mt-1 block text-xs text-slate-500">This references the centralized Governance authority engine; it is not a module approval shortcut.</span></label>

                    <div>
                        <div class="flex items-center justify-between"><h3 class="font-semibold">Critical Functions & Temporary Backups</h3><button type="button" class="text-xs font-semibold underline" @click="addCriticalFunction">Add function</button></div>
                        <div v-for="(row, index) in plan.critical_functions" :key="index" class="mt-3 grid gap-3 border border-slate-200 p-3 md:grid-cols-2 xl:grid-cols-4">
                            <label class="text-sm font-medium text-slate-700">{{ t('continuity.criticalFunction.name') }}<input v-model="row.function_name" required class="mt-1 min-h-10 w-full border border-slate-300 px-2" placeholder="e.g. Customer support" /></label>
                            <label class="text-sm font-medium text-slate-700">{{ t('continuity.criticalFunction.process') }}<input v-model="row.critical_process" required class="mt-1 min-h-10 w-full border border-slate-300 px-2" placeholder="e.g. Incident triage" /></label>
                            <label class="text-sm font-medium text-slate-700">{{ t('continuity.criticalFunction.role') }}<select v-model="row.operations_role_id" class="mt-1 min-h-10 w-full border border-slate-300 px-2"><option v-for="role in continuity.operations_roles" :key="role.id" :value="role.id">{{ role.name }}</option></select></label>
                            <label class="text-sm font-medium text-slate-700">{{ t('continuity.criticalFunction.maxDowntime') }}<input v-model.number="row.maximum_downtime_minutes" type="number" min="1" class="mt-1 min-h-10 w-full border border-slate-300 px-2" placeholder="e.g. 240" /></label>
                            <label class="text-sm font-medium text-slate-700">{{ t('continuity.criticalFunction.primaryOwner') }}<select v-model="row.primary_owner_membership_id" class="mt-1 min-h-10 w-full border border-slate-300 px-2"><option v-for="m in continuity.memberships" :key="m.id" :value="m.id">{{ m.email }}</option></select></label>
                            <label class="text-sm font-medium text-slate-700">{{ t('continuity.criticalFunction.firstBackup') }}<select v-model="row.first_backup_membership_id" required class="mt-1 min-h-10 w-full border border-slate-300 px-2"><option value="">First backup required</option><option v-for="m in continuity.memberships" :key="m.id" :value="m.id">{{ m.email }}</option></select></label>
                            <label class="text-sm font-medium text-slate-700">{{ t('continuity.criticalFunction.secondBackup') }}<select v-model="row.second_backup_membership_id" class="mt-1 min-h-10 w-full border border-slate-300 px-2"><option value="">No second backup</option><option v-for="m in continuity.memberships" :key="m.id" :value="m.id">{{ m.email }}</option></select></label>
                            <label class="text-sm font-medium text-slate-700">{{ t('continuity.criticalFunction.minimumResources') }}<input v-model="row.minimum_resources" required class="mt-1 min-h-10 w-full border border-slate-300 px-2" placeholder="e.g. Laptop, phone, approved access" /></label>
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between"><h3 class="font-semibold">Restricted Emergency Access</h3><button type="button" class="text-xs font-semibold underline" @click="addEmergencyAccess">Add access plan</button></div>
                        <div v-for="(row, index) in plan.emergency_access" :key="index" class="mt-3 grid gap-2 border-l-2 border-slate-300 pl-3 md:grid-cols-3">
                            <input v-model="row.system_asset" required class="min-h-10 border border-slate-300 px-2" placeholder="System / asset" />
                            <select v-model="row.primary_access_membership_id" class="min-h-10 border border-slate-300 px-2"><option v-for="m in continuity.memberships" :key="m.id" :value="m.id">{{ m.email }}</option></select>
                            <select v-model="row.backup_access_membership_id" required class="min-h-10 border border-slate-300 px-2"><option value="">Backup required</option><option v-for="m in continuity.memberships" :key="m.id" :value="m.id">{{ m.email }}</option></select>
                            <input v-model="row.access_level" required class="min-h-10 border border-slate-300 px-2" placeholder="Access level / role" />
                            <textarea v-model="row.emergency_access_procedure" required class="min-h-20 border border-slate-300 p-2" placeholder="Procedure — references only" />
                            <input v-model="row.secure_storage_reference" class="min-h-10 border border-slate-300 px-2" placeholder="Secure storage reference" />
                        </div>
                        <p class="mt-2 text-xs font-semibold text-amber-800">Never store passwords, PINs, OTPs, tokens, credentials or recovery secrets here.</p>
                    </div>

                    <div class="grid gap-6 xl:grid-cols-2">
                        <div><div class="flex items-center justify-between"><h3 class="font-semibold">Interim Authority Plans</h3><button type="button" class="text-xs font-semibold underline" @click="addInterimPlan">Add plan</button></div><div v-for="(row, index) in plan.interim_authority_plans" :key="index" class="mt-3 grid gap-2 border border-slate-200 p-3"><select v-model="row.operations_role_id" class="min-h-10 border border-slate-300 px-2"><option v-for="role in continuity.operations_roles" :key="role.id" :value="role.id">{{ role.name }}</option></select><select v-model="row.interim_membership_id" class="min-h-10 border border-slate-300 px-2"><option v-for="m in continuity.memberships" :key="m.id" :value="m.id">{{ m.email }}</option></select><input v-model="row.trigger" required class="min-h-10 border border-slate-300 px-2" placeholder="Trigger" /><input v-model="row.governance_decision_type" required class="min-h-10 border border-slate-300 px-2" placeholder="Governance decision type" /><input v-model="row.decision_limit" required class="min-h-10 border border-slate-300 px-2" placeholder="Decision limit" /><input v-model.number="row.maximum_interim_hours" type="number" min="1" class="min-h-10 border border-slate-300 px-2" /></div><p class="mt-2 text-xs font-semibold text-amber-800">Planning record only. Actual authority requires the governed F6A Emergency Authority workflow.</p></div>
                        <div><div class="flex items-center justify-between"><h3 class="font-semibold">Successor Candidates</h3><button type="button" class="text-xs font-semibold underline" @click="addSuccessor">Add successor</button></div><div v-for="(row, index) in plan.successors" :key="index" class="mt-3 grid gap-2 border border-slate-200 p-3"><select v-model="row.operations_role_id" class="min-h-10 border border-slate-300 px-2"><option v-for="role in continuity.operations_roles" :key="role.id" :value="role.id">{{ role.name }}</option></select><select v-model="row.current_owner_membership_id" class="min-h-10 border border-slate-300 px-2"><option v-for="m in continuity.memberships" :key="m.id" :value="m.id">{{ m.email }}</option></select><select v-model="row.candidate_membership_id" class="min-h-10 border border-slate-300 px-2"><option v-for="m in continuity.memberships" :key="m.id" :value="m.id">{{ m.email }}</option></select><select v-model="row.readiness_level" class="min-h-10 border border-slate-300 px-2"><option value="not_ready">Not ready</option><option value="developing">Developing</option><option value="ready">Ready</option><option value="ready_now">Ready now</option></select><input v-model="row.skills_gap" class="min-h-10 border border-slate-300 px-2" placeholder="Skills gap" /><OptionalTemporalInput v-model="row.target_ready_date" type="date" class="min-h-10 border border-slate-300 px-2" /></div><p class="mt-2 text-xs text-slate-500">Successor is a long-term candidate and never mutates Ownership.</p></div>
                    </div>

                    <div class="grid gap-6 xl:grid-cols-2">
                        <div><div class="flex items-center justify-between"><h3 class="font-semibold">Communication Steps</h3><button type="button" class="text-xs font-semibold underline" @click="addCommunication">Add step</button></div><div v-for="(row, index) in plan.communication_steps" :key="index" class="mt-3 grid gap-2"><input v-model="row.event_type" required class="min-h-10 border border-slate-300 px-2" placeholder="Event" /><input v-model="row.stakeholder" required class="min-h-10 border border-slate-300 px-2" placeholder="Stakeholder" /><input v-model="row.channel" required class="min-h-10 border border-slate-300 px-2" placeholder="Channel" /><input v-model="row.timing" required class="min-h-10 border border-slate-300 px-2" placeholder="Timing" /><label class="text-xs"><input v-model="row.governance_approval_may_be_required" type="checkbox" /> Governance approval may be required</label></div></div>
                        <div><div class="flex items-center justify-between"><h3 class="font-semibold">Recovery Actions</h3><button type="button" class="text-xs font-semibold underline" @click="addRecovery">Add action</button></div><div v-for="(row, index) in plan.recovery_actions" :key="index" class="mt-3 grid gap-2"><select v-model="row.timeline_band" class="min-h-10 border border-slate-300 px-2"><option value="0_24_hours">0–24 hours</option><option value="1_7_days">1–7 days</option><option value="7_30_days">7–30 days</option></select><input v-model="row.action" required class="min-h-10 border border-slate-300 px-2" placeholder="Action" /><select v-model="row.operations_role_id" class="min-h-10 border border-slate-300 px-2"><option v-for="role in continuity.operations_roles" :key="role.id" :value="role.id">{{ role.name }}</option></select></div></div>
                    </div>

                    <button type="submit" :disabled="plan.processing" class="min-h-11 bg-slate-950 px-5 text-sm font-semibold text-white disabled:opacity-50">Save Draft</button>
                </form>
            </details>

            <section class="mt-8 grid gap-6 xl:grid-cols-2">
                <div>
                    <h2 class="text-lg font-bold">Continuity Tests</h2>
                    <div class="mt-3 space-y-2"><article v-for="row in continuity.tests" :key="row.id" class="border border-slate-200 p-4"><div class="flex items-start justify-between gap-3"><div><p class="font-semibold">{{ row.scenario_name }}</p><p class="mt-1 text-xs text-slate-500">Owner {{ memberLabel(row.owner_membership_id) }} · next {{ row.next_test_date ?? '—' }}</p></div><span class="border border-slate-300 px-2 py-1 text-xs">{{ humanize(row.result) }}</span></div><p class="mt-2 text-sm text-slate-700">{{ row.scenario }}</p><div v-if="continuity.permissions.manage && !row.completed_at" class="mt-3 flex flex-wrap gap-3"><button v-if="row.result === 'planned'" class="text-xs font-semibold underline" @click="post('/continuity/tests/' + row.id + '/result', { result: 'running' })">Start</button><button class="text-xs font-semibold underline" @click="post('/continuity/tests/' + row.id + '/result', { result: 'passed' })">Pass</button><button class="text-xs font-semibold underline" @click="post('/continuity/tests/' + row.id + '/result', { result: 'partial' })">Partial</button><button class="text-xs font-semibold text-red-700 underline" @click="post('/continuity/tests/' + row.id + '/result', { result: 'failed' })">Fail</button></div></article><p v-if="continuity.tests.length === 0" class="text-sm text-slate-500">No Continuity Tests yet.</p></div>
                    <details v-if="continuity.permissions.manage && continuity.current" class="mt-3 border border-slate-200"><summary class="cursor-pointer px-4 py-3 text-sm font-semibold">Plan continuity test</summary><form class="grid gap-2 border-t border-slate-200 p-4" @submit.prevent="testForm.post('/continuity/tests', { preserveScroll: true })"><input v-model="testForm.scenario_name" required class="min-h-10 border border-slate-300 px-2" placeholder="Scenario name" /><textarea v-model="testForm.scenario" required class="border border-slate-300 p-2" placeholder="Test scenario" /><select v-model="testForm.owner_membership_id" class="min-h-10 border border-slate-300 px-2"><option v-for="m in continuity.memberships" :key="m.id" :value="m.id">{{ m.email }}</option></select><OptionalTemporalInput v-model="testForm.next_test_date" type="date" class="min-h-10 border border-slate-300 px-2" /><button type="submit" class="min-h-10 bg-slate-950 px-4 text-sm font-semibold text-white">Plan Test</button></form></details>
                </div>

                <div>
                    <h2 class="text-lg font-bold">Emergency Access Activations</h2>
                    <p class="mt-1 text-sm text-slate-600">Activation is temporary and restricted. It never creates PermissionGrant or Governance authority.</p>
                    <div class="mt-3 space-y-2"><article v-for="row in continuity.activations" :key="row.id" class="border border-slate-200 p-4"><div class="flex items-start justify-between gap-3"><div><p class="font-semibold">{{ accessLabel(row.emergency_access_record_id) }}</p><p class="mt-1 text-xs text-slate-500">{{ row.starts_at }} → {{ row.expires_at }}</p></div><span class="border border-slate-300 px-2 py-1 text-xs">{{ humanize(row.status) }}</span></div><p class="mt-2 text-sm">{{ row.trigger }}</p><p v-if="row.required_decision_type" class="mt-1 text-xs font-semibold text-amber-800">Bound to governed Emergency Authority: {{ row.required_decision_type }}</p><div v-if="continuity.permissions.manage" class="mt-3 flex gap-3"><button v-if="row.status === 'requested'" class="text-xs font-semibold underline" @click="post('/continuity/emergency-access/' + row.id + '/activate', { expected_revision: row.revision })">Activate</button><button v-if="row.status === 'active'" class="text-xs font-semibold underline" @click="post('/continuity/emergency-access/' + row.id + '/end', { expected_revision: row.revision, target: 'closed' })">Close</button><button v-if="row.status === 'active'" class="text-xs font-semibold text-red-700 underline" @click="post('/continuity/emergency-access/' + row.id + '/end', { expected_revision: row.revision, target: 'revoked' })">Revoke</button></div></article><p v-if="continuity.activations.length === 0" class="text-sm text-slate-500">No authorized Emergency Access activations.</p></div>
                    <details v-if="continuity.permissions.manage && continuity.current?.emergency_access.length" class="mt-3 border border-slate-200"><summary class="cursor-pointer px-4 py-3 text-sm font-semibold">Request emergency access activation</summary><form class="grid gap-2 border-t border-slate-200 p-4" @submit.prevent="activationForm.post('/continuity/emergency-access', { preserveScroll: true })"><select v-model="activationForm.emergency_access_record_id" class="min-h-10 border border-slate-300 px-2"><option v-for="row in continuity.current?.emergency_access ?? []" :key="row.id" :value="row.id">{{ row.system_asset }}</option></select><input v-model="activationForm.trigger" required class="min-h-10 border border-slate-300 px-2" placeholder="Emergency trigger" /><textarea v-model="activationForm.reason" required class="border border-slate-300 p-2" placeholder="Reason" /><input v-model="activationForm.starts_at" type="datetime-local" required class="min-h-10 border border-slate-300 px-2" /><input v-model="activationForm.expires_at" type="datetime-local" required class="min-h-10 border border-slate-300 px-2" /><details class="rounded-lg border border-slate-200 bg-slate-50 p-3"><summary class="cursor-pointer text-xs font-semibold text-slate-700">{{ t('governance.advancedDetails') }}</summary><div class="mt-3 grid gap-2"><input v-model="activationForm.required_decision_type" class="min-h-10 border border-slate-300 px-2" placeholder="Governance Decision Type — only if authority required" /><input v-model="activationForm.emergency_authority_grant_id" class="min-h-10 border border-slate-300 px-2" placeholder="Exact governed Emergency Authority Grant ID" /><p class="text-xs font-semibold text-amber-800">If Governance authority is required, the exact governed grant must match grantee, decision type, scope and time window.</p></div></details><button type="submit" class="min-h-10 bg-slate-950 px-4 text-sm font-semibold text-white">Request Activation</button></form></details>
                </div>
            </section>

            <details v-if="continuity.permissions.manage && continuity.current" class="pbr-surface mt-6">
                <summary class="cursor-pointer px-4 py-3 font-semibold">Create Follow-up Action</summary>
                <form class="grid gap-2 border-t border-slate-200 p-4 md:grid-cols-2 xl:grid-cols-4" @submit.prevent="actionForm.post('/continuity/actions', { preserveScroll: true })">
                    <select v-model="actionForm.source_type" class="min-h-10 border border-slate-300 px-2" @change="actionForm.source_id = ''"><option value="continuity_critical_function">Critical function</option><option value="continuity_test">Continuity test</option><option value="emergency_access_activation">Emergency access activation</option></select>
                    <select v-model="actionForm.source_id" required class="min-h-10 border border-slate-300 px-2"><option value="">Choose source record</option><option v-for="source in actionSources" :key="source.id" :value="source.id">{{ source.label }}</option></select>
                    <select v-model="actionForm.operations_role_id" class="min-h-10 border border-slate-300 px-2"><option v-for="role in continuity.operations_roles" :key="role.id" :value="role.id">{{ role.name }}</option></select>
                    <select v-model="actionForm.assigned_membership_id" class="min-h-10 border border-slate-300 px-2"><option v-for="m in continuity.memberships" :key="m.id" :value="m.id">{{ m.email }}</option></select>
                    <input v-model="actionForm.title" required class="min-h-10 border border-slate-300 px-2 md:col-span-2" placeholder="Action title" />
                    <OptionalTemporalInput v-model="actionForm.due_at" type="datetime-local" class="min-h-10 border border-slate-300 px-2" />
                    <button type="submit" class="min-h-10 bg-slate-950 px-4 text-sm font-semibold text-white">Create Action</button>
                </form>
            </details>
            </div>
        </main>
    </AuthenticatedLayout>
</template>
