<script setup lang="ts">
import { useI18n } from '../../i18n/useI18n';
import type { TranslationKey } from '../../i18n/catalog';
import type {
    BusinessSummary,
    GovernanceSummary,
    HealthSummary,
} from './types';

defineProps<{
    business: BusinessSummary;
    health: HealthSummary | null;
    governance: GovernanceSummary | null;
    currentEffectiveCount: number;
}>();

const { t } = useI18n();

const stageLabel = (stage: string): string =>
    t(`businessStage.${stage}` as TranslationKey);
</script>

<template>
    <section>
        <div class="mb-4 flex items-end justify-between gap-4">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[#7b887f]">
                    {{ t('controlCenter.snapshot.eyebrow') }}
                </p>
                <h2 class="mt-1 text-xl font-black tracking-[-0.02em]">
                    {{ t('controlCenter.snapshot.title') }}
                </h2>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-[20px] border border-[#d9e5dc] bg-white p-5 shadow-[0_10px_26px_rgb(16_35_26_/_5%)]">
                <p class="text-xs font-bold text-[var(--pbr-muted)]">
                    {{ t('controlCenter.snapshot.stage') }}
                </p>
                <p class="mt-2 text-xl font-black tracking-[-0.025em]">
                    {{ stageLabel(business.stage) }}
                </p>
            </article>

            <article class="rounded-[20px] border border-[#e6dcc5] bg-[linear-gradient(145deg,#fff,#fcf8ee)] p-5 shadow-[0_10px_26px_rgb(16_35_26_/_4%)]">
                <p class="text-xs font-bold text-[var(--pbr-muted)]">
                    {{ t('controlCenter.snapshot.currency') }}
                </p>
                <p class="mt-2 text-xl font-black tracking-[-0.025em] text-[#6f571e]">
                    {{ business.baseCurrency }}
                </p>
            </article>

            <article class="rounded-[20px] border border-[#d6e4da] bg-[linear-gradient(145deg,#fff,#f5faf6)] p-5 shadow-[0_10px_26px_rgb(16_35_26_/_4%)]">
                <p class="text-xs font-bold text-[var(--pbr-muted)]">
                    {{ t('controlCenter.snapshot.effectiveAreas') }}
                </p>
                <p class="mt-2 text-xl font-black tracking-[-0.025em] text-[var(--pbr-green-dark)]">
                    {{ currentEffectiveCount }}
                </p>
            </article>

            <article class="rounded-[20px] border border-[#d9e1eb] bg-[linear-gradient(145deg,#fff,#f7f9fc)] p-5 shadow-[0_10px_26px_rgb(16_35_26_/_4%)]">
                <p class="text-xs font-bold text-[var(--pbr-muted)]">
                    {{ t('controlCenter.snapshot.openDecisions') }}
                </p>
                <p class="mt-2 text-xl font-black tracking-[-0.025em] text-[var(--pbr-blue)]">
                    {{ governance?.openDecisions ?? 0 }}
                </p>
                <p
                    v-if="governance === null"
                    class="mt-1 text-[11px] leading-5 text-[var(--pbr-muted)]"
                >
                    {{ t('controlCenter.snapshot.notAvailable') }}
                </p>
            </article>
        </div>
    </section>
</template>
