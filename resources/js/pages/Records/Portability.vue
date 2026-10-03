<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import { useI18n } from '../../i18n/useI18n';

type ArchiveTransition = {
    id: string;
    from_status: string;
    to_status: string;
    reason: string;
    occurred_at: string | null;
};

type ExportRow = {
    id: string;
    status: string;
    requested_categories: string[];
    excluded_categories: Array<{
        category: string;
        reason: string;
    }>;
    manifest_hash: string | null;
    content_sha256: string | null;
    size_bytes: number | null;
    requested_at: string | null;
    available_at: string | null;
    can_download: boolean;
};

const props = defineProps<{
    portabilityWorkspace: {
        business: {
            id: string;
            name: string;
            workspace_status: string;
        };
        permissions: {
            view: boolean;
            manage: boolean;
        };
        supported_categories: string[];
        archive_transitions: ArchiveTransition[];
        exports: ExportRow[];
    };
}>();

const { t } = useI18n();

const archiveForm = useForm({
    reason: '',
});

const archiveError = computed(() => {
    const errors: Partial<Record<'reason' | 'archive', string>> =
        archiveForm.errors;

    return errors.archive ?? errors.reason;
});

const exportForm = useForm({
    requested_categories: [] as string[],
});

const archiveBusiness = (): void => {
    archiveForm.post('/records/portability/archive', {
        preserveScroll: true,
        onSuccess: () => archiveForm.reset(),
    });
};

const unarchiveBusiness = (): void => {
    archiveForm.post('/records/portability/unarchive', {
        preserveScroll: true,
        onSuccess: () => archiveForm.reset(),
    });
};

const createExport = (): void => {
    exportForm.post('/records/portability/exports', {
        preserveScroll: true,
        onSuccess: () => exportForm.reset(),
    });
};

const generateExport = (row: ExportRow): void => {
    useForm({}).post(
        `/records/portability/exports/${row.id}/generate`,
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
    <Head :title="t('portability.title')" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl space-y-5 px-4 py-5 sm:px-6 sm:py-6 lg:px-8 lg:py-7">
            <header class="pbr-surface p-5 sm:p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                    {{ t('portability.eyebrow') }}
                </p>
                <h1 class="mt-2 text-2xl font-semibold text-slate-950">
                    {{ t('portability.title') }}
                </h1>
                <p class="mt-2 max-w-3xl text-sm text-slate-600">
                    {{ t('portability.subtitle') }}
                </p>
                <p class="mt-2 text-xs text-slate-500">
                    {{ portabilityWorkspace.business.name }}
                </p>
            </header>

            <section class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                    <p class="text-sm font-semibold text-amber-950">
                        {{ t('portability.representation') }}
                    </p>
                </div>
                <div class="pbr-surface p-4">
                    <p class="text-sm text-slate-700">
                        {{ t('portability.privacy') }}
                    </p>
                </div>
            </section>

            <section class="pbr-surface p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            {{ t('portability.status') }}
                        </p>
                        <p class="mt-1 text-lg font-semibold capitalize text-slate-950">
                            {{ portabilityWorkspace.business.workspace_status }}
                        </p>
                    </div>
                </div>

                <p
                    v-if="portabilityWorkspace.business.workspace_status === 'closed'"
                    class="mt-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800"
                >
                    {{ t('portability.closedNotice') }}
                </p>

                <form
                    v-if="
                        portabilityWorkspace.permissions.manage &&
                        portabilityWorkspace.business.workspace_status !== 'closed'
                    "
                    class="mt-5 space-y-3"
                    @submit.prevent="
                        portabilityWorkspace.business.workspace_status === 'archived'
                            ? unarchiveBusiness()
                            : archiveBusiness()
                    "
                >
                    <label class="block text-sm font-medium text-slate-800">
                        {{ t('portability.reason') }}
                        <textarea
                            v-model="archiveForm.reason"
                            rows="3"
                            maxlength="1000"
                            class="mt-2 w-full rounded-lg border-slate-300 text-sm"
                        />
                    </label>
                    <p class="text-xs text-slate-500">
                        {{ t('portability.reasonHelp') }}
                    </p>
                    <p
                        v-if="archiveError"
                        class="text-sm text-rose-700"
                    >
                        {{ archiveError }}
                    </p>
                    <button
                        type="submit"
                        class="min-h-11 rounded-lg bg-slate-950 px-5 py-2 text-sm font-semibold text-white disabled:opacity-50"
                        :disabled="
                            archiveForm.processing ||
                            archiveForm.reason.trim().length === 0
                        "
                    >
                        {{
                            portabilityWorkspace.business.workspace_status ===
                            'archived'
                                ? t('portability.unarchive')
                                : t('portability.archive')
                        }}
                    </button>
                </form>
            </section>

            <section class="pbr-surface overflow-hidden">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 class="text-base font-semibold text-slate-950">
                        {{ t('portability.history') }}
                    </h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">From</th>
                                <th class="px-4 py-3">To</th>
                                <th class="px-4 py-3">{{ t('portability.reason') }}</th>
                                <th class="px-4 py-3">Occurred</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr
                                v-for="transition in portabilityWorkspace.archive_transitions"
                                :key="transition.id"
                            >
                                <td class="px-4 py-3 capitalize text-slate-700">
                                    {{ transition.from_status }}
                                </td>
                                <td class="px-4 py-3 capitalize text-slate-700">
                                    {{ transition.to_status }}
                                </td>
                                <td class="max-w-xl px-4 py-3 text-slate-700">
                                    {{ transition.reason }}
                                </td>
                                <td class="px-4 py-3 text-xs text-slate-500">
                                    {{ transition.occurred_at || '—' }}
                                </td>
                            </tr>
                            <tr
                                v-if="
                                    portabilityWorkspace.archive_transitions.length ===
                                    0
                                "
                            >
                                <td
                                    colspan="4"
                                    class="px-5 py-8 text-center text-sm text-slate-500"
                                >
                                    {{ t('portability.noHistory') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <form
                v-if="portabilityWorkspace.permissions.manage"
                class="pbr-surface space-y-5 p-5"
                @submit.prevent="createExport"
            >
                <h2 class="text-base font-semibold text-slate-950">
                    {{ t('portability.newExport') }}
                </h2>

                <fieldset>
                    <legend class="text-sm font-medium text-slate-800">
                        {{ t('portability.categories') }}
                    </legend>
                    <p class="mt-1 text-xs text-slate-500">
                        {{ t('portability.categoriesHelp') }}
                    </p>

                    <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        <label
                            v-for="category in portabilityWorkspace.supported_categories"
                            :key="category"
                            class="flex min-h-11 items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700"
                        >
                            <input
                                v-model="exportForm.requested_categories"
                                type="checkbox"
                                :value="category"
                            />
                            <span class="capitalize">
                                {{ category.replaceAll('_', ' ') }}
                            </span>
                        </label>
                    </div>
                </fieldset>

                <p
                    v-if="exportForm.errors.requested_categories"
                    class="text-sm text-rose-700"
                >
                    {{ exportForm.errors.requested_categories }}
                </p>

                <button
                    type="submit"
                    class="min-h-11 rounded-lg bg-slate-950 px-5 py-2 text-sm font-semibold text-white disabled:opacity-50"
                    :disabled="
                        exportForm.processing ||
                        exportForm.requested_categories.length === 0
                    "
                >
                    {{ t('portability.createExport') }}
                </button>
            </form>

            <section class="pbr-surface overflow-hidden">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 class="text-base font-semibold text-slate-950">
                        {{ t('portability.exports') }}
                    </h2>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">{{ t('portability.categories') }}</th>
                                <th class="px-4 py-3">Size</th>
                                <th class="px-4 py-3">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr
                                v-for="row in portabilityWorkspace.exports"
                                :key="row.id"
                            >
                                <td class="px-4 py-3 align-top">
                                    <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">
                                        {{ row.status }}
                                    </span>
                                </td>
                                <td class="max-w-sm px-4 py-3 align-top text-slate-700">
                                    {{ row.requested_categories.join(', ') }}
                                    <ul
                                        v-if="row.excluded_categories.length > 0"
                                        class="mt-2 space-y-1 text-xs text-slate-500"
                                    >
                                        <li
                                            v-for="item in row.excluded_categories"
                                            :key="`${item.category}-${item.reason}`"
                                        >
                                            {{ item.category }} · {{ item.reason }}
                                        </li>
                                    </ul>
                                </td>
                                <td class="px-4 py-3 align-top text-xs text-slate-600">
                                    {{ formatBytes(row.size_bytes) }}
                                    <details v-if="row.manifest_hash" class="mt-2 text-slate-500">
                                        <summary class="cursor-pointer font-semibold">{{ t('governance.advancedDetails') }}</summary>
                                        <code class="mt-1 block break-all text-[11px]">{{ row.manifest_hash }}</code>
                                    </details>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            v-if="
                                                portabilityWorkspace.permissions.manage &&
                                                row.status === 'manifest_frozen'
                                            "
                                            type="button"
                                            class="min-h-10 rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-900"
                                            @click="generateExport(row)"
                                        >
                                            {{ t('portability.generate') }}
                                        </button>
                                        <a
                                            v-if="row.can_download"
                                            :href="`/records/portability/exports/${row.id}/download`"
                                            class="inline-flex min-h-10 items-center rounded-lg bg-slate-950 px-3 py-2 text-xs font-semibold text-white"
                                        >
                                            {{ t('portability.download') }}
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="portabilityWorkspace.exports.length === 0">
                                <td
                                    colspan="4"
                                    class="px-5 py-10 text-center text-sm text-slate-500"
                                >
                                    {{ t('portability.emptyExports') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
