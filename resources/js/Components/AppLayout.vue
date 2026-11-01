<script setup>
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import Icon from './Icon.vue';
import Logo from './Logo.vue';
import NetChip from './NetChip.vue';
import Toasts from './Toasts.vue';

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
    { href: '/inventories', label: 'Инвентаризация', icon: 'inventory', show: can('inventory') },
    { href: '/locations', label: 'Склады и ячейки', icon: 'map', show: can('place') },
    { href: '/reports', label: 'Отчёты', icon: 'chart', show: can('reports') },
    { href: '/admin/users', label: 'Пользователи', icon: 'settings', show: can('admin') },
    { href: '/admin/tariffs', label: 'Тарифы', icon: 'file', show: can('admin') },
    { href: '/admin/integration', label: 'Обмен с 1С', icon: 'sync', show: can('admin') },
].filter((i) => i.show));

const tabs = computed(() => nav.value.filter((i) => ['/', '/accept', '/scan/issue', '/scan/place', '/transfers', '/tasks'].includes(i.href)).slice(0, 4));

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
                    <Icon :name="i.icon" /> {{ i.label }}
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
                <Icon :name="t.icon" :size="22" /> {{ t.label }}
            </Link>
            <button class="flex flex-col items-center gap-1 py-2.5 text-[11px] font-semibold text-ink-500" @click="open = true">
                <Icon name="menu" :size="22" /> Ещё
            </button>
        </nav>

        <Toasts />
    </div>
</template>
