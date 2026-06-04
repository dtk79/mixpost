import './bootstrap';
import '../css/app.css';
import 'floating-vue/dist/style.css'
import '@css/overrideTooltip.css'
import "@css/proseMirror.css";

import {createApp, h} from 'vue';
import {createInertiaApp} from '@inertiajs/vue3';
import {resolvePageComponent} from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js';
import {vTooltip} from 'floating-vue'
import AuthenticatedLayout from '@/Layouts/Authenticated.vue';
import {router} from "@inertiajs/vue3";

const appName = window.document.getElementsByTagName('title')[0]?.innerText || 'Mixpost';

const trackPageView = (url) => {
    if (typeof window.gtag !== 'function') {
        return;
    }

    const pageUrl = new URL(url, window.location.origin);

    window.gtag('event', 'page_view', {
        page_location: pageUrl.href,
        page_path: pageUrl.pathname,
    });
};

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: name => {
        const page = resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue'));

        page.then((module) => {
            module.default.layout = module.default.layout || AuthenticatedLayout;
        });

        return page;
    },
    setup({el, App, props, plugin}) {
        return createApp({render: () => h(App, props)})
            .use(plugin)
            .directive('tooltip', vTooltip)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4F46BB',
    },
});

// Refresh page on history operation
let stale = false;

window.addEventListener('popstate', () => {
    stale = true;
});

router.on('navigate', (event) => {
    const page = event.detail.page;

    if (stale) {
        router.get(page.url, {}, {replace: true, preserveScroll: true, preserveState: false});
    }

    stale = false;

    trackPageView(page.url);
});
