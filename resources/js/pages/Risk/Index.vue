<script setup lang="ts">
import OptionalTemporalInput from '../../components/OptionalTemporalInput.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import { useI18n } from '../../i18n/useI18n';

type Membership = { id: string; email: string };
type Role = { id: string; role_key: string; name: string };
type VersionRow = { id: string; version_number: number; revision: number; frozen_at: string | null; effective_from: string | null; review_due_at: string | null; state: string | null };
type RiskRow = { id: string; category: string; title: string; description: string; owner_membership_id: string | null; likelihood: number; impact: number; risk_score: number; risk_level: string; warning_indicator: string | null; mitigation: string; response_plan: string; review_date: string | null; status: string; confidentiality: string };
type ProtectionRow = { id: string; risk_item_id: string | null; protection_type: string; covered_subject: string; provider: string | null; policy_reference: string | null; renewal_date: string | null; owner_membership_id: string; status: string; confidentiality: string };
type IncidentRow = { id: string; risk_item_id: string | null; incident_at: string; incident_type: string; description: string; business_impact: string; status: string; confidentiality: string; revision: number };
type TestRow = { id: string; risk_item_id: string | null; control_name: string; scenario: string; result: string; owner_membership_id: string; next_test_date: string | null; confidentiality: string; completed_at: string | null };
type CurrentRisk = { formal_record_version_id: string; version_number: number; effective_from: string | null; header: { risk_owner_membership_id: string; low_max_score: number; medium_max_score: number; high_max_score: number; review_frequency: string; notes: string | null }; risks: RiskRow[]; protections: ProtectionRow[] };

const props = defineProps<{
    risk: {
        business: { id: string; name: string };
        permissions: { manage: boolean };
        current: CurrentRisk | null;
        incidents: IncidentRow[];
        control_tests: TestRow[];
        versions: VersionRow[];
        memberships: Membership[];
        operations_roles: Role[];
        attention: { incidents: number; failed_tests: number; overdue_risks: number };
    };
}>();

const { t } = useI18n();
const today = new Date().toISOString().slice(0, 10);
const firstMember = props.risk.memberships[0]?.id ?? '';
const firstRole = props.risk.operations_roles[0]?.id ?? '';
const memberLabel = (id: string | null) => props.risk.memberships.find((row) => row.id === id)?.email ?? id ?? '—';
const riskLabel = (id: string | null) => props.risk.current?.risks.find((row) => row.id === id)?.title ?? (id ? 'Restricted / unavailable' : 'Unlinked');
const attentionTotal = computed(() => props.risk.attention.incidents + props.risk.attention.failed_tests + props.risk.attention.overdue_risks);
const humanize = (value: string | null): string => value ? value.replaceAll('_', ' ') : '—';
type PostData = NonNullable<Parameters<typeof router.post>[1]>;
const post = (url: string, data: PostData = {}) => router.post(url, data, { preserveScroll: true });

const governanceSync = useForm({});
const governanceSyncVersionId = ref('');
const syncGovernedDecision = (versionId: string): void => {
    governanceSyncVersionId.value = versionId;
    governanceSync.clearErrors();
    governanceSync.post(
        `/risk/register/${versionId}/sync-decision`,
        {
            preserveScroll: true,
            onSuccess: () => governanceSync.clearErrors(),
        },
    );
};

const register = useForm({
    effective_from: today,
    review_due_at: '',
    risk_owner_membership_id: firstMember,
    low_max_score: 4,
    medium_max_score: 9,
    high_max_score: 15,
    review_frequency: 'Quarterly',
    notes: '',
    risks: [{
        category: 'operational',
        title: '',
        description: '',
        operations_role_id: firstRole,
        owner_membership_id: firstMember,
        likelihood: 3,
        impact: 3,
        warning_indicator: '',
        mitigation: '',
        response_plan: '',
        review_date: '',
        status: 'active',
        confidentiality: 'standard',
    }],
    protections: [] as Array<{
        risk_index: number | null;
        protection_type: string;
        covered_subject: string;
        provider: string;
        policy_reference: string;
        coverage_amount_minor_units: number | null;
        currency: string;
        deductible_minor_units: number | null;
        main_exclusions: string;
        premium_minor_units: number | null;
        start_date: string;
        renewal_date: string;
        owner_membership_id: string;
        access_rule: string;
        protection_method: string;
        confidentiality_requirement: string;
        evidence_reference: string;
        review_date: string;
        status: string;
        confidentiality: string;
    }>,
});
const addRisk = () => register.risks.push({ category: 'operational', title: '', description: '', operations_role_id: firstRole, owner_membership_id: firstMember, likelihood: 3, impact: 3, warning_indicator: '', mitigation: '', response_plan: '', review_date: '', status: 'active', confidentiality: 'standard' });
const addProtection = () => register.protections.push({ risk_index: register.risks.length ? 0 : null, protection_type: 'insurance', covered_subject: '', provider: '', policy_reference: '', coverage_amount_minor_units: null, currency: 'USD', deductible_minor_units: null, main_exclusions: '', premium_minor_units: null, start_date: '', renewal_date: '', owner_membership_id: firstMember, access_rule: '', protection_method: '', confidentiality_requirement: '', evidence_reference: '', review_date: '', status: 'active', confidentiality: 'standard' });

const incident = useForm({ risk_item_id: '', incident_at: new Date().toISOString().slice(0, 16), incident_type: 'operational', description: '', business_impact: '', immediate_action: '', loss_amount_minor_units: null as number | null, currency: 'USD', confidentiality: 'standard' });
const controlTest = useForm({ risk_item_id: '', risk_protection_record_id: '', control_name: '', scenario: '', owner_membership_id: firstMember, next_test_date: '', confidentiality: 'standard' });
const actionForm = useForm({ source_type: 'risk_item', source_id: '', operations_role_id: firstRole, assigned_membership_id: firstMember, title: '', description: '', due_at: '' });
const actionSources = computed(() => {
    if (actionForm.source_type === 'risk_incident') {
        return props.risk.incidents.map((row) => ({
            id: row.id,
            label: `${row.incident_type} · ${row.incident_at.slice(0, 10)}`,
        }));
    }

    if (actionForm.source_type === 'risk_control_test') {
        return props.risk.control_tests.map((row) => ({
            id: row.id,
            label: row.control_name,
        }));
    }

    return (props.risk.current?.risks ?? []).map((row) => ({
        id: row.id,
        label: row.title,
    }));
});

const nextIncident = (row: IncidentRow): string | null => ({
    open: 'investigating',
    investigating: 'contained',
    contained: 'corrective_action',
    corrective_action: 'resolved',
    resolved: 'closed',
}[row.status] ?? null);
</script>

<template>
    <Head :title="t('risk.title')" />
    <AuthenticatedLayout>
        <main class="min-h-screen px-4 py-5 text-[var(--pbr-ink)] sm:px-6 sm:py-6 lg:px-8 lg:py-7">
            <div class="mx-auto w-full max-w-[1500px]">
            <header class="pbr-surface p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ risk.business.name }}</p>
                        <h1 class="mt-2 text-2xl font-bold text-slate-950">{{ t('risk.title') }}</h1>
                        <p class="mt-2 max-w-4xl text-sm leading-6 text-slate-600">{{ t('risk.description') }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Link href="/records/documents" class="inline-flex min-h-11 items-center border border-slate-300 px-4 text-sm font-semibold">Evidence / Vault</Link>
                        <Link href="/governance" class="inline-flex min-h-11 items-center border border-slate-300 px-4 text-sm font-semibold">Governance</Link>
                        <Link href="/continuity" class="inline-flex min-h-11 items-center border border-slate-300 px-4 text-sm font-semibold">Continuity</Link>
                    </div>
                </div>
                <p class="mt-5 rounded-[16px] border border-[#cfe1d3] bg-[#f3f8f4] px-4 py-3 text-sm font-bold text-[var(--pbr-green-dark)]">{{ t('risk.boundary') }}</p>
            </header>

            <section class="mt-5 grid gap-3 md:grid-cols-4">
                <div class="pbr-surface p-4"><p class="text-xs font-bold uppercase tracking-wide text-[var(--pbr-muted)]">Needs attention</p><p class="mt-2 text-2xl font-black">{{ attentionTotal }}</p><p class="mt-1 text-xs text-[var(--pbr-muted)]">Authorized records only</p></div>
                <div class="pbr-surface p-4"><p class="text-xs font-bold uppercase tracking-wide text-[var(--pbr-muted)]">Open incidents</p><p class="mt-2 text-2xl font-black">{{ risk.attention.incidents }}</p></div>
                <div class="pbr-surface p-4"><p class="text-xs font-bold uppercase tracking-wide text-[var(--pbr-muted)]">Failed tests</p><p class="mt-2 text-2xl font-black">{{ risk.attention.failed_tests }}</p></div>
                <div class="rounded-[18px] border border-[#e8d9ab] bg-[#fffaf0] p-4 shadow-[var(--pbr-shadow-xs)]"><p class="text-xs font-bold uppercase tracking-wide text-[#7d672d]">Overdue reviews</p><p class="mt-2 text-2xl font-black text-[#66531f]">{{ risk.attention.overdue_risks }}</p></div>
            </section>

            <section class="pbr-surface mt-6 p-5 sm:p-6">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div><h2 class="text-lg font-bold">Current Effective Risk Register</h2><p class="mt-1 text-sm text-slate-600">This Effective version is current truth. Amendments create a new version.</p></div>
                    <span v-if="risk.current" class="border border-slate-300 px-2 py-1 text-xs font-semibold">v{{ risk.current.version_number }} · Effective</span>
                </div>
                <div v-if="!risk.current" class="mt-4 border border-dashed border-slate-300 p-5 text-sm text-slate-500">No Effective Risk Register yet.</div>
                <template v-else>
                    <div class="mt-4 overflow-x-auto border border-slate-200">
                        <table class="min-w-full text-left text-sm">
                            <thead><tr class="border-b border-slate-200 bg-slate-50"><th class="px-3 py-3">Risk</th><th class="px-3 py-3">Owner</th><th class="px-3 py-3">Score</th><th class="px-3 py-3">Treatment / review</th><th class="px-3 py-3">Status</th></tr></thead>
                            <tbody>
                                <tr v-for="row in risk.current.risks" :key="row.id" class="border-b border-slate-100 align-top">
                                    <td class="px-3 py-3"><p class="font-semibold">{{ row.title }}</p><p class="mt-1 text-xs text-slate-500">{{ humanize(row.category) }}<span v-if="row.confidentiality === 'restricted'"> · Restricted</span></p><p class="mt-1 max-w-xl text-xs text-slate-600">{{ row.description }}</p></td>
                                    <td class="px-3 py-3 text-xs">{{ memberLabel(row.owner_membership_id) }}</td>
                                    <td class="px-3 py-3"><span class="font-bold">{{ row.risk_score }}</span><span class="ml-2 border border-slate-300 px-2 py-1 text-xs">{{ humanize(row.risk_level) }}</span><p class="mt-1 text-xs text-slate-500">{{ row.likelihood }} × {{ row.impact }}</p></td>
                                    <td class="px-3 py-3 text-xs"><p>{{ row.mitigation }}</p><p class="mt-1 text-slate-500">Review {{ row.review_date ?? '—' }}</p></td>
                                    <td class="px-3 py-3 text-xs font-semibold">{{ humanize(row.status) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-5 overflow-x-auto border border-slate-200">
                        <div class="border-b border-slate-200 px-4 py-3 font-semibold">Insurance & Protection Register</div>
                        <table class="min-w-full text-left text-sm">
                            <thead><tr class="border-b bg-slate-50"><th class="px-3 py-3">Protection</th><th class="px-3 py-3">Covered subject</th><th class="px-3 py-3">Reference</th><th class="px-3 py-3">Review</th></tr></thead>
                            <tbody><tr v-for="row in risk.current.protections" :key="row.id" class="border-b border-slate-100"><td class="px-3 py-3 font-semibold">{{ humanize(row.protection_type) }}<span v-if="row.confidentiality === 'restricted'" class="block text-xs text-slate-500">Restricted</span></td><td class="px-3 py-3">{{ row.covered_subject }}</td><td class="px-3 py-3 text-xs">{{ row.provider ?? '—' }} · {{ row.policy_reference ?? '—' }}</td><td class="px-3 py-3 text-xs">{{ row.renewal_date ?? '—' }} · {{ humanize(row.status) }}</td></tr><tr v-if="risk.current.protections.length === 0"><td colspan="4" class="px-3 py-5 text-slate-500">No authorized protection records.</td></tr></tbody>
                        </table>
                    </div>
                </template>
            </section>

            <section class="pbr-surface mt-6 p-5 sm:p-6">
                <h2 class="text-lg font-bold">Risk Register Version History</h2>
                <div class="mt-3 overflow-x-auto border border-slate-200">
                    <table class="min-w-full text-left text-sm">
                        <thead><tr class="border-b bg-slate-50"><th class="px-3 py-3">Version</th><th class="px-3 py-3">State</th><th class="px-3 py-3">Effective</th><th class="px-3 py-3">Context action</th></tr></thead>
                        <tbody><tr v-for="version in risk.versions" :key="version.id" class="border-b border-slate-100"><td class="px-3 py-3 font-semibold">v{{ version.version_number }}</td><td class="px-3 py-3"><span class="border border-slate-300 px-2 py-1 text-xs">{{ humanize(version.state) }}</span></td><td class="px-3 py-3 text-xs">{{ version.effective_from ?? '—' }}</td><td class="px-3 py-3"><div v-if="risk.permissions.manage" class="flex flex-wrap gap-2"><button v-if="version.state === 'draft'" class="text-xs font-semibold underline" @click="post('/risk/register/' + version.id + '/submit', { expected_revision: version.revision })">Submit</button><button v-if="version.state === 'ready_for_review'" class="text-xs font-semibold underline" @click="post('/risk/register/' + version.id + '/content-review', { target: 'under_review' })">Start review</button><button v-if="version.state === 'under_review'" class="text-xs font-semibold underline" @click="post('/risk/register/' + version.id + '/content-review', { target: 'approved' })">Approve content</button><button v-if="version.state === 'approved' || version.state === 'ready_for_effect'" :disabled="governanceSync.processing" class="text-xs font-semibold underline disabled:opacity-50" @click="syncGovernedDecision(version.id)">Sync governed Decision</button></div><p v-if="governanceSyncVersionId === version.id && Object.keys(governanceSync.errors).length" class="mt-2 text-xs text-red-700">{{ Object.values(governanceSync.errors)[0] }}</p></td></tr></tbody>
                    </table>
                </div>
            </section>

            <details v-if="risk.permissions.manage" class="pbr-surface mt-6">
                <summary class="cursor-pointer px-5 py-4 font-semibold">Create Risk Register Draft / Amendment</summary>
                <form class="space-y-6 border-t border-slate-200 p-5" @submit.prevent="register.post('/risk/register', { preserveScroll: true })">
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <label class="text-sm font-medium">Effective from<input v-model="register.effective_from" type="date" required class="mt-1 min-h-11 w-full border border-slate-300 px-3" /></label>
                        <label class="text-sm font-medium">Review due<OptionalTemporalInput v-model="register.review_due_at" type="date" class="mt-1 min-h-11 w-full border border-slate-300 px-3" /></label>
                        <label class="text-sm font-medium">Risk owner<select v-model="register.risk_owner_membership_id" required class="mt-1 min-h-11 w-full border border-slate-300 px-3"><option v-for="m in risk.memberships" :key="m.id" :value="m.id">{{ m.email }}</option></select></label>
                        <label class="text-sm font-medium">Review frequency<input v-model="register.review_frequency" required class="mt-1 min-h-11 w-full border border-slate-300 px-3" /></label>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-3"><label class="text-sm font-medium">Low max<input v-model.number="register.low_max_score" type="number" min="1" max="24" class="mt-1 min-h-11 w-full border border-slate-300 px-3" /></label><label class="text-sm font-medium">Medium max<input v-model.number="register.medium_max_score" type="number" min="2" max="24" class="mt-1 min-h-11 w-full border border-slate-300 px-3" /></label><label class="text-sm font-medium">High max<input v-model.number="register.high_max_score" type="number" min="3" max="24" class="mt-1 min-h-11 w-full border border-slate-300 px-3" /></label></div>

                    <div>
                        <div class="flex items-center justify-between"><h3 class="font-semibold">Risks</h3><button type="button" class="text-xs font-semibold underline" @click="addRisk">Add risk</button></div>
                        <div v-for="(row, index) in register.risks" :key="index" class="mt-3 grid gap-3 border border-slate-200 p-3 md:grid-cols-2 xl:grid-cols-4">
                            <label class="text-sm font-medium text-slate-700">{{ t('risk.item.category') }}<select v-model="row.category" class="mt-1 min-h-10 w-full border border-slate-300 px-2"><option value="operational">Operational</option><option value="financial">Financial</option><option value="people_key_person">People / Key Person</option><option value="customer_liability">Customer / Liability</option><option value="technology_cyber">Technology / Cyber</option><option value="legal_regulatory">Legal / Regulatory</option><option value="ip_brand_confidentiality">IP / Brand / Confidentiality</option><option value="strategic_partnership">Strategic / Partnership</option></select></label>
                            <label class="text-sm font-medium text-slate-700">{{ t('risk.item.title') }}<input v-model="row.title" required class="mt-1 min-h-10 w-full border border-slate-300 px-2" placeholder="e.g. Supplier concentration" /></label>
                            <label class="text-sm font-medium text-slate-700">{{ t('risk.item.owner') }}<select v-model="row.owner_membership_id" class="mt-1 min-h-10 w-full border border-slate-300 px-2"><option value="">No assigned owner</option><option v-for="m in risk.memberships" :key="m.id" :value="m.id">{{ m.email }}</option></select></label>
                            <label class="text-sm font-medium text-slate-700">{{ t('risk.item.operationsRole') }}<select v-model="row.operations_role_id" class="mt-1 min-h-10 w-full border border-slate-300 px-2"><option value="">No Operations role</option><option v-for="role in risk.operations_roles" :key="role.id" :value="role.id">{{ role.name }}</option></select></label>
                            <label class="text-sm font-medium text-slate-700 xl:col-span-2">{{ t('risk.item.description') }}<textarea v-model="row.description" required class="mt-1 min-h-20 w-full border border-slate-300 p-2" placeholder="Describe the risk and exposure." /></label>
                            <label class="text-sm font-medium text-slate-700">{{ t('risk.item.mitigation') }}<textarea v-model="row.mitigation" required class="mt-1 min-h-20 w-full border border-slate-300 p-2" placeholder="Current mitigation." /></label>
                            <label class="text-sm font-medium text-slate-700">{{ t('risk.item.responsePlan') }}<textarea v-model="row.response_plan" required class="mt-1 min-h-20 w-full border border-slate-300 p-2" placeholder="Response if the risk materializes." /></label>
                            <label class="text-xs">Likelihood 1–5<input v-model.number="row.likelihood" type="number" min="1" max="5" class="mt-1 min-h-10 w-full border border-slate-300 px-2" /></label>
                            <label class="text-xs">Impact 1–5<input v-model.number="row.impact" type="number" min="1" max="5" class="mt-1 min-h-10 w-full border border-slate-300 px-2" /></label>
                            <label class="text-sm font-medium text-slate-700">{{ t('risk.item.warningIndicator') }}<input v-model="row.warning_indicator" class="mt-1 min-h-10 w-full border border-slate-300 px-2" placeholder="e.g. Delivery delays exceed 7 days" /></label>
                            <label class="text-sm font-medium text-slate-700">{{ t('risk.item.confidentiality') }}<select v-model="row.confidentiality" class="mt-1 min-h-10 w-full border border-slate-300 px-2"><option value="standard">Standard</option><option value="restricted">Restricted</option></select></label>
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between"><h3 class="font-semibold">Insurance / Protection</h3><button type="button" class="text-xs font-semibold underline" @click="addProtection">Add protection</button></div>
                        <div v-for="(row, index) in register.protections" :key="index" class="mt-3 grid gap-2 border-l-2 border-slate-300 pl-3 md:grid-cols-3">
                            <select v-model="row.protection_type" class="min-h-10 border border-slate-300 px-2"><option value="insurance">Insurance</option><option value="ip_brand">IP / Brand</option><option value="confidentiality_data">Confidentiality / Data</option><option value="system_access">System Access</option><option value="misconduct">Misconduct</option><option value="other">Other</option></select>
                            <input v-model="row.covered_subject" required class="min-h-10 border border-slate-300 px-2" placeholder="Covered subject" />
                            <select v-model="row.owner_membership_id" class="min-h-10 border border-slate-300 px-2"><option v-for="m in risk.memberships" :key="m.id" :value="m.id">{{ m.email }}</option></select>
                            <input v-model="row.provider" class="min-h-10 border border-slate-300 px-2" placeholder="Provider / custodian" />
                            <input v-model="row.policy_reference" class="min-h-10 border border-slate-300 px-2" placeholder="Policy/reference only" />
                            <select v-model="row.confidentiality" class="min-h-10 border border-slate-300 px-2"><option value="standard">Standard</option><option value="restricted">Restricted</option></select>
                        </div>
                        <p class="mt-2 text-xs font-semibold text-amber-800">Never enter passwords, PINs, OTPs, tokens, secret credentials or recovery secrets.</p>
                    </div>
                    <button type="submit" :disabled="register.processing" class="min-h-11 bg-slate-950 px-5 text-sm font-semibold text-white disabled:opacity-50">Save Draft</button>
                </form>
            </details>

            <section class="mt-8 grid gap-6 xl:grid-cols-2">
                <div>
                    <h2 class="text-lg font-bold">Incident Register</h2>
                    <div class="mt-3 space-y-2">
                        <article v-for="row in risk.incidents" :key="row.id" class="border border-slate-200 p-4">
                            <div class="flex items-start justify-between gap-3"><div><p class="font-semibold">{{ humanize(row.incident_type) }}</p><p class="mt-1 text-xs text-slate-500">{{ riskLabel(row.risk_item_id) }} · {{ row.incident_at }}<span v-if="row.confidentiality === 'restricted'"> · Restricted</span></p></div><span class="border border-slate-300 px-2 py-1 text-xs">{{ humanize(row.status) }}</span></div>
                            <p class="mt-2 text-sm text-slate-700">{{ row.description }}</p>
                            <button v-if="risk.permissions.manage && nextIncident(row)" class="mt-3 text-xs font-semibold underline" @click="post('/risk/incidents/' + row.id + '/transition', { target: nextIncident(row), expected_revision: row.revision })">Move to {{ nextIncident(row) }}</button>
                        </article>
                        <p v-if="risk.incidents.length === 0" class="text-sm text-slate-500">No authorized incidents.</p>
                    </div>
                    <details v-if="risk.permissions.manage && risk.current" class="mt-3 border border-slate-200"><summary class="cursor-pointer px-4 py-3 text-sm font-semibold">Open incident</summary><form class="grid gap-2 border-t border-slate-200 p-4" @submit.prevent="incident.post('/risk/incidents', { preserveScroll: true })"><select v-model="incident.risk_item_id" class="min-h-10 border border-slate-300 px-2"><option value="">Unlinked incident</option><option v-for="row in risk.current.risks" :key="row.id" :value="row.id">{{ row.title }}</option></select><input v-model="incident.incident_type" required class="min-h-10 border border-slate-300 px-2" placeholder="Incident type" /><textarea v-model="incident.description" required class="border border-slate-300 p-2" placeholder="What happened" /><textarea v-model="incident.business_impact" required class="border border-slate-300 p-2" placeholder="Business impact" /><textarea v-model="incident.immediate_action" required class="border border-slate-300 p-2" placeholder="Immediate action" /><select v-model="incident.confidentiality" class="min-h-10 border border-slate-300 px-2"><option value="standard">Standard</option><option value="restricted">Restricted</option></select><button type="submit" class="min-h-10 bg-slate-950 px-4 text-sm font-semibold text-white">Open Incident</button></form></details>
                </div>

                <div>
                    <h2 class="text-lg font-bold">Control Tests</h2>
                    <div class="mt-3 space-y-2"><article v-for="row in risk.control_tests" :key="row.id" class="border border-slate-200 p-4"><div class="flex items-start justify-between gap-3"><div><p class="font-semibold">{{ row.control_name }}</p><p class="mt-1 text-xs text-slate-500">{{ riskLabel(row.risk_item_id) }} · owner {{ memberLabel(row.owner_membership_id) }}<span v-if="row.confidentiality === 'restricted'"> · Restricted</span></p></div><span class="border border-slate-300 px-2 py-1 text-xs">{{ humanize(row.result) }}</span></div><p class="mt-2 text-sm text-slate-700">{{ row.scenario }}</p><div v-if="risk.permissions.manage && !row.completed_at" class="mt-3 flex flex-wrap gap-3"><button v-if="row.result === 'planned'" class="text-xs font-semibold underline" @click="post('/risk/control-tests/' + row.id + '/result', { result: 'running' })">Start</button><button class="text-xs font-semibold underline" @click="post('/risk/control-tests/' + row.id + '/result', { result: 'passed' })">Pass</button><button class="text-xs font-semibold underline" @click="post('/risk/control-tests/' + row.id + '/result', { result: 'partial' })">Partial</button><button class="text-xs font-semibold text-red-700 underline" @click="post('/risk/control-tests/' + row.id + '/result', { result: 'failed' })">Fail</button></div></article><p v-if="risk.control_tests.length === 0" class="text-sm text-slate-500">No authorized control tests.</p></div>
                    <details v-if="risk.permissions.manage && risk.current" class="mt-3 border border-slate-200"><summary class="cursor-pointer px-4 py-3 text-sm font-semibold">Plan control test</summary><form class="grid gap-2 border-t border-slate-200 p-4" @submit.prevent="controlTest.post('/risk/control-tests', { preserveScroll: true })"><input v-model="controlTest.control_name" required class="min-h-10 border border-slate-300 px-2" placeholder="Control name" /><select v-model="controlTest.risk_item_id" class="min-h-10 border border-slate-300 px-2"><option value="">No Risk link</option><option v-for="row in risk.current.risks" :key="row.id" :value="row.id">{{ row.title }}</option></select><textarea v-model="controlTest.scenario" required class="border border-slate-300 p-2" placeholder="Test scenario" /><select v-model="controlTest.owner_membership_id" class="min-h-10 border border-slate-300 px-2"><option v-for="m in risk.memberships" :key="m.id" :value="m.id">{{ m.email }}</option></select><select v-model="controlTest.confidentiality" class="min-h-10 border border-slate-300 px-2"><option value="standard">Standard</option><option value="restricted">Restricted</option></select><button type="submit" class="min-h-10 bg-slate-950 px-4 text-sm font-semibold text-white">Plan Test</button></form></details>
                </div>
            </section>

            <details v-if="risk.permissions.manage && risk.current" class="pbr-surface mt-6">
                <summary class="cursor-pointer px-4 py-3 font-semibold">Create Follow-up Action</summary>
                <form class="grid gap-2 border-t border-slate-200 p-4 md:grid-cols-2 xl:grid-cols-4" @submit.prevent="actionForm.post('/risk/actions', { preserveScroll: true })">
                    <select v-model="actionForm.source_type" class="min-h-10 border border-slate-300 px-2" @change="actionForm.source_id = ''"><option value="risk_item">Risk</option><option value="risk_incident">Incident</option><option value="risk_control_test">Control test</option></select>
                    <select v-model="actionForm.source_id" required class="min-h-10 border border-slate-300 px-2"><option value="">Choose source record</option><option v-for="source in actionSources" :key="source.id" :value="source.id">{{ source.label }}</option></select>
                    <select v-model="actionForm.operations_role_id" class="min-h-10 border border-slate-300 px-2"><option v-for="role in risk.operations_roles" :key="role.id" :value="role.id">{{ role.name }}</option></select>
                    <select v-model="actionForm.assigned_membership_id" class="min-h-10 border border-slate-300 px-2"><option v-for="m in risk.memberships" :key="m.id" :value="m.id">{{ m.email }}</option></select>
                    <input v-model="actionForm.title" required class="min-h-10 border border-slate-300 px-2 md:col-span-2" placeholder="Action title" />
                    <OptionalTemporalInput v-model="actionForm.due_at" type="datetime-local" class="min-h-10 border border-slate-300 px-2" />
                    <button type="submit" class="min-h-10 bg-slate-950 px-4 text-sm font-semibold text-white">Create Action</button>
                </form>
            </details>
            </div>
        </main>
    </AuthenticatedLayout>
</template>
