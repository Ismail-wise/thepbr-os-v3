<script setup lang="ts">
defineProps<{
    eyebrow: string;
    title: string;
    subtitle: string;
}>();
</script>

<template>
    <header
        class="relative overflow-hidden rounded-[26px] border border-[#cfe0d4] bg-[linear-gradient(145deg,#ffffff_0%,#f4f9f5_64%,#fbf7eb_100%)] p-5 shadow-[0_18px_46px_rgb(16_35_26_/_7%)] sm:p-7"
    >
        <div
            aria-hidden="true"
            class="pointer-events-none absolute -right-12 -top-14 h-40 w-40 rounded-full bg-[#d2a743]/10 blur-3xl"
        />
        <div
            aria-hidden="true"
            class="pointer-events-none absolute inset-y-0 left-0 w-1 bg-gradient-to-b from-[var(--pbr-green)] to-[var(--pbr-gold)]"
        />

        <div class="relative flex flex-wrap items-start justify-between gap-5">
            <div class="max-w-3xl">
                <p
                    class="text-[10px] font-black uppercase tracking-[0.2em] text-[var(--pbr-green)]"
                >
                    {{ eyebrow }}
                </p>
                <h1
                    class="mt-2 text-2xl font-black tracking-[-0.03em] text-[var(--pbr-ink)] sm:text-3xl"
                >
                    {{ title }}
                </h1>
                <p
                    class="mt-2 max-w-2xl text-sm leading-6 text-[var(--pbr-muted)] sm:text-[15px]"
                >
                    {{ subtitle }}
                </p>
            </div>

            <div v-if="$slots.actions" class="relative flex flex-wrap gap-2">
                <slot name="actions" />
            </div>
        </div>
    </header>
</template>
