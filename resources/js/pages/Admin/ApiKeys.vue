<script setup>
import { ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Modal from '../../Components/Modal.vue';
import { dt } from '../../lib/format';

const props = defineProps({ keys: Array, scopes: Object, log: Array, logFilter: String });
const page = usePage();
const scopeOf = (k) => (k.scopes && k.scopes[0]) || 'onec';

const form = useForm({ name: '', allowed_ips: '', scope: 'booking' });
const editing = ref(null); // null — окно закрыто; {} — новый ключ; ключ — правка
function open(k = null) {
    editing.value = k || {};
    form.clearErrors();
    form.name = k ? k.name : 'Онлайн-запись';
    form.allowed_ips = k ? k.allowed_ips || '' : '';
    form.scope = k ? scopeOf(k) : 'booking';
}
function save() {
    const opts = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    editing.value?.id ? form.put(`/admin/api-keys/${editing.value.id}`, opts) : form.post('/admin/api-keys', opts);
}
const toggle = (k) => router.post(`/admin/api-keys/${k.id}/toggle`, {}, { preserveScroll: true });
const allow = (r) => router.post(`/admin/api-keys/${r.key_id}/allow-ip`, { ip: r.ip }, { preserveScroll: true });
const setLog = (v) => router.get('/admin/api-keys', v === 'all' ? { log: 'all' } : {}, { preserveScroll: true, preserveState: true });
</script>

<template>
    <Head title="Ключи API" />
    <PageHeader eyebrow="Администрирование" title="Ключи API и доступ" subtitle="Ключи для 1С и онлайн-записи, разрешённые IP и журнал обращений">
        <button class="btn-primary" @click="open()">Новый ключ</button>
    </PageHeader>

    <div v-if="page.props.flash.secret" class="mb-4 rounded-[12px] bg-amber-500/10 p-4">
        <div class="text-sm font-semibold text-amber-700">Новый ключ — скопируйте сейчас, повторно он не показывается:</div>
        <code class="block mt-2 break-all text-lg">{{ page.props.flash.secret }}</code>
    </div>

    <section class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>#</th><th>Название</th><th>Для</th><th>Разрешённые IP</th><th>Использован</th><th>Статус</th><th></th></tr></thead>
            <tbody>
                <tr v-for="k in keys" :key="k.id">
                    <td class="tabular">{{ k.id }}</td>
                    <td class="font-semibold">{{ k.name }}</td>
                    <td>{{ scopes[scopeOf(k)] || scopeOf(k) }}</td>
                    <td class="text-sm">{{ k.allowed_ips || 'любые' }}</td>
                    <td class="tabular">{{ dt(k.last_used_at) }}</td>
                    <td><span class="badge" :class="k.is_active ? 'badge-green' : 'badge-gray'">{{ k.is_active ? 'активен' : 'отключён' }}</span></td>
                    <td class="whitespace-nowrap text-right">
                        <button class="btn-ghost !min-h-8" @click="open(k)">Изменить</button>
                        <button class="btn-ghost !min-h-8" :class="k.is_active ? 'text-red-600' : ''" @click="toggle(k)">{{ k.is_active ? 'Отключить' : 'Включить' }}</button>
                    </td>
                </tr>
                <tr v-if="!keys.length"><td colspan="7" class="text-ink-500">Ключей пока нет.</td></tr>
            </tbody>
        </table>
    </section>
    <p class="mt-2 text-xs text-ink-500">IP — через запятую, можно подсеть (10.0.0.0/24). Пусто — с любого адреса. Если сервер за NAT, смотрите адрес в журнале отказов ниже: хранение показывает IP, который оно увидело.</p>

    <section class="mt-6">
        <div class="flex items-center gap-2 mb-3">
            <h2 class="text-lg">Журнал обращений</h2>
            <div class="seg ml-auto">
                <button :class="{ on: logFilter === 'denied' }" @click="setLog('denied')">Отказы</button>
                <button :class="{ on: logFilter === 'all' }" @click="setLog('all')">Все</button>
            </div>
        </div>
        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>Время</th><th>Ключ</th><th>Что</th><th>IP</th><th>Телефон / госномер</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="r in log" :key="r.id">
                        <td class="tabular">{{ dt(r.created_at) }}</td>
                        <td>{{ r.key_name || '—' }}</td>
                        <td><span v-if="r.denied" class="badge badge-amber">{{ r.action_label }}</span><span v-else>{{ r.action_label }}<span v-if="r.action === 'lookup'" class="text-ink-500"> · найдено {{ r.found }}</span></span></td>
                        <td class="tabular font-semibold">{{ r.ip }}</td>
                        <td class="tabular text-sm">{{ [r.phone, r.plate].filter(Boolean).join(' · ') }}</td>
                        <td class="text-right"><button v-if="r.action === 'denied_ip' && r.key_id" class="btn-secondary !min-h-8" @click="allow(r)">Разрешить этот IP</button></td>
                    </tr>
                    <tr v-if="!log.length"><td colspan="6" class="text-ink-500">{{ logFilter === 'denied' ? 'Отказов нет.' : 'Обращений пока нет.' }}</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <Modal :show="!!editing" :title="editing?.id ? `Ключ «${editing.name}»` : 'Новый ключ'" @close="editing = null">
        <form class="grid gap-3" @submit.prevent="save">
            <div><label class="label">Название</label><input v-model="form.name" class="input" /><div class="error" v-if="form.errors.name">{{ form.errors.name }}</div></div>
            <div><label class="label">Для чего</label><select v-model="form.scope" class="input"><option v-for="(label, k) in scopes" :key="k" :value="k">{{ label }}</option></select></div>
            <div>
                <label class="label">Разрешённые IP</label>
                <input v-model="form.allowed_ips" class="input" :placeholder="form.scope === 'booking' ? 'IP сервера онлайн-записи' : 'IP сервера 1С'" />
                <div class="error" v-if="form.errors.allowed_ips">{{ form.errors.allowed_ips }}</div>
                <div class="mt-1 text-xs text-ink-500">Через запятую, можно подсеть 10.0.0.0/24. Пусто — с любого адреса.</div>
            </div>
            <p v-if="!editing?.id" class="text-xs text-ink-500">Ключ покажется один раз после создания.</p>
            <button class="btn-primary" :disabled="form.processing">{{ editing?.id ? 'Сохранить' : 'Создать ключ' }}</button>
        </form>
    </Modal>
</template>
