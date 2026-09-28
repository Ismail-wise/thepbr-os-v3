<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import { useI18n } from '../../i18n/useI18n';

type SearchItem = {
    source_type: string;
    source_id: string;
    title: string;
    snippet: string | null;
    route: string | null;
    rank: number;
};

const props = defineProps<{
    business: {
        id: string;
        name: string;
    };
    searchResults: {
        query: string;
        count: number;
        items: SearchItem[];
        suggestions: string[];
    };
}>();

const { t } = useI18n();
const query = ref(props.searchResults.query);

const submit = (): void => {
    router.get(
        '/search',
        { q: query.value },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
};

const useSuggestion = (value: string): void => {
    query.value = value;
    submit();
};

const sourceLabel = (sourceType: string): string =>
    sourceType.replaceAll('_', ' ');
</script>

<template>
    <Head :title="t('search.title')" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-6xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <header class="border-b border-slate-200 pb-5">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                    {{ t('search.eyebrow') }}
                </p>
                <h1 class="mt-2 text-2xl font-semibold text-slate-950">
                    {{ t('search.title') }}
                </h1>
                <p class="mt-2 max-w-3xl text-sm text-slate-600">
                    {{ t('search.subtitle') }}
                </p>
                <p class="mt-2 text-xs text-slate-500">
                    {{ business.name }}
                </p>
            </header>

            <form
                class="rounded-xl border border-slate-200 bg-white p-4 sm:p-5"
                role="search"
                @submit.prevent="submit"
            >
                <label class="block text-sm font-medium text-slate-900">
                    {{ t('search.label') }}
                    <div class="mt-2 flex flex-col gap-2 sm:flex-row">
                        <input
                            v-model="query"
                            type="search"
                            maxlength="200"
                            autocomplete="off"
                            class="min-h-11 flex-1 rounded-lg border-slate-300 text-sm"
                            :placeholder="t('search.placeholder')"
                        />
                        <button
                            type="submit"
                            class="min-h-11 rounded-lg bg-slate-950 px-5 py-2 text-sm font-semibold text-white"
                        >
                            {{ t('search.action') }}
                        </button>
                    </div>
                </label>

                <p class="mt-3 text-xs text-slate-500">
                    {{ t('search.privacy') }}
                </p>
            </form>

            <section
                v-if="searchResults.suggestions.length > 0"
                class="rounded-xl border border-slate-200 bg-white p-4"
            >
                <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                    {{ t('search.suggestions') }}
                </h2>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button
                        v-for="suggestion in searchResults.suggestions"
                        :key="suggestion"
                        type="button"
                        class="min-h-10 rounded-full border border-slate-300 px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-500"
                        @click="useSuggestion(suggestion)"
                    >
                        {{ suggestion }}
                    </button>
                </div>
            </section>

            <section
                v-if="searchResults.query !== ''"
                class="overflow-hidden rounded-xl border border-slate-200 bg-white"
            >
                <div class="flex items-center justify-between gap-4 border-b border-slate-200 px-5 py-4">
                    <div>
                        <h2 class="text-sm font-semibold text-slate-950">
                            {{ t('search.results') }}
                        </h2>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ searchResults.count }} {{ t('search.authorizedMatches') }}
                        </p>
                    </div>
                    <span class="max-w-xs truncate text-xs text-slate-500">
                        “{{ searchResults.query }}”
                    </span>
                </div>

                <div v-if="searchResults.items.length > 0" class="divide-y divide-slate-100">
                    <article
                        v-for="item in searchResults.items"
                        :key="`${item.source_type}:${item.source_id}`"
                        class="px-5 py-4"
                    >
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-sm font-semibold text-slate-950">
                                        {{ item.title }}
                                    </h3>
                                    <span class="rounded-full bg-slate-100 px-2 py-1 text-[11px] font-medium capitalize text-slate-600">
                                        {{ sourceLabel(item.source_type) }}
                                    </span>
                                </div>
                                <p
                                    v-if="item.snippet"
                                    class="mt-2 line-clamp-3 text-sm text-slate-600"
                                >
                                    {{ item.snippet }}
                                </p>
                            </div>

                            <Link
                                v-if="item.route"
                                :href="item.route"
                                class="shrink-0 text-sm font-semibold text-slate-900 underline underline-offset-4"
                            >
                                {{ t('search.open') }}
                            </Link>
                        </div>
                    </article>
                </div>

                <div v-else class="px-5 py-10 text-center text-sm text-slate-500">
                    {{ t('search.empty') }}
                </div>
            </section>

            <section
                v-else
                class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center"
            >
                <p class="text-sm font-medium text-slate-700">
                    {{ t('search.start') }}
                </p>
                <p class="mt-2 text-xs text-slate-500">
                    {{ t('search.startHelp') }}
                </p>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
