<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { useI18n } from '../../i18n/useI18n';

const { t } = useI18n();

const form = useForm({
    email: '',
    password: '',
});

const submit = () => {
    form.post('/login', {
        preserveScroll: true,
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <main class="min-h-screen bg-white px-6 py-16 text-slate-950">
        <section class="mx-auto w-full max-w-md">
            <p class="text-sm font-semibold uppercase tracking-wider text-slate-500">
                {{ t('common.brand') }}
            </p>

            <h1 class="mt-4 text-3xl font-semibold tracking-tight">
                {{ t('login.title') }}
            </h1>

            <p class="mt-3 text-sm leading-6 text-slate-600">
                {{ t('login.description') }}
            </p>

            <form class="mt-8 space-y-6" @submit.prevent="submit">
                <div
                    v-if="form.errors.email"
                    id="login-error"
                    role="alert"
                    aria-live="polite"
                    class="border border-slate-300 px-4 py-3 text-sm leading-6 text-slate-800"
                >
                    {{ form.errors.email }}
                </div>

                <div>
                    <label
                        for="email"
                        class="block text-sm font-medium text-slate-800"
                    >
                        {{ t('common.email') }}
                    </label>

                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        name="email"
                        autocomplete="username"
                        required
                        autofocus
                        :disabled="form.processing"
                        :aria-invalid="form.errors.email ? 'true' : 'false'"
                        :aria-describedby="form.errors.email ? 'login-error' : undefined"
                        class="mt-2 block w-full border border-slate-300 px-3 py-3 text-base outline-none focus:border-slate-950 focus:ring-2 focus:ring-slate-300 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                </div>

                <div>
                    <label
                        for="password"
                        class="block text-sm font-medium text-slate-800"
                    >
                        {{ t('login.password') }}
                    </label>

                    <input
                        id="password"
                        v-model="form.password"
                        type="password"
                        name="password"
                        autocomplete="current-password"
                        required
                        :disabled="form.processing"
                        class="mt-2 block w-full border border-slate-300 px-3 py-3 text-base outline-none focus:border-slate-950 focus:ring-2 focus:ring-slate-300 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="inline-flex min-h-11 w-full items-center justify-center bg-slate-950 px-4 py-3 text-sm font-semibold text-white focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    {{
                        form.processing
                            ? t('login.signingIn')
                            : t('login.signIn')
                    }}
                </button>
            </form>
        </section>
    </main>
</template>
