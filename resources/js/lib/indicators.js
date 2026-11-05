import { computed, ref } from 'vue';

/**
 * Счётчики актуальной работы пользователя (машина едет к нам, ждут погрузки, задания…).
 * Приходят общим пропом `indicators` при каждом переходе и обновляются в фоне раз в минуту.
 */
export const indicators = ref([]);

const TONE_ORDER = ['red', 'amber', 'blue', 'green'];
const REFRESH_MS = 60_000;
let timer = null;

export function setIndicators(list) {
    if (Array.isArray(list)) indicators.value = list;
}

export async function refreshIndicators() {
    if (typeof document !== 'undefined' && document.hidden) return;
    try {
        const res = await fetch('/indicators', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (res.ok) setIndicators(await res.json());
    } catch {
        // нет связи — оставляем последние значения
    }
}

export function startIndicators() {
    if (timer) return;
    timer = setInterval(refreshIndicators, REFRESH_MS);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshIndicators(); });
}

/** Самый «тревожный» цвет среди непустых счётчиков. */
export function worstTone(items) {
    const tones = items.filter((i) => i.count > 0).map((i) => i.tone);
    return TONE_ORDER.find((t) => tones.includes(t)) || 'blue';
}

/** Сводка по пункту меню: сумма счётчиков, относящихся к разделу, и цвет. */
export function navBadge(href) {
    const items = indicators.value.filter((i) => i.nav === href && i.count > 0);
    return items.length ? { count: items.reduce((s, i) => s + i.count, 0), tone: worstTone(items), title: items.map((i) => `${i.label}: ${i.count}`).join('\n') } : null;
}

export const activeIndicators = computed(() => indicators.value.filter((i) => i.count > 0));
export const totalActive = computed(() => activeIndicators.value.reduce((s, i) => s + i.count, 0));

export const pillClass = { red: 'bg-red-600 text-white', amber: 'bg-amber-500 text-white', blue: 'bg-blue-600 text-white', green: 'bg-green-600 text-white' };
