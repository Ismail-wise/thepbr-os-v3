<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import ActivityTimeline from '../../components/ActivityTimeline.vue';
import { useI18n } from '../../i18n/useI18n';
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';

type ActivityItem = {
    eventType: string;
    actorType: string;
    isCurrentUser: boolean;
    occurredAt: string;
    versionNumber?: number;
    fromState?: string;
    toState?: string;
};

type ActivityPage = {
    items: ActivityItem[];
    nextCursor: string | null;
};

const props = defineProps<{
    activity: ActivityPage;
}>();

const { t } = useI18n();
const items = ref<ActivityItem[]>([...props.activity.items]);
const nextCursor = ref<string | null>(props.activity.nextCursor);
const loading = ref(false);

const loadMore = () => {
    if (loading.value || nextCursor.value === null) {
        return;
    }

    loading.value = true;

    router.get(
        '/records/activity',
        { cursor: nextCursor.value },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['activity'],
            onSuccess: (page) => {
                const incoming = page.props.activity as ActivityPage;

                items.value.push(...incoming.items);
                nextCursor.value = incoming.nextCursor;
            },
            onFinish: () => {
                loading.value = false;
            },
        },
    );
};
</script>

<template>
    <AuthenticatedLayout>
        <main
            class="min-h-screen bg-white px-4 py-8 text-slate-950 sm:px-6 lg:px-8"
        >
            <section class="mx-auto max-w-5xl">
                <header class="border-b border-slate-200 pb-6">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        {{ t('activity.title') }}
                    </h1>

                    <p class="mt-2 max-w-3xl text-sm text-slate-600">
                        {{ t('activity.description') }}
                    </p>
                </header>

                <section class="py-6" aria-live="polite">
                    <ActivityTimeline :items="items" />

                    <div v-if="nextCursor !== null" class="mt-6">
                        <button
                            type="button"
                            class="inline-flex min-h-11 items-center justify-center border border-slate-300 bg-white px-4 py-2 text-sm font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="loading"
                            @click="loadMore"
                        >
                            {{
                                loading
                                    ? t('activity.loading')
                                    : t('activity.loadMore')
                            }}
                        </button>
                    </div>
                </section>
            </section>
        </main>
    </AuthenticatedLayout>
</template>
