<script setup lang="ts">
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import AccountNav from '../../components/account/AccountNav.vue';
import AccountPageHeader from '../../components/account/AccountPageHeader.vue';
import AccountEmptyState from '../../components/account/AccountEmptyState.vue';
import AccountItemCard from '../../components/account/AccountItemCard.vue';
import { useAccountCopy } from '../../account/copy';
import type { AccountWorkItem } from '../../account/types';

defineProps<{
    items: AccountWorkItem[];
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
                    :eyebrow="c.workEyebrow"
                    :title="c.workTitle"
                    :subtitle="c.workSubtitle"
                />

                <div v-if="items.length > 0" class="space-y-3">
                    <AccountItemCard
                        v-for="(item, index) in items"
                        :key="item.businessId + '|' + item.kind + '|' + item.title + '|' + index"
                        :business-id="item.businessId"
                        :business-name="item.businessName"
                        :kind="item.kind"
                        :title="item.title"
                        :description="item.description"
                        :status="item.status"
                        :date-label="c.due"
                        :date-value="item.dueAt"
                        :route="item.route"
                        :action-label="c.reviewWork"
                    />
                </div>

                <AccountEmptyState
                    v-else
                    :title="c.noWork"
                    :body="c.noWorkHelp"
                />
            </div>
        </main>
    </AuthenticatedLayout>
</template>
