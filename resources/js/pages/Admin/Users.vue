<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Modal from '../../Components/Modal.vue';
import { dt } from '../../lib/format';

const props = defineProps({ users: Array, roles: Array, locations: Array });
const editing = ref(null);
const form = useForm({ login: '', name: '', role: 'seller', location_id: null, is_active: true, password: '' });
function open(u = null) {
    editing.value = u || {};
    Object.assign(form, u ? { login: u.login, name: u.name, role: u.role, location_id: u.location_id, is_active: u.is_active, password: '' } : { login: '', name: '', role: 'seller', location_id: null, is_active: true, password: '' });
    form.clearErrors();
}
const save = () => (editing.value.id ? form.put(`/admin/users/${editing.value.id}`, { onSuccess: () => (editing.value = null) }) : form.post('/admin/users', { onSuccess: () => (editing.value = null) }));
</script>

<template>
    <Head title="Пользователи" />
    <PageHeader eyebrow="Администрирование" title="Пользователи и роли"><button class="btn-primary" @click="open()">Добавить</button></PageHeader>
    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Логин</th><th>Имя</th><th>Роль</th><th>Локация</th><th>Последний вход</th><th></th></tr></thead>
            <tbody>
                <tr v-for="u in users" :key="u.id" :class="!u.is_active ? 'opacity-50' : ''">
                    <td class="font-semibold">{{ u.login }}</td><td>{{ u.name }}</td><td>{{ u.role_label }}</td><td>{{ u.location || '—' }}</td>
                    <td class="tabular">{{ dt(u.last_login_at) }}</td><td><button class="btn-ghost !min-h-8" @click="open(u)">Изменить</button></td>
                </tr>
            </tbody>
        </table>
    </div>
    <Modal :show="!!editing" :title="editing?.id ? 'Пользователь' : 'Новый пользователь'" @close="editing = null">
        <div class="grid gap-3">
            <div><label class="label">Логин</label><input v-model="form.login" class="input" /><div class="error" v-if="form.errors.login">{{ form.errors.login }}</div></div>
            <div><label class="label">Имя</label><input v-model="form.name" class="input" /></div>
            <div><label class="label">Роль</label><select v-model="form.role" class="input"><option v-for="r in roles" :key="r.value" :value="r.value">{{ r.label }}</option></select></div>
            <div><label class="label">Локация</label><select v-model="form.location_id" class="input"><option :value="null">— вся сеть —</option><option v-for="l in locations" :key="l.id" :value="l.id">{{ l.code }} · {{ l.name }}</option></select></div>
            <div><label class="label">Пароль {{ editing?.id ? '(оставьте пустым, чтобы не менять)' : '' }}</label><input v-model="form.password" type="password" class="input" autocomplete="new-password" /><div class="error" v-if="form.errors.password">{{ form.errors.password }}</div></div>
            <label class="flex gap-2 text-sm"><input type="checkbox" v-model="form.is_active" class="accent-blue-600" /> Активен</label>
            <button class="btn-primary" :disabled="form.processing" @click="save">Сохранить</button>
        </div>
    </Modal>
</template>
