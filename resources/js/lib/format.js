import { usePage } from '@inertiajs/vue3';

function tz() {
    try {
        return usePage().props.app?.timezone || 'Asia/Yekaterinburg';
    } catch {
        return 'Asia/Yekaterinburg';
    }
}

export function dt(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat('ru-RU', { timeZone: tz(), day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value));
}

export function d(value) {
    if (!value) return '—';
    // Дата без времени (YYYY-MM-DD) — без сдвига часового пояса
    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        const [y, m, day] = value.split('-');
        return `${day}.${m}.${y}`;
    }
    return new Intl.DateTimeFormat('ru-RU', { timeZone: tz(), day: '2-digit', month: '2-digit', year: 'numeric' }).format(new Date(value));
}

export function money(v) {
    return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', maximumFractionDigits: 0 }).format(v || 0);
}

export function phone(p) {
    if (!p) return '—';
    const m = p.match(/^\+7(\d{3})(\d{3})(\d{2})(\d{2})$/);
    return m ? `+7 (${m[1]}) ${m[2]}-${m[3]}-${m[4]}` : p;
}

export function today(offsetDays = 0) {
    const dte = new Date(Date.now() + offsetDays * 86400000);
    return dte.toISOString().slice(0, 10);
}

export function can(ability) {
    const abilities = usePage().props.auth?.abilities || [];
    return abilities.includes('*') || abilities.includes(ability);
}
