<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import { useI18n } from '../../i18n/useI18n';

type RequirementState = 'met' | 'warning' | 'blocked' | 'unknown';

type RequirementSource = {
    type: string;
    id: string;
    version: number | null;
    hash: string | null;
};

type HealthRequirement = {
    key: string;
    state: RequirementState;
    reason_code: string;
    next_action_code: string;
    source: RequirementSource | null;
    last_verified_at: string | null;
    route: string | null;
};

const props = defineProps<{
    businessHealth: {
        business: {
            id: string;
            name: string;
            workspace_status: string;
        };
        generated_at: string;
        summary: Record<RequirementState, number>;
        requirements: HealthRequirement[];
    };
}>();

const { t } = useI18n();

const requirementLabel = (key: string): string => {
    switch (key) {
        case 'workspace':
            return t('health.rule.workspace');
        case 'ownership':
            return t('health.rule.ownership');
        case 'governance':
            return t('health.rule.governance');
        case 'operations':
            return t('health.rule.operations');
        case 'finance':
            return t('health.rule.finance');
        case 'rewards':
            return t('health.rule.rewards');
        case 'risk':
            return t('health.rule.risk');
        case 'continuity':
            return t('health.rule.continuity');
        case 'conflict':
            return t('health.rule.conflict');
        default:
            return key.replaceAll('_', ' ');
    }
};

const stateLabel = (state: RequirementState): string => {
    switch (state) {
        case 'met':
            return t('health.state.met');
        case 'warning':
            return t('health.state.warning');
        case 'blocked':
            return t('health.state.blocked');
        case 'unknown':
            return t('health.state.unknown');
    }
};

const reasonLabel = (code: string): string => {
    switch (code) {
        case 'workspace_active':
            return t('health.reason.workspaceActive');
        case 'workspace_restricted':
            return t('health.reason.workspaceRestricted');
        case 'workspace_archived':
            return t('health.reason.workspaceArchived');
        case 'workspace_closed':
            return t('health.reason.workspaceClosed');
        case 'current_effective_source':
            return t('health.reason.currentEffective');
        case 'authorized_source_unavailable':
            return t('health.reason.authorizedSourceUnavailable');
        case 'review_due':
            return t('health.reason.reviewDue');
        case 'review_due_soon':
            return t('health.reason.reviewDueSoon');
        default:
            return t('health.reason.unknown');
    }
};

const actionLabel = (code: string): string => {
    switch (code) {
        case 'open_workspace':
            return t('health.action.openWorkspace');
        case 'open_ownership':
            return t('health.action.openOwnership');
        case 'open_governance':
            return t('health.action.openGovernance');
        case 'open_operations':
            return t('health.action.openOperations');
        case 'open_finance':
            return t('health.action.openFinance');
        case 'open_rewards':
            return t('health.action.openRewards');
        case 'open_risk':
            return t('health.action.openRisk');
        case 'open_continuity':
            return t('health.action.openContinuity');
        case 'open_conflict':
            return t('health.action.openConflict');
        default:
            return t('health.action.review');
    }
};

const stateClasses = (state: RequirementState): string => {
    switch (state) {
        case 'met':
            return 'border-emerald-200 bg-emerald-50 text-emerald-800';
        case 'warning':
            return 'border-amber-200 bg-amber-50 text-amber-900';
        case 'blocked':
            return 'border-rose-200 bg-rose-50 text-rose-800';
        case 'unknown':
            return 'border-slate-200 bg-slate-100 text-slate-700';
    }
};

const formatDate = (value: string | null): string => {
    if (!value) {
        return t('health.notAvailable');
    }

    const parsed = new Date(value);

    if (Number.isNaN(parsed.getTime())) {
        return value;
    }

    return parsed.toLocaleString();
};

const shortHash = (hash: string | null): string | null =>
    hash ? `${hash.slice(0, 10)}…` : null;
</script>

<template>
    <Head :title="t('health.title')" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <header class="border-b border-slate-200 pb-5">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                    {{ t('health.eyebrow') }}
                </p>
                <div class="mt-2 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h1 class="text-2xl font-semibold text-slate-950">
                            {{ t('health.title') }}
                        </h1>
                        <p class="mt-2 max-w-3xl text-sm text-slate-600">
                            {{ t('health.subtitle') }}
                        </p>
                    </div>
                    <div class="text-left text-xs text-slate-500 sm:text-right">
                        <p>{{ businessHealth.business.name }}</p>
                        <p class="mt-1">
                            {{ t('health.generated') }}:
                            {{ formatDate(businessHealth.generated_at) }}
                        </p>
                    </div>
                </div>
            </header>

            <section
                aria-labelledby="health-summary-heading"
                class="border-b border-slate-200 pb-5"
            >
                <h2 id="health-summary-heading" class="text-sm font-semibold text-slate-950">
                    {{ t('health.summary') }}
                </h2>
                <p class="mt-1 text-xs text-slate-500">
                    {{ t('health.summaryHelp') }}
                </p>

                <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-4">
                    <div>
                        <dt class="text-xs font-medium text-slate-500">
                            {{ t('health.state.met') }}
                        </dt>
                        <dd class="mt-1 text-2xl font-semibold text-slate-950">
                            {{ businessHealth.summary.met }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-slate-500">
                            {{ t('health.state.warning') }}
                        </dt>
                        <dd class="mt-1 text-2xl font-semibold text-slate-950">
                            {{ businessHealth.summary.warning }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-slate-500">
                            {{ t('health.state.blocked') }}
                        </dt>
                        <dd class="mt-1 text-2xl font-semibold text-slate-950">
                            {{ businessHealth.summary.blocked }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-slate-500">
                            {{ t('health.state.unknown') }}
                        </dt>
                        <dd class="mt-1 text-2xl font-semibold text-slate-950">
                            {{ businessHealth.summary.unknown }}
                        </dd>
                    </div>
                </dl>
            </section>

            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 class="text-sm font-semibold text-slate-950">
                        {{ t('health.register') }}
                    </h2>
                    <p class="mt-1 text-xs text-slate-500">
                        {{ t('health.registerHelp') }}
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-left">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-xs font-semibold uppercase tracking-wide text-slate-600">
                                    {{ t('health.column.requirement') }}
                                </th>
                                <th class="px-5 py-3 text-xs font-semibold uppercase tracking-wide text-slate-600">
                                    {{ t('health.column.state') }}
                                </th>
                                <th class="px-5 py-3 text-xs font-semibold uppercase tracking-wide text-slate-600">
                                    {{ t('health.column.reason') }}
                                </th>
                                <th class="px-5 py-3 text-xs font-semibold uppercase tracking-wide text-slate-600">
                                    {{ t('health.column.source') }}
                                </th>
                                <th class="px-5 py-3 text-xs font-semibold uppercase tracking-wide text-slate-600">
                                    {{ t('health.column.verified') }}
                                </th>
                                <th class="px-5 py-3 text-xs font-semibold uppercase tracking-wide text-slate-600">
                                    {{ t('health.column.action') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr
                                v-for="requirement in businessHealth.requirements"
                                :key="requirement.key"
                                class="align-top"
                            >
                                <td class="px-5 py-4 text-sm font-semibold text-slate-950">
                                    {{ requirementLabel(requirement.key) }}
                                </td>
                                <td class="px-5 py-4">
                                    <span
                                        class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold"
                                        :class="stateClasses(requirement.state)"
                                    >
                                        {{ stateLabel(requirement.state) }}
                                    </span>
                                </td>
                                <td class="max-w-sm px-5 py-4 text-sm text-slate-600">
                                    {{ reasonLabel(requirement.reason_code) }}
                                </td>
                                <td class="px-5 py-4 text-xs text-slate-600">
                                    <template v-if="requirement.source">
                                        <p class="font-medium text-slate-800">
                                            {{ requirement.source.type.replaceAll('_', ' ') }}
                                        </p>
                                        <p v-if="requirement.source.version" class="mt-1">
                                            v{{ requirement.source.version }}
                                        </p>
                                        <p v-if="shortHash(requirement.source.hash)" class="mt-1 font-mono">
                                            {{ shortHash(requirement.source.hash) }}
                                        </p>
                                    </template>
                                    <span v-else>{{ t('health.notAvailable') }}</span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 text-xs text-slate-600">
                                    {{ formatDate(requirement.last_verified_at) }}
                                </td>
                                <td class="px-5 py-4">
                                    <Link
                                        v-if="requirement.route"
                                        :href="requirement.route"
                                        class="inline-flex min-h-10 items-center text-sm font-semibold text-slate-900 underline underline-offset-4"
                                    >
                                        {{ actionLabel(requirement.next_action_code) }}
                                    </Link>
                                    <span v-else class="text-xs text-slate-500">
                                        {{ actionLabel(requirement.next_action_code) }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <p class="text-xs leading-5 text-slate-500">
                {{ t('health.privacy') }}
            </p>
        </div>
    </AuthenticatedLayout>
</template>
