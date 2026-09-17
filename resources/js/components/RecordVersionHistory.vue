<script setup lang="ts">
import RecordStateBadge from './RecordStateBadge.vue';

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

interface RecordVersionHistoryItem {
    id: string;
    versionNumber: number;
    state: RecordState;
    stateLabel: string;
    effectiveFromLabel?: string | null;
    effectiveUntilLabel?: string | null;
    changeSummary: string;
    reviewTimingLabel?: string | null;
}

interface RecordVersionHistoryHeadings {
    version: string;
    state: string;
    effectiveTiming: string;
    changeSummary: string;
    reviewTiming: string;
    empty: string;
}

defineProps<{
    items: RecordVersionHistoryItem[];
    headings: RecordVersionHistoryHeadings;
    caption?: string;
}>();
</script>

<template>
    <div class="overflow-x-auto">
        <table
            class="min-w-full border-separate border-spacing-0 text-sm"
            :aria-label="caption"
        >
            <caption v-if="caption" class="sr-only">
                {{ caption }}
            </caption>

            <thead>
                <tr class="text-left">
                    <th class="border-b px-3 py-2 font-semibold">
                        {{ headings.version }}
                    </th>
                    <th class="border-b px-3 py-2 font-semibold">
                        {{ headings.state }}
                    </th>
                    <th class="border-b px-3 py-2 font-semibold">
                        {{ headings.effectiveTiming }}
                    </th>
                    <th class="border-b px-3 py-2 font-semibold">
                        {{ headings.changeSummary }}
                    </th>
                    <th class="border-b px-3 py-2 font-semibold">
                        {{ headings.reviewTiming }}
                    </th>
                </tr>
            </thead>

            <tbody>
                <tr
                    v-for="item in items"
                    :key="item.id"
                    class="align-top"
                >
                    <td class="border-b px-3 py-3 font-medium">
                        {{ item.versionNumber }}
                    </td>

                    <td class="border-b px-3 py-3">
                        <RecordStateBadge
                            :state="item.state"
                            :label="item.stateLabel"
                        />
                    </td>

                    <td class="border-b px-3 py-3">
                        <div class="flex flex-wrap items-center gap-1">
                            <span>{{ item.effectiveFromLabel ?? '—' }}</span>
                            <span
                                v-if="item.effectiveUntilLabel"
                                aria-hidden="true"
                            >
                                →
                            </span>
                            <span v-if="item.effectiveUntilLabel">
                                {{ item.effectiveUntilLabel }}
                            </span>
                        </div>
                    </td>

                    <td class="border-b px-3 py-3">
                        {{ item.changeSummary }}
                    </td>

                    <td class="border-b px-3 py-3">
                        {{ item.reviewTimingLabel ?? '—' }}
                    </td>
                </tr>

                <tr v-if="items.length === 0">
                    <td
                        colspan="5"
                        class="px-3 py-6 text-center text-sm"
                    >
                        {{ headings.empty }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
