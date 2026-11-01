<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Pagination from '../../Components/Pagination.vue';
import LocationPicker from '../../Components/LocationPicker.vue';
import { dt, can } from '../../lib/format';

defineProps({ transfers: Object, location: Object, locations: Array, status: String });
const badge = { created: 'badge-ink', shipped: 'badge-blue', received: 'badge-green', discrepancy: 'badge-red', cancelled: 'badge-gray' };
const purpose = { storage: 'На хранение', issue: 'К выдаче', relocation: 'Перемещение' };
</script>

<template>
    <Head title="Перемещения" />
    <PageHeader eyebrow="Логистика" title="Перемещения" subtitle="Магазин ⇄ склад, склад ⇄ склад. Погрузка и приёмка по скану QR бирок.">
        <LocationPicker v-if="can('reports')" :locations="locations" :current="location" />
        <Link href="/transfers/create" class="btn-primary">Новое перемещение →</Link>
    </PageHeader>
    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>№</th><th>Статус</th><th>Откуда → куда</th><th>Назначение</th><th>Комплектов</th><th>Создано</th><th>Отгружено</th><th>Принято</th></tr></thead>
            <tbody>
                <tr v-for="t in transfers.data" :key="t.id">
                    <td><Link :href="`/transfers/${t.id}`" class="font-bold text-blue-600 tabular">{{ t.number }}</Link></td>
                    <td><span class="badge" :class="badge[t.status]">{{ t.status_label }}</span></td>
                    <td>{{ t.from.code }} → {{ t.to.code }}</td>
                    <td>{{ purpose[t.purpose] }}</td>
                    <td class="tabular">{{ t.lines_count }}</td>
                    <td class="tabular">{{ dt(t.created_at) }}</td>
                    <td class="tabular">{{ dt(t.shipped_at) }}</td>
                    <td class="tabular">{{ dt(t.received_at) }}</td>
                </tr>
                <tr v-if="!transfers.data.length"><td colspan="8" class="text-center text-ink-500 py-8">Перемещений нет</td></tr>
            </tbody>
        </table>
    </div>
    <Pagination :links="transfers.links" />
</template>
