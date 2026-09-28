<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import { useI18n } from '../../i18n/useI18n';

type ExportRow = {
    id: string;
    status: string;
    output_language: string;
    requested_scope: string[];
    manifest_hash: string | null;
    content_sha256: string | null;
    size_bytes: number | null;
    as_of_at: string | null;
    created_at: string | null;
    available_at: string | null;
    can_download: boolean;
};

const props = defineProps<{
    reportsWorkspace: {
        business: {
            id: string;
            name: string;
        };
        permissions: {
            view: boolean;
            manage: boolean;
        };
        supported_scopes: string[];
        exports: ExportRow[];
    };
}>();

const { t } = useI18n();

const form = useForm({
    output_language: 'en',
    requested_scope: [] as string[],
});

const createPack = (): void => {
    form.post('/reports/business-packs', {
        preserveScroll: true,
        onSuccess: () => form.reset('requested_scope'),
    });
};

const generate = (row: ExportRow): void => {
    useForm({}).post(
        `/reports/business-packs/${row.id}/generate`,
        { preserveScroll: true },
    );
};

const formatBytes = (bytes: number | null): string => {
    if (bytes === null) return '—';
    if (bytes < 1024) return `${bytes} B`;
    return `${(bytes / 1024).toFixed(1)} KB`;
};
</script>

<template>
    <Head :title="t('reports.title')" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <header class="border-b border-slate-200 pb-5">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                    {{ t('reports.eyebrow') }}
                </p>
                <h1 class="mt-2 text-2xl font-semibold text-slate-950">
                    {{ t('reports.title') }}
                </h1>
                <p class="mt-2 max-w-3xl text-sm text-slate-600">
                    {{ t('reports.subtitle') }}
                </p>
                <p class="mt-2 text-xs text-slate-500">
                    {{ reportsWorkspace.business.name }}
                </p>
            </header>

            <section class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                    <p class="text-sm font-semibold text-amber-950">
                        {{ t('reports.representation') }}
                    </p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-sm text-slate-700">
                        {{ t('reports.privacy') }}
                    </p>
                </div>
            </section>

            <form
                v-if="reportsWorkspace.permissions.manage"
                class="space-y-5 rounded-xl border border-slate-200 bg-white p-5"
                @submit.prevent="createPack"
            >
                <h2 class="text-base font-semibold text-slate-950">
                    {{ t('reports.newPack') }}
                </h2>

                <label class="block max-w-sm text-sm font-medium text-slate-800">
                    {{ t('reports.language') }}
                    <select
                        v-model="form.output_language"
                        class="mt-2 min-h-11 w-full rounded-lg border-slate-300 text-sm"
                    >
                        <option value="en">English</option>
                        <option value="my">မြန်မာ</option>
                        <option value="mixed">မြန်မာ + EN</option>
                    </select>
                </label>

                <fieldset>
                    <legend class="text-sm font-medium text-slate-800">
                        {{ t('reports.scope') }}
                    </legend>
                    <p class="mt-1 text-xs text-slate-500">
                        {{ t('reports.scopeHelp') }}
                    </p>

                    <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        <label
                            v-for="scope in reportsWorkspace.supported_scopes"
                            :key="scope"
                            class="flex min-h-11 items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700"
                        >
                            <input
                                v-model="form.requested_scope"
                                type="checkbox"
                                :value="scope"
                            />
                            <span class="capitalize">
                                {{ scope.replaceAll('_', ' ') }}
                            </span>
                        </label>
                    </div>
                </fieldset>

                <p
                    v-if="form.errors.requested_scope"
                    class="text-sm text-rose-700"
                >
                    {{ form.errors.requested_scope }}
                </p>

                <button
                    type="submit"
                    class="min-h-11 rounded-lg bg-slate-950 px-5 py-2 text-sm font-semibold text-white disabled:opacity-50"
                    :disabled="form.processing || form.requested_scope.length === 0"
                >
                    {{ t('reports.create') }}
                </button>
            </form>

            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 class="text-base font-semibold text-slate-950">
                        {{ t('reports.exports') }}
                    </h2>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">{{ t('reports.status') }}</th>
                                <th class="px-4 py-3">{{ t('reports.scope') }}</th>
                                <th class="px-4 py-3">{{ t('reports.created') }}</th>
                                <th class="px-4 py-3">{{ t('reports.asOf') }}</th>
                                <th class="px-4 py-3">{{ t('reports.hash') }}</th>
                                <th class="px-4 py-3">Size</th>
                                <th class="px-4 py-3">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr
                                v-for="row in reportsWorkspace.exports"
                                :key="row.id"
                            >
                                <td class="px-4 py-3 align-top">
                                    <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">
                                        {{ row.status }}
                                    </span>
                                </td>
                                <td class="max-w-sm px-4 py-3 align-top text-slate-700">
                                    {{ row.requested_scope.join(', ') }}
                                    <div class="mt-1 text-xs text-slate-500">
                                        {{ row.output_language }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 align-top text-xs text-slate-600">
                                    {{ row.created_at || '—' }}
                                </td>
                                <td class="px-4 py-3 align-top text-xs text-slate-600">
                                    {{ row.as_of_at || '—' }}
                                </td>
                                <td class="max-w-xs px-4 py-3 align-top">
                                    <code class="break-all text-[11px] text-slate-600">
                                        {{ row.manifest_hash || '—' }}
                                    </code>
                                </td>
                                <td class="px-4 py-3 align-top text-xs text-slate-600">
                                    {{ formatBytes(row.size_bytes) }}
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            v-if="
                                                reportsWorkspace.permissions.manage &&
                                                row.status === 'manifest_frozen'
                                            "
                                            type="button"
                                            class="min-h-10 rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-900"
                                            @click="generate(row)"
                                        >
                                            {{ t('reports.generate') }}
                                        </button>

                                        <a
                                            v-if="row.can_download"
                                            :href="`/reports/business-packs/${row.id}/download`"
                                            class="inline-flex min-h-10 items-center rounded-lg bg-slate-950 px-3 py-2 text-xs font-semibold text-white"
                                        >
                                            {{ t('reports.download') }}
                                        </a>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="reportsWorkspace.exports.length === 0">
                                <td
                                    colspan="7"
                                    class="px-5 py-10 text-center text-sm text-slate-500"
                                >
                                    {{ t('reports.empty') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
