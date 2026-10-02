<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from '../../i18n/useI18n';
import type { HealthSummary } from './types';

const props = defineProps<{
    summary: HealthSummary | null;
    currentEffectiveCount: number;
}>();

const { t } = useI18n();

const total = computed(() => {
    if (props.summary === null) return 0;

    return (
        props.summary.ready +
        props.summary.review +
        props.summary.blocked +
        props.summary.setupNeeded
    );
});
</script>

<template>
    <section
        class="overflow-hidden rounded-[22px] border border-[#d9e5dc] bg-[linear-gradient(145deg,#fff,#f8faf8)] shadow-[0_12px_30px_rgb(16_35_26_/_5%)]"
    >
        <div class="grid gap-0 sm:grid-cols-2 lg:grid-cols-5">
            <div class="border-b border-[var(--pbr-line)] p-5 sm:col-span-2 sm:border-b-0 sm:border-r lg:col-span-1">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]">
                    {{ t('controlCenter.health.eyebrow') }}
                </p>
                <h2 class="mt-1 text-lg font-black tracking-[-0.02em]">
                    {{ t('controlCenter.health.title') }}
                </h2>
                <p class="mt-2 text-xs leading-5 text-[var(--pbr-muted)]">
                    {{
                        summary
                            ? t('controlCenter.health.basedOnAuthorized')
                            : t('controlCenter.health.unavailable')
                    }}
                </p>
            </div>

            <template v-if="summary">
                <div class="border-b border-[var(--pbr-line)] p-5 sm:border-r lg:border-b-0">
                    <p class="text-2xl font-black tracking-[-0.03em] text-[var(--pbr-green-dark)]">
                        {{ summary.ready }}<span class="text-sm text-[var(--pbr-muted)]">/{{ total }}</span>
                    </p>
                    <p class="mt-1 text-xs font-bold text-[var(--pbr-muted)]">
                        {{ t('controlCenter.health.ready') }}
                    </p>
                </div>
                <div class="border-b border-[var(--pbr-line)] p-5 lg:border-b-0 lg:border-r">
                    <p class="text-2xl font-black tracking-[-0.03em] text-[var(--pbr-amber)]">
                        {{ summary.review }}
                    </p>
                    <p class="mt-1 text-xs font-bold text-[var(--pbr-muted)]">
                        {{ t('controlCenter.health.review') }}
                    </p>
                </div>
                <div class="border-b border-[var(--pbr-line)] p-5 sm:border-b-0 sm:border-r">
                    <p class="text-2xl font-black tracking-[-0.03em] text-[var(--pbr-red)]">
                        {{ summary.blocked }}
                    </p>
                    <p class="mt-1 text-xs font-bold text-[var(--pbr-muted)]">
                        {{ t('controlCenter.health.blocked') }}
                    </p>
                </div>
                <div class="p-5">
                    <p class="text-2xl font-black tracking-[-0.03em] text-[var(--pbr-blue)]">
                        {{ currentEffectiveCount }}
                    </p>
                    <p class="mt-1 text-xs font-bold text-[var(--pbr-muted)]">
                        {{ t('controlCenter.health.currentEffective') }}
                    </p>
                </div>
            </template>

            <div v-else class="p-5 sm:col-span-2 lg:col-span-4">
                <p class="text-sm font-semibold leading-6 text-[var(--pbr-muted)]">
                    {{ t('controlCenter.health.permissionEmpty') }}
                </p>
            </div>
        </div>
    </section>
</template>
