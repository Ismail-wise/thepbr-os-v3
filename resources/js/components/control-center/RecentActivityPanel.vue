<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useI18n } from '../../i18n/useI18n';
import { activityLabelKeys } from './labels';
import type { ActivityItem } from './types';

defineProps<{
    items: ActivityItem[] | null;
}>();

const { t, uiLanguageMode } = useI18n();

const formatDateTime = (value: string): string => {
    const locale = uiLanguageMode.value === 'my' ? 'my-MM' : 'en-GB';

    return new Intl.DateTimeFormat(locale, {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
};

const actorLabel = (item: ActivityItem): string => {
    if (item.actor === 'you') {
        return t('controlCenter.activity.you');
    }

    return item.actor === 'system'
        ? t('controlCenter.activity.system')
        : t('controlCenter.activity.member');
};
</script>

<template>
    <section class="pbr-surface overflow-hidden">
        <div class="flex items-end justify-between gap-4 border-b border-[var(--pbr-line)] px-5 py-4">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[#7b887f]">
                    {{ t('controlCenter.activity.eyebrow') }}
                </p>
                <h2 class="mt-1 text-lg font-black tracking-[-0.02em]">
                    {{ t('controlCenter.activity.title') }}
                </h2>
            </div>

            <Link
                v-if="items !== null"
                href="/records/activity"
                class="text-xs font-black text-[var(--pbr-green)] hover:text-[var(--pbr-green-dark)] focus-visible:outline-none"
            >
                {{ t('controlCenter.activity.viewAll') }}
            </Link>
        </div>

        <div v-if="items !== null && items.length > 0" class="divide-y divide-[var(--pbr-line)]">
            <div
                v-for="(item, index) in items"
                :key="`${item.occurredAt}|${item.kind}|${index}`"
                class="flex gap-3 px-5 py-4"
            >
                <span
                    aria-hidden="true"
                    class="mt-1 grid h-7 w-7 shrink-0 place-items-center rounded-full border border-[#d5e3d9] bg-[var(--pbr-green-soft)] text-[10px] font-black text-[var(--pbr-green)]"
                >
                    •
                </span>

                <div class="min-w-0 flex-1">
                    <p class="text-sm font-black leading-5 text-[var(--pbr-ink)]">
                        {{ t(activityLabelKeys[item.kind] ?? 'controlCenter.activity.general') }}
                    </p>
                    <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-[var(--pbr-muted)]">
                        <span class="font-bold">{{ actorLabel(item) }}</span>
                        <span aria-hidden="true">•</span>
                        <time :datetime="item.occurredAt">
                            {{ formatDateTime(item.occurredAt) }}
                        </time>
                    </div>
                </div>
            </div>
        </div>

        <div v-else-if="items !== null" class="px-5 py-6">
            <p class="text-sm font-bold text-[var(--pbr-ink-soft)]">
                {{ t('controlCenter.activity.emptyTitle') }}
            </p>
            <p class="mt-1 text-xs leading-5 text-[var(--pbr-muted)]">
                {{ t('controlCenter.activity.emptyBody') }}
            </p>
        </div>

        <div v-else class="px-5 py-6">
            <p class="text-sm font-bold text-[var(--pbr-ink-soft)]">
                {{ t('controlCenter.activity.privateTitle') }}
            </p>
            <p class="mt-1 text-xs leading-5 text-[var(--pbr-muted)]">
                {{ t('controlCenter.activity.privateBody') }}
            </p>
        </div>
    </section>
</template>
