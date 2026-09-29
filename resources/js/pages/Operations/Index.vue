<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import { useI18n } from '../../i18n/useI18n';

type Membership = { id: string; email: string };
type Role = {
    id: string;
    role_key: string;
    name: string;
    function_name: string;
    purpose: string;
    responsibilities: string;
    operational_authority: string | null;
    reports_to_role_key: string | null;
    reporting_frequency: string | null;
    review_frequency: string;
    status: string;
};
type Assignment = {
    operations_role_id: string;
    membership_id: string;
    assignment_type: string;
};
type RaciItem = { id: string; sequence: number; activity: string; result: string | null };
type RaciAssignment = {
    operations_raci_item_id: string;
    operations_role_id: string;
    responsibility: string;
};
type Kpi = {
    id: string;
    operations_role_id: string;
    name: string;
    target: string;
    measurement_method: string;
    frequency: string;
    current_status: string;
};
type Action = {
    id: string;
    operations_role_id: string;
    title: string;
    description: string | null;
    status: string;
    blocked_reason: string | null;
    assigned_membership_id: string;
    due_at: string | null;
};
type VersionRow = {
    id: string;
    version_number: number;
    revision: number;
    frozen_at: string | null;
    effective_from: string | null;
    state: string | null;
};

const props = defineProps<{
    operations: {
        business: { id: string; name: string };
        permissions: { manage: boolean };
        memberships: Membership[];
        versions: VersionRow[];
        current: null | {
            id: string;
            version_number: number;
            effective_from: string | null;
            roles: Role[];
            assignments: Assignment[];
            raci_items: RaciItem[];
            raci_assignments: RaciAssignment[];
            kpis: Kpi[];
            actions: Action[];
        };
    };
}>();

const { t } = useI18n();
const firstMembership = props.operations.memberships[0]?.id ?? '';

const newRole = () => ({
    role_key: 'operations_lead',
    name: 'Operations Lead',
    function_name: 'Operations',
    purpose: 'Own operational delivery for the assigned function.',
    responsibilities: 'Plan, coordinate, report and close assigned work.',
    operational_authority: 'May coordinate execution within approved governance and budget limits.',
    reports_to_role_key: '',
    report_type: 'Operating update',
    reporting_frequency: 'Weekly',
    meeting_frequency: 'Weekly',
    review_frequency: 'Quarterly',
    assignments: [
        { membership_id: firstMembership, assignment_type: 'primary' },
    ],
});

const draft = useForm({
    effective_from: new Date().toISOString().slice(0, 10),
    review_due_at: '',
    organization_name: props.operations.business.name,
    notes: '',
    roles: [newRole()],
    raci: [] as Array<{
        activity: string;
        result: string;
        assignments: Array<{ role_key: string; responsibility: string }>;
    }>,
    kpis: [] as Array<{
        role_key: string;
        name: string;
        target: string;
        measurement_method: string;
        frequency: string;
        current_status: string;
    }>,
});

const newRaci = () => {
    const roleKey = draft.roles[0]?.role_key ?? '';
    draft.raci.push({
        activity: '',
        result: '',
        assignments: [{ role_key: roleKey, responsibility: 'AR' }],
    });
};

const newKpi = () => {
    draft.kpis.push({
        role_key: draft.roles[0]?.role_key ?? '',
        name: '',
        target: '',
        measurement_method: '',
        frequency: 'Monthly',
        current_status: 'not_started',
    });
};

const actionForm = useForm({
    operations_role_id: props.operations.current?.roles[0]?.id ?? '',
    assigned_membership_id: firstMembership,
    title: '',
    description: '',
    due_at: '',
});

const blockedReasons = reactive<Record<string, string>>({});

const contentReview = useForm({
    target: 'under_review',
});
const contentReviewVersionId = ref('');

const advanceContentReview = (
    versionId: string,
    target: 'under_review' | 'approved' | 'changes_requested',
) => {
    contentReviewVersionId.value = versionId;
    contentReview.clearErrors();
    contentReview.target = target;
    contentReview.post(
        `/operations/register/${versionId}/content-review`,
        { preserveScroll: true },
    );
};

const roles = computed(() => props.operations.current?.roles ?? []);
const assignmentsFor = (roleId: string) =>
    props.operations.current?.assignments.filter((row) => row.operations_role_id === roleId) ?? [];
const memberEmail = (id: string) =>
    props.operations.memberships.find((row) => row.id === id)?.email ?? id;
const roleName = (id: string) =>
    props.operations.current?.roles.find((row) => row.id === id)?.name ?? id;
const raciFor = (itemId: string) =>
    props.operations.current?.raci_assignments.filter((row) => row.operations_raci_item_id === itemId) ?? [];

type PostData = NonNullable<Parameters<typeof router.post>[1]>;

const post = (url: string, data: PostData = {}) =>
    router.post(url, data, { preserveScroll: true });

const formError = (errors: object, key: string) =>
    (errors as Record<string, string | undefined>)[key];
</script>

<template>
    <Head :title="t('operations.title')" />
    <AuthenticatedLayout>
        <main class="mx-auto w-full max-w-[1500px] px-4 py-6 sm:px-6 lg:px-8">
            <header class="border-b border-slate-200 pb-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ operations.business.name }}</p>
                        <h1 class="mt-2 text-2xl font-bold">{{ t('operations.title') }}</h1>
                        <p class="mt-2 max-w-4xl text-sm leading-6 text-slate-600">{{ t('operations.description') }}</p>
                    </div>
                    <Link href="/governance" class="inline-flex min-h-11 items-center border border-slate-300 px-4 text-sm font-semibold">
                        Governance
                    </Link>
                </div>
                <p class="mt-5 border-l-4 border-slate-800 bg-slate-100 px-4 py-3 text-sm font-semibold text-slate-800">
                    {{ t('operations.boundary') }}
                </p>
            </header>

            <section class="mt-6">
                <div class="flex items-end justify-between">
                    <div>
                        <h2 class="text-lg font-bold">{{ t('operations.currentRegister') }}</h2>
                        <p class="mt-1 text-sm text-slate-600">Effective record only. Drafts and proposals never change current responsibility.</p>
                    </div>
                    <span v-if="operations.current" class="text-xs font-semibold text-slate-500">Version {{ operations.current.version_number }}</span>
                </div>

                <div v-if="!operations.current" class="mt-4 border border-dashed border-slate-300 p-5 text-sm text-slate-500">
                    {{ t('operations.noCurrentRegister') }}
                </div>

                <div v-else class="mt-4 overflow-x-auto">
                    <table class="min-w-full border-collapse text-left text-sm">
                        <thead><tr class="border-b border-slate-300 text-slate-600"><th class="px-3 py-3">Role</th><th class="px-3 py-3">Purpose / responsibility</th><th class="px-3 py-3">Primary / backup</th><th class="px-3 py-3">Reporting</th></tr></thead>
                        <tbody>
                            <tr v-for="role in roles" :key="role.id" class="border-b border-slate-200 align-top">
                                <td class="px-3 py-4"><p class="font-semibold">{{ role.name }}</p><p class="text-xs text-slate-500">{{ role.function_name }} · {{ role.role_key }}</p></td>
                                <td class="max-w-xl px-3 py-4"><p>{{ role.purpose }}</p><p class="mt-2 text-xs text-slate-600">{{ role.responsibilities }}</p></td>
                                <td class="px-3 py-4 text-xs"><p v-for="a in assignmentsFor(role.id)" :key="a.membership_id + '-' + a.assignment_type"><strong>{{ a.assignment_type }}</strong> · {{ memberEmail(a.membership_id) }}</p></td>
                                <td class="px-3 py-4 text-xs">{{ role.reporting_frequency ?? '—' }} · review {{ role.review_frequency }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="operations.current" class="mt-8 grid gap-6 xl:grid-cols-2">
                <div>
                    <h2 class="text-lg font-bold">RACI</h2>
                    <div class="mt-3 space-y-3">
                        <article v-for="item in operations.current.raci_items" :key="item.id" class="border border-slate-200 p-4">
                            <p class="font-semibold">{{ item.activity }}</p>
                            <p v-if="item.result" class="mt-1 text-xs text-slate-600">{{ item.result }}</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <span v-for="a in raciFor(item.id)" :key="a.operations_role_id + '-' + a.responsibility" class="border border-slate-300 px-2 py-1 text-xs">
                                    {{ a.responsibility }} · {{ roleName(a.operations_role_id) }}
                                </span>
                            </div>
                        </article>
                        <p v-if="operations.current.raci_items.length === 0" class="text-sm text-slate-500">No RACI items.</p>
                    </div>
                </div>
                <div>
                    <h2 class="text-lg font-bold">KPI Register</h2>
                    <div class="mt-3 overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead><tr class="border-b border-slate-300 text-slate-600"><th class="px-3 py-3">KPI</th><th class="px-3 py-3">Owner</th><th class="px-3 py-3">Target</th><th class="px-3 py-3">Status</th></tr></thead>
                            <tbody>
                                <tr v-for="kpi in operations.current.kpis" :key="kpi.id" class="border-b border-slate-200"><td class="px-3 py-3 font-semibold">{{ kpi.name }}</td><td class="px-3 py-3">{{ roleName(kpi.operations_role_id) }}</td><td class="px-3 py-3">{{ kpi.target }}</td><td class="px-3 py-3">{{ kpi.current_status }}</td></tr>
                                <tr v-if="operations.current.kpis.length === 0"><td colspan="4" class="px-3 py-5 text-slate-500">No KPI rows.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <details v-if="operations.permissions.manage" class="mt-8 border border-slate-200">
                <summary class="cursor-pointer px-5 py-4 font-semibold">Create Operations Register Draft / Amendment</summary>
                <form class="space-y-6 border-t border-slate-200 p-5" @submit.prevent="draft.post('/operations/register', { preserveScroll: true })">
                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <label class="text-sm font-medium">Effective from<input v-model="draft.effective_from" type="date" required class="mt-1 min-h-11 w-full border border-slate-300 px-3" /></label>
                        <label class="text-sm font-medium">Review due<input v-model="draft.review_due_at" type="date" class="mt-1 min-h-11 w-full border border-slate-300 px-3" /></label>
                        <label class="text-sm font-medium">Organization<input v-model="draft.organization_name" required class="mt-1 min-h-11 w-full border border-slate-300 px-3" /></label>
                        <label class="text-sm font-medium">Notes<input v-model="draft.notes" class="mt-1 min-h-11 w-full border border-slate-300 px-3" /></label>
                    </div>

                    <div class="space-y-4">
                        <div class="flex justify-between"><h3 class="font-semibold">Roles & Role Assignments</h3><button type="button" class="min-h-9 border border-slate-300 px-3 text-xs font-semibold" @click="draft.roles.push(newRole())">Add role</button></div>
                        <article v-for="(role, ri) in draft.roles" :key="ri" class="border border-slate-200 bg-slate-50 p-4">
                            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                <input v-model="role.role_key" required class="min-h-10 border border-slate-300 px-2" placeholder="Role key" />
                                <input v-model="role.name" required class="min-h-10 border border-slate-300 px-2" placeholder="Role name" />
                                <input v-model="role.function_name" required class="min-h-10 border border-slate-300 px-2" placeholder="Function" />
                                <input v-model="role.review_frequency" required class="min-h-10 border border-slate-300 px-2" placeholder="Review frequency" />
                                <input v-model="role.reports_to_role_key" class="min-h-10 border border-slate-300 px-2" placeholder="Reports to role key" />
                                <input v-model="role.reporting_frequency" class="min-h-10 border border-slate-300 px-2" placeholder="Reporting frequency" />
                                <input v-model="role.report_type" class="min-h-10 border border-slate-300 px-2" placeholder="Report type" />
                                <input v-model="role.meeting_frequency" class="min-h-10 border border-slate-300 px-2" placeholder="Meeting frequency" />
                            </div>
                            <div class="mt-3 grid gap-3 lg:grid-cols-3">
                                <textarea v-model="role.purpose" required class="border border-slate-300 p-3" placeholder="Purpose" />
                                <textarea v-model="role.responsibilities" required class="border border-slate-300 p-3" placeholder="Responsibilities" />
                                <textarea v-model="role.operational_authority" class="border border-slate-300 p-3" placeholder="Operational authority — delivery boundary only" />
                            </div>
                            <div class="mt-3 grid gap-2 md:grid-cols-2">
                                <div v-for="(a, ai) in role.assignments" :key="ai" class="flex gap-2">
                                    <select v-model="a.membership_id" class="min-h-10 flex-1 border border-slate-300 px-2 text-xs"><option v-for="m in operations.memberships" :key="m.id" :value="m.id">{{ m.email }}</option></select>
                                    <select v-model="a.assignment_type" class="min-h-10 border border-slate-300 px-2 text-xs"><option value="primary">Primary</option><option value="backup">Backup</option></select>
                                    <button type="button" class="text-xs font-semibold text-red-700" @click="role.assignments.splice(ai, 1)">Remove</button>
                                </div>
                            </div>
                            <div class="mt-3 flex gap-3"><button v-if="role.assignments.length < 2" type="button" class="text-xs font-semibold underline" @click="role.assignments.push({ membership_id: firstMembership, assignment_type: 'backup' })">Add backup</button><button type="button" class="text-xs font-semibold text-red-700" @click="draft.roles.length > 1 && draft.roles.splice(ri, 1)">Remove role</button></div>
                        </article>
                    </div>

                    <div>
                        <div class="flex justify-between"><h3 class="font-semibold">RACI</h3><button type="button" class="min-h-9 border border-slate-300 px-3 text-xs font-semibold" @click="newRaci">Add RACI item</button></div>
                        <div v-for="(item, ii) in draft.raci" :key="ii" class="mt-3 grid gap-2 border-l-2 border-slate-300 pl-3 md:grid-cols-3">
                            <input v-model="item.activity" class="min-h-10 border border-slate-300 px-2" placeholder="Activity" />
                            <input v-model="item.result" class="min-h-10 border border-slate-300 px-2" placeholder="Expected result" />
                            <div class="space-y-2">
                                <div v-for="(a, ai) in item.assignments" :key="ai" class="flex gap-2">
                                    <select v-model="a.role_key" class="min-h-10 flex-1 border border-slate-300 px-2 text-xs"><option v-for="r in draft.roles" :key="r.role_key" :value="r.role_key">{{ r.name }}</option></select>
                                    <select v-model="a.responsibility" class="min-h-10 border border-slate-300 px-2 text-xs"><option value="A">A</option><option value="R">R</option><option value="AR">A/R</option><option value="C">C</option><option value="I">I</option></select>
                                    <button type="button" class="text-xs text-red-700" @click="item.assignments.splice(ai, 1)">X</button>
                                </div>
                                <button type="button" class="text-xs font-semibold underline" @click="item.assignments.push({ role_key: draft.roles[0]?.role_key ?? '', responsibility: 'R' })">Add assignment</button>
                            </div>
                        </div>
                    </div>

                    <div>
                        <div class="flex justify-between"><h3 class="font-semibold">KPI</h3><button type="button" class="min-h-9 border border-slate-300 px-3 text-xs font-semibold" @click="newKpi">Add KPI</button></div>
                        <div v-for="(kpi, ki) in draft.kpis" :key="ki" class="mt-3 grid gap-2 md:grid-cols-3 xl:grid-cols-6">
                            <select v-model="kpi.role_key" class="min-h-10 border border-slate-300 px-2 text-xs"><option v-for="r in draft.roles" :key="r.role_key" :value="r.role_key">{{ r.name }}</option></select>
                            <input v-model="kpi.name" class="min-h-10 border border-slate-300 px-2" placeholder="KPI" />
                            <input v-model="kpi.target" class="min-h-10 border border-slate-300 px-2" placeholder="Target" />
                            <input v-model="kpi.measurement_method" class="min-h-10 border border-slate-300 px-2" placeholder="Measurement" />
                            <input v-model="kpi.frequency" class="min-h-10 border border-slate-300 px-2" placeholder="Frequency" />
                            <select v-model="kpi.current_status" class="min-h-10 border border-slate-300 px-2 text-xs"><option value="not_started">Not started</option><option value="on_track">On track</option><option value="at_risk">At risk</option><option value="off_track">Off track</option><option value="achieved">Achieved</option></select>
                        </div>
                    </div>

                    <p v-if="formError(draft.errors, 'operations')" class="text-sm text-red-700">{{ formError(draft.errors, 'operations') }}</p>
                    <button type="submit" class="min-h-11 bg-slate-950 px-4 text-sm font-semibold text-white">Create versioned Operations draft</button>
                </form>
            </details>

            <section class="mt-8 border-t border-slate-200 pt-6">
                <h2 class="text-lg font-bold">Operations Version Workflow</h2>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead><tr class="border-b border-slate-300 text-slate-600"><th class="px-3 py-3">Version</th><th class="px-3 py-3">State</th><th class="px-3 py-3">Controls</th></tr></thead>
                        <tbody>
                            <tr
                                v-for="v in operations.versions"
                                :key="v.id"
                                class="border-b border-slate-200 align-top"
                            >
                                <td class="px-3 py-3 font-semibold">
                                    v{{ v.version_number }}
                                </td>
                                <td class="px-3 py-3">
                                    {{ v.state ?? (v.frozen_at ? 'frozen' : 'draft') }}
                                </td>
                                <td class="px-3 py-3">
                                    <div
                                        v-if="operations.permissions.manage"
                                        class="flex flex-wrap gap-2"
                                    >
                                        <button
                                            v-if="v.state === 'draft' && !v.frozen_at"
                                            type="button"
                                            class="min-h-9 border border-slate-300 px-3 text-xs font-semibold"
                                            @click="
                                                post(
                                                    '/operations/register/' + v.id + '/submit',
                                                    { expected_revision: v.revision },
                                                )
                                            "
                                        >
                                            Freeze + Proposal
                                        </button>

                                        <button
                                            v-if="v.state === 'ready_for_review'"
                                            type="button"
                                            :disabled="contentReview.processing"
                                            class="min-h-9 border border-slate-300 px-3 text-xs font-semibold disabled:opacity-50"
                                            @click="
                                                advanceContentReview(
                                                    v.id,
                                                    'under_review',
                                                )
                                            "
                                        >
                                            Under Review
                                        </button>

                                        <template v-if="v.state === 'under_review'">
                                            <button
                                                type="button"
                                                :disabled="contentReview.processing"
                                                class="min-h-9 bg-slate-900 px-3 text-xs font-semibold text-white disabled:opacity-50"
                                                @click="
                                                    advanceContentReview(
                                                        v.id,
                                                        'approved',
                                                    )
                                                "
                                            >
                                                Content Approved
                                            </button>
                                            <button
                                                type="button"
                                                :disabled="contentReview.processing"
                                                class="min-h-9 border border-slate-300 px-3 text-xs font-semibold disabled:opacity-50"
                                                @click="
                                                    advanceContentReview(
                                                        v.id,
                                                        'changes_requested',
                                                    )
                                                "
                                            >
                                                Request Changes
                                            </button>
                                        </template>
                                    </div>

                                    <p
                                        v-if="
                                            contentReviewVersionId === v.id
                                            && formError(
                                                contentReview.errors,
                                                'operations',
                                            )
                                        "
                                        class="mt-2 text-sm text-red-700"
                                    >
                                        {{
                                            formError(
                                                contentReview.errors,
                                                'operations',
                                            )
                                        }}
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="operations.current" class="mt-8 border-t border-slate-200 pt-6">
                <h2 class="text-lg font-bold">Operational Actions</h2>
                <form v-if="operations.permissions.manage" class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-5" @submit.prevent="actionForm.post('/operations/actions', { preserveScroll: true, onSuccess: () => actionForm.reset('title', 'description', 'due_at') })">
                    <select v-model="actionForm.operations_role_id" class="min-h-11 border border-slate-300 px-3"><option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option></select>
                    <select v-model="actionForm.assigned_membership_id" class="min-h-11 border border-slate-300 px-3"><option v-for="m in operations.memberships" :key="m.id" :value="m.id">{{ m.email }}</option></select>
                    <input v-model="actionForm.title" required class="min-h-11 border border-slate-300 px-3" placeholder="Action title" />
                    <input v-model="actionForm.due_at" type="datetime-local" class="min-h-11 border border-slate-300 px-3" />
                    <button type="submit" class="min-h-11 bg-slate-950 px-4 text-sm font-semibold text-white">Create Action</button>
                </form>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead><tr class="border-b border-slate-300 text-slate-600"><th class="px-3 py-3">Action</th><th class="px-3 py-3">Role</th><th class="px-3 py-3">Assignee</th><th class="px-3 py-3">State</th><th class="px-3 py-3">Controls</th></tr></thead>
                        <tbody>
                            <tr v-for="a in operations.current.actions" :key="a.id" class="border-b border-slate-200"><td class="px-3 py-3 font-semibold">{{ a.title }}</td><td class="px-3 py-3">{{ roleName(a.operations_role_id) }}</td><td class="px-3 py-3">{{ memberEmail(a.assigned_membership_id) }}</td><td class="px-3 py-3">{{ a.status }}</td><td class="px-3 py-3"><div v-if="operations.permissions.manage && !['completed','cancelled'].includes(a.status)" class="flex flex-wrap gap-2"><button type="button" class="min-h-9 border border-slate-300 px-3 text-xs font-semibold" @click="post('/operations/actions/' + a.id + '/status', { status: 'in_progress' })">In progress</button><input v-model="blockedReasons[a.id]" class="min-h-9 border border-slate-300 px-2 text-xs" placeholder="Blocked reason" /><button type="button" class="min-h-9 border border-slate-300 px-3 text-xs font-semibold" :disabled="!blockedReasons[a.id]" @click="post('/operations/actions/' + a.id + '/status', { status: 'blocked', blocked_reason: blockedReasons[a.id] })">Blocked</button><button type="button" class="min-h-9 bg-slate-900 px-3 text-xs font-semibold text-white" @click="post('/operations/actions/' + a.id + '/status', { status: 'completed' })">Complete</button></div></td></tr>
                            <tr v-if="operations.current.actions.length === 0"><td colspan="5" class="px-3 py-5 text-slate-500">No operational actions.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </AuthenticatedLayout>
</template>
