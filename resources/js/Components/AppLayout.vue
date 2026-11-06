<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import Icon from './Icon.vue';
import Logo from './Logo.vue';
import NetChip from './NetChip.vue';
import Toasts from './Toasts.vue';
import ConfirmDialog from './ConfirmDialog.vue';
import { activeIndicators, navBadge, pillClass, setIndicators, startIndicators, totalActive, worstTone } from '../lib/indicators';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const abilities = computed(() => page.props.auth?.abilities || []);
const can = (a) => abilities.value.includes('*') || abilities.value.includes(a);
const open = ref(false);
const q = ref('');

const nav = computed(() => [
    { href: '/', label: 'Главная', icon: 'home', show: true },
    { href: '/accept', label: 'Приём', icon: 'accept', show: can('accept') },
    { href: '/scan/issue', label: 'Выдача', icon: 'issue', show: can('issue') },
    { href: '/scan/place', label: 'Размещение', icon: 'scan', show: can('place') },
    { href: '/transfers', label: 'Перемещения', icon: 'truck', show: can('transfers') },
    { href: '/tasks', label: 'Задания', icon: 'tasks', show: true },
    { href: '/contracts', label: 'Договоры', icon: 'file', show: can('search') },
    { href: '/clients', label: 'Клиенты', icon: 'users', show: can('clients') },
    { href: '/vehicles', label: 'Автомобили', icon: 'car', show: can('clients') },
    { href: '/inventories', label: 'Инвентаризация', icon: 'inventory', show: can('inventory') },
    { href: '/locations', label: 'Склады и ячейки', icon: 'map', show: can('place') },
    { href: '/reports', label: 'Отчёты', icon: 'chart', show: can('reports') },
    { href: '/admin/users', label: 'Пользователи', icon: 'users', show: can('admin') },
    { href: '/admin/tariffs', label: 'Тарифы', icon: 'file', show: can('admin') },
    { href: '/admin/integration', label: 'Обмен с 1С', icon: 'sync', show: can('admin') },
    { href: '/admin/api-keys', label: 'Ключи API', icon: 'settings', show: can('admin') },
    { href: '/admin/settings', label: 'Настройки', icon: 'settings', show: can('admin') },
].filter((i) => i.show));

const tabs = computed(() => nav.value.filter((i) => ['/', '/accept', '/scan/issue', '/scan/place', '/transfers', '/tasks'].includes(i.href)).slice(0, 4));

// Индикаторы актуальной работы: из общего пропа при каждом переходе + фоновое обновление
watch(() => page.props.indicators, (v) => setIndicators(v), { immediate: true });
onMounted(() => {
    startIndicators();
    document.addEventListener('click', closeBell);
});
const offNavigate = onBeforeUnmount(() => {
    document.removeEventListener('click', closeBell);
    offNavigate();
});

const bell = ref(false);
const bellEl = ref(null);
function closeBell(e) {
    if (bell.value && bellEl.value && !bellEl.value.contains(e.target)) bell.value = false;
}
const bellTone = computed(() => worstTone(activeIndicators.value));
// Счётчики разделов, которых нет в нижней панели, — на кнопке «Ещё»
const moreBadge = computed(() => {
    const shown = new Set(tabs.value.map((t) => t.href));
    const items = nav.value.filter((i) => !shown.has(i.href)).map((i) => navBadge(i.href)).filter(Boolean);
    return items.length ? { count: items.reduce((s, b) => s + b.count, 0), tone: items.some((b) => b.tone === 'red') ? 'red' : items[0].tone } : null;
});
const toneText = { red: 'text-red-600', amber: 'text-amber-700', blue: 'text-blue-600', green: 'text-green-700' };

function active(href) {
    const url = page.url.split('?')[0];
    return href === '/' ? url === '/' : url.startsWith(href);
}
function search() {
    if (q.value.trim()) router.get('/search', { q: q.value.trim() });
    q.value = '';
}
</script>

<template>
    <div class="min-h-screen lg:pl-64">
        <!-- Боковое меню (ПК) -->
        <aside class="hero-bg fixed inset-y-0 left-0 z-40 w-64 flex-col text-white transition-transform lg:flex"
            :class="open ? 'flex translate-x-0' : 'hidden lg:flex'">
            <div class="flex items-center justify-between px-5 py-5">
                <Link href="/"><Logo light :tag="false" /></Link>
                <button class="lg:hidden text-white/70" @click="open = false"><Icon name="x" /></button>
            </div>
            <nav class="flex-1 overflow-y-auto px-3 pb-4">
                <Link v-for="i in nav" :key="i.href" :href="i.href" @click="open = false"
                    class="mb-1 flex items-center gap-3 rounded-[12px] px-3 py-2.5 text-sm font-medium transition"
                    :class="active(i.href) ? 'bg-blue-600 text-white' : 'text-white/75 hover:bg-white/10 hover:text-white'">
                    <Icon :name="i.icon" /> <span class="flex-1">{{ i.label }}</span>
                    <span v-if="navBadge(i.href)" :title="navBadge(i.href).title"
                        class="min-w-6 rounded-full px-1.5 py-0.5 text-center text-xs font-bold tabular"
                        :class="active(i.href) ? 'bg-white text-blue-600' : pillClass[navBadge(i.href).tone]">{{ navBadge(i.href).count }}</span>
                </Link>
            </nav>
            <div class="border-t border-white/10 px-5 py-4 text-sm">
                <div class="font-semibold">{{ user?.name }}</div>
                <div class="text-white/60">{{ user?.role_label }}<template v-if="user?.location"> · {{ user.location.code }}</template></div>
                <Link href="/logout" method="post" as="button" class="mt-3 inline-flex items-center gap-2 text-white/70 hover:text-white">
                    <Icon name="logout" :size="16" /> Выйти
                </Link>
            </div>
        </aside>
        <div v-if="open" class="fixed inset-0 z-30 bg-navy-900/40 lg:hidden" @click="open = false"></div>

        <!-- Шапка -->
        <header class="sticky top-0 z-20 border-b border-line bg-white/95 backdrop-blur">
            <div class="mx-auto flex max-w-[1240px] items-center gap-3 px-4 py-3">
                <button class="btn-ghost !px-2 lg:hidden" @click="open = true" aria-label="Меню"><Icon name="menu" /></button>
                <Link href="/" class="lg:hidden"><Logo small /></Link>
                <form v-if="can('search')" class="relative ml-auto w-full max-w-md hidden sm:block" @submit.prevent="search">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-500"><Icon name="search" :size="18" /></span>
                    <input v-model="q" class="input !pl-10" placeholder="Телефон, госномер, № договора, QR…" />
                </form>
                <div class="ml-auto sm:ml-0 flex items-center gap-2">
                    <Link v-if="can('search')" href="/search" class="btn-ghost !px-2 sm:hidden" aria-label="Поиск"><Icon name="search" /></Link>
                    <div ref="bellEl" class="relative">
                        <button class="btn-ghost relative !px-2" :aria-label="`Актуальные задачи: ${totalActive}`" @click="bell = !bell">
                            <Icon name="bell" />
                            <span v-if="totalActive" class="absolute -right-0.5 -top-0.5 min-w-5 rounded-full px-1 text-center text-[11px] font-bold leading-5 tabular"
                                :class="pillClass[bellTone]">{{ totalActive > 99 ? '99+' : totalActive }}</span>
                        </button>
                        <div v-if="bell" class="card absolute right-0 top-full z-30 mt-2 w-[min(22rem,calc(100vw-2rem))] p-2 shadow-xl">
                            <div class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-ink-500">Требует внимания</div>
                            <Link v-for="i in activeIndicators" :key="i.key" :href="i.href"
                                class="flex items-center gap-3 rounded-[12px] px-3 py-2.5 hover:bg-slate-50">
                                <span :class="toneText[i.tone]"><Icon :name="i.icon" /></span>
                                <span class="flex-1 text-sm"><span class="block font-semibold">{{ i.label }}</span>
                                    <span class="block text-xs text-ink-500">{{ i.hint }}</span></span>
                                <span class="min-w-7 rounded-full px-2 py-0.5 text-center text-sm font-bold tabular" :class="pillClass[i.tone]">{{ i.count }}</span>
                            </Link>
                            <div v-if="!activeIndicators.length" class="px-3 py-6 text-center text-sm text-ink-500">Всё сделано — актуальных задач нет</div>
                        </div>
                    </div>
                    <NetChip />
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-[1240px] px-4 py-6 pb-28 lg:pb-10">
            <slot />
        </main>

        <!-- Нижняя панель вкладок (ТСД/смартфон) -->
        <nav class="fixed inset-x-0 bottom-0 z-20 grid border-t border-line bg-white lg:hidden" :style="{ gridTemplateColumns: `repeat(${tabs.length + 1}, 1fr)` }">
            <Link v-for="t in tabs" :key="t.href" :href="t.href" class="flex flex-col items-center gap-1 py-2.5 text-[11px] font-semibold"
                :class="active(t.href) ? 'text-blue-600' : 'text-ink-500'">
                <span class="relative"><Icon :name="t.icon" :size="22" />
                    <span v-if="navBadge(t.href)" class="absolute -right-2.5 -top-1.5 min-w-4 rounded-full px-1 text-center text-[10px] font-bold leading-4 tabular"
                        :class="pillClass[navBadge(t.href).tone]">{{ navBadge(t.href).count }}</span></span>
                {{ t.label }}
            </Link>
            <button class="flex flex-col items-center gap-1 py-2.5 text-[11px] font-semibold text-ink-500" @click="open = true">
                <span class="relative"><Icon name="menu" :size="22" />
                    <span v-if="moreBadge" class="absolute -right-2.5 -top-1.5 min-w-4 rounded-full px-1 text-center text-[10px] font-bold leading-4 tabular"
                        :class="pillClass[moreBadge.tone]">{{ moreBadge.count }}</span></span>
                Ещё
            </button>
        </nav>

        <Toasts />
        <ConfirmDialog />
    </div>
</template>
