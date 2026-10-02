<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import BusinessSidebar from './BusinessSidebar.vue';
import MobileNavigation from './MobileNavigation.vue';
import TopCommandBar from './TopCommandBar.vue';
import { useI18n } from '../../i18n/useI18n';

type BusinessOption = {
    id: string;
    name: string;
};

type WorkspaceContext = {
    businesses: BusinessOption[];
    currentBusiness: BusinessOption | null;
};

type MobileNavigationHandle = {
    open: (trigger?: HTMLButtonElement) => void;
    close: (restoreFocus?: boolean) => void;
};

const page = usePage();
const { t } = useI18n();
const mobileNavigation = ref<MobileNavigationHandle | null>(null);
const mobileNavigationOpen = ref(false);

const workspace = computed(
    () => page.props.workspace as WorkspaceContext | null | undefined,
);

const currentBusinessName = computed(
    () =>
        workspace.value?.currentBusiness?.name ??
        t('shell.noBusinessSelected'),
);

const openMobileNavigation = (trigger: HTMLButtonElement) => {
    mobileNavigation.value?.open(trigger);
};
</script>

<template>
    <div class="pbr-app-canvas min-h-screen text-[var(--pbr-ink)]">
        <div class="min-h-screen lg:grid lg:grid-cols-[19.75rem_minmax(0,1fr)]">
            <BusinessSidebar
                :businesses="workspace?.businesses ?? []"
                :current-business="workspace?.currentBusiness ?? null"
            />

            <div class="min-w-0">
                <TopCommandBar
                    :current-business-name="currentBusinessName"
                    :mobile-navigation-open="mobileNavigationOpen"
                    @open-navigation="openMobileNavigation"
                />

                <div class="pbr-page-stage min-w-0">
                    <div class="pbr-page-frame">
                        <slot />
                    </div>
                </div>
            </div>
        </div>

        <MobileNavigation
            ref="mobileNavigation"
            :businesses="workspace?.businesses ?? []"
            :current-business="workspace?.currentBusiness ?? null"
            @state-change="mobileNavigationOpen = $event"
        />
    </div>
</template>
