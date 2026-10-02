<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import BreadcrumbContext from './BreadcrumbContext.vue';
import LanguageSwitcher from './LanguageSwitcher.vue';
import NotificationButton from './NotificationButton.vue';
import { useI18n } from '../../i18n/useI18n';

defineProps<{
    currentBusinessName: string;
    mobileNavigationOpen: boolean;
}>();

const emit = defineEmits<{
    openNavigation: [trigger: HTMLButtonElement];
}>();

const { t } = useI18n();

const openNavigation = (event: MouseEvent) => {
    if (event.currentTarget instanceof HTMLButtonElement) {
        emit('openNavigation', event.currentTarget);
    }
};
</script>

<template>
    <header
        class="sticky top-0 z-30 border-b border-[var(--pbr-line)] bg-[rgb(250_252_250_/_90%)] px-3 py-2.5 shadow-[0_8px_28px_rgb(16_35_26_/_3%)] backdrop-blur-xl sm:px-5 lg:px-6"
    >
        <div class="flex min-h-12 min-w-0 items-center gap-2.5">
            <button
                type="button"
                class="pbr-touch inline-flex shrink-0 items-center justify-center rounded-xl border border-[var(--pbr-line-strong)] bg-white text-[var(--pbr-ink)] shadow-[0_4px_14px_rgb(16_35_26_/_3%)] hover:bg-[var(--pbr-green-soft)] focus-visible:outline-none lg:hidden"
                aria-controls="mobile-workspace-navigation"
                :aria-expanded="mobileNavigationOpen ? 'true' : 'false'"
                :aria-label="t('shell.openNavigation')"
                @click="openNavigation"
            >
                <svg
                    aria-hidden="true"
                    viewBox="0 0 24 24"
                    class="h-5 w-5"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.9"
                >
                    <path d="M4 7h16M4 12h16M4 17h16" />
                </svg>
            </button>

            <Link
                href="/"
                class="pbr-touch hidden shrink-0 items-center rounded-xl px-2 text-sm font-black text-[var(--pbr-green-dark)] focus-visible:outline-none sm:inline-flex lg:hidden"
            >
                thePBR
            </Link>

            <div class="min-w-0 flex-1">
                <BreadcrumbContext :current-business-name="currentBusinessName" />
            </div>

            <Link
                href="/search"
                class="pbr-touch hidden items-center gap-2 rounded-xl border border-[var(--pbr-line)] bg-white px-3 text-sm font-bold text-[var(--pbr-muted)] shadow-[0_4px_14px_rgb(16_35_26_/_3%)] hover:border-[#c4d5ca] hover:text-[var(--pbr-green-dark)] focus-visible:outline-none md:inline-flex"
            >
                <svg
                    aria-hidden="true"
                    viewBox="0 0 24 24"
                    class="h-4.5 w-4.5"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                >
                    <circle cx="11" cy="11" r="6.5" />
                    <path d="m16 16 4 4" />
                </svg>
                <span>{{ t('nav.search') }}</span>
            </Link>

            <NotificationButton />
            <LanguageSwitcher />
        </div>
    </header>
</template>
