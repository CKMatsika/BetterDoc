import { createInertiaApp, router } from '@inertiajs/vue3';
import { createApp, h, type DefineComponent } from 'vue';
import type { BrandingPayload } from '@/types/betterdoc';

let activeOrgTitle = 'BetterDoc DMS';

function syncDocumentBranding(branding?: BrandingPayload): void {
    if (!branding || typeof document === 'undefined') {
        return;
    }
    activeOrgTitle = branding.name || branding.short_name || 'BetterDoc DMS';
    document.body.style.setProperty('--brand-primary', branding.primary_color);
    document.body.style.setProperty('--brand-primary-fg', branding.primary_fg);
    document.body.style.setProperty('--brand-accent', branding.accent_color);
    document.body.style.setProperty('--brand-accent-fg', branding.accent_fg);

    const iconLink = document.querySelector<HTMLLinkElement>('link[rel="icon"]');
    if (iconLink && branding.favicon_url) {
        iconLink.href = branding.favicon_url;
    }
}

createInertiaApp({
    title: (title) => (title ? `${title} — ${activeOrgTitle}` : activeOrgTitle),
    resolve: (name) => {
        const pages = import.meta.glob<DefineComponent>('./Pages/**/*.vue', { eager: true });
        const page = pages[`./Pages/${name}.vue`];
        if (!page) {
            throw new Error(`Inertia page not found: ${name}`);
        }
        return page;
    },
    setup({ el, App, props, plugin }) {
        const initialBranding = (props.initialPage?.props as { branding?: BrandingPayload } | undefined)?.branding;
        syncDocumentBranding(initialBranding);

        router.on('navigate', (event) => {
            const navBranding = (event.detail.page.props as { branding?: BrandingPayload } | undefined)?.branding;
            syncDocumentBranding(navBranding);
        });

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
    progress: {
        color: '#0E7C66',
    },
});

if (typeof window !== 'undefined' && 'serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => undefined);
    });
}
