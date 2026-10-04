<script setup lang="ts">
withDefaults(
    defineProps<{
        active: boolean;
        message: string;
        kind?: 'navigation' | 'save' | 'calculation' | 'ai';
        overlay?: boolean;
    }>(),
    {
        kind: 'navigation',
        overlay: false,
    },
);
</script>

<template>
    <Transition name="pbr-fade">
        <div
            v-if="active"
            role="status"
            aria-live="polite"
            aria-busy="true"
            class="min-w-0"
            :class="
                overlay
                    ? 'absolute inset-0 z-40 grid place-items-center bg-white/82 p-4 backdrop-blur-[2px]'
                    : ''
            "
            :data-loading-kind="kind"
        >
            <div
                class="inline-flex max-w-full items-center gap-3 rounded-2xl border border-[var(--pbr-line)] bg-white/96 px-4 py-3 text-sm font-bold text-[var(--pbr-ink-soft)] shadow-[var(--pbr-shadow-sm)]"
            >
                <span
                    class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-[var(--pbr-green-soft)]"
                    aria-hidden="true"
                >
                    <span class="pbr-busy-spinner block h-3.5 w-3.5 rounded-full border-2 border-[var(--pbr-green)] border-r-transparent" />
                </span>
                <span class="min-w-0 break-words leading-6">
                    {{ message }}
                </span>
            </div>
        </div>
    </Transition>
</template>
