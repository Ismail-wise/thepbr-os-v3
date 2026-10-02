<script setup lang="ts">
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import AccountNav from '../../components/account/AccountNav.vue';
import AccountPageHeader from '../../components/account/AccountPageHeader.vue';
import AccountEmptyState from '../../components/account/AccountEmptyState.vue';
import AccountItemCard from '../../components/account/AccountItemCard.vue';
import { useAccountCopy } from '../../account/copy';
import type { AccountSignature } from '../../account/types';

defineProps<{
    signatures: AccountSignature[];
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
                    :eyebrow="c.signaturesEyebrow"
                    :title="c.signaturesTitle"
                    :subtitle="c.signaturesSubtitle"
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
                        :date-label="c.requested"
                        :date-value="signature.requestedAt"
                        :route="signature.route"
                        :action-label="c.reviewSignature"
                    />
                </div>

                <AccountEmptyState
                    v-else
                    :title="c.noSignatures"
                    :body="c.noSignaturesHelp"
                />
            </div>
        </main>
    </AuthenticatedLayout>
</template>
