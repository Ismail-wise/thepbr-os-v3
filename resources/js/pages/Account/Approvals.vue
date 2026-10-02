<script setup lang="ts">
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import AccountNav from '../../components/account/AccountNav.vue';
import AccountPageHeader from '../../components/account/AccountPageHeader.vue';
import AccountEmptyState from '../../components/account/AccountEmptyState.vue';
import AccountItemCard from '../../components/account/AccountItemCard.vue';
import { useI18n } from '../../i18n/useI18n';
import type { AccountApproval } from '../../account/types';

defineProps<{
    approvals: AccountApproval[];
}>();

const { t } = useI18n();
</script>

<template>
    <AuthenticatedLayout>
        <main
            class="min-h-screen bg-[linear-gradient(180deg,#f7f9f6_0%,#f1f5f1_100%)] px-4 py-5 text-[var(--pbr-ink)] sm:px-6 sm:py-6 lg:px-7 lg:py-7"
        >
            <div class="mx-auto max-w-[1250px] space-y-5 sm:space-y-6">
                <AccountNav />
                <AccountPageHeader
                    :eyebrow="t('account.approvalsEyebrow')"
                    :title="t('account.approvalsTitle')"
                    :subtitle="t('account.approvalsSubtitle')"
                />

                <div v-if="approvals.length > 0" class="space-y-3">
                    <AccountItemCard
                        v-for="(approval, index) in approvals"
                        :key="approval.businessId + '|' + approval.decisionLabel + '|' + index"
                        :business-id="approval.businessId"
                        :business-name="approval.businessName"
                        kind="approval"
                        :title="approval.decisionLabel"
                        status="open"
                        :date-label="t('account.opened')"
                        :date-value="approval.openedAt"
                        :route="approval.route"
                        :action-label="t('account.reviewApproval')"
                    />
                </div>

                <AccountEmptyState
                    v-else
                    :title="t('account.noApprovals')"
                    :body="t('account.noApprovalsHelp')"
                />
            </div>
        </main>
    </AuthenticatedLayout>
</template>
