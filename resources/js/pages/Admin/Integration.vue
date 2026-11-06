<script setup>
import { ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Pagination from '../../Components/Pagination.vue';
import Modal from '../../Components/Modal.vue';
import { dt } from '../../lib/format';

defineProps({ events: Object, keys: Array, stats: Object, filter: String, scopes: { type: Object, default: () => ({ onec: '1С' }) } });
const page = usePage();
const key = useForm({ name: '1С УТ', allowed_ips: '', scope: 'onec' });
const payload = ref(null);
</script>

<template>
    <Head title="Обмен с 1С" />
    <PageHeader eyebrow="Администрирование" title="Обмен с 1С:УТ 10.3" subtitle="1С опрашивает очередь событий GET /api/v1/integration/events и подтверждает их POST /api/v1/integration/ack">
        <a href="/docs/api" target="_blank" class="btn-secondary">Документация API (Swagger)</a>
    </PageHeader>

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="card p-4"><div class="text-sm text-ink-500">Ждут подтверждения</div><div class="text-3xl font-black tabular">{{ stats.pending }}</div></div>
        <div class="card p-4" :class="stats.stale ? 'border-red-600' : ''"><div class="text-sm text-ink-500">Зависли (дольше порога)</div><div class="text-3xl font-black tabular" :class="stats.stale ? 'text-red-600' : ''">{{ stats.stale }}</div></div>
        <div class="card p-4"><div class="text-sm text-ink-500">Последнее подтверждение</div><div class="text-lg font-bold tabular">{{ dt(stats.last_ack_at) }}</div></div>
    </div>

    <section class="card p-5 mt-6">
        <h2 class="text-lg mb-3">Ключи API (1С и онлайн-запись)</h2>
        <div v-if="page.props.flash.secret" class="mb-4 rounded-[12px] bg-amber-500/10 p-4">
            <div class="text-sm font-semibold text-amber-700">Новый ключ — скопируйте сейчас, повторно он не показывается:</div>
            <code class="block mt-2 break-all text-lg">{{ page.props.flash.secret }}</code>
        </div>
        <table class="table">
            <thead><tr><th>#</th><th>Название</th><th>Для</th><th>IP</th><th>Использован</th><th>Статус</th><th></th></tr></thead>
            <tbody>
                <tr v-for="k in keys" :key="k.id"><td>{{ k.id }}</td><td>{{ k.name }}</td><td>{{ (k.scopes || ['onec']).map((x) => scopes[x] || x).join(', ') }}</td><td>{{ k.allowed_ips || 'любые' }}</td><td class="tabular">{{ dt(k.last_used_at) }}</td>
                    <td><span class="badge" :class="k.is_active ? 'badge-green' : 'badge-gray'">{{ k.is_active ? 'активен' : 'отключён' }}</span></td>
                    <td><button v-if="k.is_active" class="btn-ghost !min-h-8 text-red-600" @click="router.post(`/admin/api-keys/${k.id}/revoke`)">Отключить</button></td></tr>
            </tbody>
        </table>
        <form class="mt-4 grid gap-3 sm:grid-cols-4" @submit.prevent="key.post('/admin/api-keys')">
            <input v-model="key.name" class="input" placeholder="Название" />
            <select v-model="key.scope" class="input" @change="key.name = key.scope === 'booking' ? 'Онлайн-запись' : '1С УТ'"><option v-for="(label, k) in scopes" :key="k" :value="k">{{ label }}</option></select>
            <input v-model="key.allowed_ips" class="input" :placeholder="key.scope === 'booking' ? 'IP сервера онлайн-записи' : 'IP сервера 1С через запятую'" />
            <button class="btn-primary">Создать ключ</button>
        </form>
    </section>

    <section class="mt-6">
        <div class="flex items-center gap-2 mb-3">
            <h2 class="text-lg">События</h2>
            <div class="seg ml-auto"><button :class="{ on: !filter }" @click="router.get('/admin/integration')">Все</button><button :class="{ on: filter === 'pending' }" @click="router.get('/admin/integration', { filter: 'pending' })">Неподтверждённые</button></div>
        </div>
        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>id</th><th>Тип</th><th>Объект</th><th>Создано</th><th>Подтверждено</th><th>Попыток</th><th>Документ 1С</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="e in events.data" :key="e.id">
                        <td class="tabular">{{ e.id }}</td><td><code>{{ e.type }}</code></td><td class="tabular">{{ e.number }}</td><td class="tabular">{{ dt(e.created_at) }}</td>
                        <td class="tabular"><span v-if="e.delivered_at" class="text-green-700">{{ dt(e.delivered_at) }}</span><span v-else class="badge badge-amber">ждёт</span></td>
                        <td class="tabular">{{ e.attempts }}</td><td class="text-xs">{{ e.onec_doc_guid }}</td>
                        <td><button class="btn-ghost !min-h-8" @click="payload = e">JSON</button></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :links="events.links" />
    </section>
    <Modal :show="!!payload" :title="`Событие ${payload?.id} · ${payload?.type}`" wide @close="payload = null">
        <pre class="text-xs bg-bg rounded-[12px] p-4 overflow-auto max-h-[60vh]">{{ JSON.stringify(payload?.payload, null, 2) }}</pre>
    </Modal>
</template>
