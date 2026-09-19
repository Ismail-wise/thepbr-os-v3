<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { useI18n } from '../../../i18n/useI18n';
import AuthenticatedLayout from '../../../layouts/AuthenticatedLayout.vue';

type DocumentSummary = {
    id: string;
    title: string;
    category: string;
    createdAt: string | null;
};

type VersionRow = {
    id: string;
    number: number;
    filename: string;
    mimeType: string;
    sizeBytes: number;
    sha256: string;
    uploadedByMembershipId: string;
    uploadedAt: string | null;
    effectiveFrom: string | null;
    supersedesVersionNumber: number | null;
};

type EvidenceRow = {
    id: string;
    documentVersionId: string;
    versionNumber: number | null;
    confidentiality: string;
    sourceDate: string | null;
    submittedByMembershipId: string;
    verified: boolean;
    verifiedAt: string | null;
    verifiedByMembershipId: string | null;
    verificationMethod: string | null;
    verificationNote: string | null;
};

const props = defineProps<{
    document: DocumentSummary;
    versions: VersionRow[];
    evidence: EvidenceRow[];
    evidenceTargetTypes: string[];
    canManage: boolean;
}>();

const { t } = useI18n();

const versionForm = useForm<{ file: File | null }>({
    file: null,
});

const evidenceForm = useForm({
    document_version_id: props.versions[0]?.id ?? '',
    confidentiality: 'standard',
    source_date: '',
});

const linkForm = useForm({
    evidence_id: props.evidence[0]?.id ?? '',
    target_type: props.evidenceTargetTypes[0] ?? '',
    target_id: '',
});

const verifyForm = useForm({
    evidence_id: props.evidence.find((item) => !item.verified)?.id ?? '',
    verification_method: '',
    verification_note: '',
});

const categoryLabel = (category: string): string => {
    switch (category) {
        case 'corporate_legal':
            return t('documents.category.corporateLegal');
        case 'partners_ownership':
            return t('documents.category.partnersOwnership');
        case 'agreements_contracts':
            return t('documents.category.agreementsContracts');
        case 'finance_tax':
            return t('documents.category.financeTax');
        case 'governance_decisions':
            return t('documents.category.governanceDecisions');
        case 'risk_insurance':
            return t('documents.category.riskInsurance');
        case 'operations':
            return t('documents.category.operations');
        case 'pbr_generated':
            return t('documents.category.pbrGenerated');
        default:
            return category;
    }
};

const formatBytes = (bytes: number): string => {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};

const formatTimestamp = (value: string | null): string =>
    value === null ? '—' : value.slice(0, 16).replace('T', ' ');

const humanize = (value: string): string =>
    value
        .replace(/[._]/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());

const chooseVersionFile = (event: Event): void => {
    const input = event.target as HTMLInputElement;

    versionForm.file = input.files?.[0] ?? null;
};

const uploadVersion = (): void => {
    versionForm.post(
        `/records/documents/${props.document.id}/versions`,
        {
            forceFormData: true,
            preserveScroll: true,
        },
    );
};

const createEvidence = (): void => {
    if (!evidenceForm.document_version_id) {
        return;
    }

    evidenceForm.post(
        `/records/documents/${props.document.id}/versions/${evidenceForm.document_version_id}/evidence`,
        { preserveScroll: true },
    );
};

const linkEvidence = (): void => {
    if (!linkForm.evidence_id) {
        return;
    }

    linkForm.post(
        `/records/evidence/${linkForm.evidence_id}/links`,
        { preserveScroll: true },
    );
};

const verifyEvidence = (): void => {
    if (!verifyForm.evidence_id) {
        return;
    }

    verifyForm.post(
        `/records/evidence/${verifyForm.evidence_id}/verify`,
        { preserveScroll: true },
    );
};
</script>

<template>
    <AuthenticatedLayout>
        <main class="min-h-screen bg-white px-4 py-8 text-slate-950 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-7xl space-y-6">
                <header class="border-b border-slate-200 pb-6">
                    <Link
                        href="/records/documents"
                        class="inline-flex min-h-11 items-center text-sm font-semibold text-slate-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-500"
                    >
                        ← {{ t('documents.back') }}
                    </Link>

                    <div class="mt-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            {{ categoryLabel(document.category) }}
                        </p>

                        <h1 class="mt-1 text-2xl font-semibold tracking-tight">
                            {{ document.title }}
                        </h1>

                        <p class="mt-2 max-w-3xl text-sm text-slate-600">
                            {{ t('documents.latestNotEffective') }}
                        </p>
                    </div>
                </header>

                <section class="overflow-hidden border border-slate-200 bg-white">
                    <div class="border-b border-slate-200 px-5 py-4">
                        <h2 class="font-semibold">
                            {{ t('documents.history') }}
                        </h2>

                        <p class="mt-1 text-sm text-slate-500">
                            {{ t('documents.historyHelp') }}
                        </p>
                    </div>

                    <div v-if="versions.length === 0" class="px-6 py-12 text-center text-sm text-slate-500">
                        {{ t('documents.empty') }}
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="w-full min-w-[1100px] text-left text-sm">
                            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-5 py-3 font-semibold">
                                        {{ t('documents.version') }}
                                    </th>
                                    <th class="px-5 py-3 font-semibold">
                                        {{ t('documents.filename') }}
                                    </th>
                                    <th class="px-5 py-3 font-semibold">
                                        {{ t('documents.type') }}
                                    </th>
                                    <th class="px-5 py-3 font-semibold">
                                        {{ t('documents.size') }}
                                    </th>
                                    <th class="px-5 py-3 font-semibold">
                                        {{ t('documents.sha256') }}
                                    </th>
                                    <th class="px-5 py-3 font-semibold">
                                        {{ t('documents.uploaded') }}
                                    </th>
                                    <th class="px-5 py-3 font-semibold">
                                        {{ t('documents.supersedes') }}
                                    </th>
                                    <th class="px-5 py-3 text-right font-semibold">
                                        {{ t('documents.action') }}
                                    </th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-slate-100">
                                <tr
                                    v-for="version in versions"
                                    :key="version.id"
                                    class="align-top"
                                >
                                    <td class="px-5 py-4 font-semibold">
                                        {{ version.number }}
                                    </td>

                                    <td class="px-5 py-4">
                                        {{ version.filename }}
                                    </td>

                                    <td class="px-5 py-4 text-slate-600">
                                        {{ version.mimeType }}
                                    </td>

                                    <td class="px-5 py-4 text-slate-600">
                                        {{ formatBytes(version.sizeBytes) }}
                                    </td>

                                    <td class="px-5 py-4">
                                        <code class="break-all text-xs text-slate-600">
                                            {{ version.sha256 }}
                                        </code>
                                    </td>

                                    <td class="px-5 py-4 text-slate-600">
                                        {{ formatTimestamp(version.uploadedAt) }}
                                    </td>

                                    <td class="px-5 py-4 text-slate-600">
                                        {{ version.supersedesVersionNumber ?? '—' }}
                                    </td>

                                    <td class="px-5 py-4 text-right">
                                        <a
                                            :href="`/records/documents/${document.id}/versions/${version.id}/download`"
                                            class="inline-flex min-h-11 items-center px-3 font-semibold text-slate-900 hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-500"
                                        >
                                            {{ t('documents.download') }}
                                        </a>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section
                    v-if="canManage"
                    class="grid gap-6 lg:grid-cols-2"
                >
                    <div class="border border-slate-200 bg-slate-50 p-5">
                        <h2 class="font-semibold">
                            {{ t('documents.newVersion') }}
                        </h2>

                        <form class="mt-4 space-y-4" @submit.prevent="uploadVersion">
                            <input
                                type="file"
                                required
                                class="block min-h-11 w-full text-sm"
                                @change="chooseVersionFile"
                            />

                            <p
                                v-if="versionForm.errors.file"
                                class="text-sm text-red-700"
                            >
                                {{ versionForm.errors.file }}
                            </p>

                            <button
                                type="submit"
                                :disabled="versionForm.processing"
                                class="inline-flex min-h-11 items-center bg-slate-950 px-4 font-semibold text-white disabled:opacity-50"
                            >
                                {{ t('documents.upload') }}
                            </button>
                        </form>
                    </div>

                    <div class="border border-slate-200 bg-slate-50 p-5">
                        <h2 class="font-semibold">
                            {{ t('documents.createEvidence') }}
                        </h2>

                        <form class="mt-4 space-y-4" @submit.prevent="createEvidence">
                            <select
                                v-model="evidenceForm.document_version_id"
                                required
                                class="min-h-11 w-full border border-slate-300 bg-white px-3"
                            >
                                <option
                                    v-for="version in versions"
                                    :key="version.id"
                                    :value="version.id"
                                >
                                    {{ t('documents.version') }}
                                    {{ version.number }}
                                    — {{ version.filename }}
                                </option>
                            </select>

                            <select
                                v-model="evidenceForm.confidentiality"
                                required
                                class="min-h-11 w-full border border-slate-300 bg-white px-3"
                            >
                                <option value="standard">
                                    {{ t('documents.standard') }}
                                </option>
                                <option value="restricted">
                                    {{ t('documents.restricted') }}
                                </option>
                            </select>

                            <input
                                v-model="evidenceForm.source_date"
                                type="date"
                                required
                                class="min-h-11 w-full border border-slate-300 bg-white px-3"
                            />

                            <button
                                type="submit"
                                :disabled="evidenceForm.processing || versions.length === 0"
                                class="inline-flex min-h-11 items-center bg-slate-950 px-4 font-semibold text-white disabled:opacity-50"
                            >
                                {{ t('documents.create') }}
                            </button>
                        </form>
                    </div>
                </section>

                <section
                    v-if="canManage"
                    class="overflow-hidden border border-slate-200 bg-white"
                >
                    <div class="border-b border-slate-200 px-5 py-4">
                        <h2 class="font-semibold">
                            {{ t('documents.evidence') }}
                        </h2>
                    </div>

                    <div v-if="evidence.length === 0" class="px-6 py-10 text-center text-sm text-slate-500">
                        {{ t('documents.noEvidence') }}
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="w-full min-w-[900px] text-left text-sm">
                            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-5 py-3 font-semibold">
                                        {{ t('documents.version') }}
                                    </th>
                                    <th class="px-5 py-3 font-semibold">
                                        {{ t('documents.sourceDate') }}
                                    </th>
                                    <th class="px-5 py-3 font-semibold">
                                        {{ t('documents.confidentiality') }}
                                    </th>
                                    <th class="px-5 py-3 font-semibold">
                                        {{ t('documents.submittedBy') }}
                                    </th>
                                    <th class="px-5 py-3 font-semibold">
                                        {{ t('documents.verification') }}
                                    </th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="item in evidence" :key="item.id">
                                    <td class="px-5 py-4">
                                        {{ item.versionNumber ?? '—' }}
                                    </td>

                                    <td class="px-5 py-4">
                                        {{ item.sourceDate ?? '—' }}
                                    </td>

                                    <td class="px-5 py-4">
                                        {{
                                            item.confidentiality === 'restricted'
                                                ? t('documents.restricted')
                                                : t('documents.standard')
                                        }}
                                    </td>

                                    <td class="px-5 py-4">
                                        <code class="text-xs">
                                            {{ item.submittedByMembershipId }}
                                        </code>
                                    </td>

                                    <td class="px-5 py-4">
                                        <span>
                                            {{
                                                item.verified
                                                    ? t('documents.verified')
                                                    : t('documents.unverified')
                                            }}
                                        </span>

                                        <div
                                            v-if="item.verificationMethod"
                                            class="mt-1 text-xs text-slate-500"
                                        >
                                            {{ item.verificationMethod }}
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section
                    v-if="canManage && evidence.length > 0"
                    class="grid gap-6 lg:grid-cols-2"
                >
                    <div class="border border-slate-200 bg-slate-50 p-5">
                        <h2 class="font-semibold">
                            {{ t('documents.linkEvidence') }}
                        </h2>

                        <form class="mt-4 space-y-4" @submit.prevent="linkEvidence">
                            <select
                                v-model="linkForm.evidence_id"
                                required
                                class="min-h-11 w-full border border-slate-300 bg-white px-3"
                            >
                                <option
                                    v-for="item in evidence"
                                    :key="item.id"
                                    :value="item.id"
                                >
                                    {{ t('documents.version') }}
                                    {{ item.versionNumber ?? '—' }}
                                </option>
                            </select>

                            <select
                                v-model="linkForm.target_type"
                                required
                                class="min-h-11 w-full border border-slate-300 bg-white px-3"
                            >
                                <option
                                    v-for="targetType in evidenceTargetTypes"
                                    :key="targetType"
                                    :value="targetType"
                                >
                                    {{ humanize(targetType) }}
                                </option>
                            </select>

                            <input
                                v-model="linkForm.target_id"
                                type="text"
                                required
                                :placeholder="t('documents.targetId')"
                                class="min-h-11 w-full border border-slate-300 bg-white px-3"
                            />

                            <button
                                type="submit"
                                :disabled="linkForm.processing"
                                class="inline-flex min-h-11 items-center bg-slate-950 px-4 font-semibold text-white disabled:opacity-50"
                            >
                                {{ t('documents.link') }}
                            </button>
                        </form>
                    </div>

                    <div class="border border-slate-200 bg-slate-50 p-5">
                        <h2 class="font-semibold">
                            {{ t('documents.verifyEvidence') }}
                        </h2>

                        <form class="mt-4 space-y-4" @submit.prevent="verifyEvidence">
                            <select
                                v-model="verifyForm.evidence_id"
                                required
                                class="min-h-11 w-full border border-slate-300 bg-white px-3"
                            >
                                <option
                                    v-for="item in evidence.filter((row) => !row.verified)"
                                    :key="item.id"
                                    :value="item.id"
                                >
                                    {{ t('documents.version') }}
                                    {{ item.versionNumber ?? '—' }}
                                </option>
                            </select>

                            <input
                                v-model="verifyForm.verification_method"
                                type="text"
                                maxlength="120"
                                required
                                :placeholder="t('documents.verificationMethod')"
                                class="min-h-11 w-full border border-slate-300 bg-white px-3"
                            />

                            <textarea
                                v-model="verifyForm.verification_note"
                                rows="3"
                                maxlength="1000"
                                :placeholder="t('documents.verificationNote')"
                                class="w-full border border-slate-300 bg-white px-3 py-2"
                            />

                            <button
                                type="submit"
                                :disabled="
                                    verifyForm.processing ||
                                    !verifyForm.evidence_id
                                "
                                class="inline-flex min-h-11 items-center bg-slate-950 px-4 font-semibold text-white disabled:opacity-50"
                            >
                                {{ t('documents.verify') }}
                            </button>
                        </form>
                    </div>
                </section>
            </div>
        </main>
    </AuthenticatedLayout>
</template>
