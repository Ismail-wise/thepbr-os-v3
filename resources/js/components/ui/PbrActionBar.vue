<script setup lang="ts">
withDefaults(
    defineProps<{
        sticky?: boolean;
        bordered?: boolean;
    }>(),
    {
        sticky: false,
        bordered: true,
    },
);
</script>

<template>
    <div
        class="pbr-action-row min-w-0 rounded-2xl bg-white/96 px-3 py-3 sm:px-4"
        :class="[
            bordered ? 'border border-[var(--pbr-line)] shadow-[var(--pbr-shadow-xs)]' : '',
            sticky ? 'sticky bottom-3 z-30' : '',
        ]"
    >
        <div
            v-if="$slots.status"
            class="min-w-0 flex-1 basis-full sm:basis-auto"
        >
            <slot name="status" />
        </div>

        <div
            v-if="$slots.secondary"
            class="pbr-action-row min-w-0"
        >
            <slot name="secondary" />
        </div>

        <div class="pbr-action-row min-w-0 sm:ml-auto">
            <slot />
        </div>
    </div>
</template>
