<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useAccountCopy } from '../../account/copy';

const page = usePage();
const { c } = useAccountCopy();

const currentPath = computed(() => {
    const [path] = page.url.split(/[?#]/);

    return path || '/';
});

const items = computed(() => [
    { href: '/', label: c.value.navHome },
    { href: '/account/businesses', label: c.value.navBusinesses },
    { href: '/account/work', label: c.value.navWork },
    { href: '/account/notifications', label: c.value.navNotifications },
    { href: '/account/approvals', label: c.value.navApprovals },
    { href: '/account/signatures', label: c.value.navSignatures },
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
