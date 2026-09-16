<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
} from 'vue';
import BusinessSwitcher from '../components/BusinessSwitcher.vue';
import WorkspaceNavigation from '../components/WorkspaceNavigation.vue';
import { useI18n } from '../i18n/useI18n';

type BusinessOption = {
    id: string;
    name: string;
};

type WorkspaceContext = {
    businesses: BusinessOption[];
    currentBusiness: BusinessOption | null;
};

const page = usePage();
const { t } = useI18n();

const workspace = computed(
    () => page.props.workspace as WorkspaceContext | null | undefined,
);

const currentBusinessName = computed(
    () =>
        workspace.value?.currentBusiness?.name ??
        t('shell.noBusinessSelected'),
);

const mobileNavigationDialog = ref<HTMLDialogElement | null>(null);
const mobileNavigationTrigger = ref<HTMLButtonElement | null>(null);
const mobileNavigationOpen = ref(false);

let desktopMediaQuery: MediaQueryList | null = null;
let restoreFocusAfterClose = true;

const openMobileNavigation = () => {
    const dialog = mobileNavigationDialog.value;

    if (dialog === null || dialog.open) {
        return;
    }

    restoreFocusAfterClose = true;
    dialog.showModal();
    mobileNavigationOpen.value = true;
};

const closeMobileNavigation = (restoreFocus = true) => {
    const dialog = mobileNavigationDialog.value;

    restoreFocusAfterClose = restoreFocus;

    if (dialog === null || !dialog.open) {
        mobileNavigationOpen.value = false;

        if (restoreFocus) {
            void nextTick(() => mobileNavigationTrigger.value?.focus());
        }

        return;
    }

    dialog.close();
};

const handleMobileNavigationClose = () => {
    mobileNavigationOpen.value = false;

    if (restoreFocusAfterClose) {
        void nextTick(() => mobileNavigationTrigger.value?.focus());
    }

    restoreFocusAfterClose = true;
};

const handleMobileNavigationCancel = () => {
    // Native <dialog> handles Escape. Keep focus restoration enabled for close.
    restoreFocusAfterClose = true;
};

const handleDesktopBreakpoint = (event: MediaQueryListEvent) => {
    if (event.matches) {
        closeMobileNavigation(false);
    }
};

onMounted(() => {
    desktopMediaQuery = window.matchMedia('(min-width: 1024px)');
    desktopMediaQuery.addEventListener('change', handleDesktopBreakpoint);
});

onBeforeUnmount(() => {
    desktopMediaQuery?.removeEventListener(
        'change',
        handleDesktopBreakpoint,
    );
});
</script>

<template>
    <div class="min-h-screen bg-slate-50 text-slate-950">
        <div class="min-h-screen lg:grid lg:grid-cols-[18rem_minmax(0,1fr)]">
            <aside
                class="hidden bg-white lg:sticky lg:top-0 lg:flex lg:h-screen lg:flex-col lg:self-start lg:overflow-y-auto lg:border-r lg:border-slate-200"
            >
                <div class="px-5 py-5">
                    <Link
                        href="/"
                        class="inline-flex min-h-11 items-center text-sm font-bold tracking-wide text-slate-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-500 focus-visible:ring-offset-2"
                    >
                        {{ t('common.brand') }}
                    </Link>
                </div>

                <div class="border-t border-slate-200 px-5 py-5">
                    <BusinessSwitcher
                        :businesses="workspace?.businesses ?? []"
                        :current-business="workspace?.currentBusiness ?? null"
                        select-id="business-switcher-desktop"
                    />
                </div>

                <div class="border-t border-slate-200">
                    <WorkspaceNavigation />
                </div>
            </aside>

            <div class="min-w-0">
                <header
                    class="border-b border-slate-200 bg-white px-4 py-3 sm:px-6 lg:px-6 lg:py-4"
                >
                    <div class="flex min-w-0 items-center gap-3 lg:hidden">
                        <button
                            ref="mobileNavigationTrigger"
                            type="button"
                            class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center border border-slate-300 bg-white px-3 text-slate-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-500 focus-visible:ring-offset-2"
                            aria-controls="mobile-workspace-navigation"
                            :aria-expanded="mobileNavigationOpen ? 'true' : 'false'"
                            :aria-label="t('shell.openNavigation')"
                            @click="openMobileNavigation"
                        >
                            <span aria-hidden="true" class="text-xl leading-none">☰</span>
                        </button>

                        <Link
                            href="/"
                            class="min-w-0 truncate text-sm font-bold tracking-wide text-slate-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-500 focus-visible:ring-offset-2"
                        >
                            {{ t('common.brand') }}
                        </Link>
                    </div>

                    <div class="mt-3 min-w-0 lg:mt-0">
                        <p
                            class="text-xs font-semibold uppercase tracking-wider text-slate-500"
                        >
                            {{ t('shell.currentBusiness') }}
                        </p>

                        <p
                            class="mt-1 truncate text-sm font-semibold text-slate-950"
                            :title="currentBusinessName"
                        >
                            {{ currentBusinessName }}
                        </p>
                    </div>
                </header>

                <slot />
            </div>
        </div>

        <dialog
            id="mobile-workspace-navigation"
            ref="mobileNavigationDialog"
            :aria-label="t('nav.workspaceNavigation')"
            class="fixed inset-y-0 left-0 m-0 h-dvh max-h-none w-[calc(100vw-2rem)] max-w-sm border-0 bg-white p-0 text-slate-950 shadow-xl backdrop:bg-slate-950/40 lg:hidden"
            @cancel="handleMobileNavigationCancel"
            @close="handleMobileNavigationClose"
        >
            <div class="flex h-full min-h-0 flex-col">
                <div
                    class="flex min-h-16 items-center justify-between gap-4 border-b border-slate-200 px-4"
                >
                    <span class="text-sm font-bold tracking-wide">
                        {{ t('common.brand') }}
                    </span>

                    <button
                        type="button"
                        class="inline-flex min-h-11 min-w-11 items-center justify-center border border-slate-300 bg-white px-3 text-slate-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-500 focus-visible:ring-offset-2"
                        :aria-label="t('shell.closeNavigation')"
                        @click="closeMobileNavigation()"
                    >
                        <span aria-hidden="true" class="text-2xl leading-none">×</span>
                    </button>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto">
                    <div class="border-b border-slate-200 px-5 py-5">
                        <BusinessSwitcher
                            :businesses="workspace?.businesses ?? []"
                            :current-business="workspace?.currentBusiness ?? null"
                            select-id="business-switcher-mobile"
                        />
                    </div>

                    <WorkspaceNavigation
                        @navigate="closeMobileNavigation(false)"
                    />
                </div>
            </div>
        </dialog>
    </div>
</template>
