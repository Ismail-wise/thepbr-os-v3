<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from '../../i18n/useI18n';
import { areaLabelKeys } from './labels';
import type { HealthRequirement } from './types';

const props = defineProps<{
    requirement: HealthRequirement;
}>();

const { t } = useI18n();

const statusLabel = computed(() => {
    if (props.requirement.status === 'current_effective') {
        return t('controlCenter.state.currentEffective');
    }

    if (props.requirement.status === 'ready') {
        return t('controlCenter.state.ready');
    }

    if (props.requirement.status === 'review') {
        return t('controlCenter.state.review');
    }

    if (props.requirement.status === 'blocked') {
        return t('controlCenter.state.blocked');
    }

    return t('controlCenter.state.setup');
});

const statusDescription = computed(() => {
    if (props.requirement.status === 'current_effective') {
        return t('controlCenter.reason.currentEffective');
    }

    if (props.requirement.status === 'ready') {
        return t('controlCenter.reason.ready');
    }

    if (props.requirement.status === 'review') {
        return t('controlCenter.reason.review');
    }

    if (props.requirement.status === 'blocked') {
        return t('controlCenter.reason.blocked');
    }

    return t('controlCenter.reason.setupNeeded');
});

const stateClass = computed(() => {
    if (props.requirement.status === 'current_effective') {
        return 'border-[#c9dfd1] bg-[var(--pbr-green-soft)] text-[var(--pbr-green-dark)]';
    }

    if (props.requirement.status === 'review') {
        return 'border-[#ecd6b5] bg-[var(--pbr-amber-soft)] text-[var(--pbr-amber)]';
    }

    if (props.requirement.status === 'blocked') {
        return 'border-[#ecc8c2] bg-[var(--pbr-red-soft)] text-[var(--pbr-red)]';
    }

    if (props.requirement.status === 'setup_needed') {
        return 'border-[#d6e1ee] bg-[var(--pbr-blue-soft)] text-[var(--pbr-blue)]';
    }

    return 'border-[#d8e4db] bg-[var(--pbr-surface-soft)] text-[var(--pbr-ink-soft)]';
});
</script>

<template>
    <component
        :is="requirement.route ? Link : 'article'"
        :href="requirement.route ?? undefined"
        class="group rounded-[20px] border border-[#dce6de] bg-white p-5 shadow-[0_9px_24px_rgb(16_35_26_/_4.5%)]"
        :class="requirement.route ? 'focus-visible:outline-none hover:border-[#c4d8ca] hover:shadow-[0_14px_30px_rgb(16_35_26_/_7%)]' : ''"
    >
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-sm font-black text-[var(--pbr-ink)]">
                    {{ t(areaLabelKeys[requirement.area] ?? 'controlCenter.area.general') }}
                </p>
                <p class="mt-2 text-xs leading-5 text-[var(--pbr-muted)]">
                    {{ statusDescription }}
                </p>
            </div>

            <span
                class="inline-flex min-h-7 shrink-0 items-center rounded-full border px-2.5 text-[10px] font-black"
                :class="stateClass"
            >
                {{ statusLabel }}
            </span>
        </div>

        <div class="mt-4 flex justify-end">
            <span
                v-if="requirement.route"
                class="text-xs font-black text-[var(--pbr-green)] group-hover:text-[var(--pbr-green-dark)]"
            >
                {{ t('controlCenter.open') }} →
            </span>
        </div>
    </component>
</template>
