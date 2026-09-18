<script setup lang="ts">
import { useI18n } from '../i18n/useI18n';

type ActivityItem = {
    eventType: string;
    actorType: string;
    isCurrentUser: boolean;
    occurredAt: string;
    versionNumber?: number;
    fromState?: string;
    toState?: string;
};

defineProps<{
    items: ActivityItem[];
}>();

const { t } = useI18n();

const actorLabel = (item: ActivityItem): string => {
    if (item.isCurrentUser) {
        return t('activity.actor.you');
    }

    if (item.actorType === 'user') {
        return t('activity.actor.member');
    }

    if (item.actorType === 'service_integration') {
        return t('activity.actor.integration');
    }

    return t('activity.actor.system');
};

const eventLabel = (eventType: string): string => {
    switch (eventType) {
        case 'records.formal_record_version.review_frozen':
            return t('activity.event.reviewFrozen');
        case 'records.formal_record_version.state_changed':
            return t('activity.event.stateChanged');
        case 'records.formal_record_effective_head.changed':
            return t('activity.event.effectiveHeadChanged');
        case 'records.formal_record_version.superseded':
            return t('activity.event.superseded');
        case 'records.proposal_version.frozen':
            return t('activity.event.proposalFrozen');
        default:
            return t('activity.event.stateChanged');
    }
};

const formatTime = (value: string): string =>
    new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
</script>

<template>
    <ol
        v-if="items.length > 0"
        class="divide-y divide-slate-200 border-y border-slate-200"
    >
        <li
            v-for="item in items"
            :key="`${item.occurredAt}:${item.eventType}:${item.versionNumber ?? ''}`"
            class="grid gap-2 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start"
        >
            <div class="min-w-0">
                <p class="font-medium text-slate-950">
                    {{ eventLabel(item.eventType) }}
                </p>

                <p class="mt-1 text-sm text-slate-600">
                    {{ actorLabel(item) }}
                    <template v-if="item.versionNumber !== undefined">
                        · {{ t('activity.version') }} {{ item.versionNumber }}
                    </template>
                </p>
            </div>

            <time
                :datetime="item.occurredAt"
                class="text-sm text-slate-500"
            >
                {{ formatTime(item.occurredAt) }}
            </time>
        </li>
    </ol>

    <p
        v-else
        class="border-y border-slate-200 py-8 text-sm text-slate-600"
    >
        {{ t('activity.empty') }}
    </p>
</template>
