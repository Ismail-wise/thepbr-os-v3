<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { useI18n } from '../../../i18n/useI18n';
import AuthenticatedLayout from '../../../layouts/AuthenticatedLayout.vue';

type LatestVersion = {
    id: string;
    number: number;
    filename: string;
    mimeType: string;
    sizeBytes: number;
    uploadedAt: string | null;
};

type DocumentRow = {
    id: string;
    title: string;
    category: string;
    createdAt: string | null;
    versionCount: number;
    latestVersion: LatestVersion | null;
};

const props = defineProps<{
    documents: DocumentRow[];
    categories: string[];
}>();

const { t } = useI18n();

const form = useForm<{
    title: string;
    category: string;
    file: File | null;
}>({
    title: '',
    category: props.categories[0] ?? 'corporate_legal',
    file: null,
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

const chooseFile = (event: Event): void => {
    const input = event.target as HTMLInputElement;

    form.file = input.files?.[0] ?? null;
};

const submit = (): void => {
    form.post('/records/documents', {
        forceFormData: true,
        preserveScroll: true,
    });
};
</script>

<template>
    <AuthenticatedLayout>
        <main class="min-h-screen bg-white px-4 py-8 text-slate-950 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-7xl space-y-6">
                <header class="border-b border-slate-200 pb-6">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        {{ t('documents.title') }}
                    </h1>

                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                        {{ t('documents.description') }}
                    </p>
                </header>

                <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
                    <section class="min-w-0 overflow-hidden border border-slate-200 bg-white">
                        <div class="border-b border-slate-200 px-5 py-4">
                            <h2 class="font-semibold">
                                {{ t('documents.register') }}
                            </h2>
                        </div>

                        <div
                            v-if="documents.length === 0"
                            class="px-6 py-12 text-center text-sm text-slate-500"
                        >
                            {{ t('documents.empty') }}
                        </div>

                        <div v-else class="overflow-x-auto">
                            <table class="w-full min-w-[760px] text-left text-sm">
                                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th class="px-5 py-3 font-semibold">
                                            {{ t('documents.titleLabel') }}
                                        </th>
                                        <th class="px-5 py-3 font-semibold">
                                            {{ t('documents.category') }}
                                        </th>
                                        <th class="px-5 py-3 font-semibold">
                                            {{ t('documents.latestUploaded') }}
                                        </th>
                                        <th class="px-5 py-3 font-semibold">
                                            {{ t('documents.versions') }}
                                        </th>
                                        <th class="px-5 py-3 text-right font-semibold">
                                            {{ t('documents.action') }}
                                        </th>
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-slate-100">
                                    <tr
                                        v-for="document in documents"
                                        :key="document.id"
                                        class="align-top"
                                    >
                                        <td class="px-5 py-4">
                                            <div class="font-medium text-slate-950">
                                                {{ document.title }}
                                            </div>

                                            <div class="mt-1 text-xs text-slate-500">
                                                {{ formatTimestamp(document.createdAt) }}
                                            </div>
                                        </td>

                                        <td class="px-5 py-4 text-slate-700">
                                            {{ categoryLabel(document.category) }}
                                        </td>

                                        <td class="px-5 py-4">
                                            <template v-if="document.latestVersion">
                                                <div class="font-medium text-slate-900">
                                                    {{ t('documents.version') }}
                                                    {{ document.latestVersion.number }}
                                                </div>

                                                <div class="mt-1 max-w-xs truncate text-xs text-slate-500">
                                                    {{ document.latestVersion.filename }}
                                                </div>

                                                <div class="mt-1 text-xs text-slate-500">
                                                    {{ document.latestVersion.mimeType }}
                                                    ·
                                                    {{ formatBytes(document.latestVersion.sizeBytes) }}
                                                </div>
                                            </template>

                                            <span v-else class="text-slate-400">—</span>
                                        </td>

                                        <td class="px-5 py-4 text-slate-700">
                                            {{ document.versionCount }}
                                        </td>

                                        <td class="px-5 py-4 text-right">
                                            <Link
                                                :href="`/records/documents/${document.id}`"
                                                class="inline-flex min-h-11 items-center px-3 font-semibold text-slate-900 hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-500"
                                            >
                                                {{ t('documents.open') }}
                                            </Link>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <aside class="self-start border border-slate-200 bg-slate-50 p-5">
                        <h2 class="font-semibold">
                            {{ t('documents.uploadDocument') }}
                        </h2>

                        <form class="mt-5 space-y-4" @submit.prevent="submit">
                            <div>
                                <label
                                    for="document-title"
                                    class="text-sm font-medium"
                                >
                                    {{ t('documents.titleLabel') }}
                                </label>

                                <input
                                    id="document-title"
                                    v-model="form.title"
                                    type="text"
                                    maxlength="255"
                                    required
                                    class="mt-1 min-h-11 w-full border border-slate-300 bg-white px-3 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-500"
                                />

                                <p
                                    v-if="form.errors.title"
                                    class="mt-1 text-sm text-red-700"
                                >
                                    {{ form.errors.title }}
                                </p>
                            </div>

                            <div>
                                <label
                                    for="document-category"
                                    class="text-sm font-medium"
                                >
                                    {{ t('documents.category') }}
                                </label>

                                <select
                                    id="document-category"
                                    v-model="form.category"
                                    class="mt-1 min-h-11 w-full border border-slate-300 bg-white px-3 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-500"
                                >
                                    <option
                                        v-for="category in categories"
                                        :key="category"
                                        :value="category"
                                    >
                                        {{ categoryLabel(category) }}
                                    </option>
                                </select>

                                <p
                                    v-if="form.errors.category"
                                    class="mt-1 text-sm text-red-700"
                                >
                                    {{ form.errors.category }}
                                </p>
                            </div>

                            <div>
                                <label
                                    for="document-file"
                                    class="text-sm font-medium"
                                >
                                    {{ t('documents.file') }}
                                </label>

                                <input
                                    id="document-file"
                                    type="file"
                                    required
                                    class="mt-1 block min-h-11 w-full text-sm"
                                    @change="chooseFile"
                                />

                                <p
                                    v-if="form.errors.file"
                                    class="mt-1 text-sm text-red-700"
                                >
                                    {{ form.errors.file }}
                                </p>
                            </div>

                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="inline-flex min-h-11 w-full items-center justify-center bg-slate-950 px-4 font-semibold text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-500 focus-visible:ring-offset-2 disabled:opacity-50"
                            >
                                {{ t('documents.upload') }}
                            </button>
                        </form>
                    </aside>
                </div>
            </div>
        </main>
    </AuthenticatedLayout>
</template>
