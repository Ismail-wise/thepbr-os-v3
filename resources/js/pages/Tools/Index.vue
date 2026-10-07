<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import AuthenticatedLayout from '../../layouts/AuthenticatedLayout.vue';
import { useI18n } from '../../i18n/useI18n';

const props = defineProps<{
    defaults: {
        weights: {
            capital: string;
            work: string;
            expertise: string;
            risk: string;
        };
    };
    simulation: any;
    simulationError: string | null;
}>();

const { t } = useI18n();

const weights = reactive({
    capital: props.defaults.weights.capital,
    work: props.defaults.weights.work,
    expertise: props.defaults.weights.expertise,
    risk: props.defaults.weights.risk,
});

const partners = reactive([
    {
        name: '',
        capital: '0',
        work: '0',
        expertise: '0',
        risk: '0',
    },
    {
        name: '',
        capital: '0',
        work: '0',
        expertise: '0',
        risk: '0',
    },
]);

const addPartner = () => {
    partners.push({
        name: '',
        capital: '0',
        work: '0',
        expertise: '0',
        risk: '0',
    });
};

const removePartner = (index: number) => {
    if (partners.length > 1) {
        partners.splice(index, 1);
    }
};

const simulate = () => {
    router.get(
        '/tools',
        {
            simulate: 1,
            weights: JSON.stringify(weights),
            partners: JSON.stringify(partners),
        },
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        },
    );
};
</script>

<template>
    <Head :title="t('tools.title')" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-6xl space-y-6">
            <header
                class="overflow-hidden rounded-[26px] border border-[#d6e4d9] bg-[linear-gradient(145deg,#ffffff_0%,#f4faf6_58%,#fbf7ec_100%)] p-5 shadow-[0_16px_44px_rgb(24_66_40_/_8%)] sm:p-7"
            >
                <p class="text-[10px] font-black uppercase tracking-[0.2em] text-[var(--pbr-green)]">
                    {{ t('tools.eyebrow') }}
                </p>
                <h1 class="mt-2 text-3xl font-black tracking-[-0.04em] text-slate-950">
                    {{ t('tools.title') }}
                </h1>
                <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                    {{ t('tools.subtitle') }}
                </p>
            </header>

            <section
                class="rounded-[24px] border border-slate-200 bg-white p-5 sm:p-6"
                data-equity-scenario-simulator
            >
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-xl font-black text-slate-950">
                            {{ t('tools.equitySimulator') }}
                        </h2>
                        <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">
                            {{ t('tools.equitySimulatorHelp') }}
                        </p>
                    </div>
                    <Link
                        href="/partnership?section=ownership"
                        class="inline-flex min-h-11 shrink-0 items-center rounded-xl border border-slate-300 px-4 py-2 text-sm font-black text-slate-900"
                    >
                        {{ t('tools.backOwnership') }}
                    </Link>
                </div>

                <div
                    class="mt-5 rounded-2xl border border-amber-300 bg-amber-50 p-4 text-sm font-bold leading-6 text-amber-950"
                >
                    {{ t('tools.scenarioOnly') }}
                </div>

                <form class="mt-6 space-y-6" @submit.prevent="simulate">
                    <div>
                        <h3 class="font-black text-slate-950">{{ t('tools.weights') }}</h3>
                        <p class="mt-1 text-sm text-slate-600">{{ t('tools.weightsHelp') }}</p>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <label>
                                <span class="text-sm font-bold">{{ t('tools.capital') }}</span>
                                <input v-model="weights.capital" inputmode="decimal" class="mt-1 w-full rounded-xl border-slate-300" />
                            </label>
                            <label>
                                <span class="text-sm font-bold">{{ t('tools.work') }}</span>
                                <input v-model="weights.work" inputmode="decimal" class="mt-1 w-full rounded-xl border-slate-300" />
                            </label>
                            <label>
                                <span class="text-sm font-bold">{{ t('tools.expertise') }}</span>
                                <input v-model="weights.expertise" inputmode="decimal" class="mt-1 w-full rounded-xl border-slate-300" />
                            </label>
                            <label>
                                <span class="text-sm font-bold">{{ t('tools.risk') }}</span>
                                <input v-model="weights.risk" inputmode="decimal" class="mt-1 w-full rounded-xl border-slate-300" />
                            </label>
                        </div>
                    </div>

                    <div>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h3 class="font-black text-slate-950">{{ t('tools.scenarioPartners') }}</h3>
                                <p class="mt-1 text-sm text-slate-600">{{ t('tools.scenarioPartnersHelp') }}</p>
                            </div>
                            <button
                                type="button"
                                class="min-h-10 rounded-xl border border-slate-300 px-4 py-2 text-sm font-black"
                                @click="addPartner"
                            >
                                {{ t('tools.addPartner') }}
                            </button>
                        </div>

                        <div class="mt-4 space-y-4">
                            <div
                                v-for="(partner, index) in partners"
                                :key="index"
                                class="rounded-2xl border border-slate-200 p-4"
                            >
                                <div class="flex items-center justify-between gap-3">
                                    <p class="font-black text-slate-900">
                                        {{ t('tools.partner') }} {{ index + 1 }}
                                    </p>
                                    <button
                                        type="button"
                                        class="text-xs font-bold text-slate-500 underline"
                                        :disabled="partners.length <= 1"
                                        @click="removePartner(index)"
                                    >
                                        {{ t('tools.removePartner') }}
                                    </button>
                                </div>

                                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                                    <label>
                                        <span class="text-sm font-bold">{{ t('tools.partnerName') }}</span>
                                        <input v-model="partner.name" class="mt-1 w-full rounded-xl border-slate-300" />
                                    </label>
                                    <label>
                                        <span class="text-sm font-bold">{{ t('tools.capital') }}</span>
                                        <input v-model="partner.capital" inputmode="decimal" class="mt-1 w-full rounded-xl border-slate-300" />
                                    </label>
                                    <label>
                                        <span class="text-sm font-bold">{{ t('tools.work') }}</span>
                                        <input v-model="partner.work" inputmode="decimal" class="mt-1 w-full rounded-xl border-slate-300" />
                                    </label>
                                    <label>
                                        <span class="text-sm font-bold">{{ t('tools.expertise') }}</span>
                                        <input v-model="partner.expertise" inputmode="decimal" class="mt-1 w-full rounded-xl border-slate-300" />
                                    </label>
                                    <label>
                                        <span class="text-sm font-bold">{{ t('tools.risk') }}</span>
                                        <input v-model="partner.risk" inputmode="decimal" class="mt-1 w-full rounded-xl border-slate-300" />
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <button
                        type="submit"
                        class="min-h-11 rounded-xl bg-[var(--pbr-green)] px-5 py-2 text-sm font-black text-white"
                    >
                        {{ t('tools.calculateScenario') }}
                    </button>
                </form>

                <p
                    v-if="simulationError"
                    class="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-bold text-red-800"
                >
                    {{ simulationError }}
                </p>

                <div v-if="simulation" class="mt-6">
                    <div
                        v-if="!simulation.weightsTotalOneHundred"
                        class="mb-4 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm font-bold text-amber-950"
                    >
                        {{ t('tools.weightWarning') }} {{ simulation.weightTotal }}%.
                    </div>

                    <div class="overflow-x-auto rounded-2xl border border-slate-200">
                        <table class="min-w-full text-left text-sm">
                            <thead class="bg-slate-50 text-xs font-black uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">{{ t('tools.partnerName') }}</th>
                                    <th class="px-4 py-3">{{ t('tools.scenarioPercent') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="row in simulation.results" :key="row.partner">
                                    <td class="px-4 py-3 font-bold">{{ row.partner }}</td>
                                    <td class="px-4 py-3 font-black">{{ row.scenarioPercent }}%</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="mt-4 rounded-xl bg-slate-50 p-3 text-xs leading-5 text-slate-600">
                        {{ t('tools.noOfficialWrite') }}
                    </p>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
