<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import BusinessSwitcher from '../BusinessSwitcher.vue';
import WorkspaceNavigation from '../WorkspaceNavigation.vue';
import { useI18n } from '../../i18n/useI18n';

type BusinessOption = {
    id: string;
    name: string;
};

defineProps<{
    businesses: BusinessOption[];
    currentBusiness: BusinessOption | null;
}>();

const { t } = useI18n();
</script>

<template>
    <aside
        class="relative hidden h-screen flex-col overflow-hidden border-r border-[#d8e3da] bg-[linear-gradient(180deg,#fffefb_0%,#fbfcfa_38%,#f5f7f2_100%)] shadow-[18px_0_54px_rgb(16_35_26_/_5%)] lg:sticky lg:top-0 lg:flex lg:self-start"
    >
        <div
            aria-hidden="true"
            class="pointer-events-none absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-[var(--pbr-green)] via-[#2a8b56] to-[var(--pbr-gold)]"
        />

        <div class="relative px-5 pb-5 pt-6">
            <div
                aria-hidden="true"
                class="pointer-events-none absolute -right-12 -top-14 h-32 w-32 rounded-full bg-[#d2a743]/8 blur-2xl"
            />

            <Link
                href="/"
                class="group relative inline-flex min-h-11 items-center gap-3.5 rounded-2xl pr-3 font-bold text-[var(--pbr-ink)] focus-visible:outline-none"
            >
                <span
                    aria-hidden="true"
                    class="grid h-11 w-11 place-items-center rounded-[15px] border border-[#2b8052] bg-[linear-gradient(145deg,#14814b_0%,#0d6a3b_52%,#084d2b_100%)] text-sm font-black text-white shadow-[0_10px_24px_rgb(13_106_59_/_22%)] ring-1 ring-white/70"
                >
                    P
                </span>
                <span class="leading-tight">
                    <span class="block text-[16px] font-black tracking-[-0.02em]">
                        {{ t('common.brand') }}
                    </span>
                    <span
                        class="mt-1 block text-[9px] font-extrabold uppercase tracking-[0.2em] text-[#718078]"
                    >
                        {{ t('shell.businessOperatingSystem') }}
                    </span>
                </span>
            </Link>
        </div>

        <div
            class="relative mx-4 overflow-hidden rounded-[22px] border border-[#cfe0d4] bg-[linear-gradient(145deg,#ffffff_0%,#f2f8f4_62%,#fbf7ec_100%)] p-4 shadow-[0_14px_34px_rgb(30_67_43_/_8%)] ring-1 ring-white/70"
        >
            <div
                aria-hidden="true"
                class="pointer-events-none absolute inset-y-0 left-0 w-1 bg-gradient-to-b from-[var(--pbr-green)] to-[var(--pbr-gold)]"
            />
            <div
                aria-hidden="true"
                class="pointer-events-none absolute -right-8 -top-8 h-24 w-24 rounded-full bg-[#d2a743]/10 blur-xl"
            />

            <div class="relative">
                <BusinessSwitcher
                    :businesses="businesses"
                    :current-business="currentBusiness"
                    select-id="business-switcher-desktop"
                />
            </div>
        </div>

        <div class="mx-5 mt-4 h-px bg-gradient-to-r from-transparent via-[#dbe4dd] to-transparent" />

        <div class="min-h-0 flex-1 overflow-y-auto px-2.5 pb-6 pt-2">
            <WorkspaceNavigation />
        </div>
    </aside>
</template>
