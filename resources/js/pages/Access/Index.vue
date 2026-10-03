<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
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

type InvitationRow = {
    id: string;
    invitedEmail: string;
    profileName: string;
    status: string;
    tokenLast4: string;
    expiresAt: string;
    createdAt: string;
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
    canManageInvitations: boolean;
    invitations: InvitationRow[];
};

const props = defineProps<{
    access: AccessOverview;
    newInvitationToken: string | null;
}>();

const { t } = useI18n();

const inviteForm = useForm({
    email: '',
    permission_profile_id: '',
    expires_in_hours: 72,
});

const effectLabel = (effect: string): string =>
    effect === 'deny' ? t('access.deny') : t('access.allow');

const capabilityLabel = (capability: string): string =>
    capability.replaceAll('.', ' ').replaceAll('_', ' ');

const invitationError = (): string =>
    Object.values(inviteForm.errors)[0] ?? '';

const formatDateTime = (value: string): string => {
    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? value
        : date.toLocaleString();
};

const createInvitation = () => {
    inviteForm.post('/workspace/access/invitations', {
        preserveScroll: true,
        onSuccess: () => {
            inviteForm.reset('email');
        },
    });
};

const revokeInvitation = (invitationId: string) => {
    router.post(
        `/workspace/access/invitations/${invitationId}/revoke`,
        {},
        { preserveScroll: true },
    );
};
</script>

<template>
    <AuthenticatedLayout>
        <main class="min-h-screen px-4 py-5 text-[var(--pbr-ink)] sm:px-6 sm:py-6 lg:px-8 lg:py-7">
            <div class="mx-auto w-full max-w-6xl space-y-5">
            <header class="pbr-surface p-5 sm:p-6">
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

            <section class="pbr-surface p-5 sm:p-6">
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

            <section
                v-if="access.canManageInvitations"
                class="pbr-surface p-5 sm:p-6"
            >
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">
                            {{ t('access.invitationAdmin') }}
                        </h2>

                        <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">
                            {{ t('access.invitationAdminHelp') }}
                        </p>
                    </div>

                    <a
                        href="/access/invitation"
                        class="text-sm font-semibold text-slate-800 underline underline-offset-4"
                    >
                        {{ t('access.openRedeem') }}
                    </a>
                </div>

                <div
                    v-if="newInvitationToken"
                    class="mt-5 border border-slate-300 bg-slate-50 p-4"
                    role="status"
                    aria-live="polite"
                >
                    <p class="text-sm font-semibold text-slate-950">
                        {{ t('access.invitationCodeOnce') }}
                    </p>

                    <code
                        class="mt-2 block break-all bg-white px-3 py-3 font-mono text-sm text-slate-900"
                    >
                        {{ newInvitationToken }}
                    </code>

                    <p class="mt-2 text-xs leading-5 text-slate-600">
                        {{ t('access.invitationCodeHelp') }}
                    </p>
                </div>

                <form
                    class="mt-5 grid gap-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_10rem_auto]"
                    @submit.prevent="createInvitation"
                >
                    <label class="text-sm font-medium text-slate-700">
                        {{ t('access.invitedEmail') }}
                        <input
                            v-model="inviteForm.email"
                            type="email"
                            required
                            maxlength="254"
                            :disabled="inviteForm.processing"
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        >
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        {{ t('access.profile') }}
                        <select
                            v-model="inviteForm.permission_profile_id"
                            required
                            :disabled="inviteForm.processing"
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        >
                            <option value="" disabled>
                                {{ t('access.profile') }}
                            </option>
                            <option
                                v-for="profile in access.profiles"
                                :key="profile.id"
                                :value="profile.id"
                            >
                                {{ profile.name }}
                            </option>
                        </select>
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        {{ t('access.expiresHours') }}
                        <input
                            v-model.number="inviteForm.expires_in_hours"
                            type="number"
                            min="1"
                            max="168"
                            required
                            :disabled="inviteForm.processing"
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        >
                    </label>

                    <button
                        type="submit"
                        :disabled="inviteForm.processing"
                        class="min-h-11 self-end bg-slate-950 px-4 text-sm font-semibold text-white disabled:opacity-50"
                    >
                        {{ t('access.createInvitation') }}
                    </button>

                    <p
                        v-if="invitationError()"
                        class="text-sm text-red-700 md:col-span-4"
                        role="alert"
                    >
                        {{ invitationError() }}
                    </p>
                </form>

                <div class="mt-6">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-600">
                        {{ t('access.invitationHistory') }}
                    </h3>

                    <p
                        v-if="access.invitations.length === 0"
                        class="mt-3 text-sm text-slate-600"
                        role="status"
                    >
                        {{ t('access.noInvitations') }}
                    </p>

                    <div v-else class="mt-3 overflow-x-auto">
                        <table class="min-w-full border-collapse text-left text-sm">
                            <thead>
                                <tr class="border-b border-slate-300 text-slate-600">
                                    <th scope="col" class="px-3 py-3 font-semibold">
                                        {{ t('access.invitedEmail') }}
                                    </th>
                                    <th scope="col" class="px-3 py-3 font-semibold">
                                        {{ t('access.profile') }}
                                    </th>
                                    <th scope="col" class="px-3 py-3 font-semibold">
                                        {{ t('access.status') }}
                                    </th>
                                    <th scope="col" class="px-3 py-3 font-semibold">
                                        {{ t('access.expiresAt') }}
                                    </th>
                                    <th scope="col" class="px-3 py-3 font-semibold">
                                        {{ t('access.codeEnding') }}
                                    </th>
                                    <th scope="col" class="px-3 py-3 font-semibold">
                                        {{ t('documents.action') }}
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                <tr
                                    v-for="invitation in access.invitations"
                                    :key="invitation.id"
                                    class="border-b border-slate-200"
                                >
                                    <td class="px-3 py-4">
                                        {{ invitation.invitedEmail }}
                                    </td>
                                    <td class="px-3 py-4">
                                        {{ invitation.profileName }}
                                    </td>
                                    <td class="px-3 py-4 font-medium">
                                        {{ invitation.status }}
                                    </td>
                                    <td class="px-3 py-4 whitespace-nowrap">
                                        {{ formatDateTime(invitation.expiresAt) }}
                                    </td>
                                    <td class="px-3 py-4 font-mono">
                                        …{{ invitation.tokenLast4 }}
                                    </td>
                                    <td class="px-3 py-4">
                                        <button
                                            v-if="invitation.status === 'pending'"
                                            type="button"
                                            class="min-h-9 border border-slate-300 px-3 text-xs font-semibold text-slate-800"
                                            @click="revokeInvitation(invitation.id)"
                                        >
                                            {{ t('access.revoke') }}
                                        </button>
                                        <span v-else class="text-slate-400">—</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section class="pbr-surface p-5 sm:p-6">
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
                                            <span class="capitalize text-sm text-slate-700">
                                                {{ capabilityLabel(capability) }}
                                            </span>
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

            <section class="pbr-surface p-5 sm:p-6">
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
                                    <span class="capitalize text-sm text-slate-700">
                                        {{ capabilityLabel(grant.capability) }}
                                    </span>
                                </td>
                                <td class="px-3 py-4 font-semibold text-slate-800">
                                    {{ effectLabel(grant.effect) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
            </div>
        </main>
    </AuthenticatedLayout>
</template>
