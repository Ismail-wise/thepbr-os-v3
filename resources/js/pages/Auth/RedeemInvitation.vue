<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from '../../i18n/useI18n';

const props = defineProps<{
    email: string;
    authenticated: boolean;
}>();

const { t } = useI18n();

const detectedTimezone =
    Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';

const form = useForm({
    token: '',
    email: props.email,
    display_name: '',
    password: '',
    password_confirmation: '',
    language_mode: 'en',
    timezone: detectedTimezone,
});

const invitationError = (): string =>
    Object.entries(form.errors).find(([key]) => key === 'invitation')?.[1] ?? '';

const submit = () => {
    form.post('/access/invitation/redeem', {
        preserveScroll: true,
        onFinish: () => {
            form.reset('password', 'password_confirmation');
        },
    });
};
</script>

<template>
    <Head :title="t('invitation.title')" />

    <main class="min-h-screen bg-white px-6 py-12 text-slate-950">
        <section class="mx-auto w-full max-w-xl">
            <p class="text-sm font-semibold uppercase tracking-wider text-slate-500">
                {{ t('common.brand') }}
            </p>

            <h1 class="mt-4 text-3xl font-semibold tracking-tight">
                {{ t('invitation.title') }}
            </h1>

            <p class="mt-3 text-sm leading-6 text-slate-600">
                {{ t('invitation.description') }}
            </p>

            <p
                class="mt-5 border-l-4 border-slate-700 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-700"
                role="note"
            >
                {{ t('invitation.rightsNotice') }}
            </p>

            <form class="mt-8 space-y-5" @submit.prevent="submit">
                <div
                    v-if="invitationError()"
                    id="invitation-error"
                    role="alert"
                    aria-live="polite"
                    class="border border-red-300 bg-red-50 px-4 py-3 text-sm leading-6 text-red-800"
                >
                    {{ invitationError() }}
                </div>

                <div>
                    <label
                        for="invitation-token"
                        class="block text-sm font-medium text-slate-800"
                    >
                        {{ t('invitation.code') }}
                    </label>

                    <input
                        id="invitation-token"
                        v-model="form.token"
                        type="text"
                        name="token"
                        autocomplete="one-time-code"
                        maxlength="64"
                        required
                        autofocus
                        :disabled="form.processing"
                        class="mt-2 block w-full border border-slate-300 px-3 py-3 font-mono text-sm uppercase outline-none focus:border-slate-950 focus:ring-2 focus:ring-slate-300 disabled:opacity-60"
                    >

                    <p
                        v-if="form.errors.token"
                        class="mt-1 text-sm text-red-700"
                    >
                        {{ form.errors.token }}
                    </p>
                </div>

                <div>
                    <label
                        for="invitation-email"
                        class="block text-sm font-medium text-slate-800"
                    >
                        {{ t('common.email') }}
                    </label>

                    <input
                        id="invitation-email"
                        v-model="form.email"
                        type="email"
                        name="email"
                        autocomplete="email"
                        required
                        :readonly="authenticated"
                        :disabled="form.processing"
                        class="mt-2 block w-full border border-slate-300 px-3 py-3 text-base outline-none focus:border-slate-950 focus:ring-2 focus:ring-slate-300 disabled:opacity-60 read-only:bg-slate-50"
                    >

                    <p
                        v-if="form.errors.email"
                        class="mt-1 text-sm text-red-700"
                    >
                        {{ form.errors.email }}
                    </p>
                </div>

                <div>
                    <label
                        for="invitation-display-name"
                        class="block text-sm font-medium text-slate-800"
                    >
                        {{ t('invitation.displayName') }}
                    </label>

                    <input
                        id="invitation-display-name"
                        v-model="form.display_name"
                        type="text"
                        name="display_name"
                        autocomplete="name"
                        maxlength="160"
                        :disabled="form.processing"
                        class="mt-2 block w-full border border-slate-300 px-3 py-3 text-base outline-none focus:border-slate-950 focus:ring-2 focus:ring-slate-300 disabled:opacity-60"
                    >

                    <p class="mt-1 text-xs leading-5 text-slate-500">
                        {{ t('invitation.newAccountOnly') }}
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label
                            for="invitation-password"
                            class="block text-sm font-medium text-slate-800"
                        >
                            {{ t('login.password') }}
                        </label>

                        <input
                            id="invitation-password"
                            v-model="form.password"
                            type="password"
                            name="password"
                            autocomplete="new-password"
                            :disabled="form.processing"
                            class="mt-2 block w-full border border-slate-300 px-3 py-3 text-base outline-none focus:border-slate-950 focus:ring-2 focus:ring-slate-300 disabled:opacity-60"
                        >
                    </div>

                    <div>
                        <label
                            for="invitation-password-confirmation"
                            class="block text-sm font-medium text-slate-800"
                        >
                            {{ t('invitation.confirmPassword') }}
                        </label>

                        <input
                            id="invitation-password-confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            name="password_confirmation"
                            autocomplete="new-password"
                            :disabled="form.processing"
                            class="mt-2 block w-full border border-slate-300 px-3 py-3 text-base outline-none focus:border-slate-950 focus:ring-2 focus:ring-slate-300 disabled:opacity-60"
                        >
                    </div>
                </div>

                <p class="text-xs leading-5 text-slate-500">
                    {{ t('invitation.passwordHelp') }}
                </p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label
                            for="invitation-language"
                            class="block text-sm font-medium text-slate-800"
                        >
                            {{ t('invitation.language') }}
                        </label>

                        <select
                            id="invitation-language"
                            v-model="form.language_mode"
                            name="language_mode"
                            required
                            :disabled="form.processing"
                            class="mt-2 block min-h-12 w-full border border-slate-300 px-3 text-base outline-none focus:border-slate-950 focus:ring-2 focus:ring-slate-300 disabled:opacity-60"
                        >
                            <option value="en">English</option>
                            <option value="my">မြန်မာ</option>
                            <option value="mixed">မြန်မာ + EN</option>
                        </select>
                    </div>

                    <div>
                        <label
                            for="invitation-timezone"
                            class="block text-sm font-medium text-slate-800"
                        >
                            {{ t('invitation.timezone') }}
                        </label>

                        <input
                            id="invitation-timezone"
                            v-model="form.timezone"
                            type="text"
                            name="timezone"
                            required
                            maxlength="64"
                            :disabled="form.processing"
                            class="mt-2 block w-full border border-slate-300 px-3 py-3 text-base outline-none focus:border-slate-950 focus:ring-2 focus:ring-slate-300 disabled:opacity-60"
                        >
                    </div>
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="inline-flex min-h-11 w-full items-center justify-center bg-slate-950 px-4 py-3 text-sm font-semibold text-white focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    {{
                        form.processing
                            ? t('invitation.redeeming')
                            : t('invitation.redeem')
                    }}
                </button>
            </form>

            <div class="mt-6 text-center text-sm">
                <Link
                    :href="authenticated ? '/' : '/login'"
                    class="font-semibold text-slate-700 underline underline-offset-4"
                >
                    {{
                        authenticated
                            ? t('common.backToAccount')
                            : t('invitation.backToLogin')
                    }}
                </Link>
            </div>
        </section>
    </main>
</template>
