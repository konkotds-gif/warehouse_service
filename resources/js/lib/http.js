import { reactive } from 'vue';

/**
 * JSON-запросы для экранов сканирования и офлайн-очередь сканов (техдок, раздел 2.1: PWA / офлайн).
 * Если связи нет, скан сохраняется в браузере и отправляется, когда сеть появится.
 */
const KEY = 'wk.scanQueue';
const ERR_KEY = 'wk.scanErrors';

export const net = reactive({
    online: typeof navigator === 'undefined' ? true : navigator.onLine,
    queue: load(KEY),
    errors: load(ERR_KEY),
    flushing: false,
});

function load(key) {
    try {
        return JSON.parse(localStorage.getItem(key) || '[]');
    } catch {
        return [];
    }
}

function save() {
    try {
        localStorage.setItem(KEY, JSON.stringify(net.queue));
        localStorage.setItem(ERR_KEY, JSON.stringify(net.errors.slice(-50)));
    } catch {
        /* хранилище недоступно — очередь живёт только в памяти */
    }
}

function xsrf() {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

export class HttpError extends Error {
    constructor(message, status, data) {
        super(message);
        this.status = status;
        this.data = data;
    }
}

async function request(method, url, body) {
    const res = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrf(),
        },
        body: body ? JSON.stringify(body) : undefined,
    });
    let data = null;
    try {
        data = await res.json();
    } catch {
        data = null;
    }
    if (!res.ok) {
        const msg = data?.message || (data?.errors ? Object.values(data.errors).flat()[0] : null) || `Ошибка ${res.status}`;
        throw new HttpError(msg, res.status, data);
    }
    return data;
}

export const getJson = (url) => request('GET', url);

/**
 * POST JSON. С { queue: true } при отсутствии связи скан встаёт в очередь и возвращается { queued: true }.
 */
export async function postJson(url, body, { queue = false, label = '' } = {}) {
    if (queue && !navigator.onLine) {
        enqueue(url, body, label);
        return { queued: true, message: 'Нет связи — скан сохранён и будет отправлен автоматически' };
    }
    try {
        return await request('POST', url, body);
    } catch (e) {
        if (queue && !(e instanceof HttpError)) {
            enqueue(url, body, label);
            return { queued: true, message: 'Нет связи — скан сохранён и будет отправлен автоматически' };
        }
        throw e;
    }
}

function enqueue(url, body, label) {
    net.queue.push({ id: `${Date.now()}-${Math.random().toString(16).slice(2)}`, url, body, label, at: new Date().toISOString() });
    save();
}

export async function flush() {
    if (net.flushing || !net.queue.length || !navigator.onLine) return;
    net.flushing = true;
    try {
        while (net.queue.length) {
            const item = net.queue[0];
            try {
                await request('POST', item.url, item.body);
            } catch (e) {
                if (!(e instanceof HttpError)) break; // сеть снова пропала — попробуем позже
                if (e.status === 419) break; // истекла сессия — нужен вход, очередь сохраняем
                net.errors.push({ ...item, error: e.message });
            }
            net.queue.shift();
            save();
        }
    } finally {
        net.flushing = false;
    }
}

export function clearErrors() {
    net.errors = [];
    save();
}

let started = false;
export function startQueue() {
    if (started || typeof window === 'undefined') return;
    started = true;
    window.addEventListener('online', () => {
        net.online = true;
        flush();
    });
    window.addEventListener('offline', () => (net.online = false));
    setInterval(flush, 15000);
    flush();
}

/** Звук и вибрация после скана: успех / ошибка (ТСД). */
export function beep(ok = true) {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const o = ctx.createOscillator();
        const g = ctx.createGain();
        o.frequency.value = ok ? 1200 : 300;
        o.connect(g);
        g.connect(ctx.destination);
        g.gain.value = 0.15;
        o.start();
        o.stop(ctx.currentTime + (ok ? 0.12 : 0.35));
        navigator.vibrate?.(ok ? 60 : [120, 60, 120]);
    } catch {
        /* без звука */
    }
}
