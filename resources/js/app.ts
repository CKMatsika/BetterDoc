import { createInertiaApp } from '@inertiajs/vue3';
import { createApp, h, type DefineComponent } from 'vue';

createInertiaApp({
    title: (title) => (title ? `${title} — SENTINEL DMS` : 'SENTINEL DMS'),
    resolve: (name) => {
        const pages = import.meta.glob<DefineComponent>('./Pages/**/*.vue', { eager: true });
        const page = pages[`./Pages/${name}.vue`];
        if (!page) {
            throw new Error(`Inertia page not found: ${name}`);
        }
        return page;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
    progress: {
        color: '#0284C7',
    },
});
