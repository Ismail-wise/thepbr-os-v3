<script setup lang="ts">
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import AccountNav from '../../components/account/AccountNav.vue';
import AccountPageHeader from '../../components/account/AccountPageHeader.vue';
import AccountEmptyState from '../../components/account/AccountEmptyState.vue';
import AccountItemCard from '../../components/account/AccountItemCard.vue';
import { useI18n } from '../../i18n/useI18n';
import type { AccountSignature } from '../../account/types';

defineProps<{
    signatures: AccountSignature[];
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
                    :eyebrow="t('account.signaturesEyebrow')"
                    :title="t('account.signaturesTitle')"
                    :subtitle="t('account.signaturesSubtitle')"
                />

                <div v-if="signatures.length > 0" class="space-y-3">
                    <AccountItemCard
                        v-for="(signature, index) in signatures"
                        :key="signature.businessId + '|' + signature.decisionLabel + '|' + index"
                        :business-id="signature.businessId"
                        :business-name="signature.businessName"
                        kind="signature"
                        :title="signature.decisionLabel"
                        :status="signature.status"
                        :date-label="t('account.requested')"
                        :date-value="signature.requestedAt"
                        :route="signature.route"
                        :action-label="t('account.reviewSignature')"
                    />
                </div>

                <AccountEmptyState
                    v-else
                    :title="t('account.noSignatures')"
                    :body="t('account.noSignaturesHelp')"
                />
            </div>
        </main>
    </AuthenticatedLayout>
</template>
