<script setup lang="ts">
import { useId } from 'vue';
import PbrField from '../ui/PbrField.vue';

const props = withDefaults(
    defineProps<{
        modelValue: string;
        label: string;
        instruction: string;
        example: string;
        suggestions?: string[];
        disabled?: boolean;
        rows?: number;
    }>(),
    {
        suggestions: () => [],
        disabled: false,
        rows: 5,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const inputId = `guided-textarea-${useId()}`;

const update = (event: Event): void => {
    emit(
        'update:modelValue',
        (event.target as HTMLTextAreaElement).value,
    );
};

const addSuggestion = (suggestion: string): void => {
    const current = props.modelValue.trim();

    if (current === '') {
        emit('update:modelValue', suggestion);

        return;
    }

    if (current.includes(suggestion)) {
        return;
    }

    emit('update:modelValue', current + '\n' + suggestion);
};
</script>

<template>
    <PbrField
        :label="label"
        :for-id="inputId"
        :instruction="instruction"
        :example="example"
    >
        <template #default="{ examplePlaceholder, descriptionId }">
            <div
                v-if="suggestions.length > 0"
                class="mb-3 flex flex-wrap gap-2"
                :aria-label="label + ' suggestions'"
            >
                <button
                    v-for="suggestion in suggestions"
                    :key="suggestion"
                    type="button"
                    class="pbr-touch rounded-full border border-[#d4e2d7] bg-white px-3 py-1.5 text-left text-xs font-bold leading-5 text-[var(--pbr-ink-soft)] transition hover:border-[var(--pbr-green)] hover:bg-[var(--pbr-green-soft)] hover:text-[var(--pbr-green-dark)] disabled:cursor-not-allowed disabled:opacity-55"
                    :disabled="disabled"
                    @click="addSuggestion(suggestion)"
                >
                    + {{ suggestion }}
                </button>
            </div>

            <textarea
                :id="inputId"
                :value="modelValue"
                :rows="rows"
                :placeholder="examplePlaceholder"
                :disabled="disabled"
                :aria-describedby="descriptionId"
                class="pbr-input-control block min-h-32 resize-y px-3.5 py-3 text-base leading-6 placeholder:text-[#98a39d] disabled:cursor-not-allowed disabled:bg-[#f2f5f2] disabled:opacity-70"
                @input="update"
            />
        </template>
    </PbrField>
</template>
