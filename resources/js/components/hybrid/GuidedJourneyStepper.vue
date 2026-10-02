<script setup lang="ts">
type StepState = 'recorded' | 'current' | 'next' | 'available';

type JourneyStep = {
    key: string;
    label: string;
    helper?: string;
    state: StepState;
};

defineProps<{
    steps: JourneyStep[];
    label: string;
}>();

const emit = defineEmits<{
    select: [key: string];
}>();
</script>

<template>
    <nav
        :aria-label="label"
        class="overflow-hidden rounded-[22px] border border-[#d4e2d7] bg-white/90 shadow-[0_12px_30px_rgb(16_35_26_/_5%)]"
    >
        <ol
            class="grid gap-px bg-[#e3e9e4] sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
        >
            <li
                v-for="(step, index) in steps"
                :key="step.key"
                class="bg-white"
            >
                <button
                    type="button"
                    class="group flex h-full min-h-[5.25rem] w-full items-start gap-3 px-4 py-3.5 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[var(--pbr-green)]"
                    :aria-current="step.state === 'current' ? 'step' : undefined"
                    :data-state="step.state"
                    :class="
                        step.state === 'current'
                            ? 'bg-[linear-gradient(135deg,#e8f5ec,#faf8ef)]'
                            : 'hover:bg-[#f8faf8]'
                    "
                    @click="emit('select', step.key)"
                >
                    <span
                        class="mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-full border text-[11px] font-black"
                        :class="
                            step.state === 'recorded'
                                ? 'border-[#16824b] bg-[#16824b] text-white'
                                : step.state === 'current'
                                  ? 'border-[var(--pbr-green)] bg-white text-[var(--pbr-green-dark)] shadow-[0_0_0_4px_rgb(22_130_75_/_10%)]'
                                  : step.state === 'next'
                                    ? 'border-[#d2a743] bg-[#fbf6e8] text-[#7b6228]'
                                    : 'border-[#d8e2da] bg-[#f6f8f6] text-[#7d8a81]'
                        "
                    >
                        <span v-if="step.state === 'recorded'" aria-hidden="true">✓</span>
                        <span v-else aria-hidden="true">{{ index + 1 }}</span>
                    </span>

                    <span class="min-w-0">
                        <span
                            class="block text-sm font-black tracking-[-0.01em]"
                            :class="
                                step.state === 'current'
                                    ? 'text-[var(--pbr-green-dark)]'
                                    : 'text-[var(--pbr-ink-soft)]'
                            "
                        >
                            {{ step.label }}
                        </span>
                        <span
                            v-if="step.helper"
                            class="mt-1 block text-[11px] leading-4 text-[var(--pbr-muted)]"
                        >
                            {{ step.helper }}
                        </span>
                    </span>
                </button>
            </li>
        </ol>
    </nav>
</template>
