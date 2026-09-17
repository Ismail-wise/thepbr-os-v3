<script setup lang="ts">
import { computed } from 'vue';

type RecordState =
    | 'draft'
    | 'ready_for_review'
    | 'under_review'
    | 'changes_requested'
    | 'rejected'
    | 'approved'
    | 'ready_for_effect'
    | 'effective'
    | 'superseded'
    | 'archived';

const props = defineProps<{
    state: RecordState;
    label: string;
}>();

const presentation = computed(() => {
    const variants: Record<
        RecordState,
        { symbol: string; classes: string }
    > = {
        draft: {
            symbol: '○',
            classes: 'border-slate-300 bg-slate-50 text-slate-700',
        },
        ready_for_review: {
            symbol: '◇',
            classes: 'border-blue-300 bg-blue-50 text-blue-800',
        },
        under_review: {
            symbol: '◐',
            classes: 'border-amber-300 bg-amber-50 text-amber-900',
        },
        changes_requested: {
            symbol: '↺',
            classes: 'border-orange-300 bg-orange-50 text-orange-900',
        },
        rejected: {
            symbol: '×',
            classes: 'border-red-300 bg-red-50 text-red-800',
        },
        approved: {
            symbol: '✓',
            classes: 'border-emerald-300 bg-emerald-50 text-emerald-800',
        },
        ready_for_effect: {
            symbol: '◆',
            classes: 'border-cyan-300 bg-cyan-50 text-cyan-900',
        },
        effective: {
            symbol: '●',
            classes: 'border-green-400 bg-green-50 text-green-900',
        },
        superseded: {
            symbol: '↦',
            classes: 'border-violet-300 bg-violet-50 text-violet-800',
        },
        archived: {
            symbol: '□',
            classes: 'border-zinc-300 bg-zinc-100 text-zinc-700',
        },
    };

    return variants[props.state];
});
</script>

<template>
    <span
        :data-state="state"
        :aria-label="label"
        class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold"
        :class="presentation.classes"
    >
        <span aria-hidden="true" class="font-bold">
            {{ presentation.symbol }}
        </span>
        <span>{{ label }}</span>
    </span>
</template>
