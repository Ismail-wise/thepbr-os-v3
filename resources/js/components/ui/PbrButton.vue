<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        href?: string;
        type?: 'button' | 'submit' | 'reset';
        variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
        disabled?: boolean;
    }>(),
    {
        href: undefined,
        type: 'button',
        variant: 'secondary',
        disabled: false,
    },
);

const classes = computed(() => {
    const base =
        'pbr-touch inline-flex items-center justify-center rounded-xl px-4 py-2 text-sm font-bold focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-55';

    const variants = {
        primary:
            'border border-[var(--pbr-green)] bg-[var(--pbr-green)] text-white shadow-[0_8px_20px_rgb(13_106_59_/_14%)] hover:bg-[var(--pbr-green-dark)]',
        secondary:
            'border border-[var(--pbr-line-strong)] bg-white text-[var(--pbr-ink-soft)] hover:border-[#b7cbbd] hover:text-[var(--pbr-green-dark)]',
        ghost:
            'border border-transparent bg-transparent text-[var(--pbr-ink-soft)] hover:bg-[var(--pbr-green-soft)] hover:text-[var(--pbr-green-dark)]',
        danger:
            'border border-[#e8c5bf] bg-[var(--pbr-red-soft)] text-[var(--pbr-red)] hover:border-[#d8a59b]',
    } as const;

    return [base, variants[props.variant]].join(' ');
});
</script>

<template>
    <Link
        v-if="href"
        :href="href"
        :class="classes"
        :aria-disabled="disabled ? 'true' : undefined"
    >
        <slot />
    </Link>

    <button
        v-else
        :type="type"
        :disabled="disabled"
        :class="classes"
    >
        <slot />
    </button>
</template>
