import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    catalog,
    normalizeUiLanguageMode,
    terminology,
    type TerminologyKey,
    type TranslationKey,
} from './catalog';

export const useI18n = () => {
    const page = usePage();

    const uiLanguageMode = computed(() => {
        const sharedProps = page.props as unknown as {
            uiLanguageMode?: unknown;
        };

        return normalizeUiLanguageMode(sharedProps.uiLanguageMode);
    });

    const t = (key: TranslationKey): string =>
        catalog[uiLanguageMode.value][key];

    const term = (key: TerminologyKey): string =>
        terminology[uiLanguageMode.value][key];

    return {
        uiLanguageMode,
        t,
        term,
    };
};
