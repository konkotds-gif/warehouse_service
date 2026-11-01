<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PageHeader from '../Components/PageHeader.vue';
import ScanInput from '../Components/ScanInput.vue';
import StatusBadge from '../Components/StatusBadge.vue';
import { phone, d } from '../lib/format';

const props = defineProps({ q: String, clients: Array, contracts: Array });
const search = (v) => router.get('/search', { q: v });
</script>

<template>
    <Head title="Поиск" />
    <PageHeader eyebrow="Поиск" title="Найти клиента или хранение" subtitle="Телефон, госномер, ФИО, номер договора или скан QR с бирки/квитанции" />
    <ScanInput placeholder="Телефон, госномер, ФИО, № договора или QR" @scan="search" />
    <p v-if="q" class="mt-3 text-sm text-ink-500">Результаты по запросу «{{ q }}»</p>

    <div v-if="contracts.length" class="mt-6 card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Договор</th><th>Клиент</th><th>Авто</th><th>Комплекты</th><th>Принят</th></tr></thead>
            <tbody>
                <tr v-for="c in contracts" :key="c.id">
                    <td><Link :href="`/contracts/${c.id}`" class="font-bold text-blue-600 tabular">{{ c.number }}</Link></td>
                    <td>{{ c.client?.full_name }}</td>
                    <td>{{ c.vehicle?.title }}</td>
                    <td><div v-for="s in c.sets" :key="s.id" class="flex gap-2 items-center"><StatusBadge :status="s.status" /> <span class="tabular">{{ s.address }}</span></div></td>
                    <td class="tabular">{{ d(c.accepted_at) }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="mt-6 grid gap-4 md:grid-cols-2">
        <Link v-for="c in clients" :key="c.id" :href="`/clients/${c.id}`" class="card card-hover p-5">
            <div class="flex items-center justify-between">
                <div class="text-lg font-bold">{{ c.full_name }}</div>
                <span v-if="c.open_contracts" class="badge badge-green">На хранении: {{ c.open_contracts }}</span>
            </div>
            <div class="text-ink-500 tabular">{{ phone(c.phone) }}</div>
            <div class="mt-2 flex flex-wrap gap-2">
                <span v-for="v in c.vehicles" :key="v.id" class="chip bg-blue-50 text-blue-600">{{ v.title }}</span>
            </div>
        </Link>
    </div>
    <div v-if="q && !clients.length && !contracts.length" class="mt-8 card p-8 text-center text-ink-500">
        Ничего не найдено. <Link href="/accept" class="text-blue-600 font-semibold">Оформить новый приём →</Link>
    </div>
</template>
