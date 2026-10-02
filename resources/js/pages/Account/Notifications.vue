<script setup lang="ts">
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import AccountNav from '../../components/account/AccountNav.vue';
import AccountPageHeader from '../../components/account/AccountPageHeader.vue';
import AccountEmptyState from '../../components/account/AccountEmptyState.vue';
import AccountItemCard from '../../components/account/AccountItemCard.vue';
import {
    accountKindLabel,
    useAccountCopy,
} from '../../account/copy';
import type { AccountNotification } from '../../account/types';

defineProps<{
    notifications: AccountNotification[];
}>();

const { c } = useAccountCopy();
</script>

<template>
    <AuthenticatedLayout>
        <main
            class="min-h-screen bg-[linear-gradient(180deg,#f7f9f6_0%,#f1f5f1_100%)] px-4 py-5 text-[var(--pbr-ink)] sm:px-6 sm:py-6 lg:px-7 lg:py-7"
        >
            <div class="mx-auto max-w-[1250px] space-y-5 sm:space-y-6">
                <AccountNav />
                <AccountPageHeader
                    :eyebrow="c.notificationsEyebrow"
                    :title="c.notificationsTitle"
                    :subtitle="c.notificationsSubtitle"
                />

                <div v-if="notifications.length > 0" class="space-y-3">
                    <AccountItemCard
                        v-for="(notification, index) in notifications"
                        :key="notification.businessId + '|' + notification.kind + '|' + index"
                        :business-id="notification.businessId"
                        :business-name="notification.businessName"
                        :kind="notification.kind"
                        :title="accountKindLabel(notification.kind, c)"
                        :status="notification.status"
                        :date-label="c.created"
                        :date-value="notification.createdAt"
                        :route="notification.route"
                        :action-label="c.reviewNotification"
                    />
                </div>

                <AccountEmptyState
                    v-else
                    :title="c.noNotifications"
                    :body="c.noNotificationsHelp"
                />

                <p
                    class="rounded-[16px] border border-[#dce6de] bg-white/70 px-4 py-3 text-xs leading-5 text-[#77837b]"
                >
                    {{ c.privacyNote }}
                </p>
            </div>
        </main>
    </AuthenticatedLayout>
</template>
