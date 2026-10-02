<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from '../i18n/useI18n';

type BusinessOption = {
    id: string;
    name: string;
};

const props = defineProps<{
    businesses: BusinessOption[];
    currentBusiness: BusinessOption | null;
    selectId?: string;
}>();

const { t } = useI18n();

const resolvedSelectId = computed(
    () => props.selectId ?? 'business-switcher',
);

const form = useForm({
    business_id: props.currentBusiness?.id ?? '',
});

watch(
    () => props.currentBusiness?.id,
    (businessId) => {
        form.business_id = businessId ?? '';
    },
);

const selectBusiness = () => {
    if (
        form.processing ||
        form.business_id === '' ||
        form.business_id === props.currentBusiness?.id
    ) {
        return;
    }

    form.post('/current-business', {
        preserveScroll: true,
        preserveState: false,
    });
};
</script>

<template>
    <div>
        <div class="flex items-center justify-between gap-3">
            <label
                :for="resolvedSelectId"
                class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green-dark)]"
            >
                {{ t('businessSwitcher.label') }}
            </label>

            <span
                v-if="currentBusiness"
                class="inline-flex min-h-6 items-center gap-1.5 rounded-full border border-[#cfe2d5] bg-white/80 px-2 text-[9px] font-extrabold text-[#5c7164] shadow-[0_3px_10px_rgb(16_35_26_/_3%)]"
            >
                <span
                    aria-hidden="true"
                    class="h-2 w-2 rounded-full bg-[#20a35d] shadow-[0_0_0_4px_rgb(32_163_93_/_10%)]"
                />
                {{ t('businessSwitcher.current') }}
            </span>
        </div>

        <select
            :id="resolvedSelectId"
            v-model="form.business_id"
            :disabled="form.processing || businesses.length === 0"
            class="pbr-input-control mt-2.5 px-3.5 py-2.5 text-sm font-black tracking-[-0.01em] focus-visible:outline-none disabled:cursor-not-allowed disabled:bg-[#f1f4f2] disabled:text-[var(--pbr-muted)]"
            :aria-label="t('businessSwitcher.ariaLabel')"
            @change="selectBusiness"
        >
            <option value="">
                {{
                    businesses.length === 0
                        ? t('businessSwitcher.noneAccessible')
                        : t('businessSwitcher.select')
                }}
            </option>

            <option
                v-for="business in businesses"
                :key="business.id"
                :value="business.id"
            >
                {{ business.name }}
            </option>
        </select>

        <p
            v-if="form.hasErrors"
            role="alert"
            class="mt-2 text-xs font-semibold text-[var(--pbr-red)]"
        >
            {{ t('businessSwitcher.error') }}
        </p>
    </div>
</template>
