<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import Icon from '../Components/Icon.vue';
import PageHeader from '../Components/PageHeader.vue';
import StatusBadge from '../Components/StatusBadge.vue';
import LocationPicker from '../Components/LocationPicker.vue';
import { d } from '../lib/format';
import { indicators } from '../lib/indicators';

const props = defineProps({ location: Object, locations: Array, counts: Object, overdue: Number, tasks: Array, tasks_total: Number, ready: Array, integration: Object, attention: Array });
const page = usePage();
const abilities = computed(() => page.props.auth?.abilities || []);
const can = (a) => abilities.value.includes('*') || abilities.value.includes(a);
const user = computed(() => page.props.auth.user);

const tiles = computed(() => [
    { href: '/accept', title: 'Приём на хранение', text: 'Клиент · Авто/гараж · Комплект · Печать', icon: 'accept', show: can('accept'), primary: true },
    { href: '/scan/issue', title: 'Выдача', text: 'Скан квитанции, проверка долга, скан бирок', icon: 'issue', show: can('issue') },
    { href: '/scan/place', title: 'Размещение', text: 'Скан бирки → скан ячейки', icon: 'scan', show: can('place') },
    { href: '/transfers', title: 'Перемещения', text: 'Погрузка и приёмка по скану', icon: 'truck', show: can('transfers') },
    { href: '/search', title: 'Поиск', text: 'Телефон, госномер, договор, QR', icon: 'search', show: can('search') },
    { href: '/inventories', title: 'Инвентаризация', text: 'Сверка ячеек склада', icon: 'inventory', show: can('inventory') },
].filter((t) => t.show));

// «Требует внимания»: для выбранной руководителем точки — её счётчики, иначе живые из шапки; непустые сверху
const attention = computed(() => [...(props.attention ?? indicators.value)].sort((a, b) => (b.count > 0) - (a.count > 0)));
const toneBorder = { red: 'border-red-600', amber: 'border-amber-500', blue: 'border-blue-600', green: 'border-green-600' };
const toneText = { red: 'text-red-600', amber: 'text-amber-700', blue: 'text-blue-600', green: 'text-green-700' };

const stat = (s) => props.counts?.[s] || 0;
</script>

<template>
    <Head title="Главная" />
    <PageHeader eyebrow="Рабочий стол" :title="`Здравствуйте, ${user.name.split(' ')[0]}`"
        :subtitle="location ? `${location.type_label} ${location.label}` : 'Вся сеть'">
        <LocationPicker v-if="can('reports')" :locations="locations" :current="location" />
    </PageHeader>

    <section v-if="attention.length" class="mb-6">
        <h2 class="mb-3 text-lg">Требует внимания</h2>
        <div class="grid gap-3 grid-cols-2 md:grid-cols-3 xl:grid-cols-4">
            <Link v-for="i in attention" :key="i.key" :href="i.href" :title="i.hint"
                class="card card-hover flex flex-col gap-2 p-4 border-l-4" :class="i.count ? toneBorder[i.tone] : 'border-l-line opacity-70'">
                <span class="flex items-center gap-2 text-sm font-semibold" :class="i.count ? toneText[i.tone] : 'text-ink-500'">
                    <Icon :name="i.icon" :size="18" /> {{ i.label }}
                </span>
                <span class="flex items-end justify-between gap-2">
                    <span class="text-3xl font-black tabular" :class="i.count ? '' : 'text-ink-500'">{{ i.count }}</span>
                    <span v-if="i.count" class="hidden sm:inline text-xs text-ink-500 text-right">{{ i.hint }}</span>
                </span>
            </Link>
        </div>
    </section>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <Link v-for="t in tiles" :key="t.href" :href="t.href" class="card card-hover flex items-start gap-4 p-5">
            <span class="icon-tile" :class="t.primary ? '!bg-red-600' : ''"><Icon :name="t.icon" :size="24" /></span>
            <span class="flex-1">
                <span class="block text-lg font-bold">{{ t.title }}</span>
                <span class="block text-sm text-ink-500">{{ t.text }}</span>
            </span>
            <span class="text-ink-500 mt-1"><Icon name="arrow" :size="18" /></span>
        </Link>
    </div>

    <div class="mt-6 grid gap-4 grid-cols-2 md:grid-cols-4 xl:grid-cols-6">
        <div v-for="s in ['accepted','in_transit','received','placed','ordered','ready']" :key="s" class="card p-4">
            <StatusBadge :status="s" />
            <div class="mt-2 text-3xl font-black tabular">{{ stat(s) }}</div>
        </div>
    </div>

    <div v-if="integration" class="mt-4 card p-4 flex flex-wrap items-center gap-3" :class="integration.stale ? 'border-red-600' : ''">
        <Icon name="sync" />
        <span>Обмен с 1С: в очереди <b>{{ integration.pending }}</b> событий<template v-if="integration.stale">, <b class="text-red-600">{{ integration.stale }} без подтверждения дольше порога</b></template>.</span>
        <Link href="/admin/integration" class="btn-link !min-h-9 ml-auto">Открыть →</Link>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <section class="card p-5">
            <div class="flex items-center justify-between">
                <h2 class="text-lg">Задания <span class="text-ink-500 font-semibold">· {{ tasks_total }}</span></h2>
                <Link href="/tasks" class="btn-link !min-h-9">Все →</Link>
            </div>
            <ul class="mt-3 divide-y divide-line">
                <li v-for="t in tasks" :key="t.id" class="py-3 flex items-start gap-3">
                    <span class="badge" :class="t.overdue ? 'badge-red' : 'badge-blue'">{{ t.type_label }}</span>
                    <span class="flex-1 text-sm">{{ t.title }}</span>
                    <span class="text-xs text-ink-500 tabular whitespace-nowrap">{{ d(t.due_date) }}</span>
                </li>
                <li v-if="!tasks.length" class="py-6 text-center text-ink-500 text-sm">Открытых заданий нет</li>
            </ul>
        </section>
        <section class="card p-5">
            <div class="flex items-center justify-between">
                <h2 class="text-lg">Готовы к выдаче</h2>
                <span v-if="overdue" class="badge badge-red">Просрочено договоров: {{ overdue }}</span>
            </div>
            <ul class="mt-3 divide-y divide-line">
                <li v-for="s in ready" :key="s.id" class="py-3 flex items-center gap-3">
                    <Link :href="`/contracts/${s.contract_id}`" class="font-bold tabular text-blue-600">{{ s.qr_code }}</Link>
                    <span class="flex-1 text-sm">{{ s.client }} · {{ s.plate }}<br><span class="text-ink-500">{{ s.title }}</span></span>
                    <span class="text-xs text-ink-500">{{ d(s.issue_due_date) }}</span>
                </li>
                <li v-if="!ready.length" class="py-6 text-center text-ink-500 text-sm">Нет комплектов, готовых к выдаче</li>
            </ul>
        </section>
    </div>
</template>
