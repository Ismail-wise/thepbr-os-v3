<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

type BusinessOption = {
    id: string;
    name: string;
};

const props = defineProps<{
    businesses: BusinessOption[];
    currentBusiness: BusinessOption | null;
}>();

const form = useForm({
    business_id: props.currentBusiness?.id ?? '',
});

watch(
    () => props.currentBusiness?.id,
    (businessId) => {
        form.business_id = businessId ?? '';
    },
);

const selectBusiness = () => {
    if (
        form.processing ||
        form.business_id === '' ||
        form.business_id === props.currentBusiness?.id
    ) {
        return;
    }

    form.post('/current-business', {
        preserveScroll: true,
    });
};
</script>

<template>
    <div>
        <label
            for="business-switcher"
            class="block text-xs font-semibold uppercase tracking-wider text-slate-500"
        >
            Business
        </label>

        <select
            id="business-switcher"
            v-model="form.business_id"
            :disabled="form.processing || businesses.length === 0"
            class="mt-2 min-h-11 w-full border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-950 focus:outline-none focus:ring-2 focus:ring-slate-500 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500"
            aria-label="Select current Business"
            @change="selectBusiness"
        >
            <option value="">
                {{
                    businesses.length === 0
                        ? 'No accessible Businesses'
                        : 'Select a Business'
                }}
            </option>

            <option
                v-for="business in businesses"
                :key="business.id"
                :value="business.id"
            >
                {{ business.name }}
            </option>
        </select>

        <p
            v-if="form.hasErrors"
            role="alert"
            class="mt-2 text-xs font-medium text-red-700"
        >
            Business could not be selected.
        </p>
    </div>
</template>
