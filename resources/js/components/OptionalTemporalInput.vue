<script setup lang="ts">
import {
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue?: string | null;
        type: 'date' | 'datetime-local';
    }>(),
    {
        modelValue: '',
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const input = ref<HTMLInputElement | null>(null);
const timers: number[] = [];

const expectedValue = (): string => props.modelValue ?? '';

const syncDom = (): void => {
    const element = input.value;

    if (element === null) {
        return;
    }

    const expected = expectedValue();

    if (element.value !== expected) {
        element.value = expected;
    }
};

const scheduleSync = (): void => {
    void nextTick(syncDom);

    timers.push(
        window.setTimeout(syncDom, 0),
        window.setTimeout(syncDom, 200),
        window.setTimeout(syncDom, 1000),
    );
};

const updateModel = (event: Event): void => {
    const element = event.currentTarget as HTMLInputElement;

    /*
     * Browser restore/autofill can mutate a native temporal control
     * without changing the Inertia form model.
     *
     * If the field is not actively focused, preserve canonical form
     * state instead of silently accepting a browser-restored value.
     */
    if (
        document.activeElement !== element
        && element.value !== expectedValue()
    ) {
        syncDom();

        return;
    }

    emit('update:modelValue', element.value);
};

const clearWithKeyboard = (event: KeyboardEvent): void => {
    if (
        event.key !== 'Backspace'
        && event.key !== 'Delete'
    ) {
        return;
    }

    event.preventDefault();

    emit('update:modelValue', '');
    scheduleSync();
};

const onPageShow = (): void => {
    scheduleSync();
};

onMounted(() => {
    window.addEventListener('pageshow', onPageShow);

    scheduleSync();
});

onBeforeUnmount(() => {
    window.removeEventListener('pageshow', onPageShow);

    for (const timer of timers) {
        window.clearTimeout(timer);
    }
});

watch(
    () => props.modelValue,
    () => {
        void nextTick(syncDom);
    },
);
</script>

<template>
    <input
        ref="input"
        :type="type"
        :value="modelValue ?? ''"
        autocomplete="off"
        @focus="scheduleSync"
        @pointerdown="scheduleSync"
        @click="scheduleSync"
        @input="updateModel"
        @change="updateModel"
        @keydown="clearWithKeyboard"
    />
</template>
