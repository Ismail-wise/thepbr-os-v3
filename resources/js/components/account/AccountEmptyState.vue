<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    title: string;
    body: string;
    actionHref?: string;
    actionLabel?: string;
}>();
</script>

<template>
    <div
        class="rounded-[20px] border border-dashed border-[#cddcd1] bg-[linear-gradient(145deg,#fff,#f7faf7)] p-6 text-center shadow-[0_8px_24px_rgb(16_35_26_/_3%)]"
    >
        <div
            aria-hidden="true"
            class="mx-auto grid h-10 w-10 place-items-center rounded-full border border-[#cfe2d5] bg-[#edf6f0] text-[var(--pbr-green)]"
        >
            <span class="h-2 w-2 rounded-full bg-current" />
        </div>
        <p class="mt-3 font-black text-[var(--pbr-ink)]">
            {{ title }}
        </p>
        <p class="mx-auto mt-1 max-w-xl text-sm leading-6 text-[var(--pbr-muted)]">
            {{ body }}
        </p>

        <Link
            v-if="actionHref && actionLabel"
            :href="actionHref"
            class="pbr-touch mt-4 inline-flex items-center justify-center rounded-xl bg-[var(--pbr-green-dark)] px-4 py-2.5 text-sm font-extrabold text-white shadow-[0_8px_18px_rgb(13_106_59_/_16%)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--pbr-green)] focus-visible:ring-offset-2"
        >
            {{ actionLabel }}
        </Link>
    </div>
</template>
