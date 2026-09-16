<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from '../i18n/useI18n';

const emit = defineEmits<{
    navigate: [];
}>();

const page = usePage();
const { t } = useI18n();

const currentPath = computed(() => {
    const [path] = page.url.split(/[?#]/);

    return path || '/';
});

const isCurrent = (href: string): boolean => currentPath.value === href;
</script>

<template>
    <nav :aria-label="t('nav.workspaceNavigation')" class="px-3 py-4">
        <Link
            href="/"
            :aria-current="isCurrent('/') ? 'page' : undefined"
            class="flex min-h-11 items-center border-l-2 px-3 py-3 text-sm font-semibold hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-500"
            :class="
                isCurrent('/')
                    ? 'border-slate-950 bg-slate-100 text-slate-950'
                    : 'border-transparent text-slate-700'
            "
            @click="emit('navigate')"
        >
            {{ t('nav.home') }}
        </Link>

        <Link
            href="/businesses/create"
            :aria-current="isCurrent('/businesses/create') ? 'page' : undefined"
            class="flex min-h-11 items-center border-l-2 px-3 py-3 text-sm font-semibold hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-500"
            :class="
                isCurrent('/businesses/create')
                    ? 'border-slate-950 bg-slate-100 text-slate-950'
                    : 'border-transparent text-slate-700'
            "
            @click="emit('navigate')"
        >
            {{ t('nav.createBusiness') }}
        </Link>

        <Link
            href="/account/settings"
            :aria-current="isCurrent('/account/settings') ? 'page' : undefined"
            class="flex min-h-11 items-center border-l-2 px-3 py-3 text-sm font-semibold hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-500"
            :class="
                isCurrent('/account/settings')
                    ? 'border-slate-950 bg-slate-100 text-slate-950'
                    : 'border-transparent text-slate-700'
            "
            @click="emit('navigate')"
        >
            {{ t('nav.profileSettings') }}
        </Link>
    </nav>
</template>
