<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        href?: string;
        type?: 'button' | 'submit' | 'reset';
        variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
        disabled?: boolean;
        busy?: boolean;
        busyLabel?: string;
    }>(),
    {
        href: undefined,
        type: 'button',
        variant: 'secondary',
        disabled: false,
        busy: false,
        busyLabel: 'Working...',
    },
);

const blocked = computed(() => props.disabled || props.busy);

const classes = computed(() => {
    const base =
        'pbr-touch inline-flex min-w-0 items-center justify-center gap-2 rounded-xl px-4 py-2 text-sm font-bold leading-5 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-55';

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
        :href="blocked ? undefined : href"
        :class="classes"
        :aria-disabled="blocked ? 'true' : undefined"
        :aria-busy="busy ? 'true' : undefined"
        :tabindex="blocked ? -1 : undefined"
    >
        <span
            v-if="busy"
            class="pbr-busy-spinner h-3.5 w-3.5 shrink-0 rounded-full border-2 border-current border-r-transparent"
            aria-hidden="true"
        />
        <span class="min-w-0 break-words">
            <template v-if="busy">{{ busyLabel }}</template>
            <slot v-else />
        </span>
    </Link>

    <button
        v-else
        :type="type"
        :disabled="blocked"
        :class="classes"
        :aria-busy="busy ? 'true' : undefined"
    >
        <span
            v-if="busy"
            class="pbr-busy-spinner h-3.5 w-3.5 shrink-0 rounded-full border-2 border-current border-r-transparent"
            aria-hidden="true"
        />
        <span class="min-w-0 break-words">
            <template v-if="busy">{{ busyLabel }}</template>
            <slot v-else />
        </span>
    </button>
</template>
