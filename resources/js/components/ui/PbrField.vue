<script setup lang="ts">
import { computed, useId } from 'vue';

const props = defineProps<{
    label: string;
    forId?: string;
    instruction?: string;
    example?: string;
    hint?: string;
    error?: string;
    required?: boolean;
}>();

const fallbackId = useId();
const descriptionId = computed(
    () => `${props.forId ?? fallbackId}-description`,
);
</script>

<template>
    <div class="min-w-0">
        <label
            v-if="forId"
            :for="forId"
            class="block break-words text-sm font-bold leading-6 text-[var(--pbr-ink-soft)]"
        >
            {{ label }}
            <span
                v-if="required"
                class="ml-1 text-[var(--pbr-red)]"
                aria-hidden="true"
            >*</span>
        </label>

        <p
            v-else
            class="break-words text-sm font-bold leading-6 text-[var(--pbr-ink-soft)]"
        >
            {{ label }}
            <span
                v-if="required"
                class="ml-1 text-[var(--pbr-red)]"
                aria-hidden="true"
            >*</span>
        </p>

        <p
            v-if="instruction"
            :id="descriptionId"
            class="mt-1 break-words text-sm leading-6 text-[var(--pbr-muted)]"
        >
            {{ instruction }}
        </p>

        <div class="mt-2 min-w-0">
            <slot
                :example-placeholder="example"
                :description-id="instruction ? descriptionId : undefined"
                :invalid="Boolean(error)"
            />
        </div>

        <p
            v-if="error"
            role="alert"
            class="mt-2 break-words text-sm font-semibold leading-6 text-[var(--pbr-red)]"
        >
            {{ error }}
        </p>

        <p
            v-else-if="hint"
            class="mt-2 break-words text-sm leading-6 text-[var(--pbr-muted)]"
        >
            {{ hint }}
        </p>
    </div>
</template>
