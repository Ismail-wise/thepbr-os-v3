<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';

defineProps<{
    account: {
        email: string;
    };
}>();

const logoutForm = useForm({});

const logout = () => {
    logoutForm.post('/logout');
};
</script>

<template>
    <main class="min-h-screen bg-white px-6 py-12 text-slate-950">
        <section class="mx-auto max-w-5xl">
            <header class="flex flex-wrap items-start justify-between gap-6 border-b border-slate-200 pb-6">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-wider text-slate-500">
                        thePBR OS
                    </p>

                    <h1 class="mt-2 text-2xl font-semibold tracking-tight">
                        Account
                    </h1>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <Link
                        href="/businesses/create"
                        class="inline-flex min-h-11 items-center justify-center bg-slate-950 px-4 py-2 text-sm font-semibold text-white focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2"
                    >
                        Create Business
                    </Link>

                    <Link
                        href="/account/settings"
                        class="inline-flex min-h-11 items-center justify-center border border-slate-300 px-4 py-2 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2"
                    >
                        Profile &amp; Settings
                    </Link>

                    <button
                        type="button"
                        :disabled="logoutForm.processing"
                        class="inline-flex min-h-11 items-center justify-center border border-slate-300 px-4 py-2 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                        @click="logout"
                    >
                        {{ logoutForm.processing ? 'Signing out…' : 'Sign out' }}
                    </button>
                </div>
            </header>

            <section class="py-8">
                <h2 class="text-lg font-semibold">
                    Signed-in identity
                </h2>

                <dl class="mt-4 border-t border-slate-200">
                    <div class="grid gap-1 border-b border-slate-200 py-4 sm:grid-cols-[10rem_1fr]">
                        <dt class="text-sm font-medium text-slate-600">
                            Email
                        </dt>

                        <dd class="break-all text-sm text-slate-950">
                            {{ account.email }}
                        </dd>
                    </div>
                </dl>
            </section>
        </section>
    </main>
</template>
