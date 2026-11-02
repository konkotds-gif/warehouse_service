import '../css/app.css';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import AppLayout from './Components/AppLayout.vue';
import { startQueue } from './lib/http';

createInertiaApp({
    title: (title) => (title ? `${title} · Хранение колёс` : 'Хранение колёс'),
    resolve: (name) => {
        const pages = import.meta.glob('./pages/**/*.vue', { eager: true });
        const page = pages[`./pages/${name}.vue`];
        if (!page) throw new Error(`Страница не найдена: ${name}`);
        // Все страницы, кроме входа, — в общем каркасе
        page.default.layout ??= name.startsWith('Auth/') ? undefined : AppLayout;
        return page;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el);
    },
    progress: { color: '#E10A1A' },
});

startQueue();

// PWA: service worker для работы склада при плохой связи
if ('serviceWorker' in navigator && import.meta.env.PROD) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
}
