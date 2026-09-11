<script setup lang="ts">
defineProps<{
    foundation: 'F0';
}>();
</script>

<template>
    <main class="min-h-screen bg-white px-6 py-16 text-slate-950">
        <section class="mx-auto max-w-3xl">
            <p class="text-sm font-semibold uppercase tracking-wider text-slate-500">
                {{ foundation }} Engineering Foundation
            </p>

            <h1 class="mt-4 text-3xl font-semibold tracking-tight">
                thePBR OS application foundation
            </h1>

            <p class="mt-4 max-w-2xl text-base leading-7 text-slate-600">
                Laravel, Inertia, Vue 3, TypeScript, and Tailwind are wired for the private OS foundation.
                Identity, Business Workspace, Membership, governance, and domain workflows are intentionally
                not implemented in this checkpoint.
            </p>
        </section>
    </main>
</template>
