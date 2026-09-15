<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';

type SelectOption = {
    value: string;
    label: string;
};

defineProps<{
    originTypes: SelectOption[];
    businessStages: SelectOption[];
    success?: string | null;
}>();

const form = useForm({
    name: '',
    origin_type: '',
    business_stage: '',
    base_currency: '',
});

const submit = () => {
    form.post('/businesses');
};
</script>

<template>
    <main class="min-h-screen bg-white px-6 py-10 text-slate-950">
        <section class="mx-auto max-w-3xl">
            <header class="border-b border-slate-200 pb-6">
                <p class="text-sm font-semibold uppercase tracking-wider text-slate-500">
                    thePBR OS
                </p>

                <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">
                            Create Business
                        </h1>

                        <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                            Create the Business workspace and establish your access to it.
                            Ownership and governance are handled separately.
                        </p>
                    </div>

                    <Link
                        href="/"
                        class="inline-flex min-h-11 items-center justify-center border border-slate-300 px-4 py-2 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2"
                    >
                        Back to account
                    </Link>
                </div>
            </header>

            <div
                v-if="success"
                role="status"
                class="mt-6 border border-slate-300 bg-slate-50 px-4 py-3 text-sm font-medium"
            >
                {{ success }}
            </div>

            <form class="mt-8 space-y-8" @submit.prevent="submit">
                <section>
                    <label for="business-name" class="block text-sm font-semibold">
                        Business name
                    </label>

                    <input
                        id="business-name"
                        v-model="form.name"
                        type="text"
                        name="name"
                        autocomplete="organization"
                        maxlength="160"
                        required
                        class="mt-2 min-h-11 w-full border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-700 focus:ring-2 focus:ring-slate-500"
                    >

                    <p
                        v-if="form.errors.name"
                        class="mt-2 text-sm font-medium text-red-700"
                    >
                        {{ form.errors.name }}
                    </p>
                </section>

                <fieldset>
                    <legend class="text-sm font-semibold">
                        How is this Business entering PBR?
                    </legend>

                    <p class="mt-1 text-sm leading-6 text-slate-600">
                        Choose the Business origin explicitly. This does not determine its
                        current stage, ownership, or governance.
                    </p>

                    <div class="mt-4 space-y-3">
                        <label
                            v-for="option in originTypes"
                            :key="option.value"
                            :for="`origin-${option.value}`"
                            class="flex min-h-11 cursor-pointer items-start gap-3 border border-slate-300 px-4 py-3 focus-within:ring-2 focus-within:ring-slate-500"
                        >
                            <input
                                :id="`origin-${option.value}`"
                                v-model="form.origin_type"
                                type="radio"
                                name="origin_type"
                                :value="option.value"
                                required
                                class="mt-1"
                            >

                            <span class="text-sm font-medium">
                                {{ option.label }}
                            </span>
                        </label>
                    </div>

                    <p
                        v-if="form.errors.origin_type"
                        class="mt-2 text-sm font-medium text-red-700"
                    >
                        {{ form.errors.origin_type }}
                    </p>
                </fieldset>

                <section>
                    <label for="business-stage" class="block text-sm font-semibold">
                        Current Business stage
                    </label>

                    <p class="mt-1 text-sm leading-6 text-slate-600">
                        Select the current stage independently from the Business origin.
                    </p>

                    <select
                        id="business-stage"
                        v-model="form.business_stage"
                        name="business_stage"
                        required
                        class="mt-2 min-h-11 w-full border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-slate-700 focus:ring-2 focus:ring-slate-500"
                    >
                        <option value="" disabled>
                            Select a stage
                        </option>

                        <option
                            v-for="option in businessStages"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>

                    <p
                        v-if="form.errors.business_stage"
                        class="mt-2 text-sm font-medium text-red-700"
                    >
                        {{ form.errors.business_stage }}
                    </p>
                </section>

                <section>
                    <label for="base-currency" class="block text-sm font-semibold">
                        Base currency
                    </label>

                    <p class="mt-1 text-sm leading-6 text-slate-600">
                        Enter a three-letter uppercase currency code, for example USD,
                        MMK, or THB.
                    </p>

                    <input
                        id="base-currency"
                        v-model="form.base_currency"
                        type="text"
                        name="base_currency"
                        inputmode="text"
                        maxlength="3"
                        minlength="3"
                        pattern="[A-Z]{3}"
                        placeholder="USD"
                        required
                        class="mt-2 min-h-11 w-full border border-slate-300 px-3 py-2 font-mono text-sm uppercase outline-none focus:border-slate-700 focus:ring-2 focus:ring-slate-500"
                    >

                    <p
                        v-if="form.errors.base_currency"
                        class="mt-2 text-sm font-medium text-red-700"
                    >
                        {{ form.errors.base_currency }}
                    </p>
                </section>

                <footer class="flex flex-wrap items-center justify-end gap-3 border-t border-slate-200 pt-6">
                    <Link
                        href="/"
                        class="inline-flex min-h-11 items-center justify-center border border-slate-300 px-4 py-2 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2"
                    >
                        Cancel
                    </Link>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex min-h-11 items-center justify-center bg-slate-950 px-5 py-2 text-sm font-semibold text-white focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {{ form.processing ? 'Creating…' : 'Create Business' }}
                    </button>
                </footer>
            </form>
        </section>
    </main>
</template>
