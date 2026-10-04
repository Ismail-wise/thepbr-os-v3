<script setup lang="ts">
import { computed } from 'vue';
import type { DraftSaveState } from '../../composables/useAutosaveDraft';

const props = withDefaults(
    defineProps<{
        state: DraftSaveState;
        idleLabel?: string;
        dirtyLabel?: string;
        savingLabel?: string;
        savedLabel?: string;
        errorLabel?: string;
        lastSavedAt?: Date | null;
    }>(),
    {
        idleLabel: 'Draft ready',
        dirtyLabel: 'Changes not saved yet',
        savingLabel: 'Saving draft...',
        savedLabel: 'Draft saved',
        errorLabel: 'Draft could not be saved',
        lastSavedAt: null,
    },
);

const label = computed(
    () =>
        ({
            idle: props.idleLabel,
            dirty: props.dirtyLabel,
            saving: props.savingLabel,
            saved: props.savedLabel,
            error: props.errorLabel,
        })[props.state],
);

const tone = computed(
    () =>
        ({
            idle: 'text-[var(--pbr-muted)]',
            dirty: 'text-[#7b6228]',
            saving: 'text-[var(--pbr-blue)]',
            saved: 'text-[var(--pbr-green-dark)]',
            error: 'text-[var(--pbr-red)]',
        })[props.state],
);
</script>

<template>
    <div
        class="inline-flex min-w-0 items-center gap-2 text-xs font-bold"
        :class="tone"
        role="status"
        aria-live="polite"
        :aria-busy="state === 'saving' ? 'true' : 'false'"
    >
        <span
            class="h-2 w-2 shrink-0 rounded-full bg-current"
            :class="state === 'saving' ? 'animate-pulse' : ''"
            aria-hidden="true"
        />
        <span class="min-w-0 break-words">{{ label }}</span>
        <time
            v-if="state === 'saved' && lastSavedAt"
            :datetime="lastSavedAt.toISOString()"
            class="hidden font-medium text-[var(--pbr-muted)] sm:inline"
        >
            {{ lastSavedAt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}
        </time>
    </div>
</template>
