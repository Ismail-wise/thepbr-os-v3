<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import BusinessSwitcher from '../components/BusinessSwitcher.vue';

type BusinessOption = {
    id: string;
    name: string;
};

type WorkspaceContext = {
    businesses: BusinessOption[];
    currentBusiness: BusinessOption | null;
};

const page = usePage();

const workspace = computed(
    () => page.props.workspace as WorkspaceContext | null | undefined,
);

const currentBusinessName = computed(
    () => workspace.value?.currentBusiness?.name ?? 'No Business selected',
);
</script>

<template>
    <div class="min-h-screen bg-slate-50 text-slate-950">
        <div class="min-h-screen lg:grid lg:grid-cols-[18rem_minmax(0,1fr)]">
            <aside
                class="border-b border-slate-200 bg-white lg:min-h-screen lg:border-b-0 lg:border-r"
            >
                <div class="px-5 py-5">
                    <Link
                        href="/"
                        class="inline-flex items-center text-sm font-bold tracking-wide text-slate-950 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2"
                    >
                        thePBR OS
                    </Link>
                </div>

                <div class="border-t border-slate-200 px-5 py-5">
                    <BusinessSwitcher
                        :businesses="workspace?.businesses ?? []"
                        :current-business="workspace?.currentBusiness ?? null"
                    />
                </div>

                <nav
                    aria-label="Workspace navigation"
                    class="border-t border-slate-200 px-3 py-4"
                >
                    <Link
                        href="/"
                        class="block min-h-11 px-3 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-slate-500"
                    >
                        Home
                    </Link>

                    <Link
                        href="/businesses/create"
                        class="block min-h-11 px-3 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-slate-500"
                    >
                        Create Business
                    </Link>

                    <Link
                        href="/account/settings"
                        class="block min-h-11 px-3 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-slate-500"
                    >
                        Profile &amp; Settings
                    </Link>
                </nav>
            </aside>

            <div class="min-w-0">
                <header
                    class="border-b border-slate-200 bg-white px-6 py-4"
                >
                    <p
                        class="text-xs font-semibold uppercase tracking-wider text-slate-500"
                    >
                        Current Business
                    </p>
                    <p class="mt-1 truncate text-sm font-semibold text-slate-950">
                        {{ currentBusinessName }}
                    </p>
                </header>

                <slot />
            </div>
        </div>
    </div>
</template>
