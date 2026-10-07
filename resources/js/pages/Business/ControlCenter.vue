<script setup lang="ts">
import { computed } from 'vue';
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import AttentionQueue from '../../components/control-center/AttentionQueue.vue';
import BusinessHero from '../../components/control-center/BusinessHero.vue';
import BusinessSnapshotGrid from '../../components/control-center/BusinessSnapshotGrid.vue';
import HealthReadinessStrip from '../../components/control-center/HealthReadinessStrip.vue';
import MasterBusinessJourney from '../../components/journey/MasterBusinessJourney.vue';
import Grade6MvpGuide from '../../components/journey/Grade6MvpGuide.vue';
import NextBestActionCard from '../../components/control-center/NextBestActionCard.vue';
import OperatingAreaCard from '../../components/control-center/OperatingAreaCard.vue';
import RecentActivityPanel from '../../components/control-center/RecentActivityPanel.vue';
import UpcomingPanel from '../../components/control-center/UpcomingPanel.vue';
import { useI18n } from '../../i18n/useI18n';
import type {
    ActivityItem,
    AttentionItem,
    BusinessSummary,
    GovernanceSummary,
    HealthRequirement,
    HealthSummary,
    MasterJourneyPayload,
    NextActionItem,
    UpcomingItem,
} from '../../components/control-center/types';

type ControlCenterPayload = {
    business: BusinessSummary;
    journey: MasterJourneyPayload;
    attention: AttentionItem[];
    health: {
        summary: HealthSummary;
        requirements: HealthRequirement[];
        currentEffectiveCount: number;
    } | null;
    governance: {
        summary: GovernanceSummary;
    } | null;
    nextActions: NextActionItem[];
    upcoming: UpcomingItem[];
    recentActivity: {
        items: ActivityItem[];
    } | null;
};

const props = defineProps<{
    controlCenter: ControlCenterPayload;
}>();

const { t } = useI18n();

const operatingAreas = computed(() =>
    (props.controlCenter.health?.requirements ?? []).filter(
        (row) => row.area !== 'workspace',
    ),
);
</script>

<template>
    <AuthenticatedLayout>
        <main
            class="min-h-screen bg-[radial-gradient(circle_at_92%_0%,rgb(210_167_67_/_8%),transparent_23rem),linear-gradient(180deg,#f7f9f6_0%,#f2f5f1_100%)] px-4 py-5 text-[var(--pbr-ink)] sm:px-6 sm:py-6 lg:px-7 lg:py-7"
        >
            <div class="mx-auto max-w-[1500px] space-y-5 sm:space-y-6">
                <BusinessHero
                    :business="controlCenter.business"
                    :health-available="controlCenter.health !== null"
                    :governance-available="controlCenter.governance !== null"
                />

                <Grade6MvpGuide step="implementation_review" />

                <MasterBusinessJourney
                    :variant="controlCenter.journey.variant"
                    :steps="controlCenter.journey.steps"
                />

                <AttentionQueue :items="controlCenter.attention" />

                <HealthReadinessStrip
                    :summary="controlCenter.health?.summary ?? null"
                    :current-effective-count="
                        controlCenter.health?.currentEffectiveCount ?? 0
                    "
                />

                <BusinessSnapshotGrid
                    :business="controlCenter.business"
                    :health="controlCenter.health?.summary ?? null"
                    :governance="controlCenter.governance?.summary ?? null"
                    :current-effective-count="
                        controlCenter.health?.currentEffectiveCount ?? 0
                    "
                />

                <section>
                    <div class="mb-4">
                        <p
                            class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]"
                        >
                            {{ t('controlCenter.nextActions.eyebrow') }}
                        </p>
                        <h2
                            class="mt-1 text-xl font-black tracking-[-0.02em] sm:text-2xl"
                        >
                            {{ t('controlCenter.nextActions.title') }}
                        </h2>
                        <p
                            class="mt-1 max-w-3xl text-sm leading-6 text-[var(--pbr-muted)]"
                        >
                            {{ t('controlCenter.nextActions.subtitle') }}
                        </p>
                    </div>

                    <div
                        v-if="controlCenter.nextActions.length > 0"
                        class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                    >
                        <NextBestActionCard
                            v-for="(item, index) in controlCenter.nextActions"
                            :key="`${item.key}|${item.route}`"
                            :item="item"
                            :sequence="index + 1"
                        />
                    </div>

                    <div
                        v-else
                        class="rounded-[20px] border border-[#cfe2d5] bg-[linear-gradient(145deg,#fff,#f5faf6)] p-5 shadow-[0_10px_26px_rgb(16_35_26_/_4%)]"
                    >
                        <p class="font-black text-[var(--pbr-green-dark)]">
                            {{ t('controlCenter.nextActions.emptyTitle') }}
                        </p>
                        <p
                            class="mt-1 text-sm leading-6 text-[var(--pbr-muted)]"
                        >
                            {{ t('controlCenter.nextActions.emptyBody') }}
                        </p>
                    </div>
                </section>

                <section>
                    <div class="mb-4">
                        <p
                            class="text-[10px] font-black uppercase tracking-[0.18em] text-[#7b887f]"
                        >
                            {{ t('controlCenter.areas.eyebrow') }}
                        </p>
                        <h2
                            class="mt-1 text-xl font-black tracking-[-0.02em] sm:text-2xl"
                        >
                            {{ t('controlCenter.areas.title') }}
                        </h2>
                        <p
                            class="mt-1 max-w-3xl text-sm leading-6 text-[var(--pbr-muted)]"
                        >
                            {{ t('controlCenter.areas.subtitle') }}
                        </p>
                    </div>

                    <div
                        v-if="operatingAreas.length > 0"
                        class="grid gap-3 md:grid-cols-2 xl:grid-cols-3"
                    >
                        <OperatingAreaCard
                            v-for="requirement in operatingAreas"
                            :key="requirement.area"
                            :requirement="requirement"
                        />
                    </div>

                    <div
                        v-else
                        class="rounded-[20px] border border-[#d9e5dc] bg-white p-5 shadow-[0_10px_26px_rgb(16_35_26_/_4%)]"
                    >
                        <p class="font-black text-[var(--pbr-ink-soft)]">
                            {{ t('controlCenter.areas.emptyTitle') }}
                        </p>
                        <p
                            class="mt-1 text-sm leading-6 text-[var(--pbr-muted)]"
                        >
                            {{ t('controlCenter.areas.emptyBody') }}
                        </p>
                    </div>
                </section>

                <div class="grid gap-5 xl:grid-cols-2">
                    <UpcomingPanel :items="controlCenter.upcoming" />
                    <RecentActivityPanel
                        :items="controlCenter.recentActivity?.items ?? null"
                    />
                </div>
            </div>
        </main>
    </AuthenticatedLayout>
</template>
