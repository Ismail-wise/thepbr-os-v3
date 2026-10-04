<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import PbrButton from '../../components/ui/PbrButton.vue';
import PbrErrorSummary from '../../components/ui/PbrErrorSummary.vue';
import PbrFormSection from '../../components/ui/PbrFormSection.vue';
import PbrTextInput from '../../components/ui/PbrTextInput.vue';
import { useI18n } from '../../i18n/useI18n';
import { humanErrorMessages } from '../../support/humanErrors';

const { t } = useI18n();

const form = useForm({
    email: '',
    password: '',
});

const loginErrors = (): string[] =>
    humanErrorMessages(
        form.errors as Record<string, string | undefined>,
    );

const submit = () => {
    form.post('/login', {
        preserveScroll: true,
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head :title="t('login.title')" />

    <main
        class="pbr-app-canvas min-h-screen px-4 py-8 text-[var(--pbr-ink)] sm:px-6 sm:py-12"
    >
        <section class="pbr-reading-width max-w-xl">
            <header class="mb-6 min-w-0">
                <p
                    class="pbr-safe-copy text-xs font-black uppercase tracking-[0.16em] text-[var(--pbr-green)]"
                >
                    {{ t('common.brand') }}
                </p>

                <h1
                    class="pbr-safe-copy mt-3 text-3xl font-black tracking-[-0.03em] sm:text-4xl"
                >
                    {{ t('login.title') }}
                </h1>

                <p
                    class="pbr-safe-copy mt-3 max-w-lg text-sm leading-7 text-[var(--pbr-muted)]"
                >
                    {{ t('login.description') }}
                </p>
            </header>

            <form class="space-y-5" @submit.prevent="submit">
                <PbrErrorSummary
                    :title="t('login.title')"
                    :errors="loginErrors()"
                />

                <PbrFormSection
                    :title="t('login.title')"
                    :instruction="t('login.description')"
                >
                    <PbrTextInput
                        v-model="form.email"
                        :label="t('common.email')"
                        :instruction="t('login.description')"
                        example="name@example.com"
                        name="email"
                        type="email"
                        autocomplete="username"
                        required
                        :disabled="form.processing"
                        :error="form.errors.email"
                    />

                    <PbrTextInput
                        v-model="form.password"
                        :label="t('login.password')"
                        :instruction="t('login.description')"
                        example="Your password"
                        name="password"
                        type="password"
                        autocomplete="current-password"
                        required
                        :disabled="form.processing"
                        :error="form.errors.password"
                    />

                    <template #actions>
                        <PbrButton
                            type="submit"
                            variant="primary"
                            class="w-full"
                            :busy="form.processing"
                            :busy-label="t('login.signingIn')"
                        >
                            {{ t('login.signIn') }}
                        </PbrButton>
                    </template>
                </PbrFormSection>
            </form>

            <p
                class="pbr-safe-copy mt-6 text-center text-sm leading-6 text-[var(--pbr-muted)]"
            >
                <Link
                    href="/access/code"
                    class="pbr-touch inline-flex items-center rounded-xl px-3 font-bold text-[var(--pbr-green-dark)] underline decoration-[#b8cfbf] underline-offset-4"
                >
                    {{ t('accessCode.haveCode') }}
                </Link>
            </p>
        </section>
    </main>
</template>
