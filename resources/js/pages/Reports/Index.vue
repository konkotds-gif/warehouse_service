<script setup>
import { reactive } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { d, money } from '../../lib/format';

const props = defineProps({ period: Object, warehouses: Array, byStatus: Object, stores: Array, overdue: Array });
const p = reactive({ ...props.period });
const statuses = usePage().props.dict.statuses;
const pct = (w) => (w.capacity ? Math.round((w.occupied / w.capacity) * 100) : 0);
</script>

<template>
    <Head title="Отчёты" />
    <PageHeader eyebrow="Аналитика" title="Отчёты" subtitle="Заполненность складов, комплекты по статусам, оборот по магазинам, просрочки">
        <form class="flex gap-2 items-center" @submit.prevent="router.get('/reports', p)">
            <input v-model="p.from" type="date" class="input !w-auto" /><span>—</span><input v-model="p.to" type="date" class="input !w-auto" />
            <button class="btn-secondary">Показать</button>
        </form>
    </PageHeader>

    <h2 class="text-lg mb-3">Заполненность складов</h2>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <div v-for="w in warehouses" :key="w.code" class="card p-5">
            <div class="flex justify-between items-baseline"><b class="text-lg">{{ w.code }} · {{ w.name }}</b><span class="text-2xl font-black tabular" :class="pct(w) > 90 ? 'text-red-600' : ''">{{ pct(w) }}%</span></div>
            <div class="mt-2 h-2.5 rounded-full bg-line overflow-hidden"><div class="h-full" :class="pct(w) > 90 ? 'bg-red-600' : 'bg-blue-600'" :style="{ width: pct(w) + '%' }"></div></div>
            <div class="mt-2 text-sm text-ink-500 tabular">{{ w.occupied }} из {{ w.capacity }} мест · {{ w.cells }} ячеек</div>
        </div>
    </div>

    <h2 class="text-lg mt-8 mb-3">Комплекты на хранении по статусам</h2>
    <div class="card p-5 grid gap-3 grid-cols-2 md:grid-cols-4">
        <div v-for="(meta, key) in statuses" v-show="byStatus[key]" :key="key" class="flex items-center justify-between gap-2"><StatusBadge :status="key" /><b class="tabular text-lg">{{ byStatus[key] }}</b></div>
    </div>

    <h2 class="text-lg mt-8 mb-3">Оборот по магазинам за {{ d(period.from) }} — {{ d(period.to) }}</h2>
    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Магазин</th><th>Принято договоров</th><th>Сумма начислений</th><th>Выдано комплектов</th></tr></thead>
            <tbody><tr v-for="s in stores" :key="s.code"><td>{{ s.code }} · {{ s.name }}</td><td class="tabular">{{ s.accepted }}</td><td class="tabular">{{ money(s.amount) }}</td><td class="tabular">{{ s.issued }}</td></tr></tbody>
        </table>
    </div>

    <h2 class="text-lg mt-8 mb-3">Просроченные хранения</h2>
    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Договор</th><th>Клиент</th><th>Телефон</th><th>Срок до</th><th>Где лежит</th></tr></thead>
            <tbody>
                <tr v-for="c in overdue" :key="c.id">
                    <td><Link :href="`/contracts/${c.id}`" class="font-bold text-blue-600 tabular">{{ c.number }}</Link></td>
                    <td>{{ c.client?.full_name }}</td><td class="tabular">{{ c.client?.phone }}</td><td class="tabular text-red-600">{{ d(c.storage_until) }}</td>
                    <td class="tabular">{{ c.sets.map((s) => s.address).join(', ') }}</td>
                </tr>
                <tr v-if="!overdue.length"><td colspan="5" class="text-center text-ink-500 py-6">Просрочек нет</td></tr>
            </tbody>
        </table>
    </div>
</template>
