<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from '../i18n/useI18n';
import AuthenticatedLayout from '../layouts/AuthenticatedLayout.vue';
import AccountNav from '../components/account/AccountNav.vue';
import AccountPageHeader from '../components/account/AccountPageHeader.vue';
import AccountEmptyState from '../components/account/AccountEmptyState.vue';
import AccountItemCard from '../components/account/AccountItemCard.vue';
import AccountBusinessCard from '../components/account/AccountBusinessCard.vue';
import { accountKindKey } from '../account/copy';
import type {
    AccountAttentionItem,
    AccountBusiness,
    AccountNotification,
} from '../account/types';

const props = defineProps<{
    account: {
        email: string;
        displayName: string | null;
    };
    attention: AccountAttentionItem[];
    businesses: AccountBusiness[];
    notifications: AccountNotification[];
    canCreateBusiness: boolean;
}>();

const { t } = useI18n();
const logoutForm = useForm({});

const greetingName = computed(
    () => props.account.displayName?.trim() || props.account.email,
);

const actionLabel = (kind: string): string => {
    if (kind === 'approval') {
        return t('account.reviewApproval');
    }

    if (kind === 'signature') {
        return t('account.reviewSignature');
    }

    return t('account.reviewWork');
};

const logout = () => {
    logoutForm.post('/logout');
};
</script>

<template>
    <AuthenticatedLayout>
        <main
            :aria-label="t('nav.home')"
            class="min-h-screen bg-[radial-gradient(circle_at_90%_0%,rgb(210_167_67_/_9%),transparent_24rem),linear-gradient(180deg,#f7f9f6_0%,#f1f5f1_100%)] px-4 py-5 text-[var(--pbr-ink)] sm:px-6 sm:py-6 lg:px-7 lg:py-7"
        >
            <div class="mx-auto max-w-[1500px] space-y-5 sm:space-y-6">
                <AccountNav />

                <AccountPageHeader
                    :eyebrow="t('account.accountEyebrow')"
                    :title="t('account.accountTitle')"
                    :subtitle="t('account.accountSubtitle')"
                >
                    <template #actions>
                        <Link
                            v-if="canCreateBusiness"
                            href="/businesses/create"
                            class="pbr-touch inline-flex min-h-10 items-center rounded-xl bg-[var(--pbr-green-dark)] px-4 text-sm font-black text-white shadow-[0_8px_18px_rgb(13_106_59_/_16%)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--pbr-green)] focus-visible:ring-offset-2"
                        >
                            {{ t('account.createBusiness') }}
                        </Link>
                        <Link
                            href="/account/settings"
                            class="pbr-touch inline-flex min-h-10 items-center rounded-xl border border-[#cedbd1] bg-white px-4 text-sm font-black text-[var(--pbr-ink-soft)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--pbr-green)]"
                        >
                            {{ t('account.profileSettings') }}
                        </Link>
                        <button
                            type="button"
                            :disabled="logoutForm.processing"
                            class="pbr-touch inline-flex min-h-10 items-center rounded-xl border border-[#dedfdc] bg-white px-4 text-sm font-extrabold text-[#657168] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--pbr-green)] disabled:opacity-60"
                            @click="logout"
                        >
                            {{ t('account.signOut') }}
                        </button>
                    </template>
                </AccountPageHeader>

                <section
                    class="rounded-[22px] border border-[#d9e5dc] bg-white/80 p-4 shadow-[0_10px_28px_rgb(16_35_26_/_4%)] sm:p-5"
                >
                    <p
                        class="text-[10px] font-black uppercase tracking-[0.18em] text-[#7d8a82]"
                    >
                        {{ t('account.welcome') }}
                    </p>
                    <div
                        class="mt-1 flex flex-wrap items-end justify-between gap-3"
                    >
                        <div>
                            <h2
                                class="text-xl font-black tracking-[-0.02em] sm:text-2xl"
                            >
                                {{ greetingName }}
                            </h2>
                            <p class="mt-1 text-sm text-[var(--pbr-muted)]">
                                {{ t('account.signedInAs') }} · {{ account.email }}
                            </p>
                        </div>
                        <p
                            class="max-w-xl text-xs leading-5 text-[#77837b]"
                        >
                            {{ t('account.privacyNote') }}
                        </p>
                    </div>
                </section>

                <section>
                    <div class="mb-4">
                        <p
                            class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]"
                        >
                            {{ t('account.needsYou') }}
                        </p>
                        <h2
                            class="mt-1 text-xl font-black tracking-[-0.02em] sm:text-2xl"
                        >
                            {{ t('account.needsYou') }}
                        </h2>
                        <p
                            class="mt-1 max-w-3xl text-sm leading-6 text-[var(--pbr-muted)]"
                        >
                            {{ t('account.needsYouHelp') }}
                        </p>
                    </div>

                    <div
                        v-if="attention.length > 0"
                        class="grid gap-3 lg:grid-cols-2"
                    >
                        <AccountItemCard
                            v-for="(item, index) in attention"
                            :key="item.businessId + '|' + item.kind + '|' + item.title + '|' + index"
                            :business-id="item.businessId"
                            :business-name="item.businessName"
                            :kind="item.kind"
                            :title="item.title"
                            :description="item.description ?? null"
                            :status="item.status ?? null"
                            :date-label="t('account.due')"
                            :date-value="item.dueAt"
                            :route="item.route"
                            :action-label="actionLabel(item.kind)"
                        />
                    </div>

                    <AccountEmptyState
                        v-else
                        :title="t('account.nothingWaiting')"
                        :body="t('account.nothingWaitingHelp')"
                        action-href="/account/businesses"
                        :action-label="t('account.viewAllBusinesses')"
                    />
                </section>

                <section>
                    <div
                        class="mb-4 flex flex-wrap items-end justify-between gap-3"
                    >
                        <div>
                            <p
                                class="text-[10px] font-black uppercase tracking-[0.18em] text-[#7d8a82]"
                            >
                                {{ t('account.yourBusinesses') }}
                            </p>
                            <h2
                                class="mt-1 text-xl font-black tracking-[-0.02em] sm:text-2xl"
                            >
                                {{ t('account.yourBusinesses') }}
                            </h2>
                            <p
                                class="mt-1 max-w-3xl text-sm leading-6 text-[var(--pbr-muted)]"
                            >
                                {{ t('account.businessesHelp') }}
                            </p>
                        </div>
                        <Link
                            href="/account/businesses"
                            class="pbr-touch inline-flex min-h-9 items-center rounded-xl border border-[#cedbd1] bg-white px-3.5 text-xs font-black text-[var(--pbr-green-dark)]"
                        >
                            {{ t('account.viewAllBusinesses') }}
                        </Link>
                    </div>

                    <div
                        v-if="businesses.length > 0"
                        class="grid gap-3 md:grid-cols-2 xl:grid-cols-3"
                    >
                        <AccountBusinessCard
                            v-for="business in businesses"
                            :key="business.businessId"
                            :business="business"
                        />
                    </div>

                    <AccountEmptyState
                        v-else
                        :title="t('account.noBusinesses')"
                        :body="t('account.noBusinessesHelp')"
                        :action-href="canCreateBusiness ? '/businesses/create' : undefined"
                        :action-label="canCreateBusiness ? t('account.createBusiness') : undefined"
                    />
                </section>

                <section>
                    <div
                        class="mb-4 flex flex-wrap items-end justify-between gap-3"
                    >
                        <div>
                            <p
                                class="text-[10px] font-black uppercase tracking-[0.18em] text-[#7d8a82]"
                            >
                                {{ t('account.recentNotifications') }}
                            </p>
                            <h2
                                class="mt-1 text-xl font-black tracking-[-0.02em] sm:text-2xl"
                            >
                                {{ t('account.recentNotifications') }}
                            </h2>
                            <p
                                class="mt-1 max-w-3xl text-sm leading-6 text-[var(--pbr-muted)]"
                            >
                                {{ t('account.notificationsHelp') }}
                            </p>
                        </div>
                        <Link
                            href="/account/notifications"
                            class="pbr-touch inline-flex min-h-9 items-center rounded-xl border border-[#cedbd1] bg-white px-3.5 text-xs font-black text-[var(--pbr-green-dark)]"
                        >
                            {{ t('account.viewAllNotifications') }}
                        </Link>
                    </div>

                    <div
                        v-if="notifications.length > 0"
                        class="grid gap-3 lg:grid-cols-2"
                    >
                        <AccountItemCard
                            v-for="(notification, index) in notifications"
                            :key="notification.businessId + '|' + notification.kind + '|' + index"
                            :business-id="notification.businessId"
                            :business-name="notification.businessName"
                            :kind="notification.kind"
                            :title="t(accountKindKey(notification.kind))"
                            :status="notification.status"
                            :date-label="t('account.created')"
                            :date-value="notification.createdAt"
                            :route="notification.route"
                            :action-label="t('account.reviewNotification')"
                        />
                    </div>

                    <AccountEmptyState
                        v-else
                        :title="t('account.noNotifications')"
                        :body="t('account.noNotificationsHelp')"
                    />
                </section>
            </div>
        </main>
    </AuthenticatedLayout>
</template>
