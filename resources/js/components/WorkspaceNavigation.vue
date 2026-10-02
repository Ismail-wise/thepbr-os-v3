<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from '../i18n/useI18n';
import type { TranslationKey } from '../i18n/catalog';

const emit = defineEmits<{
    navigate: [];
}>();

const page = usePage();
const { t } = useI18n();

type NavItem = {
    href: string;
    label: TranslationKey;
};

type NavGroup = {
    label: TranslationKey;
    items: NavItem[];
};

const currentPath = computed(() => {
    const [path] = page.url.split(/[?#]/);

    return path || '/';
});

const isCurrent = (href: string): boolean => {
    if (href === '/') {
        return currentPath.value === '/';
    }

    return (
        currentPath.value === href ||
        currentPath.value.startsWith(`${href}/`)
    );
};

const groups: NavGroup[] = [
    {
        label: 'nav.group.workspace',
        items: [
            { href: '/', label: 'nav.home' },
            { href: '/search', label: 'nav.search' },
            { href: '/ai', label: 'nav.ai' },
            { href: '/health', label: 'nav.health' },
            { href: '/reports', label: 'nav.reports' },
        ],
    },
    {
        label: 'nav.group.setup',
        items: [
            { href: '/businesses/create', label: 'nav.createBusiness' },
            { href: '/workspace/access', label: 'nav.workspaceAccess' },
            { href: '/formation', label: 'nav.formationCapital' },
            { href: '/business/legal-structure', label: 'nav.legalStructure' },
            { href: '/partnership', label: 'nav.partnership' },
        ],
    },

    {
        label: 'nav.group.operate',
        items: [
            { href: '/governance', label: 'nav.governance' },
            { href: '/operations', label: 'nav.operations' },
            { href: '/finance', label: 'nav.finance' },
            { href: '/rewards', label: 'nav.rewards' },
        ],
    },
    {
        label: 'nav.group.protect',
        items: [
            { href: '/risk', label: 'nav.risk' },
            { href: '/continuity', label: 'nav.continuity' },
            { href: '/conflict', label: 'nav.conflict' },
        ],
    },
    {
        label: 'nav.group.changes',
        items: [
            { href: '/changes/partner-changes', label: 'nav.partnerChanges' },
            { href: '/changes/exit', label: 'nav.exitBuyout' },
            { href: '/changes/closure', label: 'nav.closure' },
        ],
    },

    {
        label: 'nav.group.records',
        items: [
            { href: '/records/documents', label: 'nav.documentVault' },
            { href: '/records/activity', label: 'nav.activity' },
            { href: '/import', label: 'nav.import' },
            { href: '/records/portability', label: 'nav.portability' },
        ],
    },
    {
        label: 'nav.group.account',
        items: [
            { href: '/account/settings', label: 'nav.profileSettings' },
        ],
    },
];
</script>

<template>
    <nav :aria-label="t('nav.workspaceNavigation')" class="py-3">
        <section
            v-for="group in groups"
            :key="group.label"
            class="mb-4 last:mb-0"
        >
            <p
                class="px-3 pb-1.5 text-[10px] font-extrabold uppercase tracking-[0.16em] text-[#8a968f]"
            >
                {{ t(group.label) }}
            </p>

            <div class="space-y-1">
                <Link
                    v-for="item in group.items"
                    :key="item.href"
                    :href="item.href"
                    :aria-current="isCurrent(item.href) ? 'page' : undefined"
                    class="pbr-touch group relative flex items-center rounded-xl px-3 py-2.5 text-sm font-bold focus-visible:outline-none"
                    :class="
                        isCurrent(item.href)
                            ? 'bg-[var(--pbr-green-soft)] text-[var(--pbr-green-dark)]'
                            : 'text-[#55665c] hover:bg-[var(--pbr-surface-soft)] hover:text-[var(--pbr-ink)]'
                    "
                    @click="emit('navigate')"
                >
                    <span
                        aria-hidden="true"
                        class="mr-3 h-2 w-2 shrink-0 rounded-full"
                        :class="
                            isCurrent(item.href)
                                ? 'bg-[var(--pbr-green)] shadow-[0_0_0_4px_rgb(13_106_59_/_9%)]'
                                : 'bg-[#d5ded8] group-hover:bg-[#9db6a6]'
                        "
                    />
                    <span class="min-w-0 truncate">{{ t(item.label) }}</span>
                </Link>
            </div>
        </section>
    </nav>
</template>
