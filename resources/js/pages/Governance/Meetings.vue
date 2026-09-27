<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import { useI18n } from '../../i18n/useI18n';

type Membership = { id: string; email: string };
type Meeting = {
    id: string;
    title: string;
    status: 'scheduled' | 'held' | 'cancelled';
    scheduled_at: string;
    notice_sent_at: string | null;
    quorum_required: number;
    quorum_present: number;
    agenda: string;
    minutes: string | null;
    held_at: string | null;
    cancelled_at: string | null;
};
type Attendee = {
    id: string;
    governance_meeting_id: string;
    membership_id: string;
    attendance_status: string;
};

const props = defineProps<{
    meetingWorkspace: {
        business: { id: string; name: string };
        permissions: { manage: boolean };
        current_source: null | { kind: string; formal_record_version_id: string };
        meetings: Meeting[];
        attendees: Attendee[];
        memberships: Membership[];
    };
}>();

const { t } = useI18n();
const firstMembership = props.meetingWorkspace.memberships[0]?.id ?? '';

const schedule = useForm({
    title: '',
    scheduled_at: '',
    notice_sent_at: '',
    quorum_required: 1,
    agenda_owner_membership_id: firstMembership,
    minutes_owner_membership_id: firstMembership,
    agenda: '',
    attendee_membership_ids: props.meetingWorkspace.memberships.map((m) => m.id),
});

const minutes = reactive<Record<string, string>>({});
const attendance = reactive<Record<string, Record<string, string>>>({});

const attendeesFor = (meetingId: string) =>
    props.meetingWorkspace.attendees.filter((row) => row.governance_meeting_id === meetingId);

const memberEmail = (id: string) =>
    props.meetingWorkspace.memberships.find((row) => row.id === id)?.email ?? id;

const ensureAttendance = (meetingId: string) => {
    if (!attendance[meetingId]) {
        attendance[meetingId] = {};
        for (const row of attendeesFor(meetingId)) {
            attendance[meetingId][row.membership_id] =
                row.attendance_status === 'invited' ? 'present' : row.attendance_status;
        }
    }
    return attendance[meetingId];
};

const scheduled = computed(() =>
    props.meetingWorkspace.meetings.filter((row) => row.status === 'scheduled'),
);
const history = computed(() =>
    props.meetingWorkspace.meetings.filter((row) => row.status !== 'scheduled'),
);

type PostData = NonNullable<Parameters<typeof router.post>[1]>;

const post = (url: string, data: PostData = {}) =>
    router.post(url, data, { preserveScroll: true });

const formError = (errors: object, key: string) =>
    (errors as Record<string, string | undefined>)[key];
</script>

<template>
    <Head :title="t('governance.meetingsTitle')" />
    <AuthenticatedLayout>
        <main class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <header class="border-b border-slate-200 pb-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ meetingWorkspace.business.name }}</p>
                        <h1 class="mt-2 text-2xl font-bold">{{ t('governance.meetingsTitle') }}</h1>
                        <p class="mt-2 max-w-4xl text-sm leading-6 text-slate-600">{{ t('governance.meetingsDescription') }}</p>
                    </div>
                    <div class="flex gap-2">
                        <Link href="/governance/rules" class="inline-flex min-h-11 items-center border border-slate-300 px-4 text-sm font-semibold">Rules & Authority</Link>
                        <Link href="/governance" class="inline-flex min-h-11 items-center bg-slate-950 px-4 text-sm font-semibold text-white">Decision Register</Link>
                    </div>
                </div>
            </header>

            <section class="mt-5 border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                Authority source:
                <strong>{{ meetingWorkspace.current_source?.kind ?? 'none' }}</strong>.
                A meeting-required Decision may open only from a Held meeting using this same authority source and meeting quorum.
            </section>

            <details v-if="meetingWorkspace.permissions.manage" class="mt-6 border border-slate-200" open>
                <summary class="cursor-pointer px-5 py-4 font-semibold">Schedule Meeting</summary>
                <form class="grid gap-4 border-t border-slate-200 p-5 md:grid-cols-2" @submit.prevent="schedule.post('/governance/meetings', { preserveScroll: true })">
                    <label class="text-sm font-medium">Title
                        <input v-model="schedule.title" required class="mt-1 min-h-11 w-full border border-slate-300 px-3" />
                    </label>
                    <label class="text-sm font-medium">Scheduled at
                        <input v-model="schedule.scheduled_at" type="datetime-local" required class="mt-1 min-h-11 w-full border border-slate-300 px-3" />
                    </label>
                    <label class="text-sm font-medium">Notice sent at
                        <input v-model="schedule.notice_sent_at" type="datetime-local" class="mt-1 min-h-11 w-full border border-slate-300 px-3" />
                    </label>
                    <label class="text-sm font-medium">Quorum required
                        <input v-model.number="schedule.quorum_required" type="number" min="1" required class="mt-1 min-h-11 w-full border border-slate-300 px-3" />
                    </label>
                    <label class="text-sm font-medium">Agenda owner
                        <select v-model="schedule.agenda_owner_membership_id" class="mt-1 min-h-11 w-full border border-slate-300 px-3">
                            <option v-for="m in meetingWorkspace.memberships" :key="m.id" :value="m.id">{{ m.email }}</option>
                        </select>
                    </label>
                    <label class="text-sm font-medium">Minutes owner
                        <select v-model="schedule.minutes_owner_membership_id" class="mt-1 min-h-11 w-full border border-slate-300 px-3">
                            <option v-for="m in meetingWorkspace.memberships" :key="m.id" :value="m.id">{{ m.email }}</option>
                        </select>
                    </label>
                    <label class="text-sm font-medium md:col-span-2">Agenda
                        <textarea v-model="schedule.agenda" rows="4" required class="mt-1 w-full border border-slate-300 p-3" />
                    </label>
                    <fieldset class="md:col-span-2">
                        <legend class="text-sm font-semibold">Invited Memberships</legend>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            <label v-for="m in meetingWorkspace.memberships" :key="m.id" class="flex min-h-10 items-center gap-2 border border-slate-200 px-3 text-sm">
                                <input v-model="schedule.attendee_membership_ids" type="checkbox" :value="m.id" />
                                {{ m.email }}
                            </label>
                        </div>
                    </fieldset>
                    <p v-if="formError(schedule.errors, 'meeting')" class="text-sm text-red-700 md:col-span-2">{{ formError(schedule.errors, 'meeting') }}</p>
                    <button type="submit" class="min-h-11 bg-slate-950 px-4 text-sm font-semibold text-white md:col-span-2 md:w-fit">Schedule governed meeting</button>
                </form>
            </details>

            <section class="mt-8">
                <h2 class="text-lg font-bold">Scheduled Meetings</h2>
                <div class="mt-4 space-y-4">
                    <article v-for="meeting in scheduled" :key="meeting.id" class="border border-slate-200 p-5">
                        <div class="flex flex-wrap justify-between gap-3">
                            <div>
                                <h3 class="font-semibold">{{ meeting.title }}</h3>
                                <p class="mt-1 text-sm text-slate-600">{{ new Date(meeting.scheduled_at).toLocaleString() }} · Quorum {{ meeting.quorum_required }}</p>
                            </div>
                            <button v-if="meetingWorkspace.permissions.manage" type="button" class="min-h-9 border border-slate-300 px-3 text-xs font-semibold" @click="post('/governance/meetings/' + meeting.id + '/cancel')">Cancel</button>
                        </div>
                        <p class="mt-3 whitespace-pre-wrap text-sm leading-6 text-slate-700">{{ meeting.agenda }}</p>

                        <div v-if="meetingWorkspace.permissions.manage" class="mt-5 border-t border-slate-200 pt-4">
                            <h4 class="text-sm font-semibold">Attendance & Minutes</h4>
                            <div class="mt-3 grid gap-2 md:grid-cols-2">
                                <label v-for="a in attendeesFor(meeting.id)" :key="a.id" class="flex items-center justify-between gap-3 border border-slate-200 px-3 py-2 text-sm">
                                    <span>{{ memberEmail(a.membership_id) }}</span>
                                    <select v-model="ensureAttendance(meeting.id)[a.membership_id]" class="min-h-9 border border-slate-300 px-2 text-xs">
                                        <option value="present">Present</option>
                                        <option value="remote">Remote</option>
                                        <option value="absent">Absent</option>
                                        <option value="recused">Recused</option>
                                    </select>
                                </label>
                            </div>
                            <textarea v-model="minutes[meeting.id]" rows="4" class="mt-3 w-full border border-slate-300 p-3 text-sm" placeholder="Minutes — required before holding the meeting" />
                            <button type="button" class="mt-3 min-h-10 bg-slate-950 px-4 text-xs font-semibold text-white" :disabled="!minutes[meeting.id]" @click="post('/governance/meetings/' + meeting.id + '/hold', { minutes: minutes[meeting.id], attendance: ensureAttendance(meeting.id) })">
                                Hold meeting and freeze history
                            </button>
                        </div>
                    </article>
                    <p v-if="scheduled.length === 0" class="border border-dashed border-slate-300 p-5 text-sm text-slate-500">No scheduled meetings.</p>
                </div>
            </section>

            <section class="mt-8 border-t border-slate-200 pt-6">
                <h2 class="text-lg font-bold">Meeting History</h2>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full border-collapse text-left text-sm">
                        <thead><tr class="border-b border-slate-300 text-slate-600"><th class="px-3 py-3">Meeting</th><th class="px-3 py-3">State</th><th class="px-3 py-3">Quorum</th><th class="px-3 py-3">Minutes</th></tr></thead>
                        <tbody>
                            <tr v-for="meeting in history" :key="meeting.id" class="border-b border-slate-200 align-top">
                                <td class="px-3 py-4"><p class="font-semibold">{{ meeting.title }}</p><p class="text-xs text-slate-500">{{ new Date(meeting.scheduled_at).toLocaleString() }}</p></td>
                                <td class="px-3 py-4 font-semibold">{{ meeting.status }}</td>
                                <td class="px-3 py-4">{{ meeting.quorum_present }} / {{ meeting.quorum_required }}</td>
                                <td class="max-w-xl whitespace-pre-wrap px-3 py-4 text-slate-700">{{ meeting.minutes ?? '—' }}</td>
                            </tr>
                            <tr v-if="history.length === 0"><td colspan="4" class="px-3 py-5 text-slate-500">No held/cancelled meeting history.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </AuthenticatedLayout>
</template>
