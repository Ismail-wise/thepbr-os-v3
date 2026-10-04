<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import GuidedJourneyStepper from '../hybrid/GuidedJourneyStepper.vue';
import { useI18n } from '../../i18n/useI18n';
import type { TranslationKey } from '../../i18n/catalog';
import type { MasterJourneyStep } from '../control-center/types';

const props = defineProps<{
    variant: 'new' | 'existing';
    steps: MasterJourneyStep[];
}>();

const { t } = useI18n();

const labelKeys: Record<string, TranslationKey> = {
    business_model: 'journey.step.businessModel',
    business_valuation: 'journey.step.businessValuation',
    partner_dynamics: 'journey.step.partnerDynamics',
    capital: 'journey.step.capital',
    contributions: 'journey.step.contributions',
    equity: 'journey.step.equity',
    governance: 'journey.step.governance',
    roles_operations: 'journey.step.rolesOperations',
    finance: 'journey.step.finance',
    rewards: 'journey.step.rewards',
    transfer: 'journey.step.transfer',
    exit: 'journey.step.exit',
    conflict: 'journey.step.conflict',
    closure: 'journey.step.closure',
};

const labelFor = (step: MasterJourneyStep): string =>
    t(labelKeys[step.key] ?? 'journey.step.general');

const helperFor = (step: MasterJourneyStep): string => {
    if (step.disabled) {
        return t('journey.state.preparing');
    }

    return t(`journey.state.${step.state}` as TranslationKey);
};

const stepperSteps = computed(() =>
    props.steps.map((step) => ({
        key: step.key,
        label: labelFor(step),
        helper: helperFor(step),
        state: step.state,
        disabled: step.disabled,
    })),
);

const currentStep = computed(
    () => props.steps.find((step) => step.state === 'current') ?? null,
);

const nextStep = computed(
    () => props.steps.find((step) => step.state === 'next') ?? null,
);

const recordedCount = computed(
    () => props.steps.filter((step) => step.state === 'recorded').length,
);

const openStep = (key: string) => {
    const step = props.steps.find((candidate) => candidate.key === key);

    if (step?.route && !step.disabled) {
        router.visit(step.route);
    }
};
</script>

<template>
    <section
        aria-labelledby="master-business-journey-title"
        class="overflow-hidden rounded-[24px] border border-[#cfe0d4] bg-[linear-gradient(145deg,#ffffff_0%,#f4f9f5_60%,#fbf7ed_100%)] shadow-[0_16px_38px_rgb(16_35_26_/_7%)]"
    >
        <div class="p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-3xl">
                    <p
                        class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]"
                    >
                        {{ t('journey.eyebrow') }}
                    </p>
                    <h2
                        id="master-business-journey-title"
                        class="mt-1 text-xl font-black tracking-[-0.02em] sm:text-2xl"
                    >
                        {{ t('journey.title') }}
                    </h2>
                    <p
                        class="pbr-safe-copy mt-2 text-sm leading-6 text-[var(--pbr-muted)]"
                    >
                        {{ t('journey.subtitle') }}
                    </p>
                </div>

                <div
                    class="rounded-2xl border border-[#d7e4da] bg-white/85 px-4 py-3 text-right"
                >
                    <p class="text-[10px] font-black uppercase tracking-[0.14em] text-[#78867d]">
                        {{ t(variant === 'existing' ? 'journey.existingBusiness' : 'journey.newBusiness') }}
                    </p>
                    <p class="mt-1 text-sm font-black text-[var(--pbr-green-dark)]">
                        {{ recordedCount }} / {{ steps.length }} {{ t('journey.recordedSummary') }}
                    </p>
                </div>
            </div>

            <div
                v-if="currentStep"
                class="mt-5 grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(15rem,0.42fr)]"
            >
                <div
                    class="rounded-[20px] border border-[#bcd8c4] bg-white p-5 shadow-[0_10px_24px_rgb(13_106_59_/_6%)]"
                >
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-[var(--pbr-green)]">
                        {{ t('journey.currentFocus') }}
                    </p>
                    <h3 class="mt-1 text-lg font-black text-[var(--pbr-ink)]">
                        {{ labelFor(currentStep) }}
                    </h3>
                    <p class="mt-1 text-sm leading-6 text-[var(--pbr-muted)]">
                        {{ helperFor(currentStep) }}
                    </p>

                    <Link
                        v-if="currentStep.route && !currentStep.disabled"
                        :href="currentStep.route"
                        class="pbr-touch mt-4 inline-flex min-h-10 items-center rounded-xl bg-[var(--pbr-green-dark)] px-4 text-sm font-black text-white shadow-[0_8px_18px_rgb(13_106_59_/_16%)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--pbr-green)] focus-visible:ring-offset-2"
                    >
                        {{ t('journey.continue') }}
                    </Link>
                </div>

                <div
                    v-if="nextStep"
                    class="rounded-[20px] border border-[#e3d8b9] bg-[#fffaf0] p-5"
                >
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-[#836b30]">
                        {{ t('journey.afterThat') }}
                    </p>
                    <p class="mt-1 font-black text-[var(--pbr-ink-soft)]">
                        {{ labelFor(nextStep) }}
                    </p>
                    <p class="mt-1 text-xs leading-5 text-[var(--pbr-muted)]">
                        {{ helperFor(nextStep) }}
                    </p>
                </div>
            </div>

            <div
                v-else-if="steps.length > 0"
                class="mt-5 rounded-[20px] border border-[#bcd8c4] bg-white p-5"
            >
                <p class="font-black text-[var(--pbr-green-dark)]">
                    {{ t('journey.recordedTitle') }}
                </p>
                <p class="mt-1 text-sm leading-6 text-[var(--pbr-muted)]">
                    {{ t('journey.recordedBody') }}
                </p>
            </div>

            <div
                v-else
                class="mt-5 rounded-[20px] border border-[#d9e5dc] bg-white p-5"
            >
                <p class="font-black text-[var(--pbr-ink-soft)]">
                    {{ t('journey.privateTitle') }}
                </p>
                <p class="mt-1 text-sm leading-6 text-[var(--pbr-muted)]">
                    {{ t('journey.privateBody') }}
                </p>
            </div>

            <details v-if="steps.length > 0" class="group mt-5">
                <summary
                    class="pbr-touch flex min-h-10 cursor-pointer list-none items-center justify-between gap-3 rounded-xl border border-[#d3dfd6] bg-white px-4 text-sm font-black text-[var(--pbr-green-dark)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--pbr-green)]"
                >
                    <span>{{ t('journey.viewFull') }}</span>
                    <span aria-hidden="true" class="transition group-open:rotate-180">⌄</span>
                </summary>

                <div class="mt-3">
                    <GuidedJourneyStepper
                        :steps="stepperSteps"
                        :label="t('journey.navigationLabel')"
                        compact
                        @select="openStep"
                    />
                </div>
            </details>

            <p class="pbr-safe-copy mt-4 text-xs leading-5 text-[#748078]">
                {{ t('journey.crossCuttingNote') }}
            </p>
        </div>
    </section>
</template>
