<script setup lang="ts">
import { computed } from 'vue';
import type { AccountBusiness } from '../../account/types';
import { humanizeAccountValue } from '../../account/copy';
import { useI18n } from '../../i18n/useI18n';
import OpenBusinessButton from './OpenBusinessButton.vue';

const props = defineProps<{
    business: AccountBusiness;
}>();

const { t } = useI18n();

const setupLabel = computed(() => {
    if (!props.business.setupPhase) {
        return t('account.notStarted');
    }

    return humanizeAccountValue(props.business.setupPhase);
});
</script>

<template>
    <article
        class="relative overflow-hidden rounded-[22px] border border-[#d6e3d9] bg-[linear-gradient(145deg,#fff,#f7faf7)] p-5 shadow-[0_12px_30px_rgb(16_35_26_/_5%)]"
    >
        <div
            aria-hidden="true"
            class="absolute -right-7 -top-9 h-24 w-24 rounded-full bg-[#d2a743]/9 blur-2xl"
        />

        <div class="relative">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p
                        class="text-[10px] font-black uppercase tracking-[0.17em] text-[var(--pbr-green)]"
                    >
                        {{ humanizeAccountValue(business.workspaceStatus) }}
                    </p>
                    <h2
                        class="mt-1 truncate text-lg font-black tracking-[-0.02em] text-[var(--pbr-ink)]"
                    >
                        {{ business.name }}
                    </h2>
                </div>

                <span
                    class="rounded-full border border-[#e5dcc0] bg-[#fbf7eb] px-2.5 py-1 text-[10px] font-black text-[#735f2b]"
                >
                    {{ business.baseCurrency }}
                </span>
            </div>

            <dl class="mt-4 grid grid-cols-2 gap-2 text-sm">
                <div class="rounded-xl border border-[#e2e9e4] bg-white/80 p-3">
                    <dt class="text-[10px] font-extrabold uppercase tracking-wide text-[#88948c]">
                        {{ t('account.stage') }}
                    </dt>
                    <dd class="mt-1 font-black text-[var(--pbr-ink-soft)]">
                        {{ humanizeAccountValue(business.stage) }}
                    </dd>
                </div>
                <div class="rounded-xl border border-[#e2e9e4] bg-white/80 p-3">
                    <dt class="text-[10px] font-extrabold uppercase tracking-wide text-[#88948c]">
                        {{ t('account.setup') }}
                    </dt>
                    <dd class="mt-1 font-black text-[var(--pbr-ink-soft)]">
                        {{ setupLabel }}
                    </dd>
                </div>
            </dl>

            <div class="mt-4 flex justify-end">
                <OpenBusinessButton
                    :business-id="business.businessId"
                    href="/overview"
                    :label="t('account.openBusiness')"
                />
            </div>
        </div>
    </article>
</template>
