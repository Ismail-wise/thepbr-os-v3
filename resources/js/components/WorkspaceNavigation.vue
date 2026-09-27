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

const isDocumentVaultCurrent = computed(
    () =>
        currentPath.value === '/records/documents' ||
        currentPath.value.startsWith('/records/documents/'),
);

const isFormationCurrent = computed(
    () =>
        currentPath.value === '/formation' ||
        currentPath.value.startsWith('/formation/'),
);


const isPartnershipCurrent = computed(
    () =>
        currentPath.value === '/partnership' ||
        currentPath.value.startsWith('/partnership/'),
);

const isGovernanceCurrent = computed(
    () =>
        currentPath.value === '/governance' ||
        currentPath.value.startsWith('/governance/'),
);

const isOperationsCurrent = computed(
    () =>
        currentPath.value === '/operations' ||
        currentPath.value.startsWith('/operations/'),
);

const isFinanceCurrent = computed(
    () =>
        currentPath.value === '/finance' ||
        currentPath.value.startsWith('/finance/'),
);

const isRewardsCurrent = computed(
    () =>
        currentPath.value === '/rewards' ||
        currentPath.value.startsWith('/rewards/'),
);

const isRiskCurrent = computed(
    () =>
        currentPath.value === '/risk' ||
        currentPath.value.startsWith('/risk/'),
);

const isContinuityCurrent = computed(
    () =>
        currentPath.value === '/continuity' ||
        currentPath.value.startsWith('/continuity/'),
);

const isConflictCurrent = computed(
    () =>
        currentPath.value === '/conflict' ||
        currentPath.value.startsWith('/conflict/'),
);
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
            href="/workspace/access"
            :aria-current="isCurrent('/workspace/access') ? 'page' : undefined"
            class="flex min-h-11 items-center border-l-2 px-3 py-3 text-sm font-semibold hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-500"
            :class="
                isCurrent('/workspace/access')
                    ? 'border-slate-950 bg-slate-100 text-slate-950'
                    : 'border-transparent text-slate-700'
            "
            @click="emit('navigate')"
        >
            {{ t('nav.workspaceAccess') }}
        </Link>

        <Link
            href="/formation"
            :aria-current="isFormationCurrent ? 'page' : undefined"
            class="flex min-h-11 items-center border-l-2 px-3 py-3 text-sm font-semibold hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-500"
            :class="
                isFormationCurrent
                    ? 'border-slate-950 bg-slate-100 text-slate-950'
                    : 'border-transparent text-slate-700'
            "
            @click="emit('navigate')"
        >
            {{ t('nav.formationCapital') }}
        </Link>

        <Link
            href="/partnership"
            :aria-current="isPartnershipCurrent ? 'page' : undefined"
            class="flex min-h-11 items-center border-l-2 px-3 py-3 text-sm font-semibold hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-500"
            :class="
                isPartnershipCurrent
                    ? 'border-slate-950 bg-slate-100 text-slate-950'
                    : 'border-transparent text-slate-700'
            "
            @click="emit('navigate')"
        >
            {{ t('nav.partnership') }}
        </Link>

        <Link
            href="/governance"
            :aria-current="isGovernanceCurrent ? 'page' : undefined"
            class="flex min-h-11 items-center border-l-2 px-3 py-3 text-sm font-semibold hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-500"
            :class="
                isGovernanceCurrent
                    ? 'border-slate-950 bg-slate-100 text-slate-950'
                    : 'border-transparent text-slate-700'
            "
            @click="emit('navigate')"
        >
            {{ t('nav.governance') }}
        </Link>

        <Link
            href="/operations"
            :aria-current="isOperationsCurrent ? 'page' : undefined"
            class="flex min-h-11 items-center border-l-2 px-3 py-3 text-sm font-semibold hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-500"
            :class="
                isOperationsCurrent
                    ? 'border-slate-950 bg-slate-100 text-slate-950'
                    : 'border-transparent text-slate-700'
            "
            @click="emit('navigate')"
        >
            {{ t('nav.operations') }}
        </Link>

        <Link
            href="/finance"
            :aria-current="isFinanceCurrent ? 'page' : undefined"
            class="flex min-h-11 items-center border-l-2 px-3 py-3 text-sm font-semibold hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-500"
            :class="
                isFinanceCurrent
                    ? 'border-slate-950 bg-slate-100 text-slate-950'
                    : 'border-transparent text-slate-700'
            "
            @click="emit('navigate')"
        >
            {{ t('nav.finance') }}
        </Link>

        <Link
            href="/rewards"
            :aria-current="isRewardsCurrent ? 'page' : undefined"
            class="flex min-h-11 items-center border-l-2 px-3 py-3 text-sm font-semibold hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-500"
            :class="
                isRewardsCurrent
                    ? 'border-slate-950 bg-slate-100 text-slate-950'
                    : 'border-transparent text-slate-700'
            "
            @click="emit('navigate')"
        >
            {{ t('nav.rewards') }}
        </Link>

        <Link
            href="/risk"
            :aria-current="isRiskCurrent ? 'page' : undefined"
            class="flex min-h-11 items-center border-l-2 px-3 py-3 text-sm font-semibold hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-500"
            :class="
                isRiskCurrent
                    ? 'border-slate-950 bg-slate-100 text-slate-950'
                    : 'border-transparent text-slate-700'
            "
            @click="emit('navigate')"
        >
            {{ t('nav.risk') }}
        </Link>

        <Link
            href="/continuity"
            :aria-current="isContinuityCurrent ? 'page' : undefined"
            class="flex min-h-11 items-center border-l-2 px-3 py-3 text-sm font-semibold hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-500"
            :class="
                isContinuityCurrent
                    ? 'border-slate-950 bg-slate-100 text-slate-950'
                    : 'border-transparent text-slate-700'
            "
            @click="emit('navigate')"
        >
            {{ t('nav.continuity') }}
        </Link>

        <Link
            href="/conflict"
            :aria-current="isConflictCurrent ? 'page' : undefined"
            class="flex min-h-11 items-center border-l-2 px-3 py-3 text-sm font-semibold hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-500"
            :class="
                isConflictCurrent
                    ? 'border-slate-950 bg-slate-100 text-slate-950'
                    : 'border-transparent text-slate-700'
            "
            @click="emit('navigate')"
        >
            {{ t('nav.conflict') }}
        </Link>

        <Link
            href="/records/documents"
            :aria-current="isDocumentVaultCurrent ? 'page' : undefined"
            class="flex min-h-11 items-center border-l-2 px-3 py-3 text-sm font-semibold hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-500"
            :class="
                isDocumentVaultCurrent
                    ? 'border-slate-950 bg-slate-100 text-slate-950'
                    : 'border-transparent text-slate-700'
            "
            @click="emit('navigate')"
        >
            {{ t('nav.documentVault') }}
        </Link>

        <Link
            href="/records/activity"
            :aria-current="isCurrent('/records/activity') ? 'page' : undefined"
            class="flex min-h-11 items-center border-l-2 px-3 py-3 text-sm font-semibold hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-500"
            :class="
                isCurrent('/records/activity')
                    ? 'border-slate-950 bg-slate-100 text-slate-950'
                    : 'border-transparent text-slate-700'
            "
            @click="emit('navigate')"
        >
            {{ t('nav.activity') }}
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
