<script setup lang="ts">
import {
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
} from 'vue';
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

const emit = defineEmits<{
    stateChange: [open: boolean];
}>();

const { t } = useI18n();
const dialog = ref<HTMLDialogElement | null>(null);
let restoreTarget: HTMLButtonElement | null = null;
let desktopMediaQuery: MediaQueryList | null = null;
let restoreFocusAfterClose = true;

const open = (trigger?: HTMLButtonElement) => {
    if (dialog.value === null || dialog.value.open) {
        return;
    }

    restoreTarget = trigger ?? null;
    restoreFocusAfterClose = true;
    dialog.value.showModal();
    emit('stateChange', true);
};

const close = (restoreFocus = true) => {
    restoreFocusAfterClose = restoreFocus;

    if (dialog.value === null || !dialog.value.open) {
        emit('stateChange', false);

        if (restoreFocus && restoreTarget !== null) {
            void nextTick(() => restoreTarget?.focus());
        }

        return;
    }

    dialog.value.close();
};

const handleClose = () => {
    emit('stateChange', false);

    if (restoreFocusAfterClose && restoreTarget !== null) {
        void nextTick(() => restoreTarget?.focus());
    }

    restoreFocusAfterClose = true;
};

const handleCancel = () => {
    restoreFocusAfterClose = true;
};

const handleDesktopBreakpoint = (event: MediaQueryListEvent) => {
    if (event.matches) {
        close(false);
    }
};

onMounted(() => {
    desktopMediaQuery = window.matchMedia('(min-width: 1024px)');
    desktopMediaQuery.addEventListener('change', handleDesktopBreakpoint);
});

onBeforeUnmount(() => {
    desktopMediaQuery?.removeEventListener('change', handleDesktopBreakpoint);
});

defineExpose({ open, close });
</script>

<template>
    <dialog
        id="mobile-workspace-navigation"
        ref="dialog"
        :aria-label="t('nav.workspaceNavigation')"
        class="fixed inset-y-0 left-0 m-0 h-dvh max-h-none w-[calc(100vw-1.5rem)] max-w-sm border-0 bg-white p-0 text-[var(--pbr-ink)] shadow-[0_28px_80px_rgb(16_35_26_/_24%)] backdrop:bg-[rgb(16_35_26_/_45%)] lg:hidden"
        @cancel="handleCancel"
        @close="handleClose"
    >
        <div class="flex h-full min-h-0 flex-col">
            <div
                class="flex min-h-16 items-center justify-between gap-4 border-b border-[var(--pbr-line)] bg-[var(--pbr-surface-soft)] px-4"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <span
                        aria-hidden="true"
                        class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-[#137544] to-[#084d2b] text-xs font-black text-white"
                    >
                        P
                    </span>
                    <span class="truncate text-sm font-black">{{ t('common.brand') }}</span>
                </div>

                <button
                    type="button"
                    class="pbr-touch inline-flex items-center justify-center rounded-xl border border-[var(--pbr-line-strong)] bg-white text-[var(--pbr-ink)] focus-visible:outline-none"
                    :aria-label="t('shell.closeNavigation')"
                    @click="close()"
                >
                    <svg
                        aria-hidden="true"
                        viewBox="0 0 24 24"
                        class="h-5 w-5"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.9"
                    >
                        <path d="m6 6 12 12M18 6 6 18" />
                    </svg>
                </button>
            </div>

            <div class="border-b border-[var(--pbr-line)] bg-[var(--pbr-green-soft)] p-4">
                <BusinessSwitcher
                    :businesses="businesses"
                    :current-business="currentBusiness"
                    select-id="business-switcher-mobile"
                />
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-2 pb-6">
                <WorkspaceNavigation @navigate="close(false)" />
            </div>
        </div>
    </dialog>
</template>