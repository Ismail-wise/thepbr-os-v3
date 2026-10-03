<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import { useI18n } from '../../i18n/useI18n';

type Jurisdiction = {
    id?: string;
    jurisdiction_code: string;
    scope_type: string;
    scope_reference: string | null;
    applicability: string;
    rationale: string | null;
    legal_review_required: boolean;
};

type Registration = {
    id?: string;
    registration_type: string;
    authority: string;
    reference_number: string | null;
    jurisdiction_code: string;
    registration_date: string | null;
    status: string;
    evidence_reference: string | null;
};

type LicensePermit = {
    id?: string;
    name: string;
    authority: string;
    reference_number: string | null;
    jurisdiction_code: string;
    start_date: string | null;
    expiry_date: string | null;
    review_date: string | null;
    status: string;
    evidence_reference: string | null;
};

type LegalRequirement = {
    id?: string;
    requirement_key: string;
    title: string;
    category: string;
    description: string;
    jurisdiction_code: string;
    source_authority: string | null;
    applicable_from: string | null;
    applicable_until: string | null;
    status: string;
    legal_review_required: boolean;
    evidence_reference: string | null;
};

type LegalReview = {
    id?: string;
    legal_requirement_id?: string | null;
    requirement_index?: number | null;
    review_type: string;
    reviewer_name: string;
    reviewer_capacity: string;
    reviewer_organization: string | null;
    review_date: string;
    outcome: string;
    notes: string | null;
    evidence_reference: string | null;
};

type CurrentLegal = {
    formal_record_version_id: string;
    version_number: number;
    revision: number;
    effective_from: string | null;
    review_due_at: string | null;
    legal_form: string;
    entity_name: string | null;
    primary_jurisdiction_code: string;
    governing_law_reference: string | null;
    registered_address: string | null;
    confidentiality: string;
    notes: string | null;
    jurisdictions: Jurisdiction[];
    registrations: Registration[];
    licenses: LicensePermit[];
    requirements: LegalRequirement[];
    reviews: LegalReview[];
};

type VersionRow = {
    id: string;
    version_number: number;
    revision: number;
    frozen_at: string | null;
    effective_from: string | null;
    review_due_at: string | null;
    legal_form: string;
    primary_jurisdiction_code: string;
    confidentiality: string;
    state: string | null;
    is_current_effective: boolean;
};

const props = defineProps<{
    legal: {
        business: { id: string; name: string };
        permissions: { manage: boolean };
        current: CurrentLegal | null;
        versions: VersionRow[];
        attention: {
            blocked_requirements: number;
            review_required: number;
            licenses_due_soon: number;
        };
    };
}>();

const { t } = useI18n();
const today = new Date().toISOString().slice(0, 10);
const attentionTotal = computed(
    () =>
        props.legal.attention.blocked_requirements +
        props.legal.attention.review_required +
        props.legal.attention.licenses_due_soon,
);

const cloneCurrent = props.legal.current;

const form = useForm({
    effective_from: today,
    review_due_at: cloneCurrent?.review_due_at?.slice(0, 10) ?? '',
    legal_form: cloneCurrent?.legal_form ?? '',
    entity_name: cloneCurrent?.entity_name ?? '',
    primary_jurisdiction_code:
        cloneCurrent?.primary_jurisdiction_code ?? '',
    governing_law_reference:
        cloneCurrent?.governing_law_reference ?? '',
    registered_address: cloneCurrent?.registered_address ?? '',
    confidentiality: cloneCurrent?.confidentiality ?? 'standard',
    notes: cloneCurrent?.notes ?? '',
    jurisdictions: (cloneCurrent?.jurisdictions ?? []).map((row) => ({
        jurisdiction_code: row.jurisdiction_code,
        scope_type: row.scope_type,
        scope_reference: row.scope_reference ?? '',
        applicability: row.applicability,
        rationale: row.rationale ?? '',
        legal_review_required: Boolean(row.legal_review_required),
    })) as Array<Omit<Jurisdiction, 'id'>>,
    registrations: (cloneCurrent?.registrations ?? []).map((row) => ({
        registration_type: row.registration_type,
        authority: row.authority,
        reference_number: row.reference_number ?? '',
        jurisdiction_code: row.jurisdiction_code,
        registration_date: row.registration_date ?? '',
        status: row.status,
        evidence_reference: row.evidence_reference ?? '',
    })) as Array<Omit<Registration, 'id'>>,
    licenses: (cloneCurrent?.licenses ?? []).map((row) => ({
        name: row.name,
        authority: row.authority,
        reference_number: row.reference_number ?? '',
        jurisdiction_code: row.jurisdiction_code,
        start_date: row.start_date ?? '',
        expiry_date: row.expiry_date ?? '',
        review_date: row.review_date ?? '',
        status: row.status,
        evidence_reference: row.evidence_reference ?? '',
    })) as Array<Omit<LicensePermit, 'id'>>,
    requirements: (cloneCurrent?.requirements ?? []).map((row) => ({
        requirement_key: row.requirement_key,
        title: row.title,
        category: row.category,
        description: row.description,
        jurisdiction_code: row.jurisdiction_code,
        source_authority: row.source_authority ?? '',
        applicable_from: row.applicable_from ?? '',
        applicable_until: row.applicable_until ?? '',
        status: row.status,
        legal_review_required: Boolean(row.legal_review_required),
        evidence_reference: row.evidence_reference ?? '',
    })) as Array<Omit<LegalRequirement, 'id'>>,
    reviews: [] as Array<Omit<LegalReview, 'id' | 'legal_requirement_id'>>,
});

if (form.jurisdictions.length === 0) {
    form.jurisdictions.push({
        jurisdiction_code: '',
        scope_type: 'entity',
        scope_reference: '',
        applicability: 'applicable',
        rationale: '',
        legal_review_required: false,
    });
}

const addJurisdiction = (): void => {
    form.jurisdictions.push({
        jurisdiction_code: form.primary_jurisdiction_code,
        scope_type: 'other',
        scope_reference: '',
        applicability: 'needs_review',
        rationale: '',
        legal_review_required: true,
    });
};

const addRegistration = (): void => {
    form.registrations.push({
        registration_type: '',
        authority: '',
        reference_number: '',
        jurisdiction_code: form.primary_jurisdiction_code,
        registration_date: '',
        status: 'planned',
        evidence_reference: '',
    });
};

const addLicense = (): void => {
    form.licenses.push({
        name: '',
        authority: '',
        reference_number: '',
        jurisdiction_code: form.primary_jurisdiction_code,
        start_date: '',
        expiry_date: '',
        review_date: '',
        status: 'planned',
        evidence_reference: '',
    });
};

const addRequirement = (): void => {
    form.requirements.push({
        requirement_key: '',
        title: '',
        category: 'corporate',
        description: '',
        jurisdiction_code: form.primary_jurisdiction_code,
        source_authority: '',
        applicable_from: '',
        applicable_until: '',
        status: 'identified',
        legal_review_required: false,
        evidence_reference: '',
    });
};

const addReview = (): void => {
    form.reviews.push({
        requirement_index: form.requirements.length > 0 ? 0 : null,
        review_type: '',
        reviewer_name: '',
        reviewer_capacity: '',
        reviewer_organization: '',
        review_date: today,
        outcome: 'pending',
        notes: '',
        evidence_reference: '',
    });
};

const submitDraft = (): void => {
    form.post('/business/legal-structure', {
        preserveScroll: true,
    });
};

type PostData = NonNullable<Parameters<typeof router.post>[1]>;
const post = (url: string, data: PostData = {}): void => {
    router.post(url, data, { preserveScroll: true });
};

const governanceSync = useForm({});
const syncingVersion = ref('');

const syncDecision = (versionId: string): void => {
    syncingVersion.value = versionId;
    governanceSync.clearErrors();
    governanceSync.post(
        `/business/legal-structure/${versionId}/sync-decision`,
        { preserveScroll: true },
    );
};
</script>

<template>
    <Head :title="t('legal.title')" />

    <AuthenticatedLayout>
        <main class="mx-auto min-h-screen w-full max-w-[1500px] px-4 py-5 text-[var(--pbr-ink)] sm:px-6 sm:py-6 lg:px-8 lg:py-7">
            <header class="pbr-surface p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                            {{ legal.business.name }}
                        </p>
                        <h1 class="mt-2 text-2xl font-bold text-slate-950">
                            {{ t('legal.title') }}
                        </h1>
                        <p class="mt-2 max-w-4xl text-sm leading-6 text-slate-600">
                            {{ t('legal.description') }}
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Link
                            href="/records/documents"
                            class="inline-flex min-h-11 items-center border border-slate-300 px-4 text-sm font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-500"
                        >
                            {{ t('legal.openVault') }}
                        </Link>
                        <Link
                            href="/governance"
                            class="inline-flex min-h-11 items-center border border-slate-300 px-4 text-sm font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-500"
                        >
                            {{ t('legal.openGovernance') }}
                        </Link>
                    </div>
                </div>

                <p class="mt-5 rounded-[16px] border border-[#e8d9ab] bg-[#fffaf0] px-4 py-3 text-sm font-bold text-[#66531f]">
                    {{ t('legal.boundary') }}
                </p>
            </header>

            <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Legal attention summary">
                <div class="pbr-surface p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        {{ t('legal.needsAttention') }}
                    </p>
                    <p class="mt-2 text-2xl font-bold">{{ attentionTotal }}</p>
                </div>
                <div class="pbr-surface p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        {{ t('legal.blockedRequirements') }}
                    </p>
                    <p class="mt-2 text-2xl font-bold">
                        {{ legal.attention.blocked_requirements }}
                    </p>
                </div>
                <div class="pbr-surface p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        {{ t('legal.reviewRequired') }}
                    </p>
                    <p class="mt-2 text-2xl font-bold">
                        {{ legal.attention.review_required }}
                    </p>
                </div>
                <div class="pbr-surface p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        {{ t('legal.licensesDueSoon') }}
                    </p>
                    <p class="mt-2 text-2xl font-bold">
                        {{ legal.attention.licenses_due_soon }}
                    </p>
                </div>
            </section>

            <section class="pbr-surface mt-6 p-5 sm:p-6">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-bold">
                            {{ t('legal.currentEffective') }}
                        </h2>
                        <p class="mt-1 text-sm text-slate-600">
                            {{ t('legal.currentEffectiveHelp') }}
                        </p>
                    </div>
                    <span
                        v-if="legal.current"
                        class="border border-emerald-300 bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-900"
                    >
                        v{{ legal.current.version_number }} · Effective
                    </span>
                </div>

                <div
                    v-if="!legal.current"
                    class="mt-4 border border-dashed border-slate-300 p-5 text-sm text-slate-500"
                >
                    {{ t('legal.noCurrent') }}
                </div>

                <template v-else>
                    <div class="mt-4 grid gap-4 border border-slate-200 p-5 md:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <p class="text-xs font-semibold uppercase text-slate-500">{{ t('legal.legalForm') }}</p>
                            <p class="mt-1 font-semibold">{{ legal.current.legal_form }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase text-slate-500">{{ t('legal.entityName') }}</p>
                            <p class="mt-1 font-semibold">{{ legal.current.entity_name ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase text-slate-500">{{ t('legal.primaryJurisdiction') }}</p>
                            <p class="mt-1 font-semibold">{{ legal.current.primary_jurisdiction_code }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase text-slate-500">{{ t('legal.governingLaw') }}</p>
                            <p class="mt-1 font-semibold">{{ legal.current.governing_law_reference ?? '—' }}</p>
                        </div>
                    </div>

                    <div class="mt-5 overflow-x-auto border border-slate-200">
                        <div class="border-b border-slate-200 px-4 py-3 font-semibold">
                            {{ t('legal.requirements') }}
                        </div>
                        <table class="min-w-full text-left text-sm">
                            <thead>
                                <tr class="border-b bg-slate-50">
                                    <th class="px-3 py-3">{{ t('legal.requirement') }}</th>
                                    <th class="px-3 py-3">{{ t('legal.jurisdiction') }}</th>
                                    <th class="px-3 py-3">{{ t('legal.status') }}</th>
                                    <th class="px-3 py-3">{{ t('legal.review') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in legal.current.requirements"
                                    :key="row.id"
                                    class="border-b border-slate-100 align-top"
                                >
                                    <td class="px-3 py-3">
                                        <p class="font-semibold">{{ row.title }}</p>
                                        <p class="mt-1 text-xs capitalize text-slate-500">{{ row.requirement_key.replaceAll('_', ' ') }} · {{ row.category.replaceAll('_', ' ') }}</p>
                                        <p class="mt-1 max-w-xl text-xs text-slate-600">{{ row.description }}</p>
                                    </td>
                                    <td class="px-3 py-3">{{ row.jurisdiction_code }}</td>
                                    <td class="px-3 py-3">
                                        <span class="border border-slate-300 px-2 py-1 text-xs font-semibold">
                                            {{ row.status }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-3 text-xs">
                                        {{ row.legal_review_required ? t('legal.required') : t('legal.notRequired') }}
                                    </td>
                                </tr>
                                <tr v-if="legal.current.requirements.length === 0">
                                    <td colspan="4" class="px-3 py-5 text-slate-500">{{ t('legal.noRequirements') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-5 grid gap-5 xl:grid-cols-2">
                        <div class="overflow-x-auto border border-slate-200">
                            <div class="border-b border-slate-200 px-4 py-3 font-semibold">
                                {{ t('legal.registrations') }}
                            </div>
                            <table class="min-w-full text-left text-sm">
                                <thead>
                                    <tr class="border-b bg-slate-50">
                                        <th class="px-3 py-3">{{ t('legal.type') }}</th>
                                        <th class="px-3 py-3">{{ t('legal.authority') }}</th>
                                        <th class="px-3 py-3">{{ t('legal.status') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in legal.current.registrations" :key="row.id" class="border-b border-slate-100">
                                        <td class="px-3 py-3">{{ row.registration_type }}</td>
                                        <td class="px-3 py-3">{{ row.authority }}</td>
                                        <td class="px-3 py-3">{{ row.status }}</td>
                                    </tr>
                                    <tr v-if="legal.current.registrations.length === 0">
                                        <td colspan="3" class="px-3 py-5 text-slate-500">{{ t('legal.none') }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="overflow-x-auto border border-slate-200">
                            <div class="border-b border-slate-200 px-4 py-3 font-semibold">
                                {{ t('legal.licenses') }}
                            </div>
                            <table class="min-w-full text-left text-sm">
                                <thead>
                                    <tr class="border-b bg-slate-50">
                                        <th class="px-3 py-3">{{ t('legal.name') }}</th>
                                        <th class="px-3 py-3">{{ t('legal.authority') }}</th>
                                        <th class="px-3 py-3">{{ t('legal.expiry') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in legal.current.licenses" :key="row.id" class="border-b border-slate-100">
                                        <td class="px-3 py-3">{{ row.name }}</td>
                                        <td class="px-3 py-3">{{ row.authority }}</td>
                                        <td class="px-3 py-3">{{ row.expiry_date ?? '—' }} · {{ row.status }}</td>
                                    </tr>
                                    <tr v-if="legal.current.licenses.length === 0">
                                        <td colspan="3" class="px-3 py-5 text-slate-500">{{ t('legal.none') }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </template>
            </section>

            <section class="pbr-surface mt-6 p-5 sm:p-6">
                <h2 class="text-lg font-bold">{{ t('legal.versionHistory') }}</h2>
                <div class="mt-3 overflow-x-auto border border-slate-200">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b bg-slate-50">
                                <th class="px-3 py-3">{{ t('legal.version') }}</th>
                                <th class="px-3 py-3">{{ t('legal.state') }}</th>
                                <th class="px-3 py-3">{{ t('legal.jurisdiction') }}</th>
                                <th class="px-3 py-3">{{ t('legal.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="version in legal.versions" :key="version.id" class="border-b border-slate-100">
                                <td class="px-3 py-3 font-semibold">
                                    v{{ version.version_number }}
                                    <span v-if="version.is_current_effective" class="ml-2 text-xs text-emerald-700">Effective</span>
                                </td>
                                <td class="px-3 py-3">
                                    <span class="border border-slate-300 px-2 py-1 text-xs">{{ version.state ?? 'Unknown' }}</span>
                                </td>
                                <td class="px-3 py-3">{{ version.primary_jurisdiction_code }}</td>
                                <td class="px-3 py-3">
                                    <div v-if="legal.permissions.manage" class="flex flex-wrap gap-3">
                                        <button
                                            v-if="version.state === 'draft'"
                                            type="button"
                                            class="min-h-10 text-xs font-semibold underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-500"
                                            @click="post('/business/legal-structure/' + version.id + '/submit', { expected_revision: version.revision })"
                                        >
                                            {{ t('legal.createProposal') }}
                                        </button>
                                        <button
                                            v-if="version.state === 'ready_for_review'"
                                            type="button"
                                            class="min-h-10 text-xs font-semibold underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-500"
                                            @click="post('/business/legal-structure/' + version.id + '/content-review', { target: 'under_review' })"
                                        >
                                            {{ t('legal.startReview') }}
                                        </button>
                                        <button
                                            v-if="version.state === 'under_review'"
                                            type="button"
                                            class="min-h-10 text-xs font-semibold underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-500"
                                            @click="post('/business/legal-structure/' + version.id + '/content-review', { target: 'approved' })"
                                        >
                                            {{ t('legal.approveContent') }}
                                        </button>
                                        <button
                                            v-if="version.state === 'approved' || version.state === 'ready_for_effect'"
                                            type="button"
                                            :disabled="governanceSync.processing"
                                            class="min-h-10 text-xs font-semibold underline disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-500"
                                            @click="syncDecision(version.id)"
                                        >
                                            {{ t('legal.syncDecision') }}
                                        </button>
                                    </div>
                                    <p
                                        v-if="syncingVersion === version.id && Object.keys(governanceSync.errors).length"
                                        class="mt-2 text-xs text-red-700"
                                    >
                                        {{ Object.values(governanceSync.errors)[0] }}
                                    </p>
                                </td>
                            </tr>
                            <tr v-if="legal.versions.length === 0">
                                <td colspan="4" class="px-3 py-5 text-slate-500">{{ t('legal.noVersions') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <details v-if="legal.permissions.manage" class="mt-8 border border-slate-200">
                <summary class="cursor-pointer px-5 py-4 font-semibold">
                    {{ legal.current ? t('legal.createAmendment') : t('legal.createDraft') }}
                </summary>

                <form class="space-y-7 border-t border-slate-200 p-5" @submit.prevent="submitDraft">
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <label class="text-sm font-medium">
                            {{ t('legal.effectiveFrom') }}
                            <input v-model="form.effective_from" type="date" required class="mt-1 min-h-11 w-full border border-slate-300 px-3" />
                        </label>
                        <label class="text-sm font-medium">
                            {{ t('legal.reviewDue') }}
                            <input v-model="form.review_due_at" type="date" class="mt-1 min-h-11 w-full border border-slate-300 px-3" />
                        </label>
                        <label class="text-sm font-medium">
                            {{ t('legal.legalForm') }}
                            <input v-model="form.legal_form" required maxlength="120" class="mt-1 min-h-11 w-full border border-slate-300 px-3" />
                        </label>
                        <label class="text-sm font-medium">
                            {{ t('legal.entityName') }}
                            <input v-model="form.entity_name" maxlength="240" class="mt-1 min-h-11 w-full border border-slate-300 px-3" />
                        </label>
                        <label class="text-sm font-medium">
                            {{ t('legal.primaryJurisdiction') }}
                            <input v-model.trim="form.primary_jurisdiction_code" required maxlength="24" class="mt-1 min-h-11 w-full border border-slate-300 px-3 uppercase" placeholder="TH" />
                        </label>
                        <label class="text-sm font-medium">
                            {{ t('legal.governingLaw') }}
                            <input v-model="form.governing_law_reference" maxlength="240" class="mt-1 min-h-11 w-full border border-slate-300 px-3" />
                        </label>
                        <label class="text-sm font-medium xl:col-span-2">
                            {{ t('legal.registeredAddress') }}
                            <input v-model="form.registered_address" class="mt-1 min-h-11 w-full border border-slate-300 px-3" />
                        </label>
                        <label class="text-sm font-medium">
                            {{ t('legal.confidentiality') }}
                            <select v-model="form.confidentiality" class="mt-1 min-h-11 w-full border border-slate-300 px-3">
                                <option value="standard">Standard</option>
                                <option value="restricted">Restricted</option>
                            </select>
                        </label>
                    </div>

                    <div>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h3 class="font-semibold">{{ t('legal.jurisdictions') }}</h3>
                                <p class="mt-1 text-xs text-slate-500">{{ t('legal.jurisdictionHelp') }}</p>
                            </div>
                            <button type="button" class="min-h-10 text-xs font-semibold underline" @click="addJurisdiction">
                                {{ t('legal.addJurisdiction') }}
                            </button>
                        </div>
                        <div v-for="(row, index) in form.jurisdictions" :key="'jur-' + index" class="mt-3 grid gap-2 border border-slate-200 p-3 md:grid-cols-2 xl:grid-cols-4">
                            <input v-model.trim="row.jurisdiction_code" required maxlength="24" class="min-h-10 border border-slate-300 px-2 uppercase" :aria-label="t('legal.jurisdiction')" placeholder="TH" />
                            <select v-model="row.scope_type" class="min-h-10 border border-slate-300 px-2" :aria-label="t('legal.scope')">
                                <option value="entity">Entity</option>
                                <option value="registration">Registration</option>
                                <option value="license_permit">License / Permit</option>
                                <option value="tax">Tax</option>
                                <option value="employment">Employment</option>
                                <option value="contract">Contract</option>
                                <option value="data">Data</option>
                                <option value="other">Other</option>
                            </select>
                            <select v-model="row.applicability" class="min-h-10 border border-slate-300 px-2" :aria-label="t('legal.applicability')">
                                <option value="applicable">Applicable</option>
                                <option value="not_applicable">Not applicable</option>
                                <option value="needs_review">Needs review</option>
                            </select>
                            <label class="flex min-h-10 items-center gap-2 text-sm">
                                <input v-model="row.legal_review_required" type="checkbox" />
                                {{ t('legal.reviewRequired') }}
                            </label>
                            <input v-model="row.scope_reference" maxlength="240" class="min-h-10 border border-slate-300 px-2 md:col-span-2" :placeholder="t('legal.scopeReference')" />
                            <input v-model="row.rationale" class="min-h-10 border border-slate-300 px-2 md:col-span-2" :placeholder="t('legal.rationale')" />
                            <button
                                v-if="form.jurisdictions.length > 1"
                                type="button"
                                class="min-h-10 justify-self-start text-xs font-semibold text-red-700 underline"
                                @click="form.jurisdictions.splice(index, 1)"
                            >
                                {{ t('legal.remove') }}
                            </button>
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="font-semibold">{{ t('legal.registrations') }}</h3>
                            <button type="button" class="min-h-10 text-xs font-semibold underline" @click="addRegistration">
                                {{ t('legal.addRegistration') }}
                            </button>
                        </div>
                        <div v-for="(row, index) in form.registrations" :key="'reg-' + index" class="mt-3 grid gap-2 border-l-2 border-slate-300 pl-3 md:grid-cols-2 xl:grid-cols-4">
                            <input v-model="row.registration_type" required class="min-h-10 border border-slate-300 px-2" :placeholder="t('legal.type')" />
                            <input v-model="row.authority" required class="min-h-10 border border-slate-300 px-2" :placeholder="t('legal.authority')" />
                            <input v-model="row.reference_number" class="min-h-10 border border-slate-300 px-2" :placeholder="t('legal.reference')" />
                            <input v-model.trim="row.jurisdiction_code" required class="min-h-10 border border-slate-300 px-2 uppercase" :placeholder="t('legal.jurisdiction')" />
                            <input v-model="row.registration_date" type="date" class="min-h-10 border border-slate-300 px-2" />
                            <select v-model="row.status" class="min-h-10 border border-slate-300 px-2">
                                <option v-for="status in ['planned','pending','active','suspended','expired','cancelled','closed']" :key="status" :value="status">{{ status }}</option>
                            </select>
                            <input v-model="row.evidence_reference" class="min-h-10 border border-slate-300 px-2" :placeholder="t('legal.evidenceReference')" />
                            <button type="button" class="min-h-10 justify-self-start text-xs font-semibold text-red-700 underline" @click="form.registrations.splice(index, 1)">
                                {{ t('legal.remove') }}
                            </button>
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="font-semibold">{{ t('legal.licenses') }}</h3>
                            <button type="button" class="min-h-10 text-xs font-semibold underline" @click="addLicense">
                                {{ t('legal.addLicense') }}
                            </button>
                        </div>
                        <div v-for="(row, index) in form.licenses" :key="'lic-' + index" class="mt-3 grid gap-2 border-l-2 border-slate-300 pl-3 md:grid-cols-2 xl:grid-cols-4">
                            <input v-model="row.name" required class="min-h-10 border border-slate-300 px-2" :placeholder="t('legal.name')" />
                            <input v-model="row.authority" required class="min-h-10 border border-slate-300 px-2" :placeholder="t('legal.authority')" />
                            <input v-model="row.reference_number" class="min-h-10 border border-slate-300 px-2" :placeholder="t('legal.reference')" />
                            <input v-model.trim="row.jurisdiction_code" required class="min-h-10 border border-slate-300 px-2 uppercase" :placeholder="t('legal.jurisdiction')" />
                            <label class="text-xs">{{ t('legal.startDate') }}<input v-model="row.start_date" type="date" class="mt-1 min-h-10 w-full border border-slate-300 px-2" /></label>
                            <label class="text-xs">{{ t('legal.expiry') }}<input v-model="row.expiry_date" type="date" class="mt-1 min-h-10 w-full border border-slate-300 px-2" /></label>
                            <label class="text-xs">{{ t('legal.reviewDue') }}<input v-model="row.review_date" type="date" class="mt-1 min-h-10 w-full border border-slate-300 px-2" /></label>
                            <select v-model="row.status" class="min-h-10 border border-slate-300 px-2">
                                <option v-for="status in ['planned','pending','active','suspended','expired','cancelled','closed']" :key="status" :value="status">{{ status }}</option>
                            </select>
                            <input v-model="row.evidence_reference" class="min-h-10 border border-slate-300 px-2 md:col-span-2" :placeholder="t('legal.evidenceReference')" />
                            <button type="button" class="min-h-10 justify-self-start text-xs font-semibold text-red-700 underline" @click="form.licenses.splice(index, 1)">
                                {{ t('legal.remove') }}
                            </button>
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="font-semibold">{{ t('legal.requirements') }}</h3>
                            <button type="button" class="min-h-10 text-xs font-semibold underline" @click="addRequirement">
                                {{ t('legal.addRequirement') }}
                            </button>
                        </div>
                        <div v-for="(row, index) in form.requirements" :key="'req-' + index" class="mt-3 grid gap-2 border border-slate-200 p-3 md:grid-cols-2 xl:grid-cols-4">
                            <input v-model="row.requirement_key" required maxlength="120" class="min-h-10 border border-slate-300 px-2" :placeholder="t('legal.requirementKey')" />
                            <input v-model="row.title" required maxlength="240" class="min-h-10 border border-slate-300 px-2" :placeholder="t('legal.requirement')" />
                            <input v-model="row.category" required maxlength="80" class="min-h-10 border border-slate-300 px-2" :placeholder="t('legal.category')" />
                            <input v-model.trim="row.jurisdiction_code" required class="min-h-10 border border-slate-300 px-2 uppercase" :placeholder="t('legal.jurisdiction')" />
                            <textarea v-model="row.description" required class="min-h-20 border border-slate-300 p-2 md:col-span-2" :placeholder="t('legal.descriptionField')" />
                            <input v-model="row.source_authority" class="min-h-10 border border-slate-300 px-2" :placeholder="t('legal.sourceAuthority')" />
                            <select v-model="row.status" class="min-h-10 border border-slate-300 px-2">
                                <option value="identified">Identified</option>
                                <option value="met">Met</option>
                                <option value="warning">Warning</option>
                                <option value="blocked">Blocked</option>
                                <option value="not_applicable">Not applicable</option>
                            </select>
                            <label class="text-xs">{{ t('legal.applicableFrom') }}<input v-model="row.applicable_from" type="date" class="mt-1 min-h-10 w-full border border-slate-300 px-2" /></label>
                            <label class="text-xs">{{ t('legal.applicableUntil') }}<input v-model="row.applicable_until" type="date" class="mt-1 min-h-10 w-full border border-slate-300 px-2" /></label>
                            <label class="flex min-h-10 items-center gap-2 text-sm"><input v-model="row.legal_review_required" type="checkbox" />{{ t('legal.reviewRequired') }}</label>
                            <input v-model="row.evidence_reference" class="min-h-10 border border-slate-300 px-2" :placeholder="t('legal.evidenceReference')" />
                            <button type="button" class="min-h-10 justify-self-start text-xs font-semibold text-red-700 underline" @click="form.requirements.splice(index, 1)">
                                {{ t('legal.remove') }}
                            </button>
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="font-semibold">{{ t('legal.reviews') }}</h3>
                            <button type="button" class="min-h-10 text-xs font-semibold underline" @click="addReview">
                                {{ t('legal.addReview') }}
                            </button>
                        </div>
                        <div v-for="(row, index) in form.reviews" :key="'review-' + index" class="mt-3 grid gap-2 border-l-2 border-slate-300 pl-3 md:grid-cols-2 xl:grid-cols-4">
                            <select v-model.number="row.requirement_index" class="min-h-10 border border-slate-300 px-2">
                                <option :value="null">{{ t('legal.generalReview') }}</option>
                                <option v-for="(requirement, requirementIndex) in form.requirements" :key="requirementIndex" :value="requirementIndex">
                                    {{ requirement.title || requirement.requirement_key || ('#' + (requirementIndex + 1)) }}
                                </option>
                            </select>
                            <input v-model="row.review_type" required class="min-h-10 border border-slate-300 px-2" :placeholder="t('legal.reviewType')" />
                            <input v-model="row.reviewer_name" required class="min-h-10 border border-slate-300 px-2" :placeholder="t('legal.reviewer')" />
                            <input v-model="row.reviewer_capacity" required class="min-h-10 border border-slate-300 px-2" :placeholder="t('legal.reviewerCapacity')" />
                            <input v-model="row.reviewer_organization" class="min-h-10 border border-slate-300 px-2" :placeholder="t('legal.organization')" />
                            <input v-model="row.review_date" type="date" required class="min-h-10 border border-slate-300 px-2" />
                            <select v-model="row.outcome" class="min-h-10 border border-slate-300 px-2">
                                <option value="pending">Pending</option>
                                <option value="passed">Passed</option>
                                <option value="qualified">Qualified</option>
                                <option value="issues_found">Issues found</option>
                                <option value="rejected">Rejected</option>
                            </select>
                            <input v-model="row.evidence_reference" class="min-h-10 border border-slate-300 px-2" :placeholder="t('legal.evidenceReference')" />
                            <textarea v-model="row.notes" class="min-h-20 border border-slate-300 p-2 md:col-span-2" :placeholder="t('legal.notes')" />
                            <button type="button" class="min-h-10 justify-self-start text-xs font-semibold text-red-700 underline" @click="form.reviews.splice(index, 1)">
                                {{ t('legal.remove') }}
                            </button>
                        </div>
                    </div>

                    <label class="block text-sm font-medium">
                        {{ t('legal.notes') }}
                        <textarea v-model="form.notes" class="mt-1 min-h-24 w-full border border-slate-300 p-3" />
                    </label>

                    <p v-if="Object.keys(form.errors).length" class="text-sm font-medium text-red-700">
                        {{ Object.values(form.errors)[0] }}
                    </p>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="min-h-11 bg-slate-950 px-5 text-sm font-semibold text-white disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-500 focus-visible:ring-offset-2"
                    >
                        {{ legal.current ? t('legal.saveAmendment') : t('legal.saveDraft') }}
                    </button>
                </form>
            </details>
        </main>
    </AuthenticatedLayout>
</template>