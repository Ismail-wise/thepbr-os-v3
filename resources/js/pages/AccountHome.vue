<script setup lang="ts">
import AuthenticatedLayout from '../layouts/AuthenticatedLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { useI18n } from '../i18n/useI18n';

defineProps<{
    account: {
        email: string;
    };
}>();

const { t } = useI18n();

const logoutForm = useForm({});

const logout = () => {
    logoutForm.post('/logout');
};
</script>

<template>
    <AuthenticatedLayout>
        <main class="min-h-screen bg-white px-6 py-12 text-slate-950">
            <section class="mx-auto max-w-5xl">
                <header class="flex flex-wrap items-start justify-between gap-6 border-b border-slate-200 pb-6">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-wider text-slate-500">
                            {{ t('common.brand') }}
                        </p>

                        <h1 class="mt-2 text-2xl font-semibold tracking-tight">
                            {{ t('account.title') }}
                        </h1>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <Link
                            href="/businesses/create"
                            class="inline-flex min-h-11 items-center justify-center bg-slate-950 px-4 py-2 text-sm font-semibold text-white focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2"
                        >
                            {{ t('nav.createBusiness') }}
                        </Link>

                        <Link
                            href="/account/settings"
                            class="inline-flex min-h-11 items-center justify-center border border-slate-300 px-4 py-2 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2"
                        >
                            {{ t('nav.profileSettings') }}
                        </Link>

                        <button
                            type="button"
                            :disabled="logoutForm.processing"
                            class="inline-flex min-h-11 items-center justify-center border border-slate-300 px-4 py-2 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                            @click="logout"
                        >
                            {{
                                logoutForm.processing
                                    ? t('account.signingOut')
                                    : t('account.signOut')
                            }}
                        </button>
                    </div>
                </header>

                <section class="py-8">
                    <h2 class="text-lg font-semibold">
                        {{ t('account.signedInIdentity') }}
                    </h2>

                    <dl class="mt-4 border-t border-slate-200">
                        <div class="grid gap-1 border-b border-slate-200 py-4 sm:grid-cols-[10rem_1fr]">
                            <dt class="text-sm font-medium text-slate-600">
                                {{ t('common.email') }}
                            </dt>

                            <dd class="break-all text-sm text-slate-950">
                                {{ account.email }}
                            </dd>
                        </div>
                    </dl>
                </section>
            </section>
        </main>
    </AuthenticatedLayout>
</template>
