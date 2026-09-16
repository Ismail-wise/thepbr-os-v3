<script setup lang="ts">
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from '../../i18n/useI18n';

const props = defineProps<{
    account: {
        email: string;
        profile: {
            display_name: string;
            language_mode: string;
            timezone: string;
        };
    };
    languageOptions: string[];
    timezoneOptions: string[];
}>();

const { t } = useI18n();
const saved = ref(false);

const form = useForm({
    display_name: props.account.profile.display_name,
    language_mode: props.account.profile.language_mode,
    timezone: props.account.profile.timezone,
});

const languageLabel = (option: string): string => {
    switch (option) {
        case 'en':
            return t('language.en');
        case 'my':
            return t('language.my');
        case 'mixed':
            return t('language.mixed');
        default:
            return option;
    }
};

const submit = () => {
    saved.value = false;

    form.patch('/account/settings', {
        preserveScroll: true,
        onSuccess: () => {
            saved.value = true;
        },
    });
};
</script>

<template>
    <AuthenticatedLayout>
        <main class="min-h-screen bg-white px-6 py-12 text-slate-950">
            <section class="mx-auto max-w-3xl">
                <header class="flex flex-wrap items-start justify-between gap-6 border-b border-slate-200 pb-6">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-wider text-slate-500">
                            {{ t('common.brand') }}
                        </p>

                        <h1 class="mt-2 text-2xl font-semibold tracking-tight">
                            {{ t('settings.title') }}
                        </h1>

                        <p class="mt-2 text-sm leading-6 text-slate-600">
                            {{ t('settings.description') }}
                        </p>
                    </div>

                    <Link
                        href="/"
                        class="inline-flex min-h-11 items-center justify-center border border-slate-300 px-4 py-2 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2"
                    >
                        {{ t('common.backToAccount') }}
                    </Link>
                </header>

                <form class="space-y-8 py-8" @submit.prevent="submit">
                    <div
                        v-if="saved"
                        role="status"
                        aria-live="polite"
                        class="border border-slate-300 px-4 py-3 text-sm leading-6 text-slate-800"
                    >
                        {{ t('settings.updated') }}
                    </div>

                    <section>
                        <h2 class="text-lg font-semibold">
                            {{ t('settings.identity') }}
                        </h2>

                        <div class="mt-4">
                            <label
                                for="email"
                                class="block text-sm font-medium text-slate-800"
                            >
                                {{ t('common.email') }}
                            </label>

                            <input
                                id="email"
                                :value="account.email"
                                type="email"
                                readonly
                                aria-readonly="true"
                                class="mt-2 block w-full border border-slate-200 bg-slate-50 px-3 py-3 text-base text-slate-700"
                            >

                            <p class="mt-2 text-sm leading-6 text-slate-500">
                                {{ t('settings.emailChangeUnavailable') }}
                            </p>
                        </div>

                        <div class="mt-6">
                            <label
                                for="display_name"
                                class="block text-sm font-medium text-slate-800"
                            >
                                {{ t('settings.displayName') }}
                            </label>

                            <input
                                id="display_name"
                                v-model="form.display_name"
                                type="text"
                                name="display_name"
                                maxlength="120"
                                required
                                :disabled="form.processing"
                                :aria-invalid="form.errors.display_name ? 'true' : 'false'"
                                :aria-describedby="form.errors.display_name ? 'display-name-error' : undefined"
                                class="mt-2 block w-full border border-slate-300 px-3 py-3 text-base outline-none focus:border-slate-950 focus:ring-2 focus:ring-slate-300 disabled:cursor-not-allowed disabled:opacity-60"
                            >

                            <p
                                v-if="form.errors.display_name"
                                id="display-name-error"
                                role="alert"
                                class="mt-2 text-sm text-slate-700"
                            >
                                {{ form.errors.display_name }}
                            </p>
                        </div>
                    </section>

                    <section class="border-t border-slate-200 pt-8">
                        <h2 class="text-lg font-semibold">
                            {{ t('settings.preferences') }}
                        </h2>

                        <div class="mt-4">
                            <label
                                for="language_mode"
                                class="block text-sm font-medium text-slate-800"
                            >
                                {{ t('settings.language') }}
                            </label>

                            <select
                                id="language_mode"
                                v-model="form.language_mode"
                                name="language_mode"
                                required
                                :disabled="form.processing"
                                :aria-invalid="form.errors.language_mode ? 'true' : 'false'"
                                :aria-describedby="form.errors.language_mode ? 'language-mode-error' : undefined"
                                class="mt-2 block w-full border border-slate-300 bg-white px-3 py-3 text-base outline-none focus:border-slate-950 focus:ring-2 focus:ring-slate-300 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                <option
                                    v-for="option in languageOptions"
                                    :key="option"
                                    :value="option"
                                >
                                    {{ languageLabel(option) }}
                                </option>
                            </select>

                            <p
                                v-if="form.errors.language_mode"
                                id="language-mode-error"
                                role="alert"
                                class="mt-2 text-sm text-slate-700"
                            >
                                {{ form.errors.language_mode }}
                            </p>

                            <p class="mt-2 text-sm leading-6 text-slate-500">
                                {{ t('settings.languagePreferenceHelp') }}
                            </p>
                        </div>

                        <div class="mt-6">
                            <label
                                for="timezone"
                                class="block text-sm font-medium text-slate-800"
                            >
                                {{ t('settings.timezone') }}
                            </label>

                            <select
                                id="timezone"
                                v-model="form.timezone"
                                name="timezone"
                                required
                                :disabled="form.processing"
                                :aria-invalid="form.errors.timezone ? 'true' : 'false'"
                                :aria-describedby="form.errors.timezone ? 'timezone-error' : undefined"
                                class="mt-2 block w-full border border-slate-300 bg-white px-3 py-3 text-base outline-none focus:border-slate-950 focus:ring-2 focus:ring-slate-300 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                <option
                                    v-for="option in timezoneOptions"
                                    :key="option"
                                    :value="option"
                                >
                                    {{ option }}
                                </option>
                            </select>

                            <p
                                v-if="form.errors.timezone"
                                id="timezone-error"
                                role="alert"
                                class="mt-2 text-sm text-slate-700"
                            >
                                {{ form.errors.timezone }}
                            </p>
                        </div>
                    </section>

                    <div class="border-t border-slate-200 pt-6">
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="inline-flex min-h-11 items-center justify-center bg-slate-950 px-5 py-3 text-sm font-semibold text-white focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{
                                form.processing
                                    ? t('settings.saving')
                                    : t('settings.save')
                            }}
                        </button>
                    </div>
                </form>
            </section>
        </main>
    </AuthenticatedLayout>
</template>
