<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Pagination from '../../Components/Pagination.vue';
import LocationPicker from '../../Components/LocationPicker.vue';
import { d, can } from '../../lib/format';

const props = defineProps({ tasks: Object, location: Object, locations: Array, filters: Object });
const types = { '': 'Все', place: 'Разместить', pick: 'Отобрать', discrepancy: 'Расхождения', inventory: 'Инвентаризация' };
const go = (patch) => router.get('/tasks', { ...props.filters, ...patch, location: props.location?.id }, { preserveState: true });
</script>

<template>
    <Head title="Задания" />
    <PageHeader eyebrow="Работа" title="Задания" :subtitle="location ? location.label : 'Все локации'">
        <LocationPicker v-if="can('reports')" :locations="locations" :current="location" />
    </PageHeader>
    <div class="flex flex-wrap gap-2 mb-4">
        <div class="seg"><button v-for="(l, k) in types" :key="k" :class="{ on: (filters.type || '') === k }" @click="go({ type: k || undefined })">{{ l }}</button></div>
        <div class="seg"><button :class="{ on: filters.status === 'open' }" @click="go({ status: 'open' })">Открытые</button><button :class="{ on: filters.status === 'done' }" @click="go({ status: 'done' })">Выполненные</button></div>
    </div>
    <div class="grid gap-3">
        <div v-for="t in tasks.data" :key="t.id" class="card p-4 flex flex-wrap items-center gap-3" :class="t.overdue ? 'border-red-600' : ''">
            <span class="badge" :class="t.overdue ? 'badge-red' : 'badge-blue'">{{ t.type_label }}</span>
            <div class="flex-1 min-w-[200px]">
                <div class="font-semibold">{{ t.title }}</div>
                <div class="text-sm text-ink-500">{{ t.location }} · срок {{ d(t.due_date) }}<template v-if="t.set"> · сейчас: <span class="tabular">{{ t.set.address }}</span></template></div>
            </div>
            <Link v-if="t.set" :href="`/contracts/${t.set.contract_id}`" class="btn-link !min-h-9">Договор →</Link>
            <Link v-if="t.type === 'place'" href="/scan/place" class="btn-blue !min-h-9">Разместить</Link>
            <Link v-if="t.type === 'inventory' && t.payload?.inventory_id" :href="`/inventories/${t.payload.inventory_id}`" class="btn-blue !min-h-9">Открыть</Link>
            <Link v-if="t.type === 'pick'" href="/transfers/create" class="btn-blue !min-h-9">Сформировать рейс</Link>
            <button v-if="t.status === 'open'" class="btn-secondary !min-h-9" @click="router.post(`/tasks/${t.id}/done`, {}, { preserveScroll: true })">Выполнено</button>
        </div>
        <div v-if="!tasks.data.length" class="card p-8 text-center text-ink-500">Заданий нет</div>
    </div>
    <Pagination :links="tasks.links" />
</template>
