<script setup lang="ts">
withDefaults(
    defineProps<{
        padded?: boolean;
    }>(),
    { padded: true },
);
</script>

<template>
    <div
        class="pbr-surface"
        :class="padded ? 'p-5 sm:p-6' : ''"
    >
        <slot />
    </div>
</template>
