<script setup lang="ts">
defineProps<{
    label: string;
    forId?: string;
    hint?: string;
    error?: string;
}>();
</script>

<template>
    <div>
        <label
            v-if="forId"
            :for="forId"
            class="block text-sm font-bold text-[var(--pbr-ink-soft)]"
        >
            {{ label }}
        </label>

        <p
            v-else
            class="text-sm font-bold text-[var(--pbr-ink-soft)]"
        >
            {{ label }}
        </p>

        <div class="mt-2">
            <slot />
        </div>

        <p
            v-if="error"
            role="alert"
            class="mt-2 text-sm font-semibold text-[var(--pbr-red)]"
        >
            {{ error }}
        </p>

        <p
            v-else-if="hint"
            class="mt-2 text-sm leading-6 text-[var(--pbr-muted)]"
        >
            {{ hint }}
        </p>
    </div>
</template>