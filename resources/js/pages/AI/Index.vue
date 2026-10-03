<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import { useI18n } from '../../i18n/useI18n';

const props = defineProps<{
    aiWorkspace: {
        business: {
            name: string;
        };
        enabled: boolean;
        advisory_only: boolean;
    };
    aiResponse: {
        status: string;
        answer: string;
        advisory_only: boolean;
    } | null;
}>();

const { t } = useI18n();

const form = useForm({
    prompt: '',
});

const ask = (): void => {
    form.post('/ai/ask', {
        preserveScroll: true,
        preserveState: true,
    });
};
</script>

<template>
    <Head :title="t('ai.title')" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-5xl space-y-6 px-4 py-5 sm:px-6 sm:py-6 lg:px-8 lg:py-7">
            <header class="pbr-surface p-5 sm:p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                    {{ t('ai.eyebrow') }}
                </p>
                <h1 class="mt-2 text-2xl font-semibold text-slate-950">
                    {{ t('ai.title') }}
                </h1>
                <p class="mt-2 max-w-3xl text-sm text-slate-600">
                    {{ t('ai.subtitle') }}
                </p>
                <p class="mt-2 text-xs text-slate-500">
                    {{ aiWorkspace.business.name }}
                </p>
            </header>

            <section
                class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950"
                aria-label="PBR AI advisory boundary"
            >
                <p class="font-semibold">
                    {{ t('ai.advisory') }}
                </p>
                <p class="mt-2 text-amber-900">
                    {{ t('ai.draftNotice') }}
                </p>
            </section>

            <section
                v-if="!aiWorkspace.enabled"
                class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700"
            >
                {{ t('ai.disabled') }}
            </section>

            <form
                class="pbr-surface p-5"
                @submit.prevent="ask"
            >
                <label
                    for="pbr-ai-prompt"
                    class="block text-sm font-semibold text-slate-950"
                >
                    {{ t('ai.prompt') }}
                </label>

                <textarea
                    id="pbr-ai-prompt"
                    v-model="form.prompt"
                    rows="7"
                    maxlength="4000"
                    class="mt-3 w-full rounded-lg border-slate-300 text-sm"
                    :placeholder="t('ai.placeholder')"
                    :aria-invalid="form.errors.prompt ? 'true' : 'false'"
                    :aria-describedby="
                        form.errors.prompt
                            ? 'pbr-ai-prompt-error pbr-ai-privacy'
                            : 'pbr-ai-privacy'
                    "
                />

                <p
                    id="pbr-ai-privacy"
                    class="mt-2 text-xs text-slate-500"
                >
                    {{ t('ai.privacy') }}
                </p>

                <p
                    v-if="form.errors.prompt"
                    id="pbr-ai-prompt-error"
                    class="mt-2 text-sm text-rose-700"
                >
                    {{ form.errors.prompt }}
                </p>

                <button
                    type="submit"
                    class="mt-4 min-h-11 rounded-lg bg-slate-950 px-5 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="
                        form.processing ||
                        form.prompt.trim().length === 0 ||
                        !aiWorkspace.enabled
                    "
                >
                    {{ t('ai.ask') }}
                </button>
            </form>

            <section
                class="pbr-surface p-5"
                aria-live="polite"
            >
                <h2 class="text-sm font-semibold text-slate-950">
                    {{ t('ai.response') }}
                </h2>

                <p
                    v-if="aiResponse"
                    class="mt-3 whitespace-pre-wrap text-sm leading-6 text-slate-700"
                >
                    {{ aiResponse.answer }}
                </p>

                <p
                    v-else
                    class="mt-3 text-sm text-slate-500"
                >
                    {{ t('ai.noResponse') }}
                </p>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
