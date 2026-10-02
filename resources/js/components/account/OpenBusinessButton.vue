<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
    businessId: string;
    href: string;
    label: string;
}>();

const processing = ref(false);

const open = () => {
    if (processing.value) {
        return;
    }

    router.post(
        '/current-business',
        { business_id: props.businessId },
        {
            preserveScroll: true,
            preserveState: false,
            onStart: () => {
                processing.value = true;
            },
            onFinish: () => {
                processing.value = false;
            },
            onSuccess: () => {
                router.visit(props.href);
            },
        },
    );
};
</script>

<template>
    <button
        type="button"
        :disabled="processing"
        class="pbr-touch inline-flex min-h-10 items-center justify-center rounded-xl bg-[var(--pbr-green-dark)] px-3.5 text-xs font-black text-white shadow-[0_7px_16px_rgb(13_106_59_/_15%)] transition hover:bg-[#0a5e34] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--pbr-green)] focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-60"
        @click="open"
    >
        {{ label }}
        <span aria-hidden="true" class="ml-2 text-sm">→</span>
    </button>
</template>
