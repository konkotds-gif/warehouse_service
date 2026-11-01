/*
 * Service worker платформы хранения: кэш оболочки приложения и собранных ассетов,
 * чтобы экран ТСД открывался при плохой связи. Сканы при отсутствии сети копит
 * офлайн-очередь в браузере (resources/js/lib/http.js) и отправляет позже.
 */
const CACHE = 'wk-v1';
const OFFLINE_URL = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((c) => c.addAll([OFFLINE_URL, '/icon.svg', '/manifest.webmanifest'])));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))));
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== location.origin) return;

    // Собранные ассеты (имена с хэшем) — из кэша
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(caches.match(req).then((hit) => hit || fetch(req).then((res) => {
            const copy = res.clone();
            caches.open(CACHE).then((c) => c.put(req, copy));
            return res;
        })));
        return;
    }

    // Страницы — сеть, при отсутствии связи — последняя сохранённая версия или заглушка
    if (req.mode === 'navigate') {
        event.respondWith(fetch(req).then((res) => {
            const copy = res.clone();
            caches.open(CACHE).then((c) => c.put(req, copy));
            return res;
        }).catch(() => caches.match(req).then((hit) => hit || caches.match(OFFLINE_URL))));
    }
});
