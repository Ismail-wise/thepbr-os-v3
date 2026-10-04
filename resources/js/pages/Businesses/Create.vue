<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import ProgressiveReveal from '../../components/hybrid/ProgressiveReveal.vue';
import PbrButton from '../../components/ui/PbrButton.vue';
import PbrErrorSummary from '../../components/ui/PbrErrorSummary.vue';
import PbrField from '../../components/ui/PbrField.vue';
import PbrFormSection from '../../components/ui/PbrFormSection.vue';
import PbrTextInput from '../../components/ui/PbrTextInput.vue';
import { useI18n } from '../../i18n/useI18n';
import { humanErrorMessages } from '../../support/humanErrors';

type SelectOption = {
    value: string;
    label: string;
};

const props = defineProps<{
    originTypes: SelectOption[];
    businessStages: SelectOption[];
    success?: string | null;
}>();

const { t } = useI18n();

const form = useForm({
    name: '',
    origin_type: '',
    business_stage: '',
    base_currency: '',
});

const nameReady = computed(() => form.name.trim().length > 0);
const originReady = computed(() => form.origin_type !== '');
const stageReady = computed(() => form.business_stage !== '');
const currencyReady = computed(() =>
    /^[A-Z]{3}$/.test(form.base_currency),
);
const canSubmit = computed(
    () =>
        nameReady.value
        && originReady.value
        && stageReady.value
        && currencyReady.value,
);

const formErrors = (): string[] =>
    humanErrorMessages(
        form.errors as Record<string, string | undefined>,
    );

const originLabel = (option: SelectOption): string => {
    switch (option.value) {
        case 'started_through_pbr':
            return t('businessOrigin.started_through_pbr');
        case 'existing_business_imported_into_pbr':
            return t(
                'businessOrigin.existing_business_imported_into_pbr',
            );
        default:
            return option.label;
    }
};

const stageLabel = (option: SelectOption): string => {
    switch (option.value) {
        case 'idea':
            return t('businessStage.idea');
        case 'validation':
            return t('businessStage.validation');
        case 'planning':
            return t('businessStage.planning');
        case 'pre_launch':
            return t('businessStage.pre_launch');
        case 'operating':
            return t('businessStage.operating');
        case 'growth':
            return t('businessStage.growth');
        case 'restructuring':
            return t('businessStage.restructuring');
        case 'exit':
            return t('businessStage.exit');
        default:
            return option.label;
    }
};

const submit = () => {
    if (!canSubmit.value) {
        return;
    }

    form.post('/businesses', {
        preserveScroll: true,
    });
};
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="t('businessCreate.title')" />

        <main
            class="pbr-app-canvas min-h-screen px-4 py-5 text-[var(--pbr-ink)] sm:px-6 sm:py-6 lg:px-8 lg:py-7"
        >
            <section class="pbr-reading-width max-w-3xl space-y-5">
                <header
                    class="rounded-[24px] border border-[#d7e3da] bg-[linear-gradient(145deg,#ffffff,#f5faf6)] p-5 shadow-[0_14px_34px_rgb(16_35_26_/_5%)] sm:p-6"
                >
                    <div
                        class="flex min-w-0 flex-wrap items-start justify-between gap-4"
                    >
                        <div class="min-w-0 flex-1">
                            <p
                                class="pbr-safe-copy text-xs font-black uppercase tracking-[0.16em] text-[var(--pbr-green)]"
                            >
                                {{ t('common.brand') }}
                            </p>

                            <h1
                                class="pbr-safe-copy mt-3 text-2xl font-black tracking-[-0.03em] sm:text-3xl"
                            >
                                {{ t('businessCreate.title') }}
                            </h1>

                            <p
                                class="pbr-safe-copy mt-2 max-w-2xl text-sm leading-7 text-[var(--pbr-muted)]"
                            >
                                {{ t('businessCreate.description') }}
                            </p>
                        </div>

                        <Link
                            href="/"
                            class="pbr-touch inline-flex items-center rounded-xl px-3 text-sm font-bold text-[var(--pbr-ink-soft)] underline underline-offset-4"
                        >
                            {{ t('common.backToAccount') }}
                        </Link>
                    </div>
                </header>

                <section
                    v-if="props.success"
                    role="status"
                    class="rounded-[22px] border border-[#bcd8c4] bg-[var(--pbr-green-soft)] p-5 text-[var(--pbr-green-dark)] shadow-[0_12px_28px_rgb(16_35_26_/_4%)]"
                >
                    <p class="pbr-safe-copy text-sm font-black leading-6">
                        {{ props.success }}
                    </p>
                    <p
                        class="pbr-safe-copy mt-1 text-sm leading-6 text-[var(--pbr-muted)]"
                    >
                        {{ t('businessCreate.description') }}
                    </p>
                    <div class="mt-4">
                        <PbrButton href="/overview" variant="primary">
                            {{ t('account.openBusiness') }}
                        </PbrButton>
                    </div>
                </section>

                <form class="space-y-5" @submit.prevent="submit">
                    <PbrErrorSummary
                        :title="t('businessCreate.title')"
                        :errors="formErrors()"
                    />

                    <PbrFormSection
                        numbered="1"
                        :title="t('businessCreate.name')"
                        :instruction="t('businessCreate.description')"
                    >
                        <PbrTextInput
                            id="business-name"
                            v-model="form.name"
                            :label="t('businessCreate.name')"
                            :instruction="t('businessCreate.description')"
                            example="Example · Golden River Trading"
                            name="name"
                            autocomplete="organization"
                            required
                            :disabled="form.processing"
                            :error="form.errors.name"
                        />
                    </PbrFormSection>

                    <ProgressiveReveal
                        :visible="
                            nameReady
                            || Boolean(form.errors.origin_type)
                        "
                    >
                        <PbrFormSection
                            numbered="2"
                            :title="t('businessCreate.originLegend')"
                            :instruction="t('businessCreate.originHelp')"
                        >
                            <fieldset>
                                <legend class="sr-only">
                                    {{ t('businessCreate.originLegend') }}
                                </legend>

                                <div class="grid gap-3 sm:grid-cols-2">
                                    <label
                                        v-for="option in props.originTypes"
                                        :key="option.value"
                                        :for="'origin-' + option.value"
                                        class="pbr-touch flex min-w-0 cursor-pointer items-start gap-3 rounded-2xl border px-4 py-4 transition focus-within:ring-2 focus-within:ring-[var(--pbr-green)]"
                                        :class="
                                            form.origin_type === option.value
                                                ? 'border-[var(--pbr-green)] bg-[var(--pbr-green-soft)] text-[var(--pbr-green-dark)]'
                                                : 'border-[var(--pbr-line-strong)] bg-white text-[var(--pbr-ink-soft)] hover:border-[#b7cabd]'
                                        "
                                    >
                                        <input
                                            :id="'origin-' + option.value"
                                            v-model="form.origin_type"
                                            type="radio"
                                            name="origin_type"
                                            :value="option.value"
                                            required
                                            :disabled="form.processing"
                                            class="mt-1 shrink-0 accent-[var(--pbr-green)]"
                                        >

                                        <span
                                            class="pbr-safe-copy min-w-0 text-sm font-bold leading-6"
                                        >
                                            {{ originLabel(option) }}
                                        </span>
                                    </label>
                                </div>

                                <p
                                    v-if="form.errors.origin_type"
                                    class="pbr-safe-copy mt-3 text-sm font-bold text-[var(--pbr-red)]"
                                >
                                    {{ form.errors.origin_type }}
                                </p>
                            </fieldset>
                        </PbrFormSection>
                    </ProgressiveReveal>

                    <ProgressiveReveal
                        :visible="
                            originReady
                            || Boolean(form.errors.business_stage)
                        "
                    >
                        <PbrFormSection
                            numbered="3"
                            :title="t('businessCreate.stage')"
                            :instruction="t('businessCreate.stageHelp')"
                        >
                            <PbrField
                                :label="t('businessCreate.stage')"
                                for-id="business-stage"
                                :instruction="t('businessCreate.stageHelp')"
                                :error="form.errors.business_stage"
                                required
                            >
                                <select
                                    id="business-stage"
                                    v-model="form.business_stage"
                                    name="business_stage"
                                    required
                                    :disabled="form.processing"
                                    class="pbr-input-control px-3.5 py-3 text-base disabled:cursor-not-allowed disabled:bg-[#f2f5f2] disabled:opacity-70"
                                >
                                    <option value="" disabled>
                                        {{ t('businessCreate.selectStage') }}
                                    </option>

                                    <option
                                        v-for="option in props.businessStages"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ stageLabel(option) }}
                                    </option>
                                </select>
                            </PbrField>
                        </PbrFormSection>
                    </ProgressiveReveal>

                    <ProgressiveReveal
                        :visible="
                            stageReady
                            || Boolean(form.errors.base_currency)
                        "
                    >
                        <PbrFormSection
                            numbered="4"
                            :title="t('businessCreate.baseCurrency')"
                            :instruction="
                                t('businessCreate.baseCurrencyHelp')
                            "
                        >
                            <PbrField
                                :label="t('businessCreate.baseCurrency')"
                                for-id="base-currency"
                                :instruction="
                                    t('businessCreate.baseCurrencyHelp')
                                "
                                example="USD"
                                :error="form.errors.base_currency"
                                required
                            >
                                <input
                                    id="base-currency"
                                    v-model="form.base_currency"
                                    type="text"
                                    name="base_currency"
                                    inputmode="text"
                                    maxlength="3"
                                    minlength="3"
                                    pattern="[A-Z]{3}"
                                    placeholder="USD"
                                    required
                                    :disabled="form.processing"
                                    class="pbr-input-control block px-3.5 py-3 font-mono text-base uppercase leading-6 placeholder:text-[#98a39d] disabled:cursor-not-allowed disabled:bg-[#f2f5f2] disabled:opacity-70"
                                >
                            </PbrField>

                            <template #actions>
                                <div
                                    class="pbr-action-row justify-between"
                                >
                                    <Link
                                        href="/"
                                        class="pbr-touch inline-flex items-center rounded-xl px-3 text-sm font-bold text-[var(--pbr-ink-soft)] underline underline-offset-4"
                                    >
                                        {{ t('businessCreate.cancel') }}
                                    </Link>

                                    <PbrButton
                                        type="submit"
                                        variant="primary"
                                        :disabled="!canSubmit"
                                        :busy="form.processing"
                                        :busy-label="
                                            t('businessCreate.creating')
                                        "
                                    >
                                        {{ t('businessCreate.create') }}
                                    </PbrButton>
                                </div>
                            </template>
                        </PbrFormSection>
                    </ProgressiveReveal>
                </form>
            </section>
        </main>
    </AuthenticatedLayout>
</template>
