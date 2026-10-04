<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import PbrButton from '../../components/ui/PbrButton.vue';
import PbrErrorSummary from '../../components/ui/PbrErrorSummary.vue';
import PbrFormSection from '../../components/ui/PbrFormSection.vue';
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import { useI18n } from '../../i18n/useI18n';
import { humanErrorMessages } from '../../support/humanErrors';

type QuestionOption = {
    value: string;
    text: string;
};

type Question = {
    number: number;
    title: string | null;
    text: string;
    options: QuestionOption[];
};

const props = defineProps<{
    assessment: {
        id: string;
        version: string;
    };
    step: number;
    totalSteps: number;
    questions: Question[];
    answers: Record<string, string | number | null>;
}>();

const { t } = useI18n();

const initialAnswers: Record<string, string | number> = {};

for (const question of props.questions) {
    const value = props.answers[String(question.number)];

    if (value !== null && value !== undefined) {
        initialAnswers[String(question.number)] = value;
    }
}

const form = useForm({
    answers: initialAnswers,
});

const progress = computed(
    () => Math.round((props.step / props.totalSteps) * 100),
);

const complete = computed(() =>
    props.questions.every((question) => {
        const value = form.answers[String(question.number)];

        return value !== undefined && value !== null && value !== '';
    }),
);

const errors = (): string[] =>
    humanErrorMessages(
        form.errors as Record<string, string | undefined>,
    );

const submit = () => {
    if (!complete.value) {
        return;
    }

    form.put(
        `/partner-dynamics/assessments/${props.assessment.id}/steps/${props.step}`,
        {
            preserveScroll: true,
        },
    );
};
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="t('partnerDynamics.assessmentTitle')" />

        <main
            class="pbr-app-canvas min-h-screen px-4 py-5 text-[var(--pbr-ink)] sm:px-6 sm:py-6 lg:px-8 lg:py-7"
        >
            <section class="mx-auto max-w-4xl space-y-5">
                <header
                    class="rounded-[24px] border border-[#d7e3da] bg-white p-5 shadow-[0_14px_34px_rgb(16_35_26_/_5%)] sm:p-6"
                >
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <p
                                class="text-xs font-black uppercase tracking-[0.16em] text-[var(--pbr-green)]"
                            >
                                {{ t('partnerDynamics.eyebrow') }}
                            </p>
                            <h1
                                class="pbr-safe-copy mt-2 text-2xl font-black tracking-[-0.03em] sm:text-3xl"
                            >
                                {{ t('partnerDynamics.assessmentTitle') }}
                            </h1>
                            <p
                                class="pbr-safe-copy mt-2 text-sm leading-6 text-[var(--pbr-muted)]"
                            >
                                {{ t('partnerDynamics.stepLabel') }}
                                {{ step }} / {{ totalSteps }}
                            </p>
                        </div>

                        <Link
                            href="/partner-dynamics"
                            class="pbr-touch inline-flex items-center rounded-xl px-3 text-sm font-bold text-[var(--pbr-ink-soft)] underline underline-offset-4"
                        >
                            {{ t('partnerDynamics.saveExit') }}
                        </Link>
                    </div>

                    <div
                        class="mt-5 h-2 overflow-hidden rounded-full bg-[#e4ebe6]"
                        role="progressbar"
                        :aria-valuenow="progress"
                        aria-valuemin="0"
                        aria-valuemax="100"
                    >
                        <div
                            class="h-full rounded-full bg-[linear-gradient(90deg,var(--pbr-green),var(--pbr-gold))] transition-[width]"
                            :style="{ width: `${progress}%` }"
                        />
                    </div>
                </header>

                <form class="space-y-5" @submit.prevent="submit">
                    <PbrErrorSummary
                        :title="t('partnerDynamics.checkAnswers')"
                        :errors="errors()"
                    />

                    <PbrFormSection
                        :numbered="String(step)"
                        :title="
                            step === 5
                                ? t('partnerDynamics.scenarioTitle')
                                : t('partnerDynamics.behaviourTitle')
                        "
                        :instruction="
                            step === 5
                                ? t('partnerDynamics.scenarioInstruction')
                                : t('partnerDynamics.behaviourInstruction')
                        "
                    >
                        <fieldset
                            v-for="question in questions"
                            :key="question.number"
                            class="rounded-2xl border border-[#d9e5dc] bg-white p-4 sm:p-5"
                        >
                            <legend
                                class="pbr-safe-copy w-full text-sm font-black leading-6 text-[var(--pbr-ink)]"
                            >
                                <span
                                    class="mr-2 text-[var(--pbr-green)]"
                                >
                                    {{ question.number }}.
                                </span>
                                <span
                                    v-if="question.title"
                                    class="mr-2 text-[var(--pbr-green-dark)]"
                                >
                                    {{ question.title }}
                                </span>
                                {{ question.text }}
                            </legend>

                            <div
                                v-if="step <= 4"
                                class="mt-4 grid gap-2 sm:grid-cols-5"
                            >
                                <label
                                    v-for="value in [1, 2, 3, 4, 5]"
                                    :key="value"
                                    class="pbr-touch flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-2.5 text-sm font-bold transition"
                                    :class="
                                        Number(
                                            form.answers[
                                                String(question.number)
                                            ],
                                        ) === value
                                            ? 'border-[var(--pbr-green)] bg-[var(--pbr-green-soft)] text-[var(--pbr-green-dark)]'
                                            : 'border-[#d9e5dc] bg-[#fafcfa] text-[var(--pbr-ink-soft)]'
                                    "
                                >
                                    <input
                                        v-model="
                                            form.answers[
                                                String(question.number)
                                            ]
                                        "
                                        type="radio"
                                        :name="`answers[${question.number}]`"
                                        :value="value"
                                        class="accent-[var(--pbr-green)]"
                                    >
                                    <span>{{ value }}</span>
                                </label>
                            </div>

                            <div
                                v-else
                                class="mt-4 grid gap-2"
                            >
                                <label
                                    v-for="option in question.options"
                                    :key="option.value"
                                    class="pbr-touch flex cursor-pointer items-start gap-3 rounded-xl border px-4 py-3 text-sm leading-6 transition"
                                    :class="
                                        form.answers[
                                            String(question.number)
                                        ] === option.value
                                            ? 'border-[var(--pbr-green)] bg-[var(--pbr-green-soft)] text-[var(--pbr-green-dark)]'
                                            : 'border-[#d9e5dc] bg-[#fafcfa] text-[var(--pbr-ink-soft)]'
                                    "
                                >
                                    <input
                                        v-model="
                                            form.answers[
                                                String(question.number)
                                            ]
                                        "
                                        type="radio"
                                        :name="`answers[${question.number}]`"
                                        :value="option.value"
                                        class="mt-1 accent-[var(--pbr-green)]"
                                    >
                                    <span class="min-w-0">
                                        <strong>{{ option.value }}.</strong>
                                        {{ option.text }}
                                    </span>
                                </label>
                            </div>

                            <p
                                v-if="step <= 4"
                                class="mt-3 flex justify-between gap-3 text-[11px] font-bold text-[#7d8a82]"
                            >
                                <span>{{
                                    t('partnerDynamics.scaleDisagree')
                                }}</span>
                                <span class="text-right">{{
                                    t('partnerDynamics.scaleAgree')
                                }}</span>
                            </p>
                        </fieldset>

                        <template #actions>
                            <div class="pbr-action-row justify-between">
                                <PbrButton
                                    v-if="step > 1"
                                    :href="`/partner-dynamics/assessments/${assessment.id}/steps/${step - 1}`"
                                    variant="secondary"
                                >
                                    {{ t('partnerDynamics.previous') }}
                                </PbrButton>
                                <span v-else />

                                <PbrButton
                                    type="submit"
                                    variant="primary"
                                    :disabled="!complete"
                                    :busy="form.processing"
                                    :busy-label="t('partnerDynamics.saving')"
                                >
                                    {{
                                        step === totalSteps
                                            ? t('partnerDynamics.finish')
                                            : t('partnerDynamics.saveNext')
                                    }}
                                </PbrButton>
                            </div>
                        </template>
                    </PbrFormSection>
                </form>
            </section>
        </main>
    </AuthenticatedLayout>
</template>
