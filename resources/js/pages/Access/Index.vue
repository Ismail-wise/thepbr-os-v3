<script setup lang="ts">
import { useI18n } from '../../i18n/useI18n';
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';

type PermissionProfileRow = {
    id: string;
    name: string;
    capabilities: string[];
    assigned: boolean;
};

type DirectGrantRow = {
    capability: string;
    effect: string;
};

type AccessOverview = {
    business: {
        id: string;
        name: string;
    };
    membership: {
        id: string;
        accessStatus: string;
    };
    profiles: PermissionProfileRow[];
    directGrants: DirectGrantRow[];
};

defineProps<{
    access: AccessOverview;
}>();

const { t } = useI18n();

const effectLabel = (effect: string): string =>
    effect === 'deny' ? t('access.deny') : t('access.allow');
</script>

<template>
    <AuthenticatedLayout>
        <main class="mx-auto w-full max-w-6xl px-4 py-6 sm:px-6 lg:px-8">
            <header class="border-b border-slate-200 pb-6">
                <h1 class="text-2xl font-semibold tracking-tight text-slate-950">
                    {{ t('access.title') }}
                </h1>

                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                    {{ t('access.description') }}
                </p>

                <p
                    class="mt-4 border-l-4 border-slate-700 bg-slate-50 px-4 py-3 text-sm font-medium leading-6 text-slate-800"
                    role="note"
                >
                    {{ t('access.scopeNotice') }}
                </p>
            </header>

            <section class="border-b border-slate-200 py-6">
                <h2 class="text-lg font-semibold text-slate-950">
                    {{ t('access.membership') }}
                </h2>

                <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            {{ t('access.business') }}
                        </dt>
                        <dd class="mt-1 text-sm font-medium text-slate-950">
                            {{ access.business.name }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            {{ t('access.status') }}
                        </dt>
                        <dd class="mt-1 text-sm font-medium text-slate-950">
                            {{ access.membership.accessStatus }}
                        </dd>
                    </div>
                </dl>
            </section>

            <section class="border-b border-slate-200 py-6">
                <h2 class="text-lg font-semibold text-slate-950">
                    {{ t('access.permissionProfiles') }}
                </h2>

                <p
                    v-if="access.profiles.length === 0"
                    class="mt-4 text-sm text-slate-600"
                    role="status"
                >
                    {{ t('access.noProfiles') }}
                </p>

                <div v-else class="mt-4 overflow-x-auto">
                    <table
                        class="min-w-full border-collapse text-left text-sm"
                        :aria-label="t('access.permissionProfiles')"
                    >
                        <thead>
                            <tr class="border-b border-slate-300 text-slate-600">
                                <th scope="col" class="px-3 py-3 font-semibold">
                                    {{ t('access.profile') }}
                                </th>
                                <th scope="col" class="px-3 py-3 font-semibold">
                                    {{ t('access.capabilities') }}
                                </th>
                                <th scope="col" class="px-3 py-3 font-semibold">
                                    {{ t('access.assignment') }}
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr
                                v-for="profile in access.profiles"
                                :key="profile.id"
                                class="border-b border-slate-200 align-top"
                            >
                                <td class="px-3 py-4 font-medium text-slate-950">
                                    {{ profile.name }}
                                </td>

                                <td class="px-3 py-4 text-slate-700">
                                    <ul
                                        v-if="profile.capabilities.length > 0"
                                        class="space-y-1"
                                    >
                                        <li
                                            v-for="capability in profile.capabilities"
                                            :key="capability"
                                        >
                                            <code
                                                class="break-all rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-800"
                                            >
                                                {{ capability }}
                                            </code>
                                        </li>
                                    </ul>

                                    <span v-else>{{ t('access.none') }}</span>
                                </td>

                                <td class="px-3 py-4">
                                    <span
                                        class="inline-flex min-h-7 items-center border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-800"
                                    >
                                        {{
                                            profile.assigned
                                                ? t('access.assigned')
                                                : t('access.notAssigned')
                                        }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="py-6">
                <h2 class="text-lg font-semibold text-slate-950">
                    {{ t('access.directGrants') }}
                </h2>

                <p
                    v-if="access.directGrants.length === 0"
                    class="mt-4 text-sm text-slate-600"
                    role="status"
                >
                    {{ t('access.noDirectGrants') }}
                </p>

                <div v-else class="mt-4 overflow-x-auto">
                    <table
                        class="min-w-full border-collapse text-left text-sm"
                        :aria-label="t('access.directGrants')"
                    >
                        <thead>
                            <tr class="border-b border-slate-300 text-slate-600">
                                <th scope="col" class="px-3 py-3 font-semibold">
                                    {{ t('access.capabilities') }}
                                </th>
                                <th scope="col" class="px-3 py-3 font-semibold">
                                    {{ t('access.effect') }}
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr
                                v-for="grant in access.directGrants"
                                :key="`${grant.capability}:${grant.effect}`"
                                class="border-b border-slate-200"
                            >
                                <td class="px-3 py-4">
                                    <code
                                        class="break-all rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-800"
                                    >
                                        {{ grant.capability }}
                                    </code>
                                </td>
                                <td class="px-3 py-4 font-semibold text-slate-800">
                                    {{ effectLabel(grant.effect) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </AuthenticatedLayout>
</template>
