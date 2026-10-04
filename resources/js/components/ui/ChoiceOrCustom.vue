<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import PbrField from './PbrField.vue';

type Choice = {
    value: string;
    label: string;
    helper?: string;
};

const props = withDefaults(
    defineProps<{
        modelValue: string;
        label: string;
        instruction: string;
        choices: Choice[];
        customLabel?: string;
        customPlaceholder?: string;
        error?: string;
        required?: boolean;
    }>(),
    {
        customLabel: '+ Add your own',
        customPlaceholder: 'Type your own option',
        error: undefined,
        required: false,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const customOpen = ref(
    props.modelValue !== ''
    && !props.choices.some((choice) => choice.value === props.modelValue),
);

const customDraft = ref(customOpen.value ? props.modelValue : '');

const isSelected = (value: string): boolean =>
    !customOpen.value && props.modelValue === value;

const selectChoice = (value: string): void => {
    customOpen.value = false;
    emit('update:modelValue', value);
};

const openCustom = (): void => {
    customOpen.value = true;
    emit('update:modelValue', customDraft.value);
};

const updateCustom = (event: Event): void => {
    const value = (event.target as HTMLInputElement).value;
    customDraft.value = value;
    emit('update:modelValue', value);
};

watch(
    () => props.modelValue,
    (value) => {
        const known = props.choices.some(
            (choice) => choice.value === value,
        );

        if (value !== '' && !known) {
            customDraft.value = value;
            customOpen.value = true;
        }
    },
);

const describedSelection = computed(() =>
    props.choices.find((choice) => choice.value === props.modelValue),
);
</script>

<template>
    <PbrField
        :label="label"
        :instruction="instruction"
        :error="error"
        :required="required"
    >
        <div
            class="grid min-w-0 gap-2 sm:grid-cols-2"
            role="group"
            :aria-label="label"
        >
            <button
                v-for="choice in choices"
                :key="choice.value"
                type="button"
                class="pbr-touch min-w-0 rounded-xl border px-3.5 py-3 text-left transition"
                :class="
                    isSelected(choice.value)
                        ? 'border-[var(--pbr-green)] bg-[var(--pbr-green-soft)] text-[var(--pbr-green-dark)]'
                        : 'border-[var(--pbr-line-strong)] bg-white text-[var(--pbr-ink-soft)] hover:border-[#b7cabd]'
                "
                :aria-pressed="isSelected(choice.value)"
                @click="selectChoice(choice.value)"
            >
                <span class="block break-words text-sm font-bold leading-5">
                    {{ choice.label }}
                </span>
                <span
                    v-if="choice.helper"
                    class="mt-1 block break-words text-xs leading-5 text-[var(--pbr-muted)]"
                >
                    {{ choice.helper }}
                </span>
            </button>

            <button
                type="button"
                class="pbr-touch min-w-0 rounded-xl border border-dashed px-3.5 py-3 text-left text-sm font-bold transition"
                :class="
                    customOpen
                        ? 'border-[var(--pbr-green)] bg-[var(--pbr-green-soft)] text-[var(--pbr-green-dark)]'
                        : 'border-[var(--pbr-line-strong)] bg-white text-[var(--pbr-ink-soft)] hover:border-[#b7cabd]'
                "
                :aria-pressed="customOpen"
                @click="openCustom"
            >
                {{ customLabel }}
            </button>
        </div>

        <div
            v-if="customOpen"
            class="mt-3"
        >
            <input
                :value="customDraft"
                type="text"
                :placeholder="customPlaceholder"
                :aria-label="customLabel"
                class="pbr-input-control block px-3.5 py-3 text-base leading-6 placeholder:text-[#98a39d]"
                @input="updateCustom"
            >
        </div>

        <p
            v-else-if="describedSelection?.helper"
            class="sr-only"
        >
            {{ describedSelection.helper }}
        </p>
    </PbrField>
</template>
