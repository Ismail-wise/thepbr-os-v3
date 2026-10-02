<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useI18n } from '../../i18n/useI18n';

const { t } = useI18n();
</script>

<template>
    <Link
        href="/governance"
        class="pbr-touch inline-flex items-center justify-center rounded-xl border border-[var(--pbr-line)] bg-white text-[var(--pbr-ink-soft)] shadow-[0_4px_14px_rgb(16_35_26_/_3%)] hover:border-[#c4d5ca] hover:bg-[var(--pbr-green-soft)] hover:text-[var(--pbr-green-dark)] focus-visible:outline-none"
        :aria-label="t('shell.notifications')"
        :title="t('shell.notifications')"
    >
        <svg
            aria-hidden="true"
            viewBox="0 0 24 24"
            class="h-5 w-5"
            fill="none"
            stroke="currentColor"
            stroke-width="1.8"
        >
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" />
            <path d="M10 21h4" />
        </svg>
    </Link>
</template>