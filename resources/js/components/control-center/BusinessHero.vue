<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useI18n } from '../../i18n/useI18n';
import BusinessStatusTags from './BusinessStatusTags.vue';
import type { BusinessSummary } from './types';

defineProps<{
    business: BusinessSummary;
    healthAvailable: boolean;
    governanceAvailable: boolean;
}>();

const { t } = useI18n();
</script>

<template>
    <section
        class="relative overflow-hidden rounded-[28px] border border-[#d6e3d9] bg-[linear-gradient(128deg,#ffffff_0%,#f4faf6_63%,#fbf7ed_100%)] px-5 py-6 shadow-[0_26px_70px_rgb(24_55_35_/_9%)] sm:px-7 sm:py-7 lg:px-8 lg:py-8"
    >
        <div
            aria-hidden="true"
            class="absolute inset-y-0 left-0 w-[5px] bg-gradient-to-b from-[var(--pbr-green)] to-[var(--pbr-gold)]"
        />
        <div
            aria-hidden="true"
            class="pointer-events-none absolute -right-16 -top-20 h-56 w-56 rounded-full bg-[#d2a743]/10 blur-3xl"
        />
        <div
            aria-hidden="true"
            class="pointer-events-none absolute bottom-[-6rem] left-[42%] h-44 w-44 rounded-full bg-[#0d6a3b]/7 blur-3xl"
        />

        <div class="relative grid gap-6 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
            <div class="min-w-0">
                <p
                    class="text-[10px] font-black uppercase tracking-[0.2em] text-[var(--pbr-green)]"
                >
                    {{ t('controlCenter.eyebrow') }}
                </p>

                <h1
                    class="mt-2 max-w-4xl text-[clamp(2rem,4vw,3.5rem)] font-black leading-[1.04] tracking-[-0.045em] text-[var(--pbr-ink)]"
                >
                    {{ business.name }}
                </h1>

                <p
                    class="mt-3 max-w-3xl text-sm leading-7 text-[var(--pbr-muted)] sm:text-[15px]"
                >
                    {{ t('controlCenter.subtitle') }}
                </p>

                <div class="mt-5">
                    <BusinessStatusTags :business="business" />
                </div>
            </div>

            <div
                v-if="healthAvailable || governanceAvailable"
                class="flex flex-wrap gap-2 lg:max-w-[19rem] lg:justify-end"
            >
                <Link
                    v-if="healthAvailable"
                    href="/health"
                    class="pbr-touch inline-flex items-center justify-center rounded-xl border border-[#cfe0d4] bg-white px-4 text-sm font-extrabold text-[var(--pbr-green-dark)] shadow-[0_8px_20px_rgb(16_35_26_/_5%)] hover:border-[#a9c4b2] hover:bg-[var(--pbr-green-soft)] focus-visible:outline-none"
                >
                    {{ t('controlCenter.openHealth') }}
                </Link>
                <Link
                    v-if="governanceAvailable"
                    href="/governance"
                    class="pbr-touch inline-flex items-center justify-center rounded-xl border border-[var(--pbr-green)] bg-[var(--pbr-green)] px-4 text-sm font-extrabold text-white shadow-[0_12px_26px_rgb(13_106_59_/_16%)] hover:bg-[var(--pbr-green-dark)] focus-visible:outline-none"
                >
                    {{ t('controlCenter.openGovernance') }}
                </Link>
            </div>
        </div>
    </section>
</template>
