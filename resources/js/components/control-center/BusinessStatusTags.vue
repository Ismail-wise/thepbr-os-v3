<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from '../../i18n/useI18n';
import type { TranslationKey } from '../../i18n/catalog';
import type { BusinessSummary } from './types';

const props = defineProps<{
    business: BusinessSummary;
}>();

const { t } = useI18n();

const stageKey = computed(
    () => `businessStage.${props.business.stage}` as TranslationKey,
);

const workspaceKey = computed(
    () =>
        `controlCenter.workspaceStatus.${props.business.workspaceStatus}` as TranslationKey,
);

const setupKey = computed(
    () =>
        (props.business.setupPhase === null
            ? 'controlCenter.setupPhase.notSet'
            : `controlCenter.setupPhase.${props.business.setupPhase}`) as TranslationKey,
);
</script>

<template>
    <div class="flex flex-wrap gap-2">
        <span
            class="inline-flex min-h-8 items-center rounded-full border border-[#cfe2d5] bg-white/80 px-3 text-xs font-extrabold text-[var(--pbr-green-dark)] shadow-[0_4px_12px_rgb(16_35_26_/_4%)]"
        >
            {{ t(stageKey) }}
        </span>
        <span
            class="inline-flex min-h-8 items-center rounded-full border border-[#e7ddc4] bg-[var(--pbr-gold-soft)] px-3 text-xs font-extrabold text-[#745a1d]"
        >
            {{ t(setupKey) }}
        </span>
        <span
            class="inline-flex min-h-8 items-center gap-2 rounded-full border border-[#d9e3dc] bg-white/82 px-3 text-xs font-extrabold text-[var(--pbr-ink-soft)]"
        >
            <span
                aria-hidden="true"
                class="h-2 w-2 rounded-full"
                :class="
                    business.workspaceStatus === 'active'
                        ? 'bg-[#1f9b58]'
                        : business.workspaceStatus === 'restricted'
                          ? 'bg-[#c98a2f]'
                          : 'bg-[#8a9890]'
                "
            />
            {{ t(workspaceKey) }}
        </span>
        <span
            class="inline-flex min-h-8 items-center rounded-full border border-[#d9e3dc] bg-white/82 px-3 text-xs font-extrabold text-[var(--pbr-ink-soft)]"
        >
            {{ business.baseCurrency }}
        </span>
    </div>
</template>
