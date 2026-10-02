<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        tone?: 'neutral' | 'success' | 'warning' | 'info' | 'danger';
    }>(),
    { tone: 'neutral' },
);

const toneClass = computed(
    () =>
        ({
            neutral: 'border-[var(--pbr-line)] bg-[var(--pbr-surface-soft)] text-[var(--pbr-ink-soft)]',
            success: 'border-[#cfe5d6] bg-[var(--pbr-green-soft)] text-[var(--pbr-green-dark)]',
            warning: 'border-[#efd5bb] bg-[var(--pbr-amber-soft)] text-[#8b4f17]',
            info: 'border-[#d3e0ef] bg-[var(--pbr-blue-soft)] text-[#355f8f]',
            danger: 'border-[#eccbc5] bg-[var(--pbr-red-soft)] text-[var(--pbr-red)]',
        })[props.tone],
);
</script>

<template>
    <span
        class="inline-flex min-h-7 items-center rounded-full border px-2.5 py-1 text-xs font-bold"
        :class="toneClass"
    >
        <slot />
    </span>
</template>
