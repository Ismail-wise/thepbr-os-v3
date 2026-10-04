<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PbrButton from '../../components/ui/PbrButton.vue';
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import { useI18n } from '../../i18n/useI18n';
import type { TranslationKey } from '../../i18n/catalog';

type Result = {
    id: string;
    assessmentVersion: string;
    primaryProfile: string;
    primaryScore: number;
    secondaryProfile: string | null;
    secondaryScore: number | null;
    isBlended: boolean;
    resultConfidence: string;
    dimensionScores: Record<string, number>;
    profileScores: Record<string, number>;
    completedAt: string | null;
};

const props = defineProps<{
    result: Result;
}>();

const { t } = useI18n();
const page = usePage();

type WorkspaceContext = {
    currentBusiness?: {
        id: string;
        name: string;
    } | null;
};

const hasCurrentBusiness = computed(
    () =>
        Boolean(
            (page.props.workspace as WorkspaceContext | null | undefined)
                ?.currentBusiness,
        ),
);

const profileLabel = (profile: string | null): string => {
    if (!profile) {
        return '';
    }

    return t(`partnerDynamics.profile.${profile}` as TranslationKey);
};

const dimensionLabel = (dimension: string): string =>
    t(`partnerDynamics.dimension.${dimension}` as TranslationKey);

const dimensions = computed(() =>
    Object.entries(props.result.dimensionScores)
        .map(([key, score]) => ({
            key,
            score: Math.max(0, Math.min(100, Number(score))),
        }))
        .sort((a, b) => b.score - a.score),
);
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="t('partnerDynamics.resultTitle')" />

        <main
            class="pbr-app-canvas min-h-screen px-4 py-5 text-[var(--pbr-ink)] sm:px-6 sm:py-6 lg:px-8 lg:py-7"
        >
            <div class="mx-auto max-w-5xl space-y-5">
                <section
                    class="overflow-hidden rounded-[28px] border border-[#cfe0d4] bg-[radial-gradient(circle_at_90%_10%,rgb(210_167_67_/_17%),transparent_20rem),linear-gradient(145deg,#ffffff,#eff8f2)] p-5 shadow-[0_18px_44px_rgb(16_35_26_/_7%)] sm:p-7"
                >
                    <p
                        class="text-xs font-black uppercase tracking-[0.17em] text-[var(--pbr-green)]"
                    >
                        {{ t('partnerDynamics.resultEyebrow') }}
                    </p>
                    <h1
                        class="pbr-safe-copy mt-3 text-3xl font-black tracking-[-0.04em] sm:text-5xl"
                    >
                        {{ profileLabel(result.primaryProfile) }}
                    </h1>
                    <p
                        v-if="result.secondaryProfile"
                        class="pbr-safe-copy mt-3 text-base leading-7 text-[var(--pbr-muted)]"
                    >
                        {{ t('partnerDynamics.secondaryProfile') }}:
                        <strong class="text-[var(--pbr-ink-soft)]">{{
                            profileLabel(result.secondaryProfile)
                        }}</strong>
                        <span v-if="result.isBlended">
                            · {{ t('partnerDynamics.blended') }}
                        </span>
                    </p>

                    <div class="mt-5 grid gap-3 sm:grid-cols-3">
                        <div
                            class="rounded-2xl border border-white/80 bg-white/75 p-4"
                        >
                            <p class="text-xs font-black uppercase tracking-[0.14em] text-[#7c8981]">
                                {{ t('partnerDynamics.primaryScore') }}
                            </p>
                            <p class="mt-1 text-2xl font-black text-[var(--pbr-green-dark)]">
                                {{ result.primaryScore.toFixed(1) }}
                            </p>
                        </div>
                        <div
                            class="rounded-2xl border border-white/80 bg-white/75 p-4"
                        >
                            <p class="text-xs font-black uppercase tracking-[0.14em] text-[#7c8981]">
                                {{ t('partnerDynamics.confidence') }}
                            </p>
                            <p class="mt-1 text-lg font-black capitalize text-[var(--pbr-ink-soft)]">
                                {{ result.resultConfidence }}
                            </p>
                        </div>
                        <div
                            class="rounded-2xl border border-white/80 bg-white/75 p-4"
                        >
                            <p class="text-xs font-black uppercase tracking-[0.14em] text-[#7c8981]">
                                {{ t('partnerDynamics.version') }}
                            </p>
                            <p class="mt-1 text-lg font-black text-[var(--pbr-ink-soft)]">
                                {{ result.assessmentVersion }}
                            </p>
                        </div>
                    </div>
                </section>

                <section
                    class="rounded-[24px] border border-[#d9e5dc] bg-white p-5 shadow-[0_12px_30px_rgb(16_35_26_/_4%)] sm:p-6"
                >
                    <h2
                        class="text-xl font-black tracking-[-0.02em]"
                    >
                        {{ t('partnerDynamics.workingDimensions') }}
                    </h2>
                    <p
                        class="mt-1 max-w-3xl text-sm leading-6 text-[var(--pbr-muted)]"
                    >
                        {{ t('partnerDynamics.workingDimensionsHelp') }}
                    </p>

                    <div class="mt-5 grid gap-4 md:grid-cols-2">
                        <div
                            v-for="dimension in dimensions"
                            :key="dimension.key"
                            class="rounded-2xl border border-[#e0e8e2] bg-[#fafcfa] p-4"
                        >
                            <div
                                class="flex items-center justify-between gap-3"
                            >
                                <p class="text-sm font-black text-[var(--pbr-ink-soft)]">
                                    {{ dimensionLabel(dimension.key) }}
                                </p>
                                <p class="text-sm font-black text-[var(--pbr-green-dark)]">
                                    {{ dimension.score.toFixed(1) }}
                                </p>
                            </div>
                            <div
                                class="mt-3 h-2 overflow-hidden rounded-full bg-[#e2eae4]"
                            >
                                <div
                                    class="h-full rounded-full bg-[linear-gradient(90deg,var(--pbr-green),var(--pbr-gold))]"
                                    :style="{ width: `${dimension.score}%` }"
                                />
                            </div>
                        </div>
                    </div>
                </section>

                <section
                    role="note"
                    class="rounded-[22px] border border-[#d4e2d7] bg-[var(--pbr-green-soft)] p-5 text-sm leading-7 text-[var(--pbr-green-dark)]"
                >
                    <strong>{{ t('partnerDynamics.privacyTitle') }}</strong>
                    {{ t('partnerDynamics.resultPrivacy') }}
                </section>

                <div class="pbr-action-row justify-between">
                    <PbrButton href="/partner-dynamics" variant="secondary">
                        {{ t('partnerDynamics.back') }}
                    </PbrButton>
                    <PbrButton
                        :href="hasCurrentBusiness ? '/overview' : '/'"
                        variant="primary"
                    >
                        {{
                            hasCurrentBusiness
                                ? t('partnerDynamics.backToJourney')
                                : t('common.backToAccount')
                        }}
                    </PbrButton>
                </div>
            </div>
        </main>
    </AuthenticatedLayout>
</template>
