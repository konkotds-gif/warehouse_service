<script setup>
import { reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import Pagination from '../../Components/Pagination.vue';
import { d } from '../../lib/format';

const props = defineProps({ contracts: Object, filters: Object, stores: Array });
const f = reactive({ ...props.filters });
const apply = () => router.get('/contracts', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true });
const statusBadge = { open: 'badge-green', overdue: 'badge-red', closed: 'badge-gray' };
</script>

<template>
    <Head title="Договоры" />
    <PageHeader eyebrow="Хранение" title="Договоры хранения">
        <Link href="/accept" class="btn-primary">Новый приём →</Link>
    </PageHeader>
    <form class="card p-4 grid gap-3 sm:grid-cols-4" @submit.prevent="apply">
        <input v-model="f.q" class="input tabular" placeholder="№ договора" />
        <select v-model="f.status" class="input" @change="apply"><option value="">Все статусы</option><option value="open">Открыт</option><option value="overdue">Просрочен</option><option value="closed">Закрыт</option></select>
        <select v-model="f.store" class="input" @change="apply"><option value="">Все магазины</option><option v-for="s in stores" :key="s.id" :value="s.id">{{ s.code }} · {{ s.name }}</option></select>
        <button class="btn-secondary">Найти</button>
    </form>
    <div class="mt-4 card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Договор</th><th>Статус</th><th>Клиент</th><th>Авто</th><th>Комплекты и адреса</th><th>Принят</th><th>Срок до</th></tr></thead>
            <tbody>
                <tr v-for="c in contracts.data" :key="c.id">
                    <td><Link :href="`/contracts/${c.id}`" class="font-bold text-blue-600 tabular">{{ c.number }}</Link></td>
                    <td><span class="badge" :class="statusBadge[c.status]">{{ c.status_label }}</span></td>
                    <td>{{ c.client?.full_name }}</td>
                    <td class="tabular">{{ c.vehicle?.plate }}</td>
                    <td><div v-for="s in c.sets" :key="s.id" class="flex gap-2 items-center mb-1"><StatusBadge :status="s.status" /><span class="tabular">{{ s.address }}</span></div></td>
                    <td class="tabular">{{ d(c.accepted_at) }} · {{ c.store?.code }}</td>
                    <td class="tabular">{{ d(c.storage_until) }}</td>
                </tr>
                <tr v-if="!contracts.data.length"><td colspan="7" class="text-center text-ink-500 py-8">Договоров нет</td></tr>
            </tbody>
        </table>
    </div>
    <Pagination :links="contracts.links" />
</template>
