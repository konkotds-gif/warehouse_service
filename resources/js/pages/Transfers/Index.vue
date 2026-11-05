<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Pagination from '../../Components/Pagination.vue';
import LocationPicker from '../../Components/LocationPicker.vue';
import { dt, can } from '../../lib/format';

const props = defineProps({ transfers: Object, location: Object, locations: Array, status: String, dir: String });
const statusLabel = { created: 'Ожидает погрузки', shipped: 'В пути', received: 'Принято', discrepancy: 'Расхождение', cancelled: 'Отменено' };
const dirLabel = { in: 'входящие', out: 'исходящие' };
const badge = { created: 'badge-ink', shipped: 'badge-blue', received: 'badge-green', discrepancy: 'badge-red', cancelled: 'badge-gray' };
const purpose = { storage: 'На хранение', issue: 'К выдаче', relocation: 'Перемещение' };
</script>

<template>
    <Head title="Перемещения" />
    <PageHeader eyebrow="Логистика" title="Перемещения" subtitle="Магазин ⇄ склад, склад ⇄ склад. Погрузка и приёмка по скану QR бирок.">
        <LocationPicker v-if="can('reports')" :locations="locations" :current="location" />
        <Link href="/tasks/transport" class="btn-secondary">Перевозки</Link>
        <Link href="/transfers/create" class="btn-primary">Новое перемещение →</Link>
    </PageHeader>
    <div v-if="status || dir" class="mb-3 flex flex-wrap items-center gap-2 text-sm">
        <span class="text-ink-500">Фильтр:</span>
        <span v-if="status" class="badge" :class="badge[status]">{{ statusLabel[status] || status }}</span>
        <span v-if="dir" class="badge badge-ink">{{ dirLabel[dir] }}<template v-if="location">&nbsp;{{ dir === 'in' ? 'в' : 'из' }} {{ location.code }}</template></span>
        <Link href="/transfers" class="btn-link !min-h-8">Сбросить</Link>
    </div>
    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>№</th><th>Статус</th><th>Откуда → куда</th><th>Назначение</th><th>Комплектов</th><th>Создано</th><th>Отгружено</th><th>Принято</th></tr></thead>
            <tbody>
                <tr v-for="t in transfers.data" :key="t.id">
                    <td><Link :href="`/transfers/${t.id}`" class="font-bold text-blue-600 tabular">{{ t.number }}</Link></td>
                    <td><span class="badge" :class="badge[t.status]">{{ t.status_label }}</span>
                        <Link v-if="t.trip" :href="`/trips/${t.trip.id}`" class="ml-1 text-xs text-blue-600 tabular">{{ t.trip.number }}</Link></td>
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
