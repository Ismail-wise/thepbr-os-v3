<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useI18n } from '../../i18n/useI18n';
import { attentionLabelKeys } from './labels';
import type { NextActionItem } from './types';

defineProps<{
    item: NextActionItem;
    sequence: number;
}>();

const { t } = useI18n();
</script>

<template>
    <Link
        :href="item.route"
        class="group relative overflow-hidden rounded-[20px] border border-[#d9e5dc] bg-white p-5 shadow-[0_10px_26px_rgb(16_35_26_/_5%)] focus-visible:outline-none"
    >
        <div
            aria-hidden="true"
            class="absolute inset-y-0 left-0 w-[3px]"
            :class="
                item.state === 'blocked'
                    ? 'bg-[var(--pbr-red)]'
                    : item.state === 'review'
                      ? 'bg-[var(--pbr-amber)]'
                      : item.state === 'action'
                        ? 'bg-[var(--pbr-green)]'
                        : 'bg-[var(--pbr-blue)]'
            "
        />

        <div class="flex items-start justify-between gap-4">
            <span
                class="grid h-8 w-8 shrink-0 place-items-center rounded-full border border-[#d7e3da] bg-[var(--pbr-surface-soft)] text-xs font-black text-[var(--pbr-ink-soft)]"
            >
                {{ sequence }}
            </span>

            <span
                v-if="item.count > 1"
                class="inline-flex min-h-7 items-center rounded-full border border-[#d7e3da] bg-[var(--pbr-surface-soft)] px-2.5 text-[11px] font-black text-[var(--pbr-muted)]"
            >
                {{ item.count }}
            </span>
        </div>

        <h3 class="mt-4 text-[15px] font-black leading-6 text-[var(--pbr-ink)]">
            {{ t(attentionLabelKeys[item.key] ?? 'controlCenter.attention.general') }}
        </h3>

        <p class="mt-2 text-xs leading-5 text-[var(--pbr-muted)]">
            {{ t('controlCenter.nextActions.helper') }}
        </p>

        <span
            class="mt-4 inline-flex items-center gap-1 text-xs font-black text-[var(--pbr-green)] group-hover:text-[var(--pbr-green-dark)]"
        >
            {{ t('controlCenter.nextActions.open') }}
            <span aria-hidden="true">→</span>
        </span>
    </Link>
</template>
