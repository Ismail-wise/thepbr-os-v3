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
            { href: '/overview', label: 'nav.businessControlCenter' },
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
            { href: '/partner-dynamics', label: 'nav.partnerDynamics' },
            { href: '/account/settings', label: 'nav.profileSettings' },
        ],
    },
];
</script>

<template>
    <nav :aria-label="t('nav.workspaceNavigation')" class="py-2">
        <section
            v-for="group in groups"
            :key="group.label"
            class="mb-5 last:mb-0"
        >
            <div class="mb-1.5 flex items-center gap-2 px-3">
                <span
                    aria-hidden="true"
                    class="h-px w-3 bg-[#cbd7ce]"
                />
                <p
                    class="text-[9px] font-black uppercase tracking-[0.19em] text-[#87958c]"
                >
                    {{ t(group.label) }}
                </p>
            </div>

            <div class="space-y-1">
                <Link
                    v-for="item in group.items"
                    :key="item.href"
                    :href="item.href"
                    :aria-current="isCurrent(item.href) ? 'page' : undefined"
                    class="pbr-touch group relative flex items-center overflow-hidden rounded-[13px] border px-3 py-2.5 text-sm font-bold transition-[background-color,border-color,box-shadow,color] duration-150 focus-visible:outline-none"
                    :class="
                        isCurrent(item.href)
                            ? 'border-[#cddfd3] bg-[linear-gradient(90deg,#e9f5ed_0%,#f3f8f4_70%,#fbf8ef_100%)] text-[var(--pbr-green-dark)] shadow-[0_7px_18px_rgb(30_67_43_/_6%)]'
                            : 'border-transparent text-[#56675d] hover:border-[#e1e8e3] hover:bg-white/72 hover:text-[var(--pbr-ink)]'
                    "
                    @click="emit('navigate')"
                >
                    <span
                        v-if="isCurrent(item.href)"
                        aria-hidden="true"
                        class="absolute inset-y-2 left-0 w-[3px] rounded-r-full bg-gradient-to-b from-[var(--pbr-green)] to-[var(--pbr-gold)]"
                    />
                    <span
                        aria-hidden="true"
                        class="mr-3 grid h-5 w-5 shrink-0 place-items-center rounded-lg border"
                        :class="
                            isCurrent(item.href)
                                ? 'border-[#c8dfd0] bg-white text-[var(--pbr-green)] shadow-[0_3px_8px_rgb(13_106_59_/_8%)]'
                                : 'border-[#e1e8e3] bg-[#f6f8f6] text-[#9caf9f] group-hover:border-[#d0ded4] group-hover:bg-white'
                        "
                    >
                        <span
                            class="h-1.5 w-1.5 rounded-full"
                            :class="
                                isCurrent(item.href)
                                    ? 'bg-[var(--pbr-green)]'
                                    : 'bg-[#c7d3ca] group-hover:bg-[#9caf9f]'
                            "
                        />
                    </span>
                    <span class="min-w-0 truncate">{{ t(item.label) }}</span>
                </Link>
            </div>
        </section>
    </nav>
</template>
