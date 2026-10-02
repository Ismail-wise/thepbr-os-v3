<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useI18n } from '../../i18n/useI18n';
import type { UpcomingItem } from './types';

defineProps<{
    items: UpcomingItem[];
}>();

const { t, uiLanguageMode } = useI18n();

const formatDate = (value: string): string => {
    const locale = uiLanguageMode.value === 'my' ? 'my-MM' : 'en-GB';

    return new Intl.DateTimeFormat(locale, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(new Date(value));
};
</script>

<template>
    <section class="pbr-surface overflow-hidden">
        <div class="border-b border-[var(--pbr-line)] px-5 py-4">
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[#7b887f]">
                {{ t('controlCenter.upcoming.eyebrow') }}
            </p>
            <h2 class="mt-1 text-lg font-black tracking-[-0.02em]">
                {{ t('controlCenter.upcoming.title') }}
            </h2>
        </div>

        <div v-if="items.length > 0" class="divide-y divide-[var(--pbr-line)]">
            <Link
                v-for="(item, index) in items"
                :key="`${item.kind}|${item.dueAt}|${index}`"
                :href="item.route"
                class="group flex items-center justify-between gap-4 px-5 py-4 focus-visible:outline-none hover:bg-[var(--pbr-surface-soft)]"
            >
                <div class="min-w-0">
                    <p class="truncate text-sm font-black text-[var(--pbr-ink)]">
                        {{
                            item.title ??
                            (item.kind === 'review'
                                ? t('controlCenter.upcoming.review')
                                : t('controlCenter.upcoming.action'))
                        }}
                    </p>
                    <p class="mt-1 text-xs text-[var(--pbr-muted)]">
                        {{ item.kind === 'review' ? t('controlCenter.upcoming.reviewType') : t('controlCenter.upcoming.actionType') }}
                    </p>
                </div>
                <time
                    :datetime="item.dueAt"
                    class="shrink-0 rounded-full border border-[#e2dccb] bg-[var(--pbr-gold-soft)] px-3 py-1.5 text-[11px] font-black text-[#73591e]"
                >
                    {{ formatDate(item.dueAt) }}
                </time>
            </Link>
        </div>

        <div v-else class="px-5 py-6">
            <p class="text-sm font-bold text-[var(--pbr-ink-soft)]">
                {{ t('controlCenter.upcoming.emptyTitle') }}
            </p>
            <p class="mt-1 text-xs leading-5 text-[var(--pbr-muted)]">
                {{ t('controlCenter.upcoming.emptyBody') }}
            </p>
        </div>
    </section>
</template>
