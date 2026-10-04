<script setup lang="ts">
withDefaults(
    defineProps<{
        visible: boolean;
        reason?: string;
        ariaLive?: 'off' | 'polite';
    }>(),
    {
        reason: undefined,
        ariaLive: 'polite',
    },
);
</script>

<template>
    <Transition name="pbr-reveal">
        <section
            v-if="visible"
            class="min-w-0"
            :aria-live="ariaLive"
        >
            <p
                v-if="reason"
                class="mb-3 rounded-xl border border-[#d9e7dc] bg-[#f5faf6] px-3.5 py-2.5 text-sm leading-6 text-[var(--pbr-green-dark)]"
            >
                {{ reason }}
            </p>
            <slot />
        </section>
    </Transition>
</template>
