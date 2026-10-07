<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AccountNav from '../../components/account/AccountNav.vue';
import PbrButton from '../../components/ui/PbrButton.vue';
import PbrFormSection from '../../components/ui/PbrFormSection.vue';
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import Grade6MvpGuide from '../../components/journey/Grade6MvpGuide.vue';
import { useI18n } from '../../i18n/useI18n';
import type { TranslationKey } from '../../i18n/catalog';

type AssessmentSummary = {
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

type DraftSummary = {
    id: string;
    nextStep: number;
    startedAt: string | null;
};

defineProps<{
    assessmentVersion: string;
    latestCompleted: AssessmentSummary | null;
    draft: DraftSummary | null;
}>();

const { t } = useI18n();
const startForm = useForm({});
const retakeForm = useForm({});

const profileLabel = (profile: string | null): string => {
    if (!profile) {
        return '';
    }

    const key = `partnerDynamics.profile.${profile}` as TranslationKey;

    return t(key);
};

const start = () => {
    startForm.post('/partner-dynamics/start');
};

const retake = () => {
    retakeForm.post('/partner-dynamics/retake');
};
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="t('partnerDynamics.title')" />
        <Grade6MvpGuide step="partner_dynamics" />

        <main
            class="pbr-app-canvas min-h-screen px-4 py-5 text-[var(--pbr-ink)] sm:px-6 sm:py-6 lg:px-8 lg:py-7"
        >
            <div class="mx-auto max-w-5xl space-y-5">
                <AccountNav />

                <header
                    class="overflow-hidden rounded-[26px] border border-[#d4e2d7] bg-[radial-gradient(circle_at_90%_0%,rgb(210_167_67_/_13%),transparent_18rem),linear-gradient(145deg,#ffffff,#f4faf6)] p-5 shadow-[0_16px_38px_rgb(16_35_26_/_6%)] sm:p-7"
                >
                    <p
                        class="text-xs font-black uppercase tracking-[0.17em] text-[var(--pbr-green)]"
                    >
                        {{ t('partnerDynamics.eyebrow') }}
                    </p>
                    <h1
                        class="pbr-safe-copy mt-3 text-3xl font-black tracking-[-0.035em] sm:text-4xl"
                    >
                        {{ t('partnerDynamics.title') }}
                    </h1>
                    <p
                        class="pbr-safe-copy mt-3 max-w-3xl text-sm leading-7 text-[var(--pbr-muted)]"
                    >
                        {{ t('partnerDynamics.subtitle') }}
                    </p>
                    <p
                        role="note"
                        class="pbr-safe-copy mt-4 max-w-3xl rounded-2xl border border-[#cfe0d4] bg-white/75 px-4 py-3 text-sm leading-6 text-[var(--pbr-green-dark)]"
                    >
                        {{ t('partnerDynamics.privacy') }}
                    </p>
                </header>

                <PbrFormSection
                    v-if="draft"
                    :title="t('partnerDynamics.continueTitle')"
                    :instruction="t('partnerDynamics.continueBody')"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-[#d9e5dc] bg-[#f8faf8] p-4"
                    >
                        <div>
                            <p class="text-sm font-black text-[var(--pbr-ink-soft)]">
                                {{ t('partnerDynamics.stepLabel') }}
                                {{ draft.nextStep }} / 5
                            </p>
                            <p class="mt-1 text-xs leading-5 text-[var(--pbr-muted)]">
                                {{ t('partnerDynamics.savedDraft') }}
                            </p>
                        </div>
                        <PbrButton
                            :href="`/partner-dynamics/assessments/${draft.id}/steps/${draft.nextStep}`"
                            variant="primary"
                        >
                            {{ t('partnerDynamics.continue') }}
                        </PbrButton>
                    </div>
                </PbrFormSection>

                <PbrFormSection
                    v-if="latestCompleted"
                    :title="t('partnerDynamics.latestResult')"
                    :instruction="t('partnerDynamics.latestResultHelp')"
                >
                    <div
                        class="grid gap-3 rounded-2xl border border-[#d4e2d7] bg-white p-4 sm:grid-cols-3 sm:p-5"
                    >
                        <div class="sm:col-span-2">
                            <p class="text-xs font-black uppercase tracking-[0.14em] text-[#7c8981]">
                                {{ t('partnerDynamics.primaryProfile') }}
                            </p>
                            <p class="mt-1 text-2xl font-black text-[var(--pbr-green-dark)]">
                                {{ profileLabel(latestCompleted.primaryProfile) }}
                            </p>
                            <p
                                v-if="latestCompleted.secondaryProfile"
                                class="mt-2 text-sm leading-6 text-[var(--pbr-muted)]"
                            >
                                {{ t('partnerDynamics.secondaryProfile') }}:
                                <strong>{{
                                    profileLabel(
                                        latestCompleted.secondaryProfile,
                                    )
                                }}</strong>
                            </p>
                        </div>

                        <div
                            class="rounded-2xl bg-[var(--pbr-green-soft)] p-4 text-[var(--pbr-green-dark)]"
                        >
                            <p class="text-xs font-black uppercase tracking-[0.14em]">
                                {{ t('partnerDynamics.confidence') }}
                            </p>
                            <p class="mt-1 text-lg font-black capitalize">
                                {{ latestCompleted.resultConfidence }}
                            </p>
                        </div>
                    </div>

                    <template #actions>
                        <div class="pbr-action-row justify-between">
                            <PbrButton
                                type="button"
                                variant="secondary"
                                :busy="retakeForm.processing"
                                :busy-label="t('partnerDynamics.starting')"
                                @click="retake"
                            >
                                {{ t('partnerDynamics.retake') }}
                            </PbrButton>
                            <PbrButton
                                :href="`/partner-dynamics/results/${latestCompleted.id}`"
                                variant="primary"
                            >
                                {{ t('partnerDynamics.viewResult') }}
                            </PbrButton>
                        </div>
                    </template>
                </PbrFormSection>

                <PbrFormSection
                    v-else-if="!draft"
                    :title="t('partnerDynamics.startTitle')"
                    :instruction="t('partnerDynamics.startBody')"
                >
                    <div
                        class="grid gap-3 sm:grid-cols-2"
                    >
                        <div
                            v-for="item in [
                                t('partnerDynamics.benefitWorkingStyle'),
                                t('partnerDynamics.benefitStrengths'),
                                t('partnerDynamics.benefitDecisions'),
                                t('partnerDynamics.benefitReuse'),
                            ]"
                            :key="item"
                            class="rounded-2xl border border-[#d9e5dc] bg-white px-4 py-3 text-sm font-bold leading-6 text-[var(--pbr-ink-soft)]"
                        >
                            {{ item }}
                        </div>
                    </div>

                    <template #actions>
                        <div class="pbr-action-row justify-end">
                            <PbrButton
                                type="button"
                                variant="primary"
                                :busy="startForm.processing"
                                :busy-label="t('partnerDynamics.starting')"
                                @click="start"
                            >
                                {{ t('partnerDynamics.start') }}
                            </PbrButton>
                        </div>
                    </template>
                </PbrFormSection>
            </div>
        </main>
    </AuthenticatedLayout>
</template>
