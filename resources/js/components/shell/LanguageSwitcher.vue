<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import { useI18n } from '../../i18n/useI18n';
import {
    uiLanguageModes,
    type UiLanguageMode,
} from '../../i18n/catalog';

const { t, uiLanguageMode } = useI18n();

const form = useForm<{
    language_mode: UiLanguageMode;
}>({
    language_mode: uiLanguageMode.value,
});

watch(uiLanguageMode, (mode) => {
    form.language_mode = mode;
});

const languageLabel = (mode: UiLanguageMode): string => {
    if (mode === 'my') return t('language.my');
    if (mode === 'mixed') return t('language.mixed');

    return t('language.en');
};

const updateLanguage = () => {
    if (form.processing || form.language_mode === uiLanguageMode.value) {
        return;
    }

    form.patch('/account/language', {
        preserveScroll: true,
        preserveState: false,
    });
};
</script>

<template>
    <div class="relative">
        <label for="shell-language-switcher" class="sr-only">
            {{ t('shell.language') }}
        </label>
        <select
            id="shell-language-switcher"
            v-model="form.language_mode"
            class="pbr-touch h-11 rounded-xl border border-[var(--pbr-line)] bg-white pl-3 pr-8 text-xs font-bold text-[var(--pbr-ink-soft)] shadow-[0_4px_14px_rgb(16_35_26_/_3%)] focus-visible:outline-none"
            :disabled="form.processing"
            :aria-label="t('shell.language')"
            @change="updateLanguage"
        >
            <option
                v-for="mode in uiLanguageModes"
                :key="mode"
                :value="mode"
            >
                {{ languageLabel(mode) }}
            </option>
        </select>
    </div>
</template>
