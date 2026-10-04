<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import PbrButton from '../../components/ui/PbrButton.vue';
import PbrErrorSummary from '../../components/ui/PbrErrorSummary.vue';
import PbrField from '../../components/ui/PbrField.vue';
import PbrFormSection from '../../components/ui/PbrFormSection.vue';
import PbrTextInput from '../../components/ui/PbrTextInput.vue';
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

const accessCodeErrors = (): string[] => {
    const message = form.errors.access_code;

    return message ? [message] : [];
};

const submit = () => {
    form.post('/access/code/redeem', {
        preserveScroll: true,
        onFinish: () => {
            form.reset('password', 'password_confirmation');
        },
    });
};
</script>

<template>
    <Head :title="t('accessCode.title')" />

    <main
        class="pbr-app-canvas min-h-screen px-4 py-8 text-[var(--pbr-ink)] sm:px-6 sm:py-12"
    >
        <section class="pbr-reading-width max-w-2xl">
            <header class="mb-6 min-w-0">
                <p class="pbr-safe-copy text-xs font-black uppercase tracking-[0.16em] text-[var(--pbr-green)]">
                    {{ t('common.brand') }}
                </p>
                <h1 class="pbr-safe-copy mt-3 text-3xl font-black tracking-[-0.03em] sm:text-4xl">
                    {{ t('accessCode.title') }}
                </h1>
                <p class="pbr-safe-copy mt-3 max-w-xl text-sm leading-7 text-[var(--pbr-muted)]">
                    {{ t('accessCode.description') }}
                </p>
                <p
                    role="note"
                    class="pbr-safe-copy mt-4 rounded-2xl border border-[#d4e3d8] bg-[var(--pbr-green-soft)] px-4 py-3 text-sm leading-6 text-[var(--pbr-green-dark)]"
                >
                    {{ t('accessCode.rightsNotice') }}
                </p>
            </header>

            <form class="space-y-5" @submit.prevent="submit">
                <PbrErrorSummary
                    :title="t('accessCode.title')"
                    :errors="accessCodeErrors()"
                />

                <PbrFormSection
                    :title="t('accessCode.title')"
                    :instruction="t('accessCode.description')"
                >
                    <PbrTextInput
                        v-model="form.token"
                        :label="t('accessCode.code')"
                        :instruction="t('accessCode.codeInstruction')"
                        :example="t('accessCode.codeExample')"
                        name="token"
                        autocomplete="one-time-code"
                        required
                        :disabled="form.processing"
                        :error="form.errors.token"
                    />

                    <PbrTextInput
                        v-model="form.email"
                        :label="t('common.email')"
                        :instruction="authenticated ? t('accessCode.passwordHelp') : t('accessCode.description')"
                        example="name@example.com"
                        name="email"
                        type="email"
                        autocomplete="email"
                        required
                        :disabled="form.processing || authenticated"
                        :error="form.errors.email"
                    />

                    <PbrTextInput
                        v-model="form.display_name"
                        :label="t('accessCode.displayName')"
                        :instruction="t('accessCode.displayNameInstruction')"
                        :example="t('accessCode.displayNameExample')"
                        name="display_name"
                        autocomplete="name"
                        :disabled="form.processing || authenticated"
                        :error="form.errors.display_name"
                    />

                    <div class="pbr-form-grid">
                        <PbrTextInput
                            v-model="form.password"
                            :label="t('login.password')"
                            :instruction="t('accessCode.passwordHelp')"
                            example="Use a secure passphrase"
                            name="password"
                            type="password"
                            autocomplete="new-password"
                            :disabled="form.processing || authenticated"
                            :error="form.errors.password"
                        />

                        <PbrTextInput
                            v-model="form.password_confirmation"
                            :label="t('accessCode.confirmPassword')"
                            :instruction="t('accessCode.passwordHelp')"
                            example="Repeat your password"
                            name="password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            :disabled="form.processing || authenticated"
                        />
                    </div>

                    <div class="pbr-form-grid">
                        <PbrField
                            :label="t('accessCode.language')"
                            for-id="access-code-language"
                            :instruction="t('accessCode.description')"
                        >
                            <select
                                id="access-code-language"
                                v-model="form.language_mode"
                                name="language_mode"
                                required
                                :disabled="form.processing || authenticated"
                                class="pbr-input-control px-3.5 py-3 text-base"
                            >
                                <option value="en">English</option>
                                <option value="my">မြန်မာ</option>
                                <option value="mixed">မြန်မာ + EN</option>
                            </select>
                        </PbrField>

                        <PbrTextInput
                            v-model="form.timezone"
                            :label="t('accessCode.timezone')"
                            :instruction="t('accessCode.description')"
                            example="Asia/Yangon"
                            name="timezone"
                            required
                            :disabled="form.processing || authenticated"
                            :error="form.errors.timezone"
                        />
                    </div>
                </PbrFormSection>

                <div class="pbr-action-row justify-between">
                    <Link
                        :href="authenticated ? '/' : '/login'"
                        class="pbr-touch inline-flex items-center rounded-xl px-3 text-sm font-bold text-[var(--pbr-ink-soft)] underline underline-offset-4"
                    >
                        {{
                            authenticated
                                ? t('common.backToAccount')
                                : t('accessCode.backToLogin')
                        }}
                    </Link>

                    <PbrButton
                        type="submit"
                        variant="primary"
                        :busy="form.processing"
                        :busy-label="t('accessCode.redeeming')"
                    >
                        {{ t('accessCode.redeem') }}
                    </PbrButton>
                </div>
            </form>
        </section>
    </main>
</template>
