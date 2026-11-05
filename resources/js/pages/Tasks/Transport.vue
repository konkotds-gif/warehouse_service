<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import LocationPicker from '../../Components/LocationPicker.vue';
import { dt, can } from '../../lib/format';

const props = defineProps({ pool: Array, trips: Array, location: Object, locations: Array });
const form = useForm({ transfer_ids: [], driver: '', vehicle: '' });
const purpose = { storage: 'На хранение', issue: 'К выдаче', relocation: 'Перемещение' };
const tripBadge = { loading: 'badge-ink', shipped: 'badge-blue', done: 'badge-green', cancelled: 'badge-gray' };
const selected = computed(() => props.pool.filter((t) => form.transfer_ids.includes(t.id)));
const totals = computed(() => ({
    sets: selected.value.reduce((n, t) => n + t.lines_count, 0),
    wheels: selected.value.reduce((n, t) => n + (t.wheels || 0), 0),
}));
function toggle(id) {
    const i = form.transfer_ids.indexOf(id);
    i >= 0 ? form.transfer_ids.splice(i, 1) : form.transfer_ids.push(id);
}
function toggleAll() {
    form.transfer_ids = form.transfer_ids.length === props.pool.length ? [] : props.pool.map((t) => t.id);
}
</script>

<template>
    <Head title="Перевозки" />
    <PageHeader eyebrow="Работа" title="Задания" :subtitle="location ? location.label : 'Все локации'">
        <LocationPicker v-if="can('reports')" :locations="locations" :current="location" />
    </PageHeader>
    <div class="flex flex-wrap gap-2 mb-4">
        <div class="seg">
            <Link href="/tasks">Задания</Link>
            <button class="on">Перевозки</button>
        </div>
    </div>

    <div class="card overflow-x-auto mb-4">
        <div class="flex flex-wrap items-center gap-2 p-4">
            <h2 class="text-lg">Ожидают погрузки <span class="text-ink-500 tabular">· {{ pool.length }}</span></h2>
            <button v-if="pool.length" class="btn-ghost ml-auto" @click="toggleAll">{{ form.transfer_ids.length === pool.length ? 'Снять все' : 'Выбрать все' }}</button>
        </div>
        <table class="table">
            <thead><tr><th></th><th>№</th><th>Откуда → куда</th><th>Назначение</th><th>Договоров</th><th>Комплектов</th><th>Колёс</th><th>Создано</th></tr></thead>
            <tbody>
                <tr v-for="t in pool" :key="t.id" class="cursor-pointer" @click="toggle(t.id)">
                    <td><input type="checkbox" class="h-5 w-5 accent-blue-600" :checked="form.transfer_ids.includes(t.id)" @click.stop @change="toggle(t.id)" /></td>
                    <td><Link :href="`/transfers/${t.id}`" class="font-bold text-blue-600 tabular" @click.stop>{{ t.number }}</Link>
                        <div v-if="t.notes" class="text-xs text-ink-500">{{ t.notes }}</div></td>
                    <td>{{ t.from.code }} → {{ t.to.code }}</td>
                    <td>{{ purpose[t.purpose] }}</td>
                    <td class="tabular">{{ t.contracts_count }}</td>
                    <td class="tabular">{{ t.lines_count }}</td>
                    <td class="tabular">{{ t.wheels }}</td>
                    <td class="tabular">{{ dt(t.created_at) }}</td>
                </tr>
                <tr v-if="!pool.length"><td colspan="8" class="text-center text-ink-500 py-8">Нет перемещений, ожидающих водителя. <Link href="/transfers/create" class="text-blue-600">Создать перемещение →</Link></td></tr>
            </tbody>
        </table>
    </div>

    <div v-if="pool.length" class="card p-5 mb-6">
        <h2 class="text-lg mb-3">Приехал водитель: сформировать ведомость</h2>
        <div class="grid gap-4 sm:grid-cols-3">
            <div><label class="label">Водитель</label><input v-model="form.driver" class="input" placeholder="ФИО, телефон" /></div>
            <div><label class="label">Автомобиль</label><input v-model="form.vehicle" class="input" placeholder="Марка, госномер" /></div>
            <div class="flex items-end">
                <button class="btn-primary w-full" :disabled="!form.transfer_ids.length || form.processing" @click="form.post('/trips')">
                    Сформировать ведомость ({{ form.transfer_ids.length }}) →
                </button>
            </div>
        </div>
        <p class="mt-2 text-sm text-ink-500">Выбрано перемещений: {{ form.transfer_ids.length }}, комплектов: {{ totals.sets }}, колёс: {{ totals.wheels }}. Бирки сканируются при погрузке на экране ведомости.</p>
        <div v-if="form.errors.domain" class="error mt-3">{{ form.errors.domain }}</div>
    </div>

    <div class="card overflow-x-auto">
        <div class="p-4"><h2 class="text-lg">Ведомости на перевозку</h2></div>
        <table class="table">
            <thead><tr><th>№</th><th>Статус</th><th>Водитель</th><th>Автомобиль</th><th>Маршрут</th><th>Перемещений</th><th>Создана</th><th>Отгружена</th></tr></thead>
            <tbody>
                <tr v-for="t in trips" :key="t.id">
                    <td><Link :href="`/trips/${t.id}`" class="font-bold text-blue-600 tabular">{{ t.number }}</Link></td>
                    <td><span class="badge" :class="tripBadge[t.status]">{{ t.status_label }}</span></td>
                    <td>{{ t.driver || '—' }}</td>
                    <td>{{ t.vehicle || '—' }}</td>
                    <td>{{ t.route.join(', ') }}</td>
                    <td class="tabular">{{ t.transfers_count }}</td>
                    <td class="tabular">{{ dt(t.created_at) }}</td>
                    <td class="tabular">{{ dt(t.shipped_at) }}</td>
                </tr>
                <tr v-if="!trips.length"><td colspan="8" class="text-center text-ink-500 py-8">Ведомостей нет</td></tr>
            </tbody>
        </table>
    </div>
</template>
