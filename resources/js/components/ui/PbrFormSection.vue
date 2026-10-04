<script setup lang="ts">
withDefaults(
    defineProps<{
        title: string;
        instruction?: string;
        eyebrow?: string;
        numbered?: string;
    }>(),
    {
        instruction: undefined,
        eyebrow: undefined,
        numbered: undefined,
    },
);
</script>

<template>
    <section class="pbr-form-section">
        <header class="min-w-0">
            <div
                v-if="eyebrow || numbered"
                class="mb-2 flex min-w-0 flex-wrap items-center gap-2"
            >
                <span
                    v-if="numbered"
                    class="grid h-7 min-w-7 shrink-0 place-items-center rounded-full bg-[var(--pbr-green-soft)] px-2 text-xs font-black text-[var(--pbr-green-dark)]"
                >
                    {{ numbered }}
                </span>
                <p
                    v-if="eyebrow"
                    class="pbr-safe-copy text-xs font-black uppercase tracking-[0.12em] text-[var(--pbr-muted)]"
                >
                    {{ eyebrow }}
                </p>
            </div>

            <h2 class="pbr-safe-copy text-lg font-black leading-7 text-[var(--pbr-ink)]">
                {{ title }}
            </h2>

            <p
                v-if="instruction"
                class="pbr-safe-copy mt-1.5 text-sm leading-6 text-[var(--pbr-muted)]"
            >
                {{ instruction }}
            </p>
        </header>

        <div class="pbr-form-stack mt-5">
            <slot />
        </div>

        <footer
            v-if="$slots.actions"
            class="mt-5 border-t border-[var(--pbr-line)] pt-4"
        >
            <slot name="actions" />
        </footer>
    </section>
</template>
