<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from '../../i18n/useI18n';

defineProps<{
    currentBusinessName: string;
}>();

const page = usePage();
const { t } = useI18n();

const currentPath = computed(() => page.url.split(/[?#]/)[0] || '/');

const sectionLabel = computed(() => {
    const path = currentPath.value;

    if (path === '/') return t('nav.home');
    if (path.startsWith('/formation')) return t('nav.formationCapital');
    if (path.startsWith('/business/legal-structure')) return t('nav.legalStructure');
    if (path.startsWith('/partnership')) return t('nav.partnership');
    if (path.startsWith('/governance')) return t('nav.governance');
    if (path.startsWith('/operations')) return t('nav.operations');
    if (path.startsWith('/finance')) return t('nav.finance');
    if (path.startsWith('/rewards')) return t('nav.rewards');
    if (path.startsWith('/risk')) return t('nav.risk');
    if (path.startsWith('/continuity')) return t('nav.continuity');
    if (path.startsWith('/conflict')) return t('nav.conflict');
    if (path.startsWith('/changes/partner-changes')) return t('nav.partnerChanges');
    if (path.startsWith('/changes/exit')) return t('nav.exitBuyout');
    if (path.startsWith('/changes/closure')) return t('nav.closure');
    if (path.startsWith('/records/documents')) return t('nav.documentVault');
    if (path.startsWith('/records/activity')) return t('nav.activity');
    if (path.startsWith('/records/portability')) return t('nav.portability');
    if (path.startsWith('/reports')) return t('nav.reports');
    if (path.startsWith('/health')) return t('nav.health');
    if (path.startsWith('/search')) return t('nav.search');
    if (path.startsWith('/ai')) return t('nav.ai');
    if (path.startsWith('/import')) return t('nav.import');
    if (path.startsWith('/workspace/access')) return t('nav.workspaceAccess');
    if (path.startsWith('/businesses/create')) return t('nav.createBusiness');
    if (path.startsWith('/account/settings')) return t('nav.profileSettings');

    return t('shell.workspace');
});
</script>

<template>
    <div class="min-w-0">
        <div class="flex min-w-0 items-center gap-2 text-xs font-semibold text-[var(--pbr-muted)]">
            <span class="truncate">{{ t('shell.currentBusiness') }}</span>
            <span aria-hidden="true" class="hidden text-[#aebbb3] sm:inline">/</span>
            <span class="hidden truncate sm:inline">{{ sectionLabel }}</span>
        </div>
        <p class="mt-1 truncate text-sm font-extrabold text-[var(--pbr-ink)]" :title="currentBusinessName">
            {{ currentBusinessName }}
        </p>
    </div>
</template>