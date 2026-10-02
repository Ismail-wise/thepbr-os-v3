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
        class="hidden h-screen flex-col border-r border-[var(--pbr-line)] bg-[rgb(255_255_255_/_94%)] shadow-[12px_0_40px_rgb(16_35_26_/_3%)] backdrop-blur lg:sticky lg:top-0 lg:flex lg:self-start"
    >
        <div class="px-5 pb-4 pt-5">
            <Link
                href="/"
                class="group inline-flex min-h-11 items-center gap-3 rounded-xl pr-3 font-bold text-[var(--pbr-ink)] focus-visible:outline-none"
            >
                <span
                    aria-hidden="true"
                    class="grid h-10 w-10 place-items-center rounded-xl bg-gradient-to-br from-[#137544] to-[#084d2b] text-sm font-black text-white shadow-[0_8px_18px_rgb(13_106_59_/_18%)]"
                >
                    P
                </span>
                <span class="leading-tight">
                    <span class="block text-[15px] tracking-tight">{{ t('common.brand') }}</span>
                    <span class="mt-0.5 block text-[10px] font-bold uppercase tracking-[0.16em] text-[var(--pbr-muted)]">
                        {{ t('shell.businessOperatingSystem') }}
                    </span>
                </span>
            </Link>
        </div>

        <div class="mx-4 rounded-2xl border border-[#d7e7dc] bg-[var(--pbr-green-soft)] p-3.5">
            <BusinessSwitcher
                :businesses="businesses"
                :current-business="currentBusiness"
                select-id="business-switcher-desktop"
            />
        </div>

        <div class="mt-3 min-h-0 flex-1 overflow-y-auto px-2 pb-5">
            <WorkspaceNavigation />
        </div>
    </aside>
</template>
