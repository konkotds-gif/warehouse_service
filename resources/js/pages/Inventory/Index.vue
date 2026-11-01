<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import { dt } from '../../lib/format';

const props = defineProps({ inventories: Array, warehouses: Array });
const form = useForm({ location_id: props.warehouses[0]?.id || null, row_id: null });
const rows = computed(() => props.warehouses.find((w) => w.id === form.location_id)?.rows || []);
</script>

<template>
    <Head title="Инвентаризация" />
    <PageHeader eyebrow="Склад" title="Инвентаризация" subtitle="Сканируйте ячейки подряд — система покажет лишние, недостающие и лежащие не на своём месте комплекты" />
    <div class="card p-5 grid gap-3 sm:grid-cols-3 items-end">
        <div><label class="label">Склад</label><select v-model="form.location_id" class="input" @change="form.row_id = null"><option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.label }}</option></select></div>
        <div><label class="label">Ряд (необязательно)</label><select v-model="form.row_id" class="input"><option :value="null">Весь склад</option><option v-for="r in rows" :key="r.id" :value="r.id">Ряд {{ r.row_no }}</option></select></div>
        <button class="btn-primary" :disabled="!form.location_id || form.processing" @click="form.post('/inventories')">Начать →</button>
        <div v-if="form.errors.domain" class="error sm:col-span-3">{{ form.errors.domain }}</div>
    </div>
    <div class="mt-4 card overflow-x-auto">
        <table class="table">
            <thead><tr><th>№</th><th>Склад</th><th>Ряд</th><th>Статус</th><th>Начата</th><th>Итог</th></tr></thead>
            <tbody>
                <tr v-for="i in inventories" :key="i.id">
                    <td><Link :href="`/inventories/${i.id}`" class="font-bold text-blue-600">{{ i.id }}</Link></td>
                    <td>{{ i.location }}</td><td>{{ i.row || 'весь' }}</td>
                    <td><span class="badge" :class="i.status === 'open' ? 'badge-blue' : 'badge-green'">{{ i.status === 'open' ? 'Идёт' : 'Завершена' }}</span></td>
                    <td class="tabular">{{ dt(i.created_at) }}</td>
                    <td><template v-if="i.summary">недостаёт {{ i.summary.missing }}, не на месте {{ i.summary.misplaced }}, лишних {{ i.summary.extra }}</template></td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
