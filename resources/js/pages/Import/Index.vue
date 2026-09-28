<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import { useI18n } from '../../i18n/useI18n';

type ImportIssue = {
    severity: string;
    code: string;
    field: string | null;
    message: string;
};

type ImportedRecord = {
    id: string;
    source_record_key: string;
    intended_target: string;
    status: string;
    observed_payload: Record<string, unknown>;
    normalized_payload: Record<string, unknown> | null;
    confirmation_error_code: string | null;
    confirmation_error_message: string | null;
    canonical_resource_type: string | null;
    issues: ImportIssue[];
};

type BatchRow = {
    id: string;
    source_type: string;
    source_system: string;
    source_filename: string;
    source_fingerprint: string;
    parser_identity: string;
    parser_version: string;
    schema_version: string;
    intended_target: string;
    status: string;
    created_at: string | null;
    completed_at: string | null;
};

type SelectedBatch = BatchRow & {
    records: ImportedRecord[];
};

const props = defineProps<{
    importWorkspace: {
        business: {
            id: string;
            name: string;
        };
        permissions: {
            view: boolean;
            manage: boolean;
        };
        supported_source_types: string[];
        supported_targets: string[];
        batches: BatchRow[];
        selected_batch: SelectedBatch | null;
    };
}>();

const { t } = useI18n();

const createForm = useForm<{
    source_system: string;
    schema_version: string;
    intended_target: string;
    source_file: File | null;
}>({
    source_system: 'manual',
    schema_version: '1',
    intended_target: 'partner',
    source_file: null,
});

const confirmForm = useForm<{
    record_ids: string[];
}>({
    record_ids: [],
});

const validRecordIds = computed(
    () =>
        props.importWorkspace.selected_batch?.records
            .filter((record) => record.status === 'valid')
            .map((record) => record.id) ?? [],
);

const stageBatch = (): void => {
    createForm.post('/import/batches', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => createForm.reset('source_file'),
    });
};

const setFile = (event: Event): void => {
    const target = event.target as HTMLInputElement;
    createForm.source_file = target.files?.[0] ?? null;
};

const parseBatch = (batch: SelectedBatch): void => {
    useForm({}).post(`/import/batches/${batch.id}/parse`, {
        preserveScroll: true,
    });
};

const validateBatch = (batch: SelectedBatch): void => {
    useForm({}).post(`/import/batches/${batch.id}/validate`, {
        preserveScroll: true,
    });
};

const confirmSelected = (batch: SelectedBatch): void => {
    confirmForm.post(`/import/batches/${batch.id}/confirm`, {
        preserveScroll: true,
        onSuccess: () => confirmForm.reset('record_ids'),
    });
};

const toggleAllValid = (): void => {
    if (confirmForm.record_ids.length === validRecordIds.value.length) {
        confirmForm.record_ids = [];
        return;
    }

    confirmForm.record_ids = [...validRecordIds.value];
};

const prettyPayload = (payload: Record<string, unknown>): string =>
    JSON.stringify(payload, null, 2);
</script>

<template>
    <Head :title="t('import.title')" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <header class="border-b border-slate-200 pb-5">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                    {{ t('import.eyebrow') }}
                </p>
                <h1 class="mt-2 text-2xl font-semibold text-slate-950">
                    {{ t('import.title') }}
                </h1>
                <p class="mt-2 max-w-3xl text-sm text-slate-600">
                    {{ t('import.subtitle') }}
                </p>
                <p class="mt-2 text-xs text-slate-500">
                    {{ importWorkspace.business.name }}
                </p>
            </header>

            <section class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                    <p class="text-sm font-semibold text-amber-950">
                        {{ t('import.observedOnly') }}
                    </p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-sm text-slate-700">
                        {{ t('import.reconcile') }}
                    </p>
                </div>
            </section>

            <form
                v-if="importWorkspace.permissions.manage"
                class="space-y-5 rounded-xl border border-slate-200 bg-white p-5"
                @submit.prevent="stageBatch"
            >
                <h2 class="text-base font-semibold text-slate-950">
                    {{ t('import.newBatch') }}
                </h2>

                <div class="grid gap-4 md:grid-cols-3">
                    <label class="text-sm font-medium text-slate-800">
                        {{ t('import.sourceSystem') }}
                        <input
                            v-model="createForm.source_system"
                            type="text"
                            maxlength="120"
                            class="mt-2 min-h-11 w-full rounded-lg border-slate-300 text-sm"
                        />
                    </label>

                    <label class="text-sm font-medium text-slate-800">
                        {{ t('import.schemaVersion') }}
                        <input
                            v-model="createForm.schema_version"
                            type="text"
                            maxlength="80"
                            class="mt-2 min-h-11 w-full rounded-lg border-slate-300 text-sm"
                        />
                    </label>

                    <label class="text-sm font-medium text-slate-800">
                        {{ t('import.target') }}
                        <select
                            v-model="createForm.intended_target"
                            class="mt-2 min-h-11 w-full rounded-lg border-slate-300 text-sm"
                        >
                            <option
                                v-for="target in importWorkspace.supported_targets"
                                :key="target"
                                :value="target"
                            >
                                {{ target.replaceAll('_', ' ') }}
                            </option>
                        </select>
                    </label>
                </div>

                <label class="block text-sm font-medium text-slate-800">
                    {{ t('import.file') }}
                    <input
                        type="file"
                        accept=".csv,.json"
                        class="mt-2 block w-full rounded-lg border border-slate-300 p-2 text-sm"
                        @change="setFile"
                    />
                </label>

                <div
                    v-if="Object.keys(createForm.errors).length > 0"
                    class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800"
                >
                    <p
                        v-for="(message, field) in createForm.errors"
                        :key="field"
                    >
                        {{ message }}
                    </p>
                </div>

                <button
                    type="submit"
                    class="min-h-11 rounded-lg bg-slate-950 px-5 py-2 text-sm font-semibold text-white disabled:opacity-50"
                    :disabled="createForm.processing || createForm.source_file === null"
                >
                    {{ t('import.stage') }}
                </button>
            </form>

            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 class="text-base font-semibold text-slate-950">
                        {{ t('import.batches') }}
                    </h2>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">{{ t('import.status') }}</th>
                                <th class="px-4 py-3">{{ t('import.source') }}</th>
                                <th class="px-4 py-3">{{ t('import.target') }}</th>
                                <th class="px-4 py-3">{{ t('import.parser') }}</th>
                                <th class="px-4 py-3">{{ t('import.fingerprint') }}</th>
                                <th class="px-4 py-3">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr
                                v-for="batch in importWorkspace.batches"
                                :key="batch.id"
                            >
                                <td class="px-4 py-3 align-top">
                                    <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">
                                        {{ batch.status }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 align-top text-slate-700">
                                    <div>{{ batch.source_filename }}</div>
                                    <div class="mt-1 text-xs text-slate-500">
                                        {{ batch.source_system }} · {{ batch.source_type }} · schema {{ batch.schema_version }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 align-top text-xs text-slate-700">
                                    {{ batch.intended_target }}
                                </td>
                                <td class="px-4 py-3 align-top text-xs text-slate-600">
                                    {{ batch.parser_identity }}@{{ batch.parser_version }}
                                </td>
                                <td class="max-w-xs px-4 py-3 align-top">
                                    <code class="break-all text-[11px] text-slate-600">
                                        {{ batch.source_fingerprint }}
                                    </code>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <Link
                                        :href="`/import?batch=${batch.id}`"
                                        class="inline-flex min-h-10 items-center rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-900"
                                    >
                                        {{ t('import.open') }}
                                    </Link>
                                </td>
                            </tr>

                            <tr v-if="importWorkspace.batches.length === 0">
                                <td
                                    colspan="6"
                                    class="px-5 py-10 text-center text-sm text-slate-500"
                                >
                                    {{ t('import.empty') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section
                v-if="importWorkspace.selected_batch"
                class="space-y-4 rounded-xl border border-slate-200 bg-white p-5"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-slate-950">
                            {{ t('import.records') }}
                        </h2>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ importWorkspace.selected_batch.source_filename }}
                            · {{ importWorkspace.selected_batch.status }}
                        </p>
                    </div>

                    <div
                        v-if="importWorkspace.permissions.manage"
                        class="flex flex-wrap gap-2"
                    >
                        <button
                            v-if="importWorkspace.selected_batch.status === 'staged'"
                            type="button"
                            class="min-h-10 rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-900"
                            @click="parseBatch(importWorkspace.selected_batch)"
                        >
                            {{ t('import.parse') }}
                        </button>

                        <button
                            v-if="importWorkspace.selected_batch.status === 'parsed'"
                            type="button"
                            class="min-h-10 rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-900"
                            @click="validateBatch(importWorkspace.selected_batch)"
                        >
                            {{ t('import.validate') }}
                        </button>

                        <button
                            v-if="
                                importWorkspace.selected_batch.status === 'review_ready' &&
                                validRecordIds.length > 0
                            "
                            type="button"
                            class="min-h-10 rounded-lg bg-slate-950 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50"
                            :disabled="
                                confirmForm.processing ||
                                confirmForm.record_ids.length === 0
                            "
                            @click="confirmSelected(importWorkspace.selected_batch)"
                        >
                            {{ t('import.confirm') }}
                        </button>
                    </div>
                </div>

                <p
                    v-if="confirmForm.errors.record_ids"
                    class="text-sm text-rose-700"
                >
                    {{ confirmForm.errors.record_ids }}
                </p>

                <div
                    v-if="importWorkspace.selected_batch.records.length > 0"
                    class="overflow-x-auto"
                >
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-3 py-3">
                                    <button
                                        v-if="
                                            importWorkspace.selected_batch.status === 'review_ready' &&
                                            validRecordIds.length > 0
                                        "
                                        type="button"
                                        class="text-xs font-semibold text-slate-700 underline"
                                        @click="toggleAllValid"
                                    >
                                        Select
                                    </button>
                                </th>
                                <th class="px-3 py-3">{{ t('import.recordKey') }}</th>
                                <th class="px-3 py-3">{{ t('import.status') }}</th>
                                <th class="px-3 py-3">{{ t('import.payload') }}</th>
                                <th class="px-3 py-3">{{ t('import.issues') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr
                                v-for="record in importWorkspace.selected_batch.records"
                                :key="record.id"
                            >
                                <td class="px-3 py-3 align-top">
                                    <input
                                        v-if="record.status === 'valid'"
                                        v-model="confirmForm.record_ids"
                                        type="checkbox"
                                        :value="record.id"
                                        :aria-label="`Select ${record.source_record_key}`"
                                    />
                                </td>
                                <td class="px-3 py-3 align-top font-medium text-slate-900">
                                    {{ record.source_record_key }}
                                </td>
                                <td class="px-3 py-3 align-top">
                                    <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">
                                        {{ record.status }}
                                    </span>
                                    <p
                                        v-if="record.confirmation_error_code"
                                        class="mt-2 text-xs text-rose-700"
                                    >
                                        {{ record.confirmation_error_code }}
                                    </p>
                                </td>
                                <td class="max-w-lg px-3 py-3 align-top">
                                    <pre class="max-h-64 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-slate-50 p-3 text-[11px] text-slate-700">{{ prettyPayload(record.observed_payload) }}</pre>
                                </td>
                                <td class="max-w-sm px-3 py-3 align-top">
                                    <ul class="space-y-2">
                                        <li
                                            v-for="issue in record.issues"
                                            :key="`${issue.code}-${issue.field ?? ''}`"
                                            class="text-xs"
                                            :class="
                                                issue.severity === 'error'
                                                    ? 'text-rose-700'
                                                    : issue.severity === 'warning'
                                                      ? 'text-amber-700'
                                                      : 'text-slate-600'
                                            "
                                        >
                                            <strong>{{ issue.code }}</strong>
                                            <span v-if="issue.field">
                                                · {{ issue.field }}
                                            </span>
                                            <div>{{ issue.message }}</div>
                                        </li>
                                    </ul>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p
                    v-else
                    class="py-8 text-center text-sm text-slate-500"
                >
                    {{ t('import.noRecords') }}
                </p>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
