<script setup lang="ts">
import PbrButton from '../ui/PbrButton.vue';
import { useI18n } from '../../i18n/useI18n';
import type { TranslationKey } from '../../i18n/catalog';

type PartnerSummary = {
    partnerId: string;
    displayName: string;
    partnerStatus: string;
    completionStatus: 'completed' | 'pending';
    primaryProfile: string | null;
    secondaryProfile: string | null;
    completedAt: string | null;
    isCurrentUser: boolean;
};

type AlignmentItem = {
    dimension: string;
    label: string;
    averageScore?: number;
    gap?: number;
    message: string;
};

type RoleSuggestion = {
    name: string;
    primaryProfile: string;
    secondaryProfile: string | null;
    suggestions: string[];
    note: string;
};

type DecisionRecommendation = {
    title: string;
    message: string;
};

type DiscussionPriority = {
    priority: string;
    topic: string;
    reason: string;
};

type Alignment = {
    summary: {
        participantCount: number;
        sharedStrengthCount: number;
        complementaryAreaCount: number;
        importantDifferenceCount: number;
        sharedBlindSpotCount: number;
        note: string;
    };
    sharedStrengths: AlignmentItem[];
    complementaryAreas: AlignmentItem[];
    importantDifferences: AlignmentItem[];
    sharedBlindSpots: AlignmentItem[];
    roleSuggestions: RoleSuggestion[];
    decisionRecommendations: DecisionRecommendation[];
    discussionPriorities: DiscussionPriority[];
};

defineProps<{
    workspace: {
        progress: {
            completed: number;
            total: number;
            ready: boolean;
        };
        currentUser: {
            linked: boolean;
            completed: boolean;
            assessmentRoute: string;
        };
        participants: PartnerSummary[];
        alignment: Alignment | null;
        advisoryOnly: boolean;
    };
}>();

const { t } = useI18n();

const profileLabel = (profile: string | null): string => {
    if (!profile) {
        return '';
    }

    return t(
        `partnerDynamics.profile.${profile}` as TranslationKey,
    );
};

const completionLabel = (status: PartnerSummary['completionStatus']): string =>
    status === 'completed'
        ? t('partnerDynamics.workspace.completed')
        : t('partnerDynamics.workspace.pending');
</script>

<template>
    <section
        class="mt-5 overflow-hidden rounded-[22px] border border-[#d3e1d6] bg-[linear-gradient(145deg,#ffffff,#f5faf6)] shadow-[0_12px_30px_rgb(16_35_26_/_5%)]"
    >
        <div class="p-5 sm:p-6">
            <div
                class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"
            >
                <div class="min-w-0">
                    <p
                        class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]"
                    >
                        {{ t('partnerDynamics.workspace.eyebrow') }}
                    </p>
                    <h3
                        class="mt-2 text-xl font-black tracking-[-0.02em] text-[var(--pbr-ink)]"
                    >
                        {{ t('partnerDynamics.workspace.title') }}
                    </h3>
                    <p
                        class="mt-2 max-w-3xl text-sm leading-6 text-[var(--pbr-muted)]"
                    >
                        {{ t('partnerDynamics.workspace.subtitle') }}
                    </p>
                </div>

                <div
                    class="shrink-0 rounded-2xl border border-[#cfe0d4] bg-white px-4 py-3 text-right"
                >
                    <p
                        class="text-[10px] font-black uppercase tracking-[0.14em] text-[#7b8980]"
                    >
                        {{ t('partnerDynamics.workspace.progress') }}
                    </p>
                    <p
                        class="mt-1 text-2xl font-black text-[var(--pbr-green-dark)]"
                    >
                        {{ workspace.progress.completed }}
                        <span
                            class="text-sm font-bold text-[var(--pbr-muted)]"
                        >
                            / {{ workspace.progress.total }}
                        </span>
                    </p>
                </div>
            </div>

            <div
                v-if="
                    workspace.currentUser.linked
                    && !workspace.currentUser.completed
                "
                class="mt-5 flex flex-col gap-3 rounded-2xl border border-[#e7d8a6] bg-[#fffaf0] p-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <p class="text-sm font-black text-[#6d5a22]">
                        {{ t('partnerDynamics.workspace.yourAssessmentPending') }}
                    </p>
                    <p class="mt-1 text-xs leading-5 text-[#7d6a32]">
                        {{ t('partnerDynamics.workspace.pendingDoesNotBlock') }}
                    </p>
                </div>
                <PbrButton
                    :href="workspace.currentUser.assessmentRoute"
                    variant="primary"
                >
                    {{ t('partnerDynamics.workspace.completeMine') }}
                </PbrButton>
            </div>

            <div
                v-if="workspace.progress.total === 0"
                class="mt-5 rounded-2xl border border-[#dce6de] bg-white p-4 text-sm leading-6 text-[var(--pbr-muted)]"
            >
                {{ t('partnerDynamics.workspace.noLinkedPartners') }}
            </div>

            <div
                v-else
                class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3"
            >
                <article
                    v-for="participant in workspace.participants"
                    :key="participant.partnerId"
                    class="rounded-2xl border border-[#dde7df] bg-white p-4"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p
                                class="break-words text-sm font-black text-[var(--pbr-ink)]"
                            >
                                {{ participant.displayName }}
                                <span
                                    v-if="participant.isCurrentUser"
                                    class="ml-1 text-xs font-bold text-[var(--pbr-green)]"
                                >
                                    {{ t('partnerDynamics.workspace.you') }}
                                </span>
                            </p>
                            <p
                                class="mt-1 text-xs font-bold uppercase tracking-[0.08em]"
                                :class="
                                    participant.completionStatus === 'completed'
                                        ? 'text-[var(--pbr-green)]'
                                        : 'text-[#9a7c2c]'
                                "
                            >
                                {{
                                    completionLabel(
                                        participant.completionStatus,
                                    )
                                }}
                            </p>
                        </div>
                    </div>

                    <div
                        v-if="participant.completionStatus === 'completed'"
                        class="mt-3 rounded-xl bg-[var(--pbr-green-soft)] px-3 py-3"
                    >
                        <p
                            class="text-sm font-black text-[var(--pbr-green-dark)]"
                        >
                            {{
                                profileLabel(
                                    participant.primaryProfile,
                                )
                            }}
                        </p>
                        <p
                            v-if="participant.secondaryProfile"
                            class="mt-1 text-xs leading-5 text-[var(--pbr-muted)]"
                        >
                            {{ t('partnerDynamics.secondaryProfile') }}:
                            {{
                                profileLabel(
                                    participant.secondaryProfile,
                                )
                            }}
                        </p>
                    </div>

                    <p
                        v-else
                        class="mt-3 text-xs leading-5 text-[var(--pbr-muted)]"
                    >
                        {{ t('partnerDynamics.workspace.reminderHint') }}
                    </p>
                </article>
            </div>
        </div>

        <div
            v-if="workspace.alignment"
            class="border-t border-[#dce6de] bg-white/85 p-5 sm:p-6"
        >
            <div
                class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between"
            >
                <div>
                    <p
                        class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]"
                    >
                        {{ t('partnerDynamics.workspace.alignmentEyebrow') }}
                    </p>
                    <h4
                        class="mt-2 text-lg font-black text-[var(--pbr-ink)]"
                    >
                        {{ t('partnerDynamics.workspace.alignmentTitle') }}
                    </h4>
                    <p
                        class="mt-2 max-w-3xl text-sm leading-6 text-[var(--pbr-muted)]"
                    >
                        {{ t('partnerDynamics.workspace.alignmentHelp') }}
                    </p>
                </div>

                <span
                    class="inline-flex w-fit rounded-full border border-[#cfe0d4] bg-[var(--pbr-green-soft)] px-3 py-1.5 text-xs font-black text-[var(--pbr-green-dark)]"
                >
                    {{ t('partnerDynamics.workspace.advisoryOnly') }}
                </span>
            </div>

            <div class="mt-5 grid gap-4 xl:grid-cols-2">
                <section
                    v-if="workspace.alignment.sharedStrengths.length"
                    class="rounded-2xl border border-[#d8e6dc] bg-[#f7fbf8] p-4"
                >
                    <h5 class="text-sm font-black text-[var(--pbr-green-dark)]">
                        {{ t('partnerDynamics.workspace.sharedStrengths') }}
                    </h5>
                    <div class="mt-3 space-y-3">
                        <div
                            v-for="item in workspace.alignment.sharedStrengths"
                            :key="`strength-${item.dimension}`"
                        >
                            <p class="text-sm font-bold text-[var(--pbr-ink-soft)]">
                                {{ item.label }}
                            </p>
                            <p class="mt-1 text-xs leading-5 text-[var(--pbr-muted)]">
                                {{ item.message }}
                            </p>
                        </div>
                    </div>
                </section>

                <section
                    v-if="workspace.alignment.complementaryAreas.length"
                    class="rounded-2xl border border-[#dce4ed] bg-[#f8fafc] p-4"
                >
                    <h5 class="text-sm font-black text-[#39516a]">
                        {{ t('partnerDynamics.workspace.complementaryAreas') }}
                    </h5>
                    <div class="mt-3 space-y-3">
                        <div
                            v-for="item in workspace.alignment.complementaryAreas"
                            :key="`complement-${item.dimension}`"
                        >
                            <p class="text-sm font-bold text-[var(--pbr-ink-soft)]">
                                {{ item.label }}
                            </p>
                            <p class="mt-1 text-xs leading-5 text-[var(--pbr-muted)]">
                                {{ item.message }}
                            </p>
                        </div>
                    </div>
                </section>

                <section
                    v-if="workspace.alignment.importantDifferences.length"
                    class="rounded-2xl border border-[#ead9a5] bg-[#fffaf0] p-4"
                >
                    <h5 class="text-sm font-black text-[#6d5a22]">
                        {{ t('partnerDynamics.workspace.importantDifferences') }}
                    </h5>
                    <div class="mt-3 space-y-3">
                        <div
                            v-for="item in workspace.alignment.importantDifferences"
                            :key="`difference-${item.dimension}`"
                        >
                            <p class="text-sm font-bold text-[#66531f]">
                                {{ item.label }}
                            </p>
                            <p class="mt-1 text-xs leading-5 text-[#7d6a32]">
                                {{ item.message }}
                            </p>
                        </div>
                    </div>
                </section>

                <section
                    v-if="workspace.alignment.sharedBlindSpots.length"
                    class="rounded-2xl border border-[#e8cfca] bg-[#fff7f5] p-4"
                >
                    <h5 class="text-sm font-black text-[#84483c]">
                        {{ t('partnerDynamics.workspace.sharedBlindSpots') }}
                    </h5>
                    <div class="mt-3 space-y-3">
                        <div
                            v-for="item in workspace.alignment.sharedBlindSpots"
                            :key="`blind-${item.dimension}`"
                        >
                            <p class="text-sm font-bold text-[#794439]">
                                {{ item.label }}
                            </p>
                            <p class="mt-1 text-xs leading-5 text-[#85675f]">
                                {{ item.message }}
                            </p>
                        </div>
                    </div>
                </section>
            </div>

            <div class="mt-5 grid gap-4 xl:grid-cols-2">
                <section
                    class="rounded-2xl border border-[#dce6de] bg-white p-4"
                >
                    <h5 class="text-sm font-black text-[var(--pbr-ink)]">
                        {{ t('partnerDynamics.workspace.roleSuggestions') }}
                    </h5>
                    <div class="mt-3 space-y-4">
                        <article
                            v-for="row in workspace.alignment.roleSuggestions"
                            :key="`role-${row.name}`"
                        >
                            <p class="text-sm font-black text-[var(--pbr-green-dark)]">
                                {{ row.name }} ·
                                {{ profileLabel(row.primaryProfile) }}
                            </p>
                            <ul
                                class="mt-2 list-disc space-y-1 pl-5 text-xs leading-5 text-[var(--pbr-muted)]"
                            >
                                <li
                                    v-for="suggestion in row.suggestions"
                                    :key="suggestion"
                                >
                                    {{ suggestion }}
                                </li>
                            </ul>
                        </article>
                    </div>
                </section>

                <section
                    class="rounded-2xl border border-[#dce6de] bg-white p-4"
                >
                    <h5 class="text-sm font-black text-[var(--pbr-ink)]">
                        {{ t('partnerDynamics.workspace.safeguards') }}
                    </h5>
                    <div class="mt-3 space-y-3">
                        <article
                            v-for="row in workspace.alignment.decisionRecommendations"
                            :key="row.title"
                            class="rounded-xl bg-[#f8faf8] p-3"
                        >
                            <p class="text-sm font-black text-[var(--pbr-ink-soft)]">
                                {{ row.title }}
                            </p>
                            <p class="mt-1 text-xs leading-5 text-[var(--pbr-muted)]">
                                {{ row.message }}
                            </p>
                        </article>
                    </div>
                </section>
            </div>

            <section
                v-if="workspace.alignment.discussionPriorities.length"
                class="mt-5 rounded-2xl border border-[#dce6de] bg-[#f8faf8] p-4"
            >
                <h5 class="text-sm font-black text-[var(--pbr-ink)]">
                    {{ t('partnerDynamics.workspace.discussionPriorities') }}
                </h5>
                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    <article
                        v-for="row in workspace.alignment.discussionPriorities"
                        :key="`${row.priority}-${row.topic}`"
                        class="rounded-xl border border-[#e1e9e3] bg-white p-3"
                    >
                        <p class="text-xs font-black uppercase tracking-[0.08em] text-[var(--pbr-green)]">
                            {{ row.priority }} · {{ row.topic }}
                        </p>
                        <p class="mt-1 text-xs leading-5 text-[var(--pbr-muted)]">
                            {{ row.reason }}
                        </p>
                    </article>
                </div>
            </section>

            <p
                class="mt-5 rounded-xl border border-[#e3e7e4] bg-[#fafbfa] px-4 py-3 text-xs leading-5 text-[var(--pbr-muted)]"
            >
                {{ t('partnerDynamics.workspace.boundary') }}
            </p>
        </div>

        <div
            v-else-if="workspace.progress.total > 0"
            class="border-t border-[#dce6de] bg-white/85 p-5 text-sm leading-6 text-[var(--pbr-muted)] sm:p-6"
        >
            {{ t('partnerDynamics.workspace.waitingForAlignment') }}
        </div>
    </section>
</template>
