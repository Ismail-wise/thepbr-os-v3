<script setup lang="ts">
import { useId } from 'vue';
import PbrField from './PbrField.vue';

const props = withDefaults(
    defineProps<{
        modelValue: string;
        label: string;
        instruction: string;
        example: string;
        id?: string;
        name?: string;
        type?: 'text' | 'email' | 'password' | 'search' | 'tel' | 'url';
        autocomplete?: string;
        inputmode?: 'none' | 'text' | 'decimal' | 'numeric' | 'tel' | 'search' | 'email' | 'url';
        required?: boolean;
        disabled?: boolean;
        error?: string;
        hint?: string;
    }>(),
    {
        id: undefined,
        name: undefined,
        type: 'text',
        autocomplete: undefined,
        inputmode: undefined,
        required: false,
        disabled: false,
        error: undefined,
        hint: undefined,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const generatedId = useId();
const inputId = props.id ?? `pbr-input-${generatedId}`;

const update = (event: Event): void => {
    emit(
        'update:modelValue',
        (event.target as HTMLInputElement).value,
    );
};
</script>

<template>
    <PbrField
        :label="label"
        :for-id="inputId"
        :instruction="instruction"
        :example="example"
        :error="error"
        :hint="hint"
        :required="required"
    >
        <template #default="{ examplePlaceholder, descriptionId, invalid }">
            <input
                :id="inputId"
                :name="name"
                :type="type"
                :value="modelValue"
                :placeholder="examplePlaceholder"
                :autocomplete="autocomplete"
                :inputmode="inputmode"
                :required="required"
                :disabled="disabled"
                :aria-describedby="descriptionId"
                :aria-invalid="invalid ? 'true' : undefined"
                class="pbr-input-control block px-3.5 py-3 text-base leading-6 placeholder:text-[#98a39d] disabled:cursor-not-allowed disabled:bg-[#f2f5f2] disabled:opacity-70"
                @input="update"
            >
        </template>
    </PbrField>
</template>
