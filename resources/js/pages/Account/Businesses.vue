<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import AccountNav from '../../components/account/AccountNav.vue';
import AccountPageHeader from '../../components/account/AccountPageHeader.vue';
import AccountBusinessCard from '../../components/account/AccountBusinessCard.vue';
import AccountEmptyState from '../../components/account/AccountEmptyState.vue';
import { useI18n } from '../../i18n/useI18n';
import type { AccountBusiness } from '../../account/types';

defineProps<{
    businesses: AccountBusiness[];
    canCreateBusiness: boolean;
}>();

const { t } = useI18n();
</script>

<template>
    <AuthenticatedLayout>
        <main
            class="min-h-screen bg-[linear-gradient(180deg,#f7f9f6_0%,#f1f5f1_100%)] px-4 py-5 text-[var(--pbr-ink)] sm:px-6 sm:py-6 lg:px-7 lg:py-7"
        >
            <div class="mx-auto max-w-[1500px] space-y-5 sm:space-y-6">
                <AccountNav />
                <AccountPageHeader
                    :eyebrow="t('account.businessesEyebrow')"
                    :title="t('account.businessesTitle')"
                    :subtitle="t('account.businessesSubtitle')"
                >
                    <template #actions>
                        <Link
                            v-if="canCreateBusiness"
                            href="/businesses/create"
                            class="pbr-touch inline-flex min-h-10 items-center rounded-xl bg-[var(--pbr-green-dark)] px-4 text-sm font-black text-white"
                        >
                            {{ t('account.createBusiness') }}
                        </Link>
                    </template>
                </AccountPageHeader>

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
            </div>
        </main>
    </AuthenticatedLayout>
</template>
