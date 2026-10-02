<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from '../../i18n/useI18n';

const page = usePage();
const { t } = useI18n();

const currentPath = computed(() => {
    const [path] = page.url.split(/[?#]/);

    return path || '/';
});

const items = computed(() => [
    { href: '/', label: t('account.navHome') },
    { href: '/account/businesses', label: t('account.navBusinesses') },
    { href: '/account/work', label: t('account.navWork') },
    { href: '/account/notifications', label: t('account.navNotifications') },
    { href: '/account/approvals', label: t('account.navApprovals') },
    { href: '/account/signatures', label: t('account.navSignatures') },
]);

const isCurrent = (href: string): boolean => currentPath.value === href;
</script>

<template>
    <nav
        aria-label="Account navigation"
        class="overflow-x-auto rounded-[18px] border border-[#d9e5dc] bg-white/85 p-1.5 shadow-[0_10px_28px_rgb(16_35_26_/_4%)] backdrop-blur"
    >
        <div class="flex min-w-max gap-1">
            <Link
                v-for="item in items"
                :key="item.href"
                :href="item.href"
                :aria-current="isCurrent(item.href) ? 'page' : undefined"
                class="pbr-touch inline-flex min-h-10 items-center rounded-[13px] px-3.5 text-sm font-extrabold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--pbr-green)]"
                :class="
                    isCurrent(item.href)
                        ? 'bg-[linear-gradient(135deg,#e7f4eb,#f7f4e9)] text-[var(--pbr-green-dark)] shadow-[0_5px_14px_rgb(16_35_26_/_6%)]'
                        : 'text-[#637168] hover:bg-[#f4f7f4] hover:text-[var(--pbr-ink)]'
                "
            >
                {{ item.label }}
            </Link>
        </div>
    </nav>
</template>
