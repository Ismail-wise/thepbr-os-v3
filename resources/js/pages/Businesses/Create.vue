<script setup lang="ts">
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { useI18n } from '../../i18n/useI18n';

type SelectOption = {
    value: string;
    label: string;
};

defineProps<{
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
    form.post('/businesses');
};
</script>

<template>
    <AuthenticatedLayout>
        <main class="min-h-screen bg-white px-6 py-10 text-slate-950">
            <section class="mx-auto max-w-3xl">
                <header class="border-b border-slate-200 pb-6">
                    <p class="text-sm font-semibold uppercase tracking-wider text-slate-500">
                        {{ t('common.brand') }}
                    </p>

                    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h1 class="text-2xl font-semibold tracking-tight">
                                {{ t('businessCreate.title') }}
                            </h1>

                            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                                {{ t('businessCreate.description') }}
                            </p>
                        </div>

                        <Link
                            href="/"
                            class="inline-flex min-h-11 items-center justify-center border border-slate-300 px-4 py-2 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2"
                        >
                            {{ t('common.backToAccount') }}
                        </Link>
                    </div>
                </header>

                <div
                    v-if="success"
                    role="status"
                    class="mt-6 border border-slate-300 bg-slate-50 px-4 py-3 text-sm font-medium"
                >
                    {{ success }}
                </div>

                <form class="mt-8 space-y-8" @submit.prevent="submit">
                    <section>
                        <label for="business-name" class="block text-sm font-semibold">
                            {{ t('businessCreate.name') }}
                        </label>

                        <input
                            id="business-name"
                            v-model="form.name"
                            type="text"
                            name="name"
                            autocomplete="organization"
                            maxlength="160"
                            required
                            class="mt-2 min-h-11 w-full border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-700 focus:ring-2 focus:ring-slate-500"
                        >

                        <p
                            v-if="form.errors.name"
                            class="mt-2 text-sm font-medium text-red-700"
                        >
                            {{ form.errors.name }}
                        </p>
                    </section>

                    <fieldset>
                        <legend class="text-sm font-semibold">
                            {{ t('businessCreate.originLegend') }}
                        </legend>

                        <p class="mt-1 text-sm leading-6 text-slate-600">
                            {{ t('businessCreate.originHelp') }}
                        </p>

                        <div class="mt-4 space-y-3">
                            <label
                                v-for="option in originTypes"
                                :key="option.value"
                                :for="`origin-${option.value}`"
                                class="flex min-h-11 cursor-pointer items-start gap-3 border border-slate-300 px-4 py-3 focus-within:ring-2 focus-within:ring-slate-500"
                            >
                                <input
                                    :id="`origin-${option.value}`"
                                    v-model="form.origin_type"
                                    type="radio"
                                    name="origin_type"
                                    :value="option.value"
                                    required
                                    class="mt-1"
                                >

                                <span class="text-sm font-medium">
                                    {{ originLabel(option) }}
                                </span>
                            </label>
                        </div>

                        <p
                            v-if="form.errors.origin_type"
                            class="mt-2 text-sm font-medium text-red-700"
                        >
                            {{ form.errors.origin_type }}
                        </p>
                    </fieldset>

                    <section>
                        <label for="business-stage" class="block text-sm font-semibold">
                            {{ t('businessCreate.stage') }}
                        </label>

                        <p class="mt-1 text-sm leading-6 text-slate-600">
                            {{ t('businessCreate.stageHelp') }}
                        </p>

                        <select
                            id="business-stage"
                            v-model="form.business_stage"
                            name="business_stage"
                            required
                            class="mt-2 min-h-11 w-full border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-slate-700 focus:ring-2 focus:ring-slate-500"
                        >
                            <option value="" disabled>
                                {{ t('businessCreate.selectStage') }}
                            </option>

                            <option
                                v-for="option in businessStages"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ stageLabel(option) }}
                            </option>
                        </select>

                        <p
                            v-if="form.errors.business_stage"
                            class="mt-2 text-sm font-medium text-red-700"
                        >
                            {{ form.errors.business_stage }}
                        </p>
                    </section>

                    <section>
                        <label for="base-currency" class="block text-sm font-semibold">
                            {{ t('businessCreate.baseCurrency') }}
                        </label>

                        <p class="mt-1 text-sm leading-6 text-slate-600">
                            {{ t('businessCreate.baseCurrencyHelp') }}
                        </p>

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
                            class="mt-2 min-h-11 w-full border border-slate-300 px-3 py-2 font-mono text-sm uppercase outline-none focus:border-slate-700 focus:ring-2 focus:ring-slate-500"
                        >

                        <p
                            v-if="form.errors.base_currency"
                            class="mt-2 text-sm font-medium text-red-700"
                        >
                            {{ form.errors.base_currency }}
                        </p>
                    </section>

                    <footer class="flex flex-wrap items-center justify-end gap-3 border-t border-slate-200 pt-6">
                        <Link
                            href="/"
                            class="inline-flex min-h-11 items-center justify-center border border-slate-300 px-4 py-2 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2"
                        >
                            {{ t('businessCreate.cancel') }}
                        </Link>

                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="inline-flex min-h-11 items-center justify-center bg-slate-950 px-5 py-2 text-sm font-semibold text-white focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{
                                form.processing
                                    ? t('businessCreate.creating')
                                    : t('businessCreate.create')
                            }}
                        </button>
                    </footer>
                </form>
            </section>
        </main>
    </AuthenticatedLayout>
</template>
