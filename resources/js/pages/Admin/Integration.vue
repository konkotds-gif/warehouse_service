<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Pagination from '../../Components/Pagination.vue';
import Modal from '../../Components/Modal.vue';
import { dt } from '../../lib/format';

defineProps({ events: Object, stats: Object, filter: String });
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

    <p class="mt-4 text-sm text-ink-500">Ключи API и разрешённые IP — в разделе <Link href="/admin/api-keys" class="font-semibold underline">Ключи API</Link>.</p>

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
