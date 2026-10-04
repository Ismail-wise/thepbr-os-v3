<script setup lang="ts">
defineProps<{
    title: string;
    errors: string[];
    help?: string;
}>();
</script>

<template>
    <section
        v-if="errors.length > 0"
        role="alert"
        aria-live="assertive"
        class="rounded-2xl border border-[#ebc8c2] bg-[var(--pbr-red-soft)] px-4 py-4 text-[var(--pbr-red)]"
    >
        <h2 class="break-words text-sm font-black leading-6">
            {{ title }}
        </h2>
        <p
            v-if="help"
            class="mt-1 break-words text-sm leading-6"
        >
            {{ help }}
        </p>
        <ul class="mt-2 space-y-1.5 pl-5 text-sm leading-6">
            <li
                v-for="(error, index) in errors"
                :key="`${index}-${error}`"
                class="list-disc break-words"
            >
                {{ error }}
            </li>
        </ul>
    </section>
</template>
