<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useI18n } from '../../i18n/useI18n';
import { attentionLabelKeys } from './labels';
import type { AttentionItem } from './types';

defineProps<{
    items: AttentionItem[];
}>();

const { t } = useI18n();
</script>

<template>
    <section class="pbr-surface overflow-hidden">
        <div class="border-b border-[var(--pbr-line)] px-5 py-4 sm:px-6">
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[#8a6851]">
                {{ t('controlCenter.attention.eyebrow') }}
            </p>
            <div class="mt-1 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-xl font-black tracking-[-0.02em] text-[var(--pbr-ink)]">
                        {{ t('controlCenter.attention.title') }}
                    </h2>
                    <p class="mt-1 text-sm leading-6 text-[var(--pbr-muted)]">
                        {{ t('controlCenter.attention.subtitle') }}
                    </p>
                </div>
                <span
                    v-if="items.length > 0"
                    class="inline-flex min-h-8 items-center rounded-full border border-[#edd7bb] bg-[var(--pbr-amber-soft)] px-3 text-xs font-black text-[var(--pbr-amber)]"
                >
                    {{ items.length }} {{ t('controlCenter.attention.items') }}
                </span>
            </div>
        </div>

        <div v-if="items.length > 0" class="grid gap-3 p-4 sm:grid-cols-2 sm:p-5 lg:grid-cols-3">
            <Link
                v-for="item in items"
                :key="`${item.key}|${item.route}`"
                :href="item.route"
                class="group rounded-2xl border p-4 focus-visible:outline-none"
                :class="
                    item.tone === 'danger'
                        ? 'border-[#edc8c2] bg-[linear-gradient(145deg,#fff,#fff4f2)]'
                        : item.tone === 'warning'
                          ? 'border-[#ecd8b9] bg-[linear-gradient(145deg,#fff,#fff9ee)]'
                          : 'border-[#d5e2d8] bg-[linear-gradient(145deg,#fff,#f5faf6)]'
                "
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-sm font-black text-[var(--pbr-ink)]">
                            {{ t(attentionLabelKeys[item.key] ?? 'controlCenter.attention.general') }}
                        </p>
                        <p class="mt-2 text-xs leading-5 text-[var(--pbr-muted)]">
                            {{
                                item.count === 1
                                    ? t('controlCenter.attention.oneItem')
                                    : t('controlCenter.attention.multipleItems').replace('{count}', String(item.count))
                            }}
                        </p>
                    </div>
                    <span
                        class="grid h-8 min-w-8 place-items-center rounded-full border bg-white text-xs font-black"
                        :class="
                            item.tone === 'danger'
                                ? 'border-[#ecc5bf] text-[var(--pbr-red)]'
                                : item.tone === 'warning'
                                  ? 'border-[#ead4af] text-[var(--pbr-amber)]'
                                  : 'border-[#cfe0d4] text-[var(--pbr-green)]'
                        "
                    >
                        {{ item.count }}
                    </span>
                </div>

                <span
                    class="mt-4 inline-flex items-center gap-1 text-xs font-black text-[var(--pbr-green)] group-hover:text-[var(--pbr-green-dark)]"
                >
                    {{ t('controlCenter.open') }}
                    <span aria-hidden="true">→</span>
                </span>
            </Link>
        </div>

        <div
            v-else
            class="m-4 rounded-2xl border border-[#cfe3d5] bg-[linear-gradient(145deg,#f4faf6,#fbfdfb)] px-5 py-5 sm:m-5"
        >
            <div class="flex items-start gap-3">
                <span
                    aria-hidden="true"
                    class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-[var(--pbr-green-soft)] text-[var(--pbr-green)]"
                >
                    ✓
                </span>
                <div>
                    <p class="font-black text-[var(--pbr-green-dark)]">
                        {{ t('controlCenter.attention.clearTitle') }}
                    </p>
                    <p class="mt-1 text-sm leading-6 text-[var(--pbr-muted)]">
                        {{ t('controlCenter.attention.clearBody') }}
                    </p>
                </div>
            </div>
        </div>
    </section>
</template>
