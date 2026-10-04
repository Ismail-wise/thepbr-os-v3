import { onBeforeUnmount, ref, watch, type Ref } from 'vue';

export type DraftSaveState =
    | 'idle'
    | 'dirty'
    | 'saving'
    | 'saved'
    | 'error';

type AutosaveOptions<T> = {
    source: () => T;
    save: (snapshot: T) => Promise<void> | void;
    enabled?: () => boolean;
    delay?: number;
};

type AutosaveResult = {
    state: Ref<DraftSaveState>;
    lastSavedAt: Ref<Date | null>;
    errorMessage: Ref<string | null>;
    flush: () => Promise<void>;
    markDirty: () => void;
};

export const useAutosaveDraft = <T>(
    options: AutosaveOptions<T>,
): AutosaveResult => {
    const state = ref<DraftSaveState>('idle');
    const lastSavedAt = ref<Date | null>(null);
    const errorMessage = ref<string | null>(null);

    const delay = Math.max(250, options.delay ?? 900);
    let timer: ReturnType<typeof setTimeout> | null = null;
    let revision = 0;
    let stopped = false;

    const clearTimer = (): void => {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
    };

    const enabled = (): boolean =>
        options.enabled ? options.enabled() : true;

    const markDirty = (): void => {
        if (stopped || !enabled()) {
            return;
        }

        revision += 1;
        state.value = 'dirty';
        errorMessage.value = null;
        clearTimer();

        timer = setTimeout(() => {
            void flush();
        }, delay);
    };

    const flush = async (): Promise<void> => {
        if (stopped || !enabled()) {
            return;
        }

        clearTimer();

        const savingRevision = revision;
        const snapshot = structuredClone(options.source());

        state.value = 'saving';
        errorMessage.value = null;

        try {
            await options.save(snapshot);

            if (stopped) {
                return;
            }

            if (savingRevision === revision) {
                state.value = 'saved';
                lastSavedAt.value = new Date();
            } else {
                state.value = 'dirty';
            }
        } catch (error) {
            if (stopped) {
                return;
            }

            state.value = 'error';
            errorMessage.value =
                error instanceof Error && error.message.trim() !== ''
                    ? error.message
                    : 'Unable to save this draft right now.';
        }
    };

    const stop = watch(
        options.source,
        markDirty,
        {
            deep: true,
            flush: 'post',
        },
    );

    onBeforeUnmount(() => {
        stopped = true;
        clearTimer();
        stop();
    });

    return {
        state,
        lastSavedAt,
        errorMessage,
        flush,
        markDirty,
    };
};
