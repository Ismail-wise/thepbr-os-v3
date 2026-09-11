import '../css/app.css';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h, type DefineComponent } from 'vue';

createInertiaApp({
    title: (title) => (title ? `${title} · thePBR OS` : 'thePBR OS'),
    resolve: (name) =>
        resolvePageComponent<DefineComponent>(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./pages/**/*.vue', {
                import: 'default',
            }),
        ),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});
