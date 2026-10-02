<script setup lang="ts">
import { computed } from 'vue';
import { accountKindKey, humanizeAccountValue } from '../../account/copy';
import { useI18n } from '../../i18n/useI18n';
import { formatAccountDate } from '../../account/format';
import OpenBusinessButton from './OpenBusinessButton.vue';

const props = defineProps<{
    businessId: string;
    businessName: string;
    kind: string;
    title: string;
    description?: string | null;
    status?: string | null;
    dateLabel?: string;
    dateValue?: string | null;
    route: string;
    actionLabel: string;
}>();

const { t } = useI18n();

const kindLabel = computed(() => t(accountKindKey(props.kind)));

const statusLabel = computed(() => {
    if (!props.status) {
        return '';
    }

    if (props.status === 'unread') {
        return t('account.unread');
    }

    if (props.status === 'read') {
        return t('account.read');
    }

    return humanizeAccountValue(props.status);
});
</script>

<template>
    <article
        class="group relative overflow-hidden rounded-[20px] border border-[#d8e4db] bg-white p-4 shadow-[0_10px_28px_rgb(16_35_26_/_4%)] transition hover:-translate-y-0.5 hover:border-[#c4d9ca] hover:shadow-[0_14px_34px_rgb(16_35_26_/_7%)] sm:p-5"
    >
        <div
            aria-hidden="true"
            class="absolute inset-y-0 left-0 w-[3px] bg-gradient-to-b from-[var(--pbr-green)] to-[var(--pbr-gold)] opacity-80"
        />

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="inline-flex min-h-6 items-center rounded-full border border-[#cfe1d4] bg-[#eef6f0] px-2.5 text-[10px] font-black uppercase tracking-[0.12em] text-[var(--pbr-green-dark)]"
                    >
                        {{ kindLabel }}
                    </span>
                    <span
                        v-if="statusLabel"
                        class="inline-flex min-h-6 items-center rounded-full border border-[#e5dcc0] bg-[#fbf7eb] px-2.5 text-[10px] font-extrabold text-[#735f2b]"
                    >
                        {{ statusLabel }}
                    </span>
                </div>

                <h2
                    class="mt-3 text-[17px] font-black tracking-[-0.02em] text-[var(--pbr-ink)]"
                >
                    {{ title }}
                </h2>
                <p class="mt-1 text-sm font-bold text-[var(--pbr-green-dark)]">
                    {{ businessName }}
                </p>
                <p
                    v-if="description"
                    class="mt-2 line-clamp-2 text-sm leading-6 text-[var(--pbr-muted)]"
                >
                    {{ description }}
                </p>
                <p
                    v-if="dateValue"
                    class="mt-3 text-xs font-semibold text-[#758279]"
                >
                    {{ dateLabel }} · {{ formatAccountDate(dateValue) }}
                </p>
            </div>

            <OpenBusinessButton
                :business-id="businessId"
                :href="route"
                :label="actionLabel"
            />
        </div>
    </article>
</template>
